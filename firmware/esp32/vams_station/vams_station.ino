/**
 * Forest Lawn Memorial Park — vehicle access monitoring station.
 *
 * One of these sits in a weather box at each gate. It reads a windshield tag
 * as a vehicle approaches (UHF), or a card handed through the guardhouse
 * window (RC522), asks the server whether the vehicle may pass, and shows the
 * answer on the lamps, the buzzer and the display.
 *
 * Three properties shape the whole sketch:
 *
 *   Nothing blocks.        A vehicle arriving while the station is talking to
 *                          the server must still be read. Every peripheral is
 *                          polled each pass of loop(); every wait is a
 *                          deadline compared against millis(), never a delay().
 *
 *   Nothing is lost.       A scan taken while the network is down is queued to
 *                          flash with the time it actually happened, and sent
 *                          when the link returns. The server accepts the
 *                          device's timestamp so the movement lands in the
 *                          record at the right moment.
 *
 *   Nothing is decided     The station never grants access on its own. It
 *   here.                  reports what it read; the server applies the rules
 *                          and returns the verdict. A station that cannot
 *                          reach the server queues and shows "offline" — it
 *                          does not fall back to letting vehicles through.
 *
 * Wiring, library versions and commissioning are in README.md.
 *
 * @file
 * @version 1.0.0
 */

#include <Arduino.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include <WiFi.h>

#include "ApiClient.h"
#include "Display.h"
#include "FingerprintSensor.h"
#include "Indicators.h"
#include "Logger.h"
#include "NetworkManager.h"
#include "RfidReader.h"
#include "ScanQueue.h"
#include "UhfReader.h"
#include "config.h"
#include "secrets.h"

// ---------------------------------------------------------------------------
// Endpoints
// ---------------------------------------------------------------------------

static const char* PATH_AUTHENTICATE = "/api/v1/device/authenticate";
static const char* PATH_CONFIGURATION = "/api/v1/device/configuration";
static const char* PATH_HEARTBEAT     = "/api/v1/device/heartbeat";
static const char* PATH_ERROR         = "/api/v1/device/error";
static const char* PATH_SCAN          = "/api/v1/device/access/scan";
static const char* PATH_FP_VERIFY     = "/api/v1/device/fingerprint/verify";
static const char* PATH_FP_SIGNOUT    = "/api/v1/device/fingerprint/sign-out";

static const char* TAG = "station";

// ---------------------------------------------------------------------------
// Runtime configuration
//
// These come from the server, so an administrator can retune a station
// without anyone driving out to it with a laptop. The values here are only
// the defaults that apply before the first successful authentication.
// ---------------------------------------------------------------------------

struct RuntimeConfig {
    unsigned long heartbeatIntervalMs = 60000;
    unsigned long scanDebounceMs      = 5000;
    String gateType                   = "both";
    bool requireOperator              = true;
    unsigned long operatorSessionMs   = 3600000;
    size_t maxQueueSize               = QUEUE_CAPACITY;
};

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

enum class StationState : uint8_t {
    Booting,
    Connecting,     ///< radio associating
    Authenticating, ///< credentials not yet accepted this power cycle
    Ready,          ///< polling readers
    Fault           ///< the server refused the credentials; a person is needed
};

static NetworkManager    network;
static ApiClient         api;
static Display           display;
static Indicators        indicators;
static ScanQueue         queue;
static RfidReader        rfid(PIN_RFID_SS, PIN_RFID_RST);
static UhfReader         uhf(Serial1);
static FingerprintSensor fingerprint(Serial2);
static Preferences       storage;

static RuntimeConfig config;
static StationState  state = StationState::Booting;

static String stationName = DEVICE_CODE;

static bool   operatorActive = false;
static String operatorName;
static unsigned long operatorExpiresAt = 0;

static unsigned long lastHeartbeatAt   = 0;
static unsigned long lastAuthAttemptAt = 0;
static uint8_t       authFailures      = 0;
static unsigned long lastQueueAttemptAt = 0;
static unsigned long resultShownUntil   = 0;
static bool          idleScreenDrawn    = false;

static unsigned long buttonPressedAt = 0;
static bool          buttonHandled   = false;

/**
 * Peripheral failures found at boot, held until there is a server to tell.
 *
 * The readers are brought up before the radio, so a fault found there cannot
 * be reported when it is discovered. It is kept and sent once, after the
 * first successful authentication.
 */
static String pendingFaults;

/**
 * The last few UIDs seen, so a tag sitting in the antenna field is reported
 * once rather than continuously.
 *
 * A small ring is enough: the point is to suppress the repeat reads of the
 * vehicles currently at the barrier, not to remember the day's traffic.
 */
struct RecentUid {
    char uid[33];
    unsigned long seenAt;
};

static const uint8_t RECENT_SLOTS = 8;
static RecentUid recent[RECENT_SLOTS];
static uint8_t   recentNext = 0;

// ---------------------------------------------------------------------------
// Forward declarations
// ---------------------------------------------------------------------------

static void bootPeripherals();
static void attemptAuthentication();
static void sendHeartbeat();
static void drainQueue();
static void pollReaders();
static void pollFingerprint();
static void handleButton();
static void submitScan(const String& uid, const char* source);
static void maintainReaders();
static void applyConfiguration(const JsonDocument& data);
static bool isRepeat(const String& uid, unsigned long window);
static void rememberUid(const String& uid);
static void showResult(bool granted, const String& headline, const String& detail);
static void refreshIdle();
static void reportFault(const String& code, const String& message, const String& severity);
static void announce(const String& title, const String& detail);
static void factoryReset();

// ---------------------------------------------------------------------------
// setup
// ---------------------------------------------------------------------------

void setup()
{
    Logger::begin(SERIAL_BAUD, LogLevel::Info);
    Logger::info(TAG, "Forest Lawn access station " VAMS_FIRMWARE_VERSION);

    pinMode(PIN_BUTTON, INPUT_PULLUP);

    indicators.begin(PIN_LED_GREEN, PIN_LED_RED, PIN_BUZZER);
    indicators.set(Indication::Working);

    display.begin(PIN_OLED_SDA, PIN_OLED_SCL, OLED_ADDRESS);
    display.showBoot("Starting");

    // Held at boot: clear the queue and any stored state. The check happens
    // before anything else is brought up so a station wedged by bad stored
    // data can still be recovered.
    if (digitalRead(PIN_BUTTON) == LOW) {
        unsigned long heldSince = millis();

        display.showBoot("Hold to reset");

        while (digitalRead(PIN_BUTTON) == LOW && millis() - heldSince < 3000) {
            indicators.update();
        }

        if (digitalRead(PIN_BUTTON) == LOW) {
            factoryReset();
        }
    }

    storage.begin("vams", false);
    stationName = storage.getString("name", DEVICE_CODE);
    storage.end();

    queue.begin();

    if (!queue.isEmpty()) {
        Logger::info(TAG, String(queue.size()) + " scan(s) carried over from the last run");
    }

    bootPeripherals();

    api.begin(API_BASE_URL, DEVICE_CODE, DEVICE_API_KEY, API_ROOT_CA);

    display.showBoot("Wi-Fi");
    network.begin(WIFI_SSID, WIFI_PASSWORD, String("vams-") + DEVICE_CODE);

    state = StationState::Connecting;
}

/**
 * Bring up the readers, and tell the server about any that did not answer.
 *
 * A missing peripheral is not fatal. A station whose fingerprint sensor has
 * failed is still worth having at a gate — it can read tags — and an
 * administrator finding out from the device list beats finding out from a
 * queue of cars.
 */
static void bootPeripherals()
{
    display.showBoot("RFID reader");

    if (rfid.begin()) {
        Logger::info(TAG, "RC522 ready");
    } else {
        Logger::error(TAG, "RC522 did not answer — check the SPI wiring and 3.3 V supply");
        pendingFaults += "RC522 unresponsive. ";
    }

    display.showBoot("UHF reader");

    uhf.begin(PIN_UHF_RX, PIN_UHF_TX, UHF_BAUD,
#if UHF_DIAGNOSTIC_MODE
              UhfProtocol::Diagnostic
#else
              UhfProtocol::M100Frame
#endif
    );
    uhf.requestContinuousRead();

#if UHF_DIAGNOSTIC_MODE
    Logger::warning(TAG, "UHF diagnostic mode: raw bytes only, no tags will be reported");
#endif

    display.showBoot("Fingerprint");

    if (fingerprint.begin(PIN_FINGER_RX, PIN_FINGER_TX, FINGER_BAUD)) {
        Logger::info(TAG, "AS608 ready, " + String(fingerprint.storedCount()) + " template(s)");
    } else {
        Logger::error(TAG, "AS608 did not answer — check the crossover and the 3.3 V supply");
        pendingFaults += "AS608 unresponsive. ";
    }
}

// ---------------------------------------------------------------------------
// loop
// ---------------------------------------------------------------------------

void loop()
{
    network.update();
    indicators.update();
    handleButton();

    // The result screen is cleared on a timer rather than by the next event,
    // so a verdict stays up long enough for the guard to read it.
    if (resultShownUntil != 0 && millis() > resultShownUntil) {
        resultShownUntil = 0;
        idleScreenDrawn = false;
    }

    switch (state) {
        case StationState::Booting:
        case StationState::Connecting:
            if (network.isConnected()) {
                state = StationState::Authenticating;
                lastAuthAttemptAt = 0;
            } else {
                indicators.set(Indication::Offline);
                announce("Connecting", WIFI_SSID);
            }
            break;

        case StationState::Authenticating:
            attemptAuthentication();
            break;

        case StationState::Ready:
            if (!network.isConnected()) {
                indicators.set(Indication::Offline);
            }

            sendHeartbeat();
            drainQueue();
            pollReaders();
            pollFingerprint();
            refreshIdle();
            break;

        case StationState::Fault:
            indicators.set(Indication::Fault);
            announce("Not registered", "Check the device key");

            // Readers stay live so a scan is still recorded to the queue; the
            // server will accept it once the credentials are corrected.
            pollReaders();
            break;
    }
}

// ---------------------------------------------------------------------------
// Server conversation
// ---------------------------------------------------------------------------

static void attemptAuthentication()
{
    if (!network.isConnected()) {
        state = StationState::Connecting;

        return;
    }

    // Backoff after a failure, capped, so a station left running against a
    // server that is down does not hammer it.
    unsigned long wait = 0;

    if (authFailures > 0) {
        const uint8_t doublings = authFailures - 1 > 5 ? 5 : authFailures - 1;

        wait = 2000UL << doublings;
    }

    if (lastAuthAttemptAt != 0 && millis() - lastAuthAttemptAt < wait) {
        return;
    }

    lastAuthAttemptAt = millis();

    indicators.set(Indication::Working);
    display.showStatus("Registering", DEVICE_CODE);

    StaticJsonDocument<192> body;
    body["firmware_version"] = VAMS_FIRMWARE_VERSION;
    body["ip_address"] = network.ipAddress();

    ApiResponse response = api.post(PATH_AUTHENTICATE, body);

    if (response.ok) {
        authFailures = 0;

        if (!response.data["device"]["device_name"].isNull()) {
            stationName = response.data["device"]["device_name"].as<String>();

            storage.begin("vams", false);
            storage.putString("name", stationName);
            storage.end();
        }

        applyConfiguration(response.data);

        Logger::info(TAG, "Registered as " + stationName + " (" + config.gateType + " gate)");

        state = StationState::Ready;
        lastHeartbeatAt = 0;
        idleScreenDrawn = false;
        indicators.set(Indication::Idle);

        if (pendingFaults.length() > 0) {
            reportFault("PERIPHERAL_INIT", pendingFaults, "warning");
            pendingFaults = "";
        }

        return;
    }

    if (authFailures < 255) {
        authFailures++;
    }

    // A refused credential is different in kind from an unreachable server:
    // waiting will not fix it, so the station says so plainly rather than
    // retrying in silence for the rest of the shift.
    if (response.status == 401 || response.status == 403) {
        Logger::error(TAG, "The server refused these credentials: " + response.message);
        state = StationState::Fault;

        return;
    }

    display.showStatus("No server", response.message);
    indicators.set(Indication::Offline);
}

static void applyConfiguration(const JsonDocument& data)
{
    JsonVariantConst node = data["configuration"];

    if (node.isNull()) {
        return;
    }

    if (!node["heartbeat_interval"].isNull()) {
        config.heartbeatIntervalMs = node["heartbeat_interval"].as<unsigned long>() * 1000UL;
    }

    if (!node["scan_debounce"].isNull()) {
        config.scanDebounceMs = node["scan_debounce"].as<unsigned long>() * 1000UL;
    }

    if (!node["gate_type"].isNull()) {
        config.gateType = node["gate_type"].as<String>();
    }

    if (!node["require_operator"].isNull()) {
        config.requireOperator = node["require_operator"].as<bool>();
    }

    if (!node["operator_session_minutes"].isNull()) {
        config.operatorSessionMs = node["operator_session_minutes"].as<unsigned long>() * 60000UL;
    }

    if (!node["max_queue_size"].isNull()) {
        const size_t served = node["max_queue_size"].as<size_t>();

        // The served bound may be lower than the hardware ceiling but never
        // higher: the buffer is statically sized.
        config.maxQueueSize = served < QUEUE_CAPACITY ? served : QUEUE_CAPACITY;
    }
}

static void sendHeartbeat()
{
    if (!network.isConnected()) {
        return;
    }

    if (lastHeartbeatAt != 0 && millis() - lastHeartbeatAt < config.heartbeatIntervalMs) {
        return;
    }

    lastHeartbeatAt = millis();

    StaticJsonDocument<512> body;
    body["firmware_version"] = VAMS_FIRMWARE_VERSION;
    body["ip_address"]       = network.ipAddress();
    body["signal_strength"]  = network.signalStrength();
    body["free_heap_bytes"]  = ESP.getFreeHeap();
    body["heap_total_bytes"] = ESP.getHeapSize();
    body["uptime_seconds"]   = millis() / 1000UL;
    body["queued_requests"]  = queue.size();
    body["status"]           = queue.isEmpty() ? "online" : "degraded";

    // memory_usage_pct is derived here rather than server-side because only
    // the station knows its own heap size.
    const uint32_t total = ESP.getHeapSize();
    if (total > 0) {
        body["memory_usage_pct"] = 100.0 - ((100.0 * ESP.getFreeHeap()) / total);
    }

    ApiResponse response = api.post(PATH_HEARTBEAT, body);

    if (!response.ok) {
        if (response.status == 401 || response.status == 403) {
            state = StationState::Fault;
        }

        return;
    }

    if (!response.data["heartbeat_interval"].isNull()) {
        config.heartbeatIntervalMs = response.data["heartbeat_interval"].as<unsigned long>() * 1000UL;
    }

    const bool active = response.data["monitoring_active"].as<bool>();

    if (active != operatorActive) {
        operatorActive = active;
        idleScreenDrawn = false;
    }

    if (active && !response.data["operator"]["name"].isNull()) {
        operatorName = response.data["operator"]["name"].as<String>();
    } else if (!active) {
        operatorName = "";
    }

    // The server decides when a held queue should go, so a fleet reconnecting
    // after an outage does not all transmit at once.
    if (response.data["flush_queue"].as<bool>()) {
        lastQueueAttemptAt = 0;
    }
}

/**
 * Send one held scan per pass.
 *
 * One at a time, not the whole queue in a burst: the station has to keep
 * reading tags while it catches up, and the server's rate limit for a gate is
 * sized for a gate.
 */
static void drainQueue()
{
    if (queue.isEmpty() || !network.isConnected()) {
        return;
    }

    if (lastQueueAttemptAt != 0 && millis() - lastQueueAttemptAt < 1500) {
        return;
    }

    lastQueueAttemptAt = millis();

    QueuedScan held;

    if (!queue.peek(held)) {
        return;
    }

    StaticJsonDocument<256> body;
    body["rfid_uid"]   = held.uid;
    body["scanned_at"] = held.occurredAt;
    body["verification_method"] = "rfid";
    body["remarks"] = "Recorded while the station was offline.";

    if (held.accessType[0] != '\0') {
        body["action"] = held.accessType;
    }

    ApiResponse response = api.post(PATH_SCAN, body);

    // A refusal is still an answer: the server has recorded the attempt, so
    // the entry has done its job and must not be sent again. Only a transport
    // failure or a server error leaves it queued.
    if (response.status >= 200 && response.status < 500) {
        queue.pop();

        Logger::info(TAG, String("Held scan sent, ") + String(queue.size()) + " remaining");

        if (queue.isEmpty()) {
            idleScreenDrawn = false;
        }

        return;
    }

    queue.recordAttempt();
}

// ---------------------------------------------------------------------------
// Readers
// ---------------------------------------------------------------------------

static void pollReaders()
{
    maintainReaders();

    String uid;

    if (uhf.poll(uid) && uid.length() > 0) {
        if (!isRepeat(uid, SAME_TAG_LOCKOUT_MS)) {
            rememberUid(uid);
            submitScan(uid, "uhf");
        }
    }

    if (rfid.poll(uid) && uid.length() > 0) {
        if (!isRepeat(uid, SAME_CARD_LOCKOUT_MS)) {
            rememberUid(uid);
            submitScan(uid, "rc522");
        }
    }
}

/**
 * Keep the readers honest.
 *
 * An RC522 on a long cable in an electrically noisy gatehouse does
 * occasionally stop answering, and a UHF module that was never wired
 * correctly is silent from the start. Both are recoverable without a visit —
 * one by reinitialising the bus, the other by telling somebody.
 */
static void maintainReaders()
{
    static unsigned long lastRecoveryAt = 0;
    static bool uhfSilenceReported = false;

    if (!rfid.isPresent() && millis() - lastRecoveryAt > 30000) {
        lastRecoveryAt = millis();

        if (rfid.recover()) {
            Logger::info(TAG, "RC522 came back");
        }
    }

    // Two minutes is long enough that a working reader will have said
    // something, even at an empty gate: these modules chatter.
    if (!uhfSilenceReported && !uhf.hasEverResponded() && millis() > 120000) {
        uhfSilenceReported = true;

        Logger::warning(TAG, "Nothing has arrived from the UHF reader");
        reportFault("UHF_SILENT",
                    "No data received from the UHF reader since boot. Check the baud rate, "
                    "the TX/RX crossover and the reader's logic level.",
                    "warning");
    }
}

static void submitScan(const String& uid, const char* source)
{
    Logger::info(TAG, String("Read ") + uid + " on " + source);
    indicators.chirpRead();

    const String occurredAt = api.timestamp();

    // With operator authentication required, a station nobody has signed on to
    // is not in service. The read is still queued so the attempt appears in
    // the record — a gate that quietly discards scans is worse than one that
    // refuses them.
    if (config.requireOperator && !operatorActive) {
        queue.enqueue(uid, occurredAt, "");
        showResult(false, "Not on duty", "Sign in with your fingerprint");

        return;
    }

    if (!network.isConnected()) {
        if (queue.size() >= config.maxQueueSize || !queue.enqueue(uid, occurredAt, "")) {
            Logger::error(TAG, "The offline queue is full — this scan was not recorded");
            showResult(false, "Queue full", "Call the administrator");
            indicators.set(Indication::Fault);

            return;
        }

        showResult(false, "Offline", "Held: " + String(queue.size()));
        indicators.set(Indication::Offline);

        return;
    }

    indicators.set(Indication::Working);
    display.showStatus("Checking", uid);

    StaticJsonDocument<256> body;
    body["rfid_uid"]   = uid;
    body["scanned_at"] = occurredAt;
    body["verification_method"] = "rfid";

    ApiResponse response = api.post(PATH_SCAN, body);

    if (response.status <= 0) {
        // The request never reached the server, so the movement is unrecorded
        // and belongs in the queue.
        queue.enqueue(uid, occurredAt, "");
        showResult(false, "Offline", "Held: " + String(queue.size()));
        indicators.set(Indication::Offline);

        return;
    }

    if (response.status == 401 || response.status == 403) {
        state = StationState::Fault;
        showResult(false, "Refused", "Station not registered");

        return;
    }

    if (response.status == 429) {
        showResult(false, "Too fast", "Wait a moment");

        return;
    }

    const bool granted = response.data["granted"].as<bool>();
    String plate = response.data["plate_number"].as<String>();

    if (plate.length() == 0) {
        plate = uid;
    }

    showResult(granted, granted ? "GO" : "STOP",
               granted ? plate : plate + " — " + response.message);
}

static void pollFingerprint()
{
    if (!fingerprint.isPresent()) {
        return;
    }

    // An operator whose session has run out is signed off locally so the
    // display stops claiming they are on duty; the server expires the session
    // on its own schedule and the next heartbeat reconciles the two.
    if (operatorActive && operatorExpiresAt != 0 && millis() > operatorExpiresAt) {
        operatorActive = false;
        operatorName = "";
        operatorExpiresAt = 0;
        idleScreenDrawn = false;

        Logger::info(TAG, "The operator session expired");
    }

    const FingerprintMatch match = fingerprint.poll();

    if (!match.attempted) {
        return;
    }

    if (!network.isConnected()) {
        showResult(false, "Offline", "Cannot sign in now");

        return;
    }

    indicators.set(Indication::Working);
    display.showStatus("Verifying", "Hold still");

    StaticJsonDocument<192> body;
    body["matched"] = match.matched;
    body["purpose"] = "operator_login";

    // The slot and the score are the whole of what leaves the station. The
    // image never does, and there is no code path here that could send one.
    if (match.matched) {
        body["sensor_slot"] = match.slot;
        body["match_score"] = match.score;
    }

    ApiResponse response = api.post(PATH_FP_VERIFY, body);

    if (response.status <= 0) {
        showResult(false, "No server", "Try again");

        return;
    }

    const bool verified = response.data["verified"].as<bool>();

    operatorActive = response.data["monitoring_active"].as<bool>();
    operatorName = operatorActive && !response.data["operator"]["name"].isNull()
        ? response.data["operator"]["name"].as<String>()
        : String("");

    operatorExpiresAt = operatorActive ? millis() + config.operatorSessionMs : 0;

    showResult(verified, verified ? "On duty" : "Not recognised",
               verified ? operatorName : response.message);
}

// ---------------------------------------------------------------------------
// Button
// ---------------------------------------------------------------------------

/**
 * Held for two seconds during normal running: sign the operator out.
 *
 * A guard leaving the gate has both hands full and a queue behind them. One
 * button beats a menu.
 */
static void handleButton()
{
    const bool down = digitalRead(PIN_BUTTON) == LOW;

    if (down && buttonPressedAt == 0) {
        buttonPressedAt = millis();
        buttonHandled = false;

        return;
    }

    if (!down) {
        buttonPressedAt = 0;
        buttonHandled = false;

        return;
    }

    if (buttonHandled || millis() - buttonPressedAt < 2000) {
        return;
    }

    buttonHandled = true;

    if (!operatorActive) {
        return;
    }

    if (!network.isConnected()) {
        showResult(false, "Offline", "Cannot sign out now");

        return;
    }

    StaticJsonDocument<64> body;
    body["reason"] = "button";

    ApiResponse response = api.post(PATH_FP_SIGNOUT, body);

    if (response.ok) {
        operatorActive = false;
        operatorName = "";
        operatorExpiresAt = 0;

        showResult(true, "Signed out", "Monitoring stopped");
    } else {
        showResult(false, "Sign-out failed", response.message);
    }
}

// ---------------------------------------------------------------------------
// Presentation and small helpers
// ---------------------------------------------------------------------------

/**
 * Put a standing status on the display, but only when it has changed.
 *
 * The states that use this are entered from loop() and persist for as long as
 * the condition does, so writing the panel unconditionally would mean an I2C
 * transfer every pass — enough to visibly slow the readers.
 */
static void announce(const String& title, const String& detail)
{
    static String lastTitle;
    static String lastDetail;

    if (title == lastTitle && detail == lastDetail) {
        return;
    }

    lastTitle = title;
    lastDetail = detail;

    display.showStatus(title, detail);
}

static void showResult(bool granted, const String& headline, const String& detail)
{
    display.showResult(granted, headline, detail);

    indicators.set(granted ? Indication::Granted : Indication::Denied);

    if (granted) {
        indicators.chirpAccept();
    } else {
        indicators.chirpReject();
    }

    resultShownUntil = millis() + RESULT_DISPLAY_MS;
    idleScreenDrawn = false;
}

/**
 * Return to the resting screen once a verdict has had its time.
 *
 * Redrawn only when something it shows has changed: an I2C write every pass
 * of loop() would starve the readers.
 */
static void refreshIdle()
{
    if (resultShownUntil != 0 || idleScreenDrawn) {
        return;
    }

    display.showIdle(stationName, config.gateType, network.isConnected(),
                     queue.size(), operatorName);

    indicators.set(network.isConnected() ? Indication::Idle : Indication::Offline);

    idleScreenDrawn = true;
}

static bool isRepeat(const String& uid, unsigned long window)
{
    for (uint8_t i = 0; i < RECENT_SLOTS; i++) {
        if (recent[i].uid[0] == '\0') {
            continue;
        }

        if (uid.equals(recent[i].uid) && millis() - recent[i].seenAt < window) {
            return true;
        }
    }

    return false;
}

static void rememberUid(const String& uid)
{
    // If this UID is already in the ring its timestamp is refreshed rather
    // than a second slot consumed; otherwise a tag held in the field would
    // fill the ring with copies of itself and evict the other vehicles.
    for (uint8_t i = 0; i < RECENT_SLOTS; i++) {
        if (uid.equals(recent[i].uid)) {
            recent[i].seenAt = millis();

            return;
        }
    }

    strncpy(recent[recentNext].uid, uid.c_str(), sizeof(recent[recentNext].uid) - 1);
    recent[recentNext].uid[sizeof(recent[recentNext].uid) - 1] = '\0';
    recent[recentNext].seenAt = millis();

    recentNext = (recentNext + 1) % RECENT_SLOTS;
}

static void reportFault(const String& code, const String& message, const String& severity)
{
    if (!network.isConnected() || state == StationState::Fault) {
        return;
    }

    StaticJsonDocument<384> body;
    body["code"] = code;
    body["message"] = message;
    body["severity"] = severity;

    api.post(PATH_ERROR, body);
}

/**
 * Clear everything the station has stored.
 *
 * The credentials themselves are compiled in, so this cannot strand a station
 * — it clears the held queue, the cached name, and nothing that would stop it
 * coming back up.
 */
static void factoryReset()
{
    Logger::warning(TAG, "Button held at boot — clearing stored state");

    display.showBoot("Resetting");

    queue.begin();
    queue.clear();

    storage.begin("vams", false);
    storage.clear();
    storage.end();

    indicators.chirpReject();

    // Wait for the button to come back up, or the reset would run again on
    // the next pass through setup().
    while (digitalRead(PIN_BUTTON) == LOW) {
        indicators.update();
    }
}
