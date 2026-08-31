/**
 * Forest Lawn Memorial Park — vehicle access monitoring station.
 *
 * One of these sits in a weather box at each gate. It reads a windshield tag
 * as a vehicle approaches (UHF), or a card handed through the guardhouse
 * window (RC522), asks the server whether the vehicle may pass, and shows the
 * answer on the lamps, the buzzer and the display.
 *
 * This is the whole firmware. Everything the station does is in this one
 * file, in the order it is built up: constants, then each peripheral, then
 * the station itself. The only other file is secrets.h, which carries the
 * site's credentials and is deliberately kept out of version control.
 *
 * Three properties shape the whole sketch:
 *
 *   Nothing blocks.        A vehicle arriving while the station is talking to
 *                          the server must still be read. Every peripheral is
 *                          polled each pass of loop(); every wait is a
 *                          deadline compared against millis(), never a
 *                          delay(). The one exception is fingerprint
 *                          enrolment, which is a supervised action at a bench
 *                          and is reached only from the serial console.
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
 * A fingerprint image never leaves the sensor. A match produces a slot number
 * and a confidence score, and that is the whole of what this firmware sees or
 * transmits; there is no code path here that could send anything more.
 *
 * Before flashing: copy secrets.h.example to secrets.h and fill it in.
 * Wiring, library versions and commissioning are in README.md.
 *
 * @file
 * @version 1.0.0
 */

#include <Arduino.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <SPI.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <Wire.h>

#include <Adafruit_Fingerprint.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <MFRC522.h>

#include <esp_system.h>
#include <limits.h>
#include <mbedtls/md.h>
#include <time.h>

#include "secrets.h"

// ===========================================================================
// 1. Compile-time configuration: pin assignments and fixed limits
//
// Anything an administrator might want to retune at runtime — the heartbeat
// interval, the debounce window, whether an operator must sign on — is served
// by the server instead and lives in RuntimeConfig. What remains here is the
// wiring, which cannot change without a screwdriver.
//
// Hardware this firmware is written for:
//   ESP32 development board (WROOM-32 or equivalent)
//   MFRC522 RFID reader   — 13.56 MHz HF, SPI, a few centimetres
//   UHF reader            — 860-960 MHz, UART, metres (model configurable)
//   AS608 fingerprint     — optical, UART
//   SSD1306 OLED 128x64   — I2C, optional
//
// The two RFID readers do different jobs. The UHF reader picks up a
// windshield tag as a vehicle approaches, which is what makes a drive-through
// lane possible. The RC522 reads a card held against it at the guardhouse
// window — visitor cards, and any credential handed over by hand. Both feed
// the same endpoint: the server resolves a UID against the tag register and
// then the card register, so it does not need to be told which reader saw it.
// ===========================================================================

#define VAMS_FIRMWARE_VERSION "1.0.0"

// ---------------------------------------------------------------------------
// Pin assignments
//
// Two boards are supported, and the right map is chosen by whatever is
// selected in Tools -> Board. They are not interchangeable: the Arduino Nano
// ESP32 carries an ESP32-S3, which numbers its pins differently and reserves
// different ones, so a map written for one board is wrong on the other.
//
// If the sketch stops at the #error below, the selected board is neither of
// them. Pick the one that matches the board in your hand — this is a wiring
// decision, and guessing it produces a station that flashes cleanly and then
// reads nothing.
// ---------------------------------------------------------------------------

#if defined(ARDUINO_NANO_ESP32)

// --- Arduino Nano ESP32 (ESP32-S3) -----------------------------------------
//
// Written against the silkscreen — D2, A0 and so on — rather than raw GPIO
// numbers, because the two do not match on this board and the silkscreen is
// what you can actually read while wiring. The SPI and I2C pins are the
// board's defaults, so the libraries find them without being told.

// MFRC522 over the default SPI bus.
#define PIN_RFID_SS    D10  // SDA/SS on the module silkscreen
#define PIN_RFID_RST   D9
#define PIN_RFID_SCK   D13
#define PIN_RFID_MISO  D12
#define PIN_RFID_MOSI  D11

// UHF reader over UART1.
//
// Check the reader's logic level before wiring it. Many UHF modules run their
// UART at 5 V; the ESP32 is not 5 V tolerant and its receive pin will be
// damaged. If the reader's TX idles at 5 V, put a level shifter — or at
// minimum a divider — between them.
#define PIN_UHF_RX     D5   // ESP32 receives here  <- reader TX
#define PIN_UHF_TX     D6   // ESP32 transmits here -> reader RX

// AS608 over UART2. Cross the pair: sensor TX goes to the ESP32 RX.
#define PIN_FINGER_RX  D7   // ESP32 receives here  <- sensor TX
#define PIN_FINGER_TX  D8   // ESP32 transmits here -> sensor RX

// SSD1306 over the default I2C bus.
#define PIN_OLED_SDA   A4
#define PIN_OLED_SCL   A5

// Operator feedback.
#define PIN_LED_GREEN  D2
#define PIN_LED_RED    D3
#define PIN_BUZZER     D4

// Held at boot to clear stored state; also signs the operator out during
// normal running.
#define PIN_BUTTON     A0

#elif defined(ARDUINO_ARCH_ESP32)

// --- ESP32-WROOM-32 development board --------------------------------------
//
// Chosen to avoid the pins that cannot be used freely on a classic ESP32:
//   GPIO 6-11   wired to the SPI flash; using them bricks the boot
//   GPIO 0,2,12,15  strapping pins, held at boot to select the boot mode
//   GPIO 34-39  input only, no pull-ups, no output
//
// The RC522 reset line is on 27 rather than the 22 many tutorials use,
// because 22 is the I2C clock for the display.

// MFRC522 over VSPI.
#define PIN_RFID_SS    5    // SDA/SS on the module silkscreen
#define PIN_RFID_RST   27
#define PIN_RFID_SCK   18
#define PIN_RFID_MISO  19
#define PIN_RFID_MOSI  23

// UHF reader over UART1.
//
// GPIO 35 is input-only, which is all a receive line needs. The default UART1
// pins (9 and 10) are wired to the SPI flash and cannot be used.
//
// Check the reader's logic level before wiring it. Many UHF modules run their
// UART at 5 V; the ESP32 is not 5 V tolerant and its receive pin will be
// damaged. If the reader's TX idles at 5 V, put a level shifter — or at
// minimum a divider — between them.
#define PIN_UHF_RX     35   // ESP32 receives here  <- reader TX
#define PIN_UHF_TX     13   // ESP32 transmits here -> reader RX

// AS608 over UART2. Cross the pair: sensor TX goes to the ESP32 RX.
#define PIN_FINGER_RX  16   // ESP32 receives here  <- sensor TX
#define PIN_FINGER_TX  17   // ESP32 transmits here -> sensor RX

// SSD1306 over I2C.
#define PIN_OLED_SDA   21
#define PIN_OLED_SCL   22

// Operator feedback.
#define PIN_LED_GREEN  25
#define PIN_LED_RED    26
#define PIN_BUZZER     33

// Held at boot to clear stored state; also signs the operator out during
// normal running.
#define PIN_BUTTON     32

#else
#error "Select an ESP32 board in Tools -> Board. This firmware runs on an ESP32; there is no pin map for the board currently selected."
#endif

// Shared by both boards.
#define UHF_BAUD       115200
#define FINGER_BAUD    57600
#define OLED_ADDRESS   0x3C
#define OLED_WIDTH     128
#define OLED_HEIGHT    64

// ---------------------------------------------------------------------------
// Fixed limits
// ---------------------------------------------------------------------------

/**
 * Scans held while the network is down.
 *
 * The server serves its own bound in the configuration payload; this is the
 * ceiling the hardware can hold regardless. Each entry is small, but the queue
 * is persisted to flash, and an unbounded one would wear it out.
 */
#define QUEUE_CAPACITY 64

/** Longest a single HTTP request may take before it is abandoned. */
#define HTTP_TIMEOUT_MS 8000

/** How long a decision stays on the display before it returns to idle. */
#define RESULT_DISPLAY_MS 4000

/** Wi-Fi association attempt length, before the radio is cycled. */
#define WIFI_CONNECT_TIMEOUT_MS 20000

/**
 * A card left resting on the reader is read continuously. Anything sooner
 * than this after the previous read of the same UID is the same presentation,
 * not a second one.
 *
 * The server applies its own duplicate-suppression window as well; this one
 * exists so the station does not spend the network on transmissions the
 * server would only discard.
 */
#define SAME_CARD_LOCKOUT_MS 3000

/**
 * A vehicle sitting in range of the UHF reader is read over and over. The
 * lockout above applies per UID to both readers, but the UHF antenna also has
 * a wider field, so the same tag can be seen for as long as the vehicle is
 * stationary at the barrier. This is the longer window applied to UHF reads.
 */
#define SAME_TAG_LOCKOUT_MS 8000

/** Serial console speed. */
#define SERIAL_BAUD 115200

/**
 * Dump every byte arriving from the UHF reader to the serial console instead
 * of parsing it.
 *
 * Set this when commissioning a reader whose framing is not yet known: hold a
 * tag to the antenna, read the hex the console prints, and pick or write the
 * matching protocol. The serial command "uhf diag" does the same thing
 * without reflashing. See the README section "Bringing up an unknown UHF
 * reader".
 */
#define UHF_DIAGNOSTIC_MODE 0

// ===========================================================================
// 2. Logger — serial output with levels and a millisecond stamp
//
// The console is often the only window into a station mounted in a weather
// box at a gate, so the output is kept readable rather than terse.
// ===========================================================================

enum class LogLevel : uint8_t {
    Debug   = 0,
    Info    = 1,
    Warning = 2,
    Error   = 3,
    None    = 4
};

namespace Logger {

LogLevel g_level = LogLevel::Info;

inline const char* label(LogLevel level)
{
    switch (level) {
        case LogLevel::Debug:   return "DEBUG";
        case LogLevel::Info:    return "INFO ";
        case LogLevel::Warning: return "WARN ";
        case LogLevel::Error:   return "ERROR";
        default:                return "     ";
    }
}

inline void write(LogLevel level, const String& tag, const String& message)
{
    if (static_cast<uint8_t>(level) < static_cast<uint8_t>(g_level)) {
        return;
    }

    // Seconds since boot, which is what matters when reading back a log to
    // work out how long a station ran before it faulted.
    const unsigned long ms = millis();

    char stamp[16];
    snprintf(stamp, sizeof(stamp), "%7lu.%03lu", ms / 1000UL, ms % 1000UL);

    Serial.print('[');
    Serial.print(stamp);
    Serial.print("] ");
    Serial.print(label(level));
    Serial.print(' ');
    Serial.print(tag);
    Serial.print(": ");
    Serial.println(message);
}

inline void begin(unsigned long baud, LogLevel level = LogLevel::Info)
{
    Serial.begin(baud);

    // A board that has just been flashed prints boot chatter; a short pause
    // keeps the first real line from landing in the middle of it.
    delay(200);

    g_level = level;
}

inline void setLevel(LogLevel level) { g_level = level; }
inline LogLevel level() { return g_level; }

inline void debug(const String& tag, const String& message)   { write(LogLevel::Debug, tag, message); }
inline void info(const String& tag, const String& message)    { write(LogLevel::Info, tag, message); }
inline void warning(const String& tag, const String& message) { write(LogLevel::Warning, tag, message); }
inline void error(const String& tag, const String& message)   { write(LogLevel::Error, tag, message); }

/** Print a byte buffer as spaced hex — used when inspecting reader frames. */
inline void hexDump(const String& tag, const uint8_t* data, size_t length)
{
    if (static_cast<uint8_t>(LogLevel::Debug) < static_cast<uint8_t>(g_level)) {
        return;
    }

    String line;
    line.reserve(length * 3);

    for (size_t i = 0; i < length; i++) {
        char byteText[4];
        snprintf(byteText, sizeof(byteText), "%02X ", data[i]);
        line += byteText;
    }

    write(LogLevel::Debug, tag, line);
}

}  // namespace Logger

// ===========================================================================
// 3. Indicators — the lamps and the buzzer
//
// A guard standing at a barrier in daylight cannot read a small OLED, so the
// green and red lamps carry the decision and the display only elaborates.
// Every pattern is non-blocking: the station must keep reading tags while a
// lamp is lit.
// ===========================================================================

enum class Indication : uint8_t {
    Idle,
    Working,     // both lamps alternating while a call is out
    Granted,
    Denied,
    Offline,     // slow red pulse: queueing rather than transmitting
    Fault        // fast red pulse: something needs a person
};

// The ESP32 core drives a passive buzzer through the LEDC peripheral.
//
// Version 3 of the core rewrote that API: a channel is no longer allocated by
// hand, and every call addresses the pin directly. Both spellings are kept
// here because the two boards this firmware supports do not ship the same
// core — the Arduino Nano ESP32 package is on 3.x, and most ESP32 dev-board
// installations are still on 2.x.
static const uint8_t BUZZER_CHANNEL = 0;
static const uint8_t BUZZER_RESOLUTION = 8;

#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
#define VAMS_LEDC_ATTACH(pin, frequency, bits) ledcAttach((pin), (frequency), (bits))
#define VAMS_LEDC_TONE(pin, frequency)         ledcWriteTone((pin), (frequency))
#define VAMS_LEDC_SILENCE(pin)                 ledcWrite((pin), 0)
#else
#define VAMS_LEDC_ATTACH(pin, frequency, bits)                 \
    do {                                                       \
        ledcSetup(BUZZER_CHANNEL, (frequency), (bits));        \
        ledcAttachPin((pin), BUZZER_CHANNEL);                  \
    } while (0)
#define VAMS_LEDC_TONE(pin, frequency) ledcWriteTone(BUZZER_CHANNEL, (frequency))
#define VAMS_LEDC_SILENCE(pin)         ledcWrite(BUZZER_CHANNEL, 0)
#endif

class Indicators {
public:
    void begin(uint8_t greenPin, uint8_t redPin, uint8_t buzzerPin)
    {
        greenPin_  = greenPin;
        redPin_    = redPin;
        buzzerPin_ = buzzerPin;

        pinMode(greenPin_, OUTPUT);
        pinMode(redPin_, OUTPUT);

        VAMS_LEDC_ATTACH(buzzerPin_, 2000, BUZZER_RESOLUTION);
        VAMS_LEDC_SILENCE(buzzerPin_);

        applySteady(false, false);
        state_ = Indication::Idle;
        stateSince_ = millis();
    }

    /** Set the standing pattern. */
    void set(Indication state)
    {
        if (state_ == state) {
            return;
        }

        state_ = state;
        stateSince_ = millis();
        lastToggle_ = stateSince_;
        phase_ = false;
    }

    Indication state() const { return state_; }

    /** Drive the current pattern. Call every loop; never blocks. */
    void update()
    {
        const unsigned long now = millis();

        // --- Tones ---------------------------------------------------------

        if (toneActive_ && now - toneStartedAt_ >= toneDuration_[tonePlaying_]) {
            tonePlaying_++;

            if (tonePlaying_ >= toneCount_) {
                VAMS_LEDC_SILENCE(buzzerPin_);
                toneActive_ = false;
                toneCount_ = 0;
            } else {
                const unsigned int frequency = toneFreq_[tonePlaying_];

                if (frequency == 0) {
                    VAMS_LEDC_SILENCE(buzzerPin_);   // a rest between notes
                } else {
                    VAMS_LEDC_TONE(buzzerPin_, frequency);
                }

                toneStartedAt_ = now;
            }
        }

        // --- Lamps ---------------------------------------------------------

        switch (state_) {
            case Indication::Idle:
                applySteady(false, false);
                break;

            case Indication::Granted:
                applySteady(true, false);
                break;

            case Indication::Denied:
                applySteady(false, true);
                break;

            case Indication::Working:
                // Both lamps alternating reads as "busy" without implying
                // either verdict.
                if (now - lastToggle_ >= 150) {
                    phase_ = !phase_;
                    lastToggle_ = now;
                    applySteady(phase_, !phase_);
                }
                break;

            case Indication::Offline:
                if (now - lastToggle_ >= 1200) {
                    phase_ = !phase_;
                    lastToggle_ = now;
                    applySteady(false, phase_);
                }
                break;

            case Indication::Fault:
                if (now - lastToggle_ >= 200) {
                    phase_ = !phase_;
                    lastToggle_ = now;
                    applySteady(false, phase_);
                }
                break;
        }
    }

    /** A single tick — something was read, before the verdict is known. */
    void chirpRead()
    {
        toneFreq_[0] = 2200; toneDuration_[0] = 40;
        startTones(1);
    }

    /** A short rising pair — a credential was accepted. */
    void chirpAccept()
    {
        toneFreq_[0] = 1800; toneDuration_[0] = 90;
        toneFreq_[1] = 2600; toneDuration_[1] = 140;
        startTones(2);
    }

    /** A low double note — a credential was refused. */
    void chirpReject()
    {
        // Low, twice, with a gap: distinguishable from the accept pair
        // without having to look up.
        toneFreq_[0] = 420; toneDuration_[0] = 180;
        toneFreq_[1] = 0;   toneDuration_[1] = 80;
        toneFreq_[2] = 420; toneDuration_[2] = 320;
        startTones(3);
    }

private:
    void startTones(uint8_t count)
    {
        toneCount_ = count;
        tonePlaying_ = 0;
        toneStartedAt_ = millis();
        toneActive_ = true;
        VAMS_LEDC_TONE(buzzerPin_, toneFreq_[0]);
    }

    void applySteady(bool green, bool red)
    {
        digitalWrite(greenPin_, green ? HIGH : LOW);
        digitalWrite(redPin_, red ? HIGH : LOW);
    }

    uint8_t greenPin_  = 0;
    uint8_t redPin_    = 0;
    uint8_t buzzerPin_ = 0;

    Indication state_ = Indication::Idle;
    unsigned long stateSince_ = 0;
    unsigned long lastToggle_ = 0;
    bool phase_ = false;

    // A queued tone sequence, played out by update() so nothing blocks.
    static const uint8_t TONE_SLOTS = 4;
    unsigned int toneFreq_[TONE_SLOTS] = {0};
    unsigned long toneDuration_[TONE_SLOTS] = {0};
    uint8_t toneCount_ = 0;
    uint8_t tonePlaying_ = 0;
    unsigned long toneStartedAt_ = 0;
    bool toneActive_ = false;
};

// ===========================================================================
// 4. Display — the SSD1306 OLED
//
// Optional: if no panel answers on the bus the station runs exactly as it
// would otherwise, because the lamps and the buzzer already carry the
// decision. Every method is safe to call when the display is absent.
// ===========================================================================

static const char* TAG_OLED = "oled";

class Display {
public:
    Display() : panel_(OLED_WIDTH, OLED_HEIGHT, &Wire, -1) {}

    bool begin(uint8_t sdaPin, uint8_t sclPin, uint8_t address)
    {
        Wire.begin(sdaPin, sclPin);

        present_ = panel_.begin(SSD1306_SWITCHCAPVCC, address);

        if (!present_) {
            Logger::warning(TAG_OLED, "no panel found; running without a display");

            return false;
        }

        panel_.clearDisplay();
        panel_.setTextColor(SSD1306_WHITE);
        panel_.display();

        Logger::info(TAG_OLED, "display online");

        return true;
    }

    /** Boot progress, so a station that hangs shows where it stopped. */
    void showBoot(const String& step)
    {
        if (!present_) {
            return;
        }

        panel_.clearDisplay();
        panel_.setTextSize(1);
        panel_.setCursor(0, 0);
        panel_.println(F("VAMS station"));
        panel_.drawFastHLine(0, 10, OLED_WIDTH, SSD1306_WHITE);
        panel_.setCursor(0, 16);
        printWrapped(step, 4);
        panel_.display();
    }

    /** A transient status line: connecting, sending, that sort of thing. */
    void showStatus(const String& title, const String& detail)
    {
        if (!present_) {
            return;
        }

        panel_.clearDisplay();
        panel_.setTextSize(1);
        panel_.setCursor(0, 0);
        panel_.println(title);
        panel_.drawFastHLine(0, 10, OLED_WIDTH, SSD1306_WHITE);
        panel_.setCursor(0, 16);
        printWrapped(detail, 5);
        panel_.display();
    }

    /** The resting screen: station name, gate role, link state, queue depth. */
    void showIdle(const String& stationName,
                  const String& gateType,
                  bool online,
                  size_t queued,
                  const String& operatorName)
    {
        if (!present_) {
            return;
        }

        panel_.clearDisplay();

        panel_.setTextSize(1);
        panel_.setCursor(0, 0);
        panel_.print(stationName);

        // Link state sits top-right, where it can be checked at a glance.
        panel_.setCursor(OLED_WIDTH - 24, 0);
        panel_.print(online ? F("ON") : F("OFF"));

        panel_.drawFastHLine(0, 10, OLED_WIDTH, SSD1306_WHITE);

        panel_.setTextSize(2);
        panel_.setCursor(0, 18);
        panel_.print(F("READY"));

        panel_.setTextSize(1);
        panel_.setCursor(0, 40);
        panel_.print(gateType);

        panel_.setCursor(0, 50);

        if (operatorName.length() > 0) {
            panel_.print(operatorName);
        } else {
            panel_.print(F("no operator"));
        }

        if (queued > 0) {
            // The queue depth matters: it is the count of movements the
            // record does not have yet.
            panel_.setCursor(OLED_WIDTH - 36, 50);
            panel_.print(F("Q:"));
            panel_.print(queued);
        }

        panel_.display();
    }

    /** A decision. Large verdict, plate underneath, reason if refused. */
    void showResult(bool granted, const String& headline, const String& detail)
    {
        if (!present_) {
            return;
        }

        panel_.clearDisplay();

        // The verdict is inverted so it reads from further away than the
        // detail.
        panel_.fillRect(0, 0, OLED_WIDTH, 20, SSD1306_WHITE);
        panel_.setTextColor(SSD1306_BLACK);
        panel_.setTextSize(2);
        panel_.setCursor(4, 3);
        panel_.print(granted ? F("PASS") : F("STOP"));

        panel_.setTextColor(SSD1306_WHITE);
        panel_.setTextSize(1);
        panel_.setCursor(0, 24);
        panel_.print(headline);

        panel_.setCursor(0, 36);
        printWrapped(detail, 3);

        panel_.display();
    }

    bool isPresent() const { return present_; }

private:
    /** Break text onto lines that fit the panel at the current size. */
    void printWrapped(const String& text, uint8_t maxLines)
    {
        // At size 1 the 6-pixel font gives 21 characters across a 128-pixel
        // panel.
        const uint8_t columns = 21;

        uint8_t line = 0;
        unsigned int start = 0;

        while (start < text.length() && line < maxLines) {
            unsigned int end = start + columns;

            if (end >= text.length()) {
                panel_.println(text.substring(start));
                break;
            }

            // Break on a space rather than mid-word where one is close
            // enough.
            int space = -1;
            for (unsigned int i = end; i > start; i--) {
                if (text.charAt(i) == ' ') {
                    space = static_cast<int>(i);
                    break;
                }
            }

            if (space > static_cast<int>(start)) {
                panel_.println(text.substring(start, space));
                start = space + 1;
            } else {
                panel_.println(text.substring(start, end));
                start = end;
            }

            line++;
        }
    }

    Adafruit_SSD1306 panel_;
    bool present_ = false;
};

// ===========================================================================
// 5. NetworkManager — the Wi-Fi link
//
// A gate is not a desk: the access point may be at the far end of a car park,
// the weather box heats up, and the radio drops. The station has to survive
// that without a person walking out to it, so association is handled as a
// state machine with backoff rather than as a blocking call in setup().
//
// Nothing here blocks for longer than a few milliseconds. The readers keep
// polling while the radio is reconnecting, and a scan taken during an outage
// goes to the queue.
// ===========================================================================

static const char* TAG_WIFI = "wifi";

/** Backoff schedule in seconds, then held at the last value. */
static const unsigned long BACKOFF_SECONDS[] = {2, 5, 10, 20, 30, 60};
static const uint8_t BACKOFF_STEPS = sizeof(BACKOFF_SECONDS) / sizeof(BACKOFF_SECONDS[0]);

class NetworkManager {
public:
    void begin(const String& ssid, const String& password, const String& hostname)
    {
        ssid_ = ssid;
        password_ = password;
        hostname_ = hostname;

        WiFi.persistent(false);
        WiFi.mode(WIFI_STA);
        WiFi.setHostname(hostname_.c_str());

        // Modem sleep saves power but adds latency to every request and, on
        // some access points, drops the association outright. A mains-powered
        // gate station gains nothing from it.
        WiFi.setSleep(false);

        // The reconnection is driven from update() so the backoff is
        // honoured; leaving the SDK to retry on its own would race with it.
        WiFi.setAutoReconnect(false);

        startAttempt();
    }

    /** Drive the connection state machine. Call every loop. */
    void update()
    {
        const bool connected = WiFi.status() == WL_CONNECTED;

        if (connected) {
            if (!wasConnected_) {
                wasConnected_ = true;
                attempting_ = false;
                failures_ = 0;
                connectedSince_ = millis();

                Logger::info(TAG_WIFI, "Connected as " + ipAddress()
                                  + " (" + String(signalStrength()) + " dBm)");
            }

            return;
        }

        if (wasConnected_) {
            wasConnected_ = false;
            connectedSince_ = 0;
            Logger::warning(TAG_WIFI, "Link lost");
        }

        if (attempting_) {
            if (millis() - attemptStartedAt_ < WIFI_CONNECT_TIMEOUT_MS) {
                return;
            }

            attempting_ = false;
            lastFailureAt_ = millis();

            if (failures_ < 255) {
                failures_++;
            }

            Logger::warning(TAG_WIFI, "Association timed out (attempt " + String(failures_)
                                 + "), retrying in " + String(backoffMs() / 1000) + "s");

            // The radio is cycled rather than merely retried. An ESP32 that
            // has failed to associate several times in a row is usually in a
            // state the next WiFi.begin() alone will not clear.
            WiFi.disconnect(true, false);
            WiFi.mode(WIFI_OFF);
            WiFi.mode(WIFI_STA);
            WiFi.setHostname(hostname_.c_str());

            return;
        }

        if (millis() - lastFailureAt_ >= backoffMs()) {
            startAttempt();
        }
    }

    bool isConnected() const { return WiFi.status() == WL_CONNECTED; }

    /** Dotted-quad address, or "0.0.0.0" when the link is down. */
    String ipAddress() const
    {
        return isConnected() ? WiFi.localIP().toString() : String("0.0.0.0");
    }

    /** Received signal strength in dBm, or 0 when the link is down. */
    int signalStrength() const { return isConnected() ? WiFi.RSSI() : 0; }

    /** Consecutive failed association attempts since the last success. */
    uint8_t failureCount() const { return failures_; }

    /** Seconds the link has been continuously up, or 0 when it is down. */
    unsigned long uptimeSeconds() const
    {
        return connectedSince_ == 0 ? 0UL : (millis() - connectedSince_) / 1000UL;
    }

    const String& ssid() const { return ssid_; }

private:
    void startAttempt()
    {
        Logger::info(TAG_WIFI, "Associating with " + ssid_);

        WiFi.disconnect(false, false);
        WiFi.begin(ssid_.c_str(), password_.c_str());

        attempting_ = true;
        attemptStartedAt_ = millis();
    }

    /**
     * How long to wait before the next attempt after a failure.
     *
     * Backoff matters here for a reason beyond politeness: retrying a dead
     * access point every second keeps the radio transmitting continuously,
     * which is the largest current draw on the board and the fastest way to
     * cook a sealed enclosure.
     */
    unsigned long backoffMs() const
    {
        const uint8_t index = failures_ == 0 ? 0
                            : (failures_ - 1 < BACKOFF_STEPS ? failures_ - 1 : BACKOFF_STEPS - 1);

        return BACKOFF_SECONDS[index] * 1000UL;
    }

    String ssid_;
    String password_;
    String hostname_;

    bool attempting_ = false;
    unsigned long attemptStartedAt_ = 0;
    unsigned long lastFailureAt_ = 0;
    unsigned long connectedSince_ = 0;
    uint8_t failures_ = 0;
    bool wasConnected_ = false;
};

// ===========================================================================
// 6. ApiClient — the signed HTTP client
//
// Every call carries five headers the server checks before it will look at
// the body: the device code, the API key, a timestamp, a nonce, and an
// HMAC-SHA256 signature over a canonical form of the request. The signature
// is what makes a captured request useless to replay, and the nonce is what
// makes it useless to send twice.
//
// The canonical string, which must match DeviceAuthenticationService exactly:
//
//     METHOD \n /path \n timestamp \n nonce \n sha256(body)
// ===========================================================================

static const char* TAG_API = "api";

/** What came back from a call. */
struct ApiResponse {
    bool ok = false;               ///< transport succeeded and the envelope said success
    int status = 0;                ///< HTTP status, or a negative client error
    String errorCode;              ///< the server's error_code, when it sent one
    String message;                ///< human-readable, safe to show on the display
    StaticJsonDocument<1536> data; ///< the envelope's data member
};

class ApiClient {
public:
    void begin(const String& baseUrl,
               const String& deviceCode,
               const String& apiKey,
               const String& rootCertificate)
    {
        baseUrl_ = baseUrl;

        while (baseUrl_.endsWith("/")) {
            baseUrl_.remove(baseUrl_.length() - 1);
        }

        deviceCode_ = deviceCode;
        apiKey_ = apiKey;

        // The server derives the signing secret from the API key the same way.
        signingSecret_ = apiKey + "-signing";

        rootCertificate_ = rootCertificate;
        useTls_ = baseUrl_.startsWith("https://");

        if (!useTls_) {
            Logger::warning(TAG_API, "the server URL is plain http — the API key travels in clear text");
        } else if (rootCertificate_.length() == 0) {
            Logger::warning(TAG_API, "no certificate pinned — any server presenting https will be trusted");
        }
    }

    /**
     * Adopt the server's clock.
     *
     * The station has no battery-backed clock, and its timestamps have to
     * land inside the server's tolerance window or every request is refused
     * as stale. Rather than depending on an NTP server the guardhouse LAN may
     * not have, the offset is taken from the server_time every response
     * carries.
     */
    void syncClock(const String& serverTimeIso)
    {
        if (serverTimeIso.length() < 19) {
            return;
        }

        // "2026-08-23T17:45:21+08:00" — parse the civil part and the offset
        // separately so the result is a true epoch rather than a local
        // reading.
        struct tm parts = {};

        parts.tm_year = serverTimeIso.substring(0, 4).toInt() - 1900;
        parts.tm_mon  = serverTimeIso.substring(5, 7).toInt() - 1;
        parts.tm_mday = serverTimeIso.substring(8, 10).toInt();
        parts.tm_hour = serverTimeIso.substring(11, 13).toInt();
        parts.tm_min  = serverTimeIso.substring(14, 16).toInt();
        parts.tm_sec  = serverTimeIso.substring(17, 19).toInt();

        const time_t civil = mktime(&parts);

        if (civil == static_cast<time_t>(-1)) {
            Logger::warning(TAG_API, "could not read the server clock from '" + serverTimeIso + "'");

            return;
        }

        // mktime read the civil time as local. The station's TZ is UTC, so
        // the only correction needed is the offset the server declared.
        long offsetSeconds = 0;

        int signPosition = -1;
        for (unsigned int i = 19; i < serverTimeIso.length(); i++) {
            const char c = serverTimeIso.charAt(i);

            if (c == '+' || c == '-') {
                signPosition = static_cast<int>(i);
                break;
            }

            if (c == 'Z' || c == 'z') {
                signPosition = -2;   // explicit UTC
                break;
            }
        }

        if (signPosition >= 0) {
            const int hours   = serverTimeIso.substring(signPosition + 1, signPosition + 3).toInt();
            const int minutes = serverTimeIso.substring(signPosition + 4, signPosition + 6).toInt();

            offsetSeconds = (hours * 3600L) + (minutes * 60L);

            if (serverTimeIso.charAt(signPosition) == '+') {
                offsetSeconds = -offsetSeconds;   // subtract to reach UTC
            }
        }

        const time_t serverEpoch = civil + offsetSeconds;

        clockOffset_ = static_cast<long>(serverEpoch - time(nullptr));
        clockSynced_ = true;

        Logger::info(TAG_API, "clock synced to the server, offset " + String(clockOffset_) + "s");
    }

    /** Whether the clock has been set from the server at least once. */
    bool clockIsSynced() const { return clockSynced_; }

    /** ISO-8601 timestamp in the server's frame of reference. */
    String timestamp() const
    {
        const time_t now = time(nullptr) + clockOffset_;

        struct tm utc;
        gmtime_r(&now, &utc);

        char buffer[32];
        strftime(buffer, sizeof(buffer), "%Y-%m-%dT%H:%M:%SZ", &utc);

        return String(buffer);
    }

    /** Seconds since the last call that reached the server. */
    unsigned long secondsSinceContact() const
    {
        return lastContactAt_ == 0 ? ULONG_MAX : (millis() - lastContactAt_) / 1000UL;
    }

    ApiResponse post(const String& path, const JsonDocument& body)
    {
        String encoded;
        serializeJson(body, encoded);

        return send("POST", path, encoded);
    }

    ApiResponse get(const String& path)
    {
        // The signature covers the hash of an empty body, which is what the
        // server computes for a GET.
        return send("GET", path, "");
    }

private:
    ApiResponse send(const String& method, const String& path, const String& body)
    {
        ApiResponse response;

        if (WiFi.status() != WL_CONNECTED) {
            response.status = -1;
            response.errorCode = "NO_NETWORK";
            response.message = "No network";

            return response;
        }

        const String stamp = timestamp();
        const String nonce = randomNonce();
        const String sign  = signature(method, path, stamp, nonce, body);

        HTTPClient http;
        WiFiClientSecure secureClient;
        WiFiClient plainClient;

        bool opened = false;

        if (useTls_) {
            if (rootCertificate_.length() > 0) {
                secureClient.setCACert(rootCertificate_.c_str());
            } else {
                // Documented in secrets.h.example: acceptable on a bench, not
                // in an installation.
                secureClient.setInsecure();
            }

            opened = http.begin(secureClient, baseUrl_ + path);
        } else {
            opened = http.begin(plainClient, baseUrl_ + path);
        }

        if (!opened) {
            response.status = -2;
            response.errorCode = "BAD_URL";
            response.message = "Bad server URL";

            return response;
        }

        http.setTimeout(HTTP_TIMEOUT_MS);
        http.setConnectTimeout(HTTP_TIMEOUT_MS);

        http.addHeader("Content-Type", "application/json");
        http.addHeader("Accept", "application/json");
        http.addHeader("X-Device-Id", deviceCode_);
        http.addHeader("X-Api-Key", apiKey_);
        http.addHeader("X-Timestamp", stamp);
        http.addHeader("X-Nonce", nonce);
        http.addHeader("X-Signature", sign);
        http.addHeader("X-Firmware-Version", VAMS_FIRMWARE_VERSION);

        const int status = (method == "GET")
            ? http.GET()
            : http.POST(reinterpret_cast<uint8_t*>(const_cast<char*>(body.c_str())), body.length());

        response.status = status;

        if (status <= 0) {
            response.errorCode = "TRANSPORT";
            response.message = HTTPClient::errorToString(status);

            Logger::warning(TAG_API, method + " " + path + " failed: " + response.message);

            http.end();

            return response;
        }

        const String payload = http.getString();
        http.end();

        lastContactAt_ = millis();

        StaticJsonDocument<2048> envelope;
        const DeserializationError parseError = deserializeJson(envelope, payload);

        if (parseError) {
            response.errorCode = "BAD_JSON";
            response.message = "Unreadable reply";

            Logger::warning(TAG_API, method + " " + path + " returned unparseable JSON");

            return response;
        }

        response.ok = envelope["success"].as<bool>() && status >= 200 && status < 300;
        response.message = envelope["message"].as<String>();

        if (!response.ok) {
            response.errorCode = envelope["error_code"].as<String>();

            Logger::warning(TAG_API, method + " " + path + " -> " + String(status)
                + " " + response.errorCode + ": " + response.message);
        }

        if (!envelope["data"].isNull()) {
            response.data.set(envelope["data"]);
        }

        // Every response carries the server clock; taking it here means the
        // station corrects its drift continuously rather than only at
        // startup.
        if (!envelope["data"]["server_time"].isNull()) {
            syncClock(envelope["data"]["server_time"].as<String>());
        }

        return response;
    }

    String signature(const String& method,
                     const String& path,
                     const String& stamp,
                     const String& nonce,
                     const String& body) const
    {
        String canonical = method;
        canonical.toUpperCase();

        // The server normalises the path to a single leading slash and no
        // trailing one before signing; the same normalisation has to happen
        // here or every signature is rejected.
        String normalisedPath = path;
        while (normalisedPath.startsWith("/")) {
            normalisedPath.remove(0, 1);
        }
        while (normalisedPath.endsWith("/")) {
            normalisedPath.remove(normalisedPath.length() - 1);
        }

        canonical += "\n/";
        canonical += normalisedPath;
        canonical += "\n";
        canonical += stamp;
        canonical += "\n";
        canonical += nonce;
        canonical += "\n";
        canonical += sha256Hex(body);

        return hmacSha256Hex(signingSecret_, canonical);
    }

    static String toHex(const uint8_t* digest, size_t length)
    {
        String out;
        out.reserve(length * 2);

        for (size_t i = 0; i < length; i++) {
            char byteText[3];
            snprintf(byteText, sizeof(byteText), "%02x", digest[i]);
            out += byteText;
        }

        return out;
    }

    static String sha256Hex(const String& input)
    {
        uint8_t digest[32];

        const mbedtls_md_info_t* info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);

        mbedtls_md_context_t context;
        mbedtls_md_init(&context);
        mbedtls_md_setup(&context, info, 0);
        mbedtls_md_starts(&context);
        mbedtls_md_update(&context, reinterpret_cast<const unsigned char*>(input.c_str()), input.length());
        mbedtls_md_finish(&context, digest);
        mbedtls_md_free(&context);

        return toHex(digest, sizeof(digest));
    }

    static String hmacSha256Hex(const String& key, const String& message)
    {
        uint8_t digest[32];

        const mbedtls_md_info_t* info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);

        mbedtls_md_context_t context;
        mbedtls_md_init(&context);
        mbedtls_md_setup(&context, info, 1);   // 1: HMAC
        mbedtls_md_hmac_starts(&context, reinterpret_cast<const unsigned char*>(key.c_str()), key.length());
        mbedtls_md_hmac_update(&context, reinterpret_cast<const unsigned char*>(message.c_str()), message.length());
        mbedtls_md_hmac_finish(&context, digest);
        mbedtls_md_free(&context);

        return toHex(digest, sizeof(digest));
    }

    static String randomNonce()
    {
        // esp_random() draws on the hardware RNG once the radio is up, which
        // is the case by the time anything is sent. A predictable nonce would
        // let a captured request be replayed.
        String out;
        out.reserve(32);

        for (int i = 0; i < 4; i++) {
            char part[9];
            snprintf(part, sizeof(part), "%08x", static_cast<unsigned int>(esp_random()));
            out += part;
        }

        return out;
    }

    String baseUrl_;
    String deviceCode_;
    String apiKey_;
    String signingSecret_;
    String rootCertificate_;
    bool useTls_ = false;

    // Seconds to add to the local clock to land on the server's.
    long clockOffset_ = 0;
    bool clockSynced_ = false;

    unsigned long lastContactAt_ = 0;
};

// ===========================================================================
// 7. ScanQueue — scans held while the network is down
//
// A gate does not stop working because the Wi-Fi did. A scan the station
// cannot transmit is stored with the moment it actually happened and sent
// when the link returns; the server accepts a device-supplied timestamp
// precisely so a replayed queue lands in the record at the right time rather
// than bunched at the moment of reconnection.
//
// The queue survives a power cut: it is held in flash through Preferences,
// not only in RAM. A station that loses power mid-shift comes back with its
// unsent movements intact.
// ===========================================================================

static const char* TAG_QUEUE = "queue";
static const char* NVS_NAMESPACE = "vams";
static const char* QUEUE_BLOB_KEY = "scanq";

// A version byte in front of the blob, so firmware that changes the entry
// layout discards an old queue rather than reading it as garbage.
static const uint8_t QUEUE_BLOB_VERSION = 1;

struct QueuedScan {
    char uid[33];          ///< upper-case hex, 32 characters at most
    char occurredAt[25];   ///< ISO-8601, the moment the tag was read
    char accessType[8];    ///< "entry", "exit" or "" to let the server decide
    uint8_t attempts;      ///< transmissions tried so far
};

struct QueueBlob {
    uint8_t version;
    uint16_t count;
    QueuedScan entries[QUEUE_CAPACITY];
};

class ScanQueue {
public:
    /** Load anything left over from a previous run. */
    void begin()
    {
        restore();

        if (count_ > 0) {
            Logger::info(TAG_QUEUE, String(count_) + " scan(s) recovered from the previous run");
        }
    }

    /**
     * Add a scan.
     *
     * @return false when the queue is full. The caller must tell the
     *         operator: a silently dropped movement is a hole in the record.
     */
    bool enqueue(const String& uid, const String& occurredAt, const String& accessType)
    {
        if (isFull()) {
            Logger::error(TAG_QUEUE, "queue is full; the scan of " + uid + " could not be held");

            return false;
        }

        const size_t slot = (head_ + count_) % QUEUE_CAPACITY;

        QueuedScan& entry = entries_[slot];

        strncpy(entry.uid, uid.c_str(), sizeof(entry.uid) - 1);
        entry.uid[sizeof(entry.uid) - 1] = '\0';

        strncpy(entry.occurredAt, occurredAt.c_str(), sizeof(entry.occurredAt) - 1);
        entry.occurredAt[sizeof(entry.occurredAt) - 1] = '\0';

        strncpy(entry.accessType, accessType.c_str(), sizeof(entry.accessType) - 1);
        entry.accessType[sizeof(entry.accessType) - 1] = '\0';

        entry.attempts = 0;

        count_++;
        dirty_ = true;
        persist();

        Logger::info(TAG_QUEUE, "held " + uid + " for later; " + String(count_) + " waiting");

        return true;
    }

    /** Look at the oldest entry without removing it. */
    bool peek(QueuedScan& scan) const
    {
        if (count_ == 0) {
            return false;
        }

        scan = entries_[head_];

        return true;
    }

    /** Remove the oldest entry, after it has been accepted by the server. */
    void pop()
    {
        if (count_ == 0) {
            return;
        }

        head_ = (head_ + 1) % QUEUE_CAPACITY;
        count_--;
        dirty_ = true;
        persist();
    }

    /** Note that the oldest entry failed to send. */
    void recordAttempt()
    {
        if (count_ == 0) {
            return;
        }

        // Saturating: the counter is only there to inform the operator, and
        // wrapping it would make a stuck entry look fresh.
        if (entries_[head_].attempts < 255) {
            entries_[head_].attempts++;
            dirty_ = true;
        }
    }

    size_t size() const { return count_; }
    bool isEmpty() const { return count_ == 0; }
    bool isFull() const { return count_ >= QUEUE_CAPACITY; }

    /** Discard everything. Used only by the held-button reset. */
    void clear()
    {
        head_ = 0;
        count_ = 0;
        dirty_ = true;
        persist();

        Logger::warning(TAG_QUEUE, "queue cleared");
    }

private:
    /**
     * Flash is only written when the queue actually changed, and the whole
     * queue is written as one blob rather than entry by entry: NVS wears with
     * the number of writes, and a gate can generate a lot of them.
     */
    void persist()
    {
        if (!dirty_) {
            return;
        }

        Preferences preferences;

        if (!preferences.begin(NVS_NAMESPACE, false)) {
            Logger::error(TAG_QUEUE, "could not open storage; the queue is held in RAM only");

            return;
        }

        QueueBlob blob;
        blob.version = QUEUE_BLOB_VERSION;
        blob.count = static_cast<uint16_t>(count_);

        // Written from the head so the blob is always in order and the reader
        // does not need to know where the ring wrapped.
        for (size_t i = 0; i < count_; i++) {
            blob.entries[i] = entries_[(head_ + i) % QUEUE_CAPACITY];
        }

        const size_t used = sizeof(uint8_t) + sizeof(uint16_t) + (count_ * sizeof(QueuedScan));

        preferences.putBytes(QUEUE_BLOB_KEY, &blob, used);
        preferences.end();

        dirty_ = false;
    }

    void restore()
    {
        Preferences preferences;

        if (!preferences.begin(NVS_NAMESPACE, true)) {
            return;   // nothing stored yet, which is the normal first boot
        }

        const size_t stored = preferences.getBytesLength(QUEUE_BLOB_KEY);

        if (stored < sizeof(uint8_t) + sizeof(uint16_t) || stored > sizeof(QueueBlob)) {
            preferences.end();

            return;
        }

        QueueBlob blob;
        preferences.getBytes(QUEUE_BLOB_KEY, &blob, stored);
        preferences.end();

        if (blob.version != QUEUE_BLOB_VERSION) {
            Logger::warning(TAG_QUEUE, "stored queue is from an older firmware; discarded");

            return;
        }

        size_t recovered = blob.count;

        if (recovered > QUEUE_CAPACITY) {
            recovered = QUEUE_CAPACITY;
        }

        for (size_t i = 0; i < recovered; i++) {
            entries_[i] = blob.entries[i];
        }

        head_ = 0;
        count_ = recovered;
    }

    QueuedScan entries_[QUEUE_CAPACITY];
    size_t head_ = 0;
    size_t count_ = 0;
    bool dirty_ = false;
};

// ===========================================================================
// 8. RfidReader — the MFRC522: 13.56 MHz, a few centimetres of range
//
// This is the guardhouse-window reader. A visitor card or a credential handed
// over by hand is presented to it deliberately; it cannot read a windshield
// tag on a moving vehicle, which is what the UHF reader is for.
// ===========================================================================

static const char* TAG_RC522 = "rc522";

class RfidReader {
public:
    RfidReader(uint8_t ssPin, uint8_t rstPin) : reader_(ssPin, rstPin) {}

    /** Bring up SPI and the reader. Returns false if the module is silent. */
    bool begin()
    {
        SPI.begin();
        reader_.PCD_Init();

        // The antenna gain is not raised: this reader is meant to require a
        // deliberate presentation. Turning it up encourages stray reads from
        // a card in a pocket near the window, which would record movements
        // nobody made.
        delay(50);

        present_ = selfTest();

        if (present_) {
            Logger::info(TAG_RC522, "reader online");
        } else {
            Logger::error(TAG_RC522, "no response on the SPI bus — check wiring and that VCC is 3.3 V, not 5 V");
        }

        return present_;
    }

    /**
     * Poll for a card.
     *
     * @param uid Receives the UID as upper-case hex with no separators, which
     *            is the form the server stores and compares.
     * @return true when a new card was read this call.
     */
    bool poll(String& uid)
    {
        if (!present_) {
            return false;
        }

        if (!reader_.PICC_IsNewCardPresent()) {
            return false;
        }

        if (!reader_.PICC_ReadCardSerial()) {
            return false;
        }

        uid = "";
        uid.reserve(reader_.uid.size * 2);

        for (byte i = 0; i < reader_.uid.size; i++) {
            char byteText[3];
            snprintf(byteText, sizeof(byteText), "%02X", reader_.uid.uidByte[i]);
            uid += byteText;
        }

        // Stop talking to this card so the next presentation is seen as new.
        reader_.PICC_HaltA();
        reader_.PCD_StopCrypto1();

        Logger::debug(TAG_RC522, "read " + uid);

        return uid.length() >= 8;
    }

    /** Whether the module answered on the bus at startup. */
    bool isPresent() const { return present_; }

    /**
     * Re-run the self test and reinitialise if the module has stopped
     * answering. An RC522 on a long or noisy cable does occasionally wedge,
     * and recovering beats waiting for someone to power-cycle the box.
     */
    bool recover()
    {
        reader_.PCD_Reset();
        delay(50);
        reader_.PCD_Init();
        delay(50);

        present_ = selfTest();

        if (present_) {
            Logger::warning(TAG_RC522, "reader recovered after a reset");
        }

        return present_;
    }

private:
    bool selfTest()
    {
        const byte version = reader_.PCD_ReadRegister(MFRC522::VersionReg);

        // 0x00 and 0xFF both mean "nothing is answering": a floating bus
        // reads as one or the other depending on the pull.
        return version != 0x00 && version != 0xFF;
    }

    MFRC522 reader_;
    bool present_ = false;
};

// ===========================================================================
// 9. UhfReader — the long-range reader: 860-960 MHz, metres of range
//
// This is the lane reader — it picks up a windshield tag as a vehicle
// approaches, which is what lets a gate work without the driver winding a
// window down.
//
// There is no single standard for these modules. Nearly all of them speak
// UART, but the framing differs by manufacturer, so the parsing is pluggable:
// pick the protocol that matches the reader, or add one. Two cover most of
// the market:
//
//   AsciiLine  The reader emits the EPC as hex text ending in CR/LF. Common
//              on inexpensive modules and on almost any module switched into
//              a "notify" or "auto-read" mode.
//
//   M100Frame  The length-prefixed binary frame used by the JRD-100, YRM100
//              and the several modules built on the same Magicrf chipset —
//              the ones usually paired with an ESP32.
//
// If neither fits, send "uhf diag" on the serial console (or set
// UHF_DIAGNOSTIC_MODE above), hold a tag to the antenna, and read the raw
// bytes. The README section "Bringing up an unknown UHF reader" walks
// through it.
// ===========================================================================

static const char* TAG_UHF = "uhf";

// --- M100 / JRD-100 framing ------------------------------------------------
//
// A notification frame is:
//
//   BB  header
//   02  type: notification
//   22  command: tag read
//   LL LL  payload length, big endian
//   RSSI(1) PC(2) EPC(n) CRC(2)   payload
//   CS  checksum: sum of every byte from type to the last payload byte, & 0xFF
//   7E  trailer
//
// The EPC is the payload minus the leading RSSI and PC and the trailing CRC.

static const uint8_t M100_HEADER  = 0xBB;
static const uint8_t M100_TRAILER = 0x7E;
static const uint8_t M100_TYPE_NOTIFICATION = 0x02;
static const uint8_t M100_CMD_TAG_READ = 0x22;

enum class UhfProtocol : uint8_t {
    /** Hex text, one tag per line, terminated by CR and/or LF. */
    AsciiLine,

    /** Magicrf M100 / JRD-100 / YRM100 binary notification frame. */
    M100Frame,

    /**
     * Print raw bytes to the console and never report a tag.
     *
     * For commissioning a reader whose framing is unknown.
     */
    Diagnostic
};

class UhfReader {
public:
    /**
     * @param serial The UART to use. UART1 on this board; UART2 carries the
     *               fingerprint sensor.
     */
    explicit UhfReader(HardwareSerial& serial) : serial_(serial) {}

    void begin(uint8_t rxPin, uint8_t txPin, unsigned long baud, UhfProtocol protocol)
    {
        protocol_ = protocol;

        serial_.begin(baud, SERIAL_8N1, rxPin, txPin);
        lineBuffer_.reserve(96);

        Logger::info(TAG_UHF, String("listening at ") + baud + " baud on RX=" + rxPin + " TX=" + txPin);

        if (protocol_ == UhfProtocol::Diagnostic) {
            Logger::warning(TAG_UHF, "diagnostic mode: frames will be printed, no tags reported");
        }
    }

    /**
     * Poll for a tag.
     *
     * @param epc Receives the EPC as upper-case hex with no separators.
     * @return true when a tag was decoded this call.
     */
    bool poll(String& epc)
    {
        if (serial_.available() > 0) {
            everResponded_ = true;
        }

        switch (protocol_) {
            case UhfProtocol::AsciiLine:
                return pollAsciiLine(epc);

            case UhfProtocol::M100Frame:
                return pollM100Frame(epc);

            case UhfProtocol::Diagnostic:
                pollDiagnostic();
                return false;
        }

        return false;
    }

    /**
     * Ask the reader to start reporting.
     *
     * Modules differ: some free-run from power-up, some need a command. This
     * sends the M100 "start multiple polling" frame when that protocol is
     * selected, and does nothing otherwise, which is correct for a
     * free-running reader.
     */
    void requestContinuousRead()
    {
        if (protocol_ != UhfProtocol::M100Frame) {
            // A free-running reader needs no prompting, and sending an
            // unknown command to one that does could put it into a state the
            // operator then has to power-cycle out of.
            return;
        }

        // Start multiple polling, count 0xFFFF (continuous).
        const uint8_t command[] = {
            0xBB, 0x00, 0x27, 0x00, 0x03, 0x22, 0xFF, 0xFF, 0x4A, 0x7E
        };

        serial_.write(command, sizeof(command));
        serial_.flush();

        Logger::info(TAG_UHF, "asked the reader to poll continuously");
    }

    /** Whether anything at all has arrived from the reader since boot. */
    bool hasEverResponded() const { return everResponded_; }

    void setProtocol(UhfProtocol protocol)
    {
        protocol_ = protocol;

        // Half-assembled state belongs to the protocol that was reading it.
        lineBuffer_ = "";
        frameLength_ = 0;
        inFrame_ = false;
    }

    UhfProtocol protocol() const { return protocol_; }

    /** The protocol's name, for the console. */
    static const char* protocolName(UhfProtocol protocol)
    {
        switch (protocol) {
            case UhfProtocol::AsciiLine:  return "ascii";
            case UhfProtocol::M100Frame:  return "m100";
            case UhfProtocol::Diagnostic: return "diag";
        }

        return "unknown";
    }

private:
    bool pollAsciiLine(String& epc)
    {
        while (serial_.available() > 0) {
            const char c = static_cast<char>(serial_.read());

            if (c == '\r' || c == '\n') {
                if (lineBuffer_.length() == 0) {
                    continue;
                }

                const String candidate = sanitiseHex(lineBuffer_);
                lineBuffer_ = "";

                // A UID shorter than four bytes is not an EPC; it is noise,
                // or a status line the reader emitted alongside the tag data.
                if (candidate.length() >= 8 && candidate.length() <= 32) {
                    epc = candidate;
                    Logger::debug(TAG_UHF, "read " + epc);

                    return true;
                }

                continue;
            }

            // A runaway line means the reader is not speaking this protocol.
            // Dropping it keeps the buffer bounded.
            if (lineBuffer_.length() >= 90) {
                lineBuffer_ = "";
                continue;
            }

            lineBuffer_ += c;
        }

        return false;
    }

    bool pollM100Frame(String& epc)
    {
        while (serial_.available() > 0) {
            const uint8_t byteRead = static_cast<uint8_t>(serial_.read());

            if (!inFrame_) {
                if (byteRead == M100_HEADER) {
                    inFrame_ = true;
                    frameLength_ = 0;
                    frame_[frameLength_++] = byteRead;
                }

                continue;
            }

            if (frameLength_ >= FRAME_MAX) {
                // Longer than any valid frame: resynchronise rather than
                // overrunning the buffer.
                inFrame_ = false;
                frameLength_ = 0;
                continue;
            }

            frame_[frameLength_++] = byteRead;

            if (byteRead != M100_TRAILER) {
                continue;
            }

            inFrame_ = false;

            // Shortest useful frame: header, type, command, two length bytes,
            // checksum, trailer.
            if (frameLength_ < 7) {
                continue;
            }

            if (frame_[1] != M100_TYPE_NOTIFICATION || frame_[2] != M100_CMD_TAG_READ) {
                continue;   // a reply to something else, not a tag report
            }

            const size_t payloadLength = (static_cast<size_t>(frame_[3]) << 8) | frame_[4];

            // header + type + command + length(2) + payload + checksum + trailer
            if (payloadLength + 7 != frameLength_) {
                Logger::debug(TAG_UHF, "frame length disagrees with its header; discarded");
                continue;
            }

            uint8_t checksum = 0;
            for (size_t i = 1; i < frameLength_ - 2; i++) {
                checksum = static_cast<uint8_t>(checksum + frame_[i]);
            }

            if (checksum != frame_[frameLength_ - 2]) {
                Logger::debug(TAG_UHF, "frame checksum failed; discarded");
                continue;
            }

            // Payload: RSSI(1) PC(2) EPC(n) CRC(2)
            if (payloadLength < 6) {
                continue;
            }

            const size_t epcStart = 5 + 3;                   // past header, length, RSSI and PC
            const size_t epcLength = payloadLength - 3 - 2;  // minus RSSI+PC, minus CRC

            if (epcLength == 0 || epcStart + epcLength > frameLength_) {
                continue;
            }

            epc = "";
            epc.reserve(epcLength * 2);

            for (size_t i = 0; i < epcLength; i++) {
                char byteText[3];
                snprintf(byteText, sizeof(byteText), "%02X", frame_[epcStart + i]);
                epc += byteText;
            }

            // The server accepts 8 to 32 hex characters. A 96-bit EPC is 24,
            // which fits; a longer one is truncated rather than refused,
            // because the leading bytes are the ones that identify the tag.
            if (epc.length() > 32) {
                epc = epc.substring(0, 32);
            }

            if (epc.length() >= 8) {
                Logger::debug(TAG_UHF, "read " + epc);

                return true;
            }
        }

        return false;
    }

    void pollDiagnostic()
    {
        while (serial_.available() > 0) {
            diagnosticBuffer_[diagnosticFilled_++] = static_cast<uint8_t>(serial_.read());
            diagnosticLastByteAt_ = millis();

            if (diagnosticFilled_ == sizeof(diagnosticBuffer_)) {
                Logger::hexDump(TAG_UHF, diagnosticBuffer_, diagnosticFilled_);
                diagnosticFilled_ = 0;
            }
        }

        // Flush a short burst once the line has gone quiet, so a frame
        // smaller than the buffer still gets printed.
        if (diagnosticFilled_ > 0 && millis() - diagnosticLastByteAt_ > 60) {
            Logger::hexDump(TAG_UHF, diagnosticBuffer_, diagnosticFilled_);
            diagnosticFilled_ = 0;
        }
    }

    /** Keep only hex digits, upper-cased. Returns "" if anything else is present. */
    static String sanitiseHex(const String& raw)
    {
        String out;
        out.reserve(raw.length());

        for (unsigned int i = 0; i < raw.length(); i++) {
            const char c = raw.charAt(i);

            if (c >= '0' && c <= '9') {
                out += c;
            } else if (c >= 'a' && c <= 'f') {
                out += static_cast<char>(c - 'a' + 'A');
            } else if (c >= 'A' && c <= 'F') {
                out += c;
            } else if (c == ' ' || c == '\t' || c == ':' || c == '-') {
                continue;   // separators some readers insert between bytes
            } else {
                // Any other character means this line was not a tag report.
                return "";
            }
        }

        return out;
    }

    HardwareSerial& serial_;
    UhfProtocol protocol_ = UhfProtocol::AsciiLine;
    bool everResponded_ = false;

    String lineBuffer_;

    // Frame assembly for the binary protocol.
    static const size_t FRAME_MAX = 64;
    uint8_t frame_[FRAME_MAX];
    size_t frameLength_ = 0;
    bool inFrame_ = false;

    // Byte collection for the diagnostic protocol.
    uint8_t diagnosticBuffer_[32];
    size_t diagnosticFilled_ = 0;
    unsigned long diagnosticLastByteAt_ = 0;
};

// ===========================================================================
// 10. FingerprintSensor — the AS608 optical sensor
//
// The sensor holds the templates. This firmware never sees a fingerprint
// image and never transmits one: a match produces a slot number and a
// confidence score, and that is all that leaves the station. There is no path
// by which biometric data reaches the network or the database.
// ===========================================================================

static const char* TAG_AS608 = "as608";

/** What a verification attempt produced. */
struct FingerprintMatch {
    bool attempted = false;   ///< a finger was present and was processed
    bool matched = false;     ///< it matched an enrolled template
    uint16_t slot = 0;        ///< the sensor storage position that matched
    uint16_t score = 0;       ///< the sensor's confidence, higher is better
};

class FingerprintSensor {
public:
    explicit FingerprintSensor(HardwareSerial& serial) : sensor_(&serial), serial_(serial) {}

    bool begin(uint8_t rxPin, uint8_t txPin, unsigned long baud)
    {
        serial_.begin(baud, SERIAL_8N1, rxPin, txPin);
        delay(100);

        present_ = sensor_.verifyPassword();

        if (present_) {
            sensor_.getParameters();
            Logger::info(TAG_AS608, "sensor online, capacity " + String(sensor_.capacity)
                + ", holding " + String(storedCount()));
        } else {
            Logger::error(TAG_AS608, "no response — check the TX/RX pair is crossed and the sensor has 3.3 V");
        }

        return present_;
    }

    /**
     * Look for a finger and try to match it.
     *
     * Returns immediately when no finger is present, so this can be called
     * every loop without stalling the readers.
     */
    FingerprintMatch poll()
    {
        FingerprintMatch result;

        if (!present_) {
            return result;
        }

        uint8_t status = sensor_.getImage();

        if (status == FINGERPRINT_NOFINGER) {
            return result;   // the common case: nobody is touching it
        }

        if (status != FINGERPRINT_OK) {
            // A read error is worth noticing but not worth reporting
            // upstream: a wet or badly placed finger produces these
            // constantly.
            Logger::debug(TAG_AS608, "image capture failed, code " + String(status));

            return result;
        }

        result.attempted = true;

        status = sensor_.image2Tz();

        if (status != FINGERPRINT_OK) {
            Logger::debug(TAG_AS608, "image could not be converted, code " + String(status));

            return result;
        }

        status = sensor_.fingerFastSearch();

        if (status == FINGERPRINT_OK) {
            result.matched = true;
            result.slot = sensor_.fingerID;
            result.score = sensor_.confidence;

            Logger::info(TAG_AS608, "matched slot " + String(result.slot)
                + " with confidence " + String(result.score));
        } else if (status == FINGERPRINT_NOTFOUND) {
            Logger::info(TAG_AS608, "no match for the presented finger");
        } else {
            Logger::debug(TAG_AS608, "search failed, code " + String(status));
        }

        return result;
    }

    /**
     * Enrol a finger into a slot.
     *
     * Two impressions are taken and combined, which is what the sensor needs
     * to build a usable template. This one does block: enrolment is a
     * deliberate, supervised action reached from the serial console at a
     * bench, not something that happens while a queue forms at the gate.
     *
     * @param onPrompt Called with text to show the person being enrolled.
     */
    bool enrol(uint16_t slot, void (*onPrompt)(const String&))
    {
        if (!present_) {
            return false;
        }

        auto prompt = [onPrompt](const String& text) {
            Logger::info(TAG_AS608, text);

            if (onPrompt != nullptr) {
                onPrompt(text);
            }
        };

        prompt("Place finger");

        // First impression.
        unsigned long startedAt = millis();
        uint8_t status;

        do {
            status = sensor_.getImage();

            if (millis() - startedAt > 20000) {
                prompt("Timed out");

                return false;
            }

            delay(50);
        } while (status != FINGERPRINT_OK);

        if (sensor_.image2Tz(1) != FINGERPRINT_OK) {
            prompt("Could not read that");

            return false;
        }

        prompt("Lift finger");

        while (sensor_.getImage() != FINGERPRINT_NOFINGER) {
            delay(50);
        }

        prompt("Place the same finger again");

        // Second impression. Two are needed: the template is built from the
        // agreement between them, which is what makes a later match reliable.
        startedAt = millis();

        do {
            status = sensor_.getImage();

            if (millis() - startedAt > 20000) {
                prompt("Timed out");

                return false;
            }

            delay(50);
        } while (status != FINGERPRINT_OK);

        if (sensor_.image2Tz(2) != FINGERPRINT_OK) {
            prompt("Could not read that");

            return false;
        }

        if (sensor_.createModel() != FINGERPRINT_OK) {
            prompt("The two impressions did not agree");

            return false;
        }

        if (sensor_.storeModel(slot) != FINGERPRINT_OK) {
            prompt("Could not store it");

            return false;
        }

        prompt("Enrolled in slot " + String(slot));
        Logger::info(TAG_AS608, "enrolled slot " + String(slot));

        return true;
    }

    /** Remove a template from the sensor. */
    bool remove(uint16_t slot)
    {
        if (!present_) {
            return false;
        }

        const bool removed = sensor_.deleteModel(slot) == FINGERPRINT_OK;

        Logger::info(TAG_AS608, removed
            ? "removed slot " + String(slot)
            : "could not remove slot " + String(slot));

        return removed;
    }

    /** How many templates the sensor currently holds. */
    uint16_t storedCount()
    {
        if (!present_) {
            return 0;
        }

        if (sensor_.getTemplateCount() != FINGERPRINT_OK) {
            return 0;
        }

        return sensor_.templateCount;
    }

    /** How many templates the sensor can hold. */
    uint16_t capacity() const { return present_ ? sensor_.capacity : 0; }

    bool isPresent() const { return present_; }

private:
    Adafruit_Fingerprint sensor_;
    HardwareSerial& serial_;
    bool present_ = false;
};

// ===========================================================================
// 11. The station
// ===========================================================================

// --- Endpoints -------------------------------------------------------------

static const char* PATH_AUTHENTICATE  = "/api/v1/device/authenticate";
static const char* PATH_CONFIGURATION = "/api/v1/device/configuration";
static const char* PATH_HEARTBEAT     = "/api/v1/device/heartbeat";
static const char* PATH_ERROR         = "/api/v1/device/error";
static const char* PATH_SCAN          = "/api/v1/device/access/scan";
static const char* PATH_FP_VERIFY     = "/api/v1/device/fingerprint/verify";
static const char* PATH_FP_SIGNOUT    = "/api/v1/device/fingerprint/sign-out";

static const char* TAG_STATION = "station";

/**
 * How often the served configuration is re-read.
 *
 * The authentication reply carries it, so this only matters for a station
 * that has been running for a while when an administrator changes its gate
 * role or its debounce window. Fifteen minutes is soon enough for a setting
 * change and rare enough to be invisible in the request log.
 */
static const unsigned long CONFIG_REFRESH_MS = 900000UL;

// --- Runtime configuration -------------------------------------------------
//
// These come from the server, so an administrator can retune a station
// without anyone driving out to it with a laptop. The values here are only
// the defaults that apply before the first successful authentication.

struct RuntimeConfig {
    unsigned long heartbeatIntervalMs = 60000;
    unsigned long scanDebounceMs      = 5000;
    String gateType                   = "both";
    bool requireOperator              = true;
    unsigned long operatorSessionMs   = 3600000;
    size_t maxQueueSize               = QUEUE_CAPACITY;
};

// --- State -----------------------------------------------------------------

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

static unsigned long lastHeartbeatAt    = 0;
static unsigned long lastAuthAttemptAt  = 0;
static uint8_t       authFailures       = 0;
static unsigned long lastQueueAttemptAt = 0;
static unsigned long lastConfigFetchAt  = 0;
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

/** The line being typed on the serial console. */
static String consoleLine;

// --- Forward declarations --------------------------------------------------

static void bootPeripherals();
static void attemptAuthentication();
static void sendHeartbeat();
static void refreshConfiguration(bool force);
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
static void pollConsole();

// ---------------------------------------------------------------------------
// setup
// ---------------------------------------------------------------------------

void setup()
{
    Logger::begin(SERIAL_BAUD, LogLevel::Info);
    Logger::info(TAG_STATION, "Forest Lawn access station " VAMS_FIRMWARE_VERSION);
    Logger::info(TAG_STATION, "Type \"help\" on this console for the commands available.");

    pinMode(PIN_BUTTON, INPUT_PULLUP);

    indicators.begin(PIN_LED_GREEN, PIN_LED_RED, PIN_BUZZER);
    indicators.set(Indication::Working);

    display.begin(PIN_OLED_SDA, PIN_OLED_SCL, OLED_ADDRESS);
    display.showBoot("Starting");

    // Held at boot: clear the queue and any stored state. The check happens
    // before anything else is brought up so a station wedged by bad stored
    // data can still be recovered.
    if (digitalRead(PIN_BUTTON) == LOW) {
        const unsigned long heldSince = millis();

        display.showBoot("Hold to reset");

        while (digitalRead(PIN_BUTTON) == LOW && millis() - heldSince < 3000) {
            indicators.update();
        }

        if (digitalRead(PIN_BUTTON) == LOW) {
            factoryReset();
        }
    }

    storage.begin(NVS_NAMESPACE, false);
    stationName = storage.getString("name", DEVICE_CODE);
    storage.end();

    queue.begin();

    if (!queue.isEmpty()) {
        Logger::info(TAG_STATION, String(queue.size()) + " scan(s) carried over from the last run");
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
        Logger::info(TAG_STATION, "RC522 ready");
    } else {
        Logger::error(TAG_STATION, "RC522 did not answer — check the SPI wiring and 3.3 V supply");
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
    Logger::warning(TAG_STATION, "UHF diagnostic mode: raw bytes only, no tags will be reported");
#endif

    display.showBoot("Fingerprint");

    if (fingerprint.begin(PIN_FINGER_RX, PIN_FINGER_TX, FINGER_BAUD)) {
        Logger::info(TAG_STATION, "AS608 ready, " + String(fingerprint.storedCount()) + " template(s)");
    } else {
        Logger::error(TAG_STATION, "AS608 did not answer — check the crossover and the 3.3 V supply");
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
    pollConsole();

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
                announce("Connecting", network.ssid());
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
            refreshConfiguration(false);
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

    const ApiResponse response = api.post(PATH_AUTHENTICATE, body);

    if (response.ok) {
        authFailures = 0;

        if (!response.data["device"]["device_name"].isNull()) {
            stationName = response.data["device"]["device_name"].as<String>();

            storage.begin(NVS_NAMESPACE, false);
            storage.putString("name", stationName);
            storage.end();
        }

        applyConfiguration(response.data);

        // The reply carried the configuration, so the periodic refresh starts
        // its clock here rather than firing immediately.
        lastConfigFetchAt = millis();

        Logger::info(TAG_STATION, "Registered as " + stationName + " (" + config.gateType + " gate)");

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
        Logger::error(TAG_STATION, "The server refused these credentials: " + response.message);
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

/**
 * Re-read the served configuration.
 *
 * @param force Fetch now, ignoring the interval. Used by the console.
 */
static void refreshConfiguration(bool force)
{
    if (!network.isConnected()) {
        return;
    }

    if (!force && lastConfigFetchAt != 0 && millis() - lastConfigFetchAt < CONFIG_REFRESH_MS) {
        return;
    }

    lastConfigFetchAt = millis();

    const ApiResponse response = api.get(PATH_CONFIGURATION);

    if (!response.ok) {
        return;
    }

    const String previousGate = config.gateType;

    applyConfiguration(response.data);

    if (config.gateType != previousGate) {
        Logger::info(TAG_STATION, "Gate role changed to " + config.gateType);
        idleScreenDrawn = false;
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

    const ApiResponse response = api.post(PATH_HEARTBEAT, body);

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

    const ApiResponse response = api.post(PATH_SCAN, body);

    // A refusal is still an answer: the server has recorded the attempt, so
    // the entry has done its job and must not be sent again. Only a transport
    // failure or a server error leaves it queued.
    if (response.status >= 200 && response.status < 500) {
        queue.pop();

        Logger::info(TAG_STATION, String("Held scan sent, ") + String(queue.size()) + " remaining");

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
            Logger::info(TAG_STATION, "RC522 came back");
        }
    }

    // Two minutes is long enough that a working reader will have said
    // something, even at an empty gate: these modules chatter.
    if (!uhfSilenceReported && !uhf.hasEverResponded() && millis() > 120000) {
        uhfSilenceReported = true;

        Logger::warning(TAG_STATION, "Nothing has arrived from the UHF reader");
        reportFault("UHF_SILENT",
                    "No data received from the UHF reader since boot. Check the baud rate, "
                    "the TX/RX crossover and the reader's logic level.",
                    "warning");
    }
}

static void submitScan(const String& uid, const char* source)
{
    Logger::info(TAG_STATION, String("Read ") + uid + " on " + source);
    indicators.chirpRead();

    const String occurredAt = api.timestamp();

    // With operator authentication required, a station nobody has signed on
    // to is not in service. The read is still queued so the attempt appears
    // in the record — a gate that quietly discards scans is worse than one
    // that refuses them.
    if (config.requireOperator && !operatorActive) {
        queue.enqueue(uid, occurredAt, "");
        showResult(false, "Not on duty", "Sign in with your fingerprint");

        return;
    }

    if (!network.isConnected()) {
        if (queue.size() >= config.maxQueueSize || !queue.enqueue(uid, occurredAt, "")) {
            Logger::error(TAG_STATION, "The offline queue is full — this scan was not recorded");
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

    const ApiResponse response = api.post(PATH_SCAN, body);

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

        Logger::info(TAG_STATION, "The operator session expired");
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

    const ApiResponse response = api.post(PATH_FP_VERIFY, body);

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

    const ApiResponse response = api.post(PATH_FP_SIGNOUT, body);

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
    Logger::warning(TAG_STATION, "Clearing stored state");

    display.showBoot("Resetting");

    queue.begin();
    queue.clear();

    storage.begin(NVS_NAMESPACE, false);
    storage.clear();
    storage.end();

    indicators.chirpReject();

    // Wait for the button to come back up, or the reset would run again on
    // the next pass through setup().
    while (digitalRead(PIN_BUTTON) == LOW) {
        indicators.update();
    }
}

// ===========================================================================
// 12. Serial console
//
// A station in a weather box has no keyboard, but a technician at a bench has
// a USB cable, and there are things that can only be done there: enrolling a
// guard's finger, checking why a reader is silent, working out the framing of
// an unfamiliar UHF module. Those live here rather than in a second sketch,
// so the one file that gets flashed is also the one that gets debugged.
//
// The reader is non-blocking — a character at a time, acted on at the newline
// — so a half-typed command never stops the gate working. Enrolment is the
// single exception, and it is deliberate: it needs two impressions from a
// person who is standing there.
// ===========================================================================

static void enrolPrompt(const String& text)
{
    display.showStatus("Enrolment", text);
}

static void consoleHelp()
{
    Serial.println();
    Serial.println(F("Commands"));
    Serial.println(F("  help              this list"));
    Serial.println(F("  status            link, server, readers, operator"));
    Serial.println(F("  queue             the held scans"));
    Serial.println(F("  queue clear       discard them (they are never recorded)"));
    Serial.println(F("  config            re-read the configuration from the server"));
    Serial.println(F("  enrol <slot>      enrol a finger into a sensor slot, 1 upward"));
    Serial.println(F("  delete <slot>     remove a template from the sensor"));
    Serial.println(F("  uhf <ascii|m100|diag>"));
    Serial.println(F("                    change how UHF frames are read, until reboot"));
    Serial.println(F("  log <debug|info|warn|error|none>"));
    Serial.println(F("  reset             clear the queue and the cached name"));
    Serial.println(F("  restart           reboot the station"));
    Serial.println();
}

static const char* stateName(StationState value)
{
    switch (value) {
        case StationState::Booting:        return "booting";
        case StationState::Connecting:     return "connecting";
        case StationState::Authenticating: return "authenticating";
        case StationState::Ready:          return "ready";
        case StationState::Fault:          return "fault";
    }

    return "unknown";
}

static void consoleStatus()
{
    const unsigned long sinceContact = api.secondsSinceContact();

    Serial.println();
    Serial.println(F("Station"));
    Serial.println("  firmware      " VAMS_FIRMWARE_VERSION);
    Serial.println("  device code   " + String(DEVICE_CODE));
    Serial.println("  name          " + stationName);
    Serial.println("  state         " + String(stateName(state)));
    Serial.println("  gate role     " + config.gateType);
    Serial.println("  uptime        " + String(millis() / 1000UL) + "s");
    Serial.println("  free heap     " + String(ESP.getFreeHeap()) + " of " + String(ESP.getHeapSize()));

    Serial.println(F("Network"));
    Serial.println("  ssid          " + network.ssid());
    Serial.println("  connected     " + String(network.isConnected() ? "yes" : "no"));
    Serial.println("  address       " + network.ipAddress());
    Serial.println("  signal        " + String(network.signalStrength()) + " dBm");
    Serial.println("  link uptime   " + String(network.uptimeSeconds()) + "s");
    Serial.println("  failures      " + String(network.failureCount()));

    Serial.println(F("Server"));
    Serial.println("  url           " + String(API_BASE_URL));
    Serial.println("  clock synced  " + String(api.clockIsSynced() ? "yes" : "no"));
    Serial.println("  station time  " + api.timestamp());
    Serial.println("  last contact  " + (sinceContact == ULONG_MAX
        ? String("never")
        : String(sinceContact) + "s ago"));

    Serial.println(F("Readers"));
    Serial.println("  rc522         " + String(rfid.isPresent() ? "online" : "silent"));
    Serial.println("  uhf protocol  " + String(UhfReader::protocolName(uhf.protocol())));
    Serial.println("  uhf traffic   " + String(uhf.hasEverResponded() ? "seen" : "none since boot"));
    Serial.println("  as608         " + String(fingerprint.isPresent() ? "online" : "silent"));

    if (fingerprint.isPresent()) {
        Serial.println("  templates     " + String(fingerprint.storedCount())
            + " of " + String(fingerprint.capacity()));
    }

    Serial.println(F("Operator"));
    Serial.println("  required      " + String(config.requireOperator ? "yes" : "no"));
    Serial.println("  on duty       " + String(operatorActive ? (operatorName.length() > 0 ? operatorName : String("yes")) : String("no")));

    Serial.println(F("Queue"));
    Serial.println("  held          " + String(queue.size()) + " of " + String(config.maxQueueSize));
    Serial.println();
}

static void consoleQueue(const String& argument)
{
    if (argument == "clear") {
        // Said plainly: these are movements the record does not have, and
        // clearing them means it never will.
        const size_t discarded = queue.size();

        queue.clear();

        Serial.println("Discarded " + String(discarded) + " scan(s). They were never recorded.");
        idleScreenDrawn = false;

        return;
    }

    if (argument.length() > 0) {
        Serial.println(F("Say \"queue\" or \"queue clear\"."));

        return;
    }

    Serial.println("Held: " + String(queue.size()) + " of " + String(config.maxQueueSize));

    QueuedScan oldest;

    if (queue.peek(oldest)) {
        Serial.println("  oldest      " + String(oldest.uid) + " at " + String(oldest.occurredAt)
            + ", " + String(oldest.attempts) + " attempt(s)");
    }
}

static void consoleConfig()
{
    if (!network.isConnected()) {
        Serial.println(F("No network — the configuration cannot be read right now."));

        return;
    }

    refreshConfiguration(true);

    Serial.println(F("Configuration in force"));
    Serial.println("  gate role         " + config.gateType);
    Serial.println("  heartbeat         " + String(config.heartbeatIntervalMs / 1000UL) + "s");
    Serial.println("  scan debounce     " + String(config.scanDebounceMs / 1000UL) + "s");
    Serial.println("  operator required " + String(config.requireOperator ? "yes" : "no"));
    Serial.println("  operator session  " + String(config.operatorSessionMs / 60000UL) + " min");
    Serial.println("  queue bound       " + String(config.maxQueueSize));
}

/**
 * Read a slot number from a command argument.
 *
 * @return 0 when the argument is missing or not a positive number. Slot 0 is
 *         not used: the AS608 numbers from 0, but the server's enrolment
 *         records start at 1 and matching the two avoids an off-by-one that
 *         would only show up as the wrong guard being recognised.
 */
static uint16_t parseSlot(const String& argument)
{
    if (argument.length() == 0) {
        return 0;
    }

    for (unsigned int i = 0; i < argument.length(); i++) {
        if (!isDigit(argument.charAt(i))) {
            return 0;
        }
    }

    const long value = argument.toInt();

    return (value >= 1 && value <= 65535) ? static_cast<uint16_t>(value) : 0;
}

static void consoleEnrol(const String& argument)
{
    const uint16_t slot = parseSlot(argument);

    if (slot == 0) {
        Serial.println(F("Name the slot to enrol into, 1 or higher: enrol 4"));

        return;
    }

    if (!fingerprint.isPresent()) {
        Serial.println(F("The fingerprint sensor is not responding."));

        return;
    }

    Serial.println("Enrolling into slot " + String(slot) + ". Follow the prompts.");
    Serial.println(F("The gate is not reading tags while this runs."));

    indicators.set(Indication::Working);

    const bool enrolled = fingerprint.enrol(slot, &enrolPrompt);

    indicators.set(Indication::Idle);
    idleScreenDrawn = false;

    if (enrolled) {
        Serial.println("Enrolled. Record slot " + String(slot)
            + " against the guard in the web interface, or the server cannot match it.");
    } else {
        Serial.println(F("Enrolment did not complete. Nothing was stored."));
    }
}

static void consoleDelete(const String& argument)
{
    const uint16_t slot = parseSlot(argument);

    if (slot == 0) {
        Serial.println(F("Name the slot to remove: delete 4"));

        return;
    }

    if (!fingerprint.isPresent()) {
        Serial.println(F("The fingerprint sensor is not responding."));

        return;
    }

    Serial.println(fingerprint.remove(slot)
        ? "Slot " + String(slot) + " removed."
        : "Slot " + String(slot) + " could not be removed; it may already be empty.");
}

static void consoleUhf(const String& argument)
{
    UhfProtocol protocol;

    if (argument == "ascii") {
        protocol = UhfProtocol::AsciiLine;
    } else if (argument == "m100") {
        protocol = UhfProtocol::M100Frame;
    } else if (argument == "diag") {
        protocol = UhfProtocol::Diagnostic;
    } else {
        Serial.println("Reading UHF frames as " + String(UhfReader::protocolName(uhf.protocol()))
            + ". Say: uhf ascii | uhf m100 | uhf diag");

        return;
    }

    uhf.setProtocol(protocol);

    if (protocol == UhfProtocol::Diagnostic) {
        // Debug is where hexDump prints; raising the level here saves the
        // technician a second command they would otherwise have to know
        // about.
        Logger::setLevel(LogLevel::Debug);

        Serial.println(F("Raw UHF bytes will be printed. No tags will be reported until this is changed back."));
    } else {
        uhf.requestContinuousRead();

        Serial.println("Reading UHF frames as " + String(UhfReader::protocolName(protocol))
            + ". This lasts until the next reboot; set it in the sketch to make it permanent.");
    }
}

static void consoleLogLevel(const String& argument)
{
    if (argument == "debug") {
        Logger::setLevel(LogLevel::Debug);
    } else if (argument == "info") {
        Logger::setLevel(LogLevel::Info);
    } else if (argument == "warn") {
        Logger::setLevel(LogLevel::Warning);
    } else if (argument == "error") {
        Logger::setLevel(LogLevel::Error);
    } else if (argument == "none") {
        Logger::setLevel(LogLevel::None);
    } else {
        Serial.println("Say: log debug | info | warn | error | none");

        return;
    }

    Serial.println("Logging at " + String(Logger::label(Logger::level())) + ".");
}

static void executeCommand(const String& line)
{
    const int space = line.indexOf(' ');

    String verb = space < 0 ? line : line.substring(0, space);
    String argument = space < 0 ? String("") : line.substring(space + 1);

    verb.toLowerCase();
    argument.trim();
    argument.toLowerCase();

    if (verb == "help" || verb == "?") {
        consoleHelp();
    } else if (verb == "status") {
        consoleStatus();
    } else if (verb == "queue") {
        consoleQueue(argument);
    } else if (verb == "config") {
        consoleConfig();
    } else if (verb == "enrol" || verb == "enroll") {
        consoleEnrol(argument);
    } else if (verb == "delete") {
        consoleDelete(argument);
    } else if (verb == "uhf") {
        consoleUhf(argument);
    } else if (verb == "log") {
        consoleLogLevel(argument);
    } else if (verb == "reset") {
        factoryReset();
        Serial.println(F("Stored state cleared."));
    } else if (verb == "restart") {
        Serial.println(F("Restarting."));
        Serial.flush();
        ESP.restart();
    } else {
        Serial.println("Unknown command \"" + verb + "\". Type \"help\".");
    }
}

static void pollConsole()
{
    while (Serial.available() > 0) {
        const char c = static_cast<char>(Serial.read());

        if (c == '\r') {
            continue;   // a terminal sending CRLF must not submit twice
        }

        if (c == '\n') {
            consoleLine.trim();

            if (consoleLine.length() > 0) {
                executeCommand(consoleLine);
            }

            consoleLine = "";

            return;   // one command per pass, so the gate keeps its turn
        }

        // A pasted file or a stuck key must not grow the buffer without
        // bound.
        if (consoleLine.length() >= 64) {
            consoleLine = "";
            continue;
        }

        consoleLine += c;
    }
}
