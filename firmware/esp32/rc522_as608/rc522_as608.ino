/**
 * RC522 + AS608 on one ESP32 — a single Arduino IDE sketch.
 *
 * Two readers share one board: an MFRC522 13.56 MHz card reader on the SPI
 * bus, and an AS608 optical fingerprint sensor on a hardware UART. Both are
 * polled every pass of loop(), so a card presented while a finger is on the
 * sensor is still read, and neither reader can starve the other.
 *
 * What it does: prints a card UID when a card is presented, and prints the
 * matched slot and confidence when an enrolled finger is recognised. Enrolling
 * and deleting fingers is done from the Serial Monitor — type `help` for the
 * list of commands. That is the whole sketch; wire it up, open the monitor at
 * 115200 baud, and it works standing alone.
 *
 * Libraries (Sketch -> Include Library -> Manage Libraries):
 *
 *   MFRC522              by GithubCommunity
 *   Adafruit Fingerprint Sensor Library
 *
 * plus the ESP32 boards package (Tools -> Board -> Boards Manager -> "esp32").
 *
 * Wiring, ESP32 WROOM-32 devkit — every module runs at 3.3 V:
 *
 *   RC522        ESP32          AS608        ESP32
 *   ------------------          ------------------
 *   SDA/SS  ->   GPIO 5         VCC     ->   3V3
 *   SCK     ->   GPIO 18        GND     ->   GND
 *   MOSI    ->   GPIO 23        TX      ->   GPIO 16   (ESP32 receives)
 *   MISO    ->   GPIO 19        RX      ->   GPIO 17   (ESP32 transmits)
 *   RST     ->   GPIO 27
 *   3.3V    ->   3V3
 *   GND     ->   GND
 *
 * Two wiring notes worth more than they look:
 *
 *   The RC522 is a 3.3 V part. Its silkscreen says 3.3V and it means it;
 *   feeding it 5 V kills it, usually not immediately, which makes the fault
 *   look intermittent.
 *
 *   The AS608's TX goes to the ESP32's RX and vice versa. Crossed is correct;
 *   straight-through gives a sensor that never answers, which reads exactly
 *   like a dead sensor.
 *
 * The pin map for the Arduino Nano ESP32 is below too — the sketch picks the
 * right one from the board selected in Tools -> Board, so nothing needs
 * editing when moving between the two.
 */

#include <Arduino.h>
#include <SPI.h>

#include <Adafruit_Fingerprint.h>
#include <MFRC522.h>

// ===========================================================================
// 1. Pins
// ===========================================================================

#if defined(ARDUINO_NANO_ESP32)

// Arduino Nano ESP32. The D-numbers are the ones printed on the board; they
// are not GPIO numbers, and writing the GPIO number here would land on the
// wrong pin.
#define PIN_RFID_SS    D10  // SDA/SS on the module silkscreen
#define PIN_RFID_RST   D9
#define PIN_RFID_SCK   D13
#define PIN_RFID_MISO  D12
#define PIN_RFID_MOSI  D11

#define PIN_FINGER_RX  D7   // ESP32 receives here  <- sensor TX
#define PIN_FINGER_TX  D8   // ESP32 transmits here -> sensor RX

#elif defined(ARDUINO_ARCH_ESP32)

// ESP32 WROOM-32 devkit and the usual clones. SPI here is the VSPI bus.
#define PIN_RFID_SS    5    // SDA/SS on the module silkscreen
#define PIN_RFID_RST   27
#define PIN_RFID_SCK   18
#define PIN_RFID_MISO  19
#define PIN_RFID_MOSI  23

#define PIN_FINGER_RX  16   // ESP32 receives here  <- sensor TX
#define PIN_FINGER_TX  17   // ESP32 transmits here -> sensor RX

#else
#error "Select an ESP32 board in Tools -> Board. This sketch runs on an ESP32; there is no pin map for the board currently selected."
#endif

// ===========================================================================
// 2. Fixed settings
// ===========================================================================

/** Serial Monitor baud rate. Set the monitor to match or the log is garbage. */
static const unsigned long CONSOLE_BAUD = 115200;

/**
 * AS608 UART baud rate.
 *
 * 57600 is the factory default and what nearly every module ships with. A
 * sensor that has been reconfigured elsewhere may be at 9600 or 115200; if the
 * sensor is not found, that is the first thing to try.
 */
static const uint32_t FINGER_BAUD = 57600;

/**
 * How long the same card is ignored after it is read.
 *
 * The RC522 will happily report a card left sitting on the antenna several
 * times a second. Two seconds is long enough that one presentation reads as
 * one event, and short enough that deliberately tapping twice still counts
 * twice.
 */
static const unsigned long CARD_REPEAT_MS = 2000;

/** How long a finger, once matched, is ignored before it can match again. */
static const unsigned long FINGER_REPEAT_MS = 2000;

/** Highest slot number the AS608 will accept. Most modules hold 127 or 162. */
static const uint16_t FINGER_MAX_SLOT = 127;

// ===========================================================================
// 3. The card reader
// ===========================================================================

class CardReader {
public:
    CardReader(uint8_t ssPin, uint8_t rstPin) : reader_(ssPin, rstPin) {}

    /** Bring up SPI and the reader. Returns false if the module is silent. */
    bool begin()
    {
        SPI.begin(PIN_RFID_SCK, PIN_RFID_MISO, PIN_RFID_MOSI, PIN_RFID_SS);
        reader_.PCD_Init();
        delay(50);

        present_ = selfTest();

        return present_;
    }

    /**
     * Poll for a card. Returns true once per presentation, with the UID as
     * upper-case hex and no separators — the form most systems store.
     *
     * Nothing here blocks: when no card is on the antenna the whole call is a
     * couple of register reads, so the fingerprint sensor still gets polled
     * on the same pass of loop().
     */
    bool poll(String& uid)
    {
        if (!present_) {
            return false;
        }

        if (!reader_.PICC_IsNewCardPresent() || !reader_.PICC_ReadCardSerial()) {
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

        // Suppress the repeats a card left on the antenna produces.
        const unsigned long now = millis();

        if (uid == lastUid_ && now - lastReadAt_ < CARD_REPEAT_MS) {
            lastReadAt_ = now;

            return false;
        }

        lastUid_ = uid;
        lastReadAt_ = now;

        return uid.length() >= 8;
    }

    /** The card type the module reports, for the log line. */
    String lastCardType()
    {
        return String(reader_.PICC_GetTypeName(
            reader_.PICC_GetType(reader_.uid.sak)));
    }

    /**
     * Reset and re-initialise a module that has stopped answering. An RC522 on
     * a long or noisy cable does occasionally wedge, and recovering beats
     * waiting for someone to pull the power.
     */
    bool recover()
    {
        reader_.PCD_Reset();
        delay(50);
        reader_.PCD_Init();
        delay(50);

        present_ = selfTest();

        return present_;
    }

    bool isPresent() const { return present_; }

private:
    bool selfTest()
    {
        const byte version = reader_.PCD_ReadRegister(MFRC522::VersionReg);

        // 0x00 and 0xFF both mean "nothing is answering": a floating bus reads
        // as one or the other depending on which way it is pulled.
        return version != 0x00 && version != 0xFF;
    }

    MFRC522 reader_;
    bool present_ = false;
    String lastUid_;
    unsigned long lastReadAt_ = 0;
};

// ===========================================================================
// 4. The fingerprint sensor
// ===========================================================================

/** What one poll of the sensor found. */
struct FingerMatch {
    bool attempted = false;  ///< a finger was on the sensor
    bool matched   = false;  ///< and it was recognised
    uint16_t slot  = 0;
    uint16_t score = 0;      ///< the sensor's confidence in the match
};

class FingerprintReader {
public:
    explicit FingerprintReader(HardwareSerial& port)
        : sensor_(&port), serial_(port) {}

    /** Open the UART and check the sensor answers. */
    bool begin(uint8_t rxPin, uint8_t txPin, uint32_t baud)
    {
        serial_.begin(baud, SERIAL_8N1, rxPin, txPin);
        delay(100);

        present_ = sensor_.verifyPassword();

        if (present_) {
            sensor_.getParameters();
        }

        return present_;
    }

    /**
     * Poll for a finger.
     *
     * The AS608 answers FINGERPRINT_NOFINGER immediately when nobody is
     * touching it, which is the common case and costs one short UART exchange.
     * That is what keeps this callable every pass of loop() alongside the card
     * reader.
     */
    FingerMatch poll()
    {
        FingerMatch result;

        if (!present_) {
            return result;
        }

        uint8_t status = sensor_.getImage();

        if (status != FINGERPRINT_OK) {
            // NOFINGER is the idle case; anything else is a wet, smudged or
            // badly placed finger. Neither is worth a log line — they arrive
            // constantly and would bury the reads that matter.
            return result;
        }

        result.attempted = true;

        if (sensor_.image2Tz() != FINGERPRINT_OK) {
            return result;
        }

        status = sensor_.fingerFastSearch();

        if (status == FINGERPRINT_OK) {
            const unsigned long now = millis();

            // A finger resting on the sensor matches over and over; report the
            // first match and stay quiet until it is lifted and returned.
            if (sensor_.fingerID == lastSlot_ && now - lastMatchAt_ < FINGER_REPEAT_MS) {
                lastMatchAt_ = now;
                result.attempted = false;

                return result;
            }

            result.matched = true;
            result.slot = sensor_.fingerID;
            result.score = sensor_.confidence;

            lastSlot_ = sensor_.fingerID;
            lastMatchAt_ = now;
        }

        return result;
    }

    /**
     * Enrol a finger into a slot.
     *
     * Two impressions are taken and combined; the template is built from where
     * they agree, which is what makes a later match reliable. This one does
     * block, deliberately: enrolment is a supervised action at a bench with
     * someone reading the prompts, not something that happens mid-traffic.
     */
    bool enrol(uint16_t slot)
    {
        if (!present_) {
            Serial.println(F("[fp] no sensor"));

            return false;
        }

        if (slot < 1 || slot > FINGER_MAX_SLOT) {
            Serial.println(F("[fp] slot must be between 1 and 127"));

            return false;
        }

        Serial.println("[fp] enrolling into slot " + String(slot));

        if (!captureInto(1, F("place finger on the sensor"))) {
            return false;
        }

        Serial.println(F("[fp] lift finger"));

        while (sensor_.getImage() != FINGERPRINT_NOFINGER) {
            delay(50);
        }

        if (!captureInto(2, F("place the same finger again"))) {
            return false;
        }

        if (sensor_.createModel() != FINGERPRINT_OK) {
            Serial.println(F("[fp] the two impressions did not agree — try again"));

            return false;
        }

        if (sensor_.storeModel(slot) != FINGERPRINT_OK) {
            Serial.println(F("[fp] could not store the template"));

            return false;
        }

        Serial.println("[fp] enrolled in slot " + String(slot));

        return true;
    }

    /** Remove one template. */
    bool remove(uint16_t slot)
    {
        if (!present_) {
            return false;
        }

        const bool removed = sensor_.deleteModel(slot) == FINGERPRINT_OK;

        Serial.println(removed
            ? "[fp] removed slot " + String(slot)
            : "[fp] could not remove slot " + String(slot));

        return removed;
    }

    /** Remove every template. There is no undo. */
    bool removeAll()
    {
        if (!present_) {
            return false;
        }

        const bool emptied = sensor_.emptyDatabase() == FINGERPRINT_OK;

        Serial.println(emptied
            ? F("[fp] database emptied")
            : F("[fp] could not empty the database"));

        return emptied;
    }

    /** How many templates the sensor holds. */
    uint16_t storedCount()
    {
        if (!present_ || sensor_.getTemplateCount() != FINGERPRINT_OK) {
            return 0;
        }

        return sensor_.templateCount;
    }

    uint16_t capacity() const { return present_ ? sensor_.capacity : 0; }

    bool isPresent() const { return present_; }

private:
    /** Take one impression into the sensor's buffer 1 or 2. */
    bool captureInto(uint8_t buffer, const __FlashStringHelper* prompt)
    {
        Serial.print(F("[fp] "));
        Serial.println(prompt);

        const unsigned long startedAt = millis();
        uint8_t status;

        do {
            status = sensor_.getImage();

            if (millis() - startedAt > 20000) {
                Serial.println(F("[fp] timed out"));

                return false;
            }

            delay(50);
        } while (status != FINGERPRINT_OK);

        if (sensor_.image2Tz(buffer) != FINGERPRINT_OK) {
            Serial.println(F("[fp] could not read that impression"));

            return false;
        }

        return true;
    }

    Adafruit_Fingerprint sensor_;
    HardwareSerial& serial_;
    bool present_ = false;
    uint16_t lastSlot_ = 0;
    unsigned long lastMatchAt_ = 0;
};

// ===========================================================================
// 5. The sketch
// ===========================================================================

static CardReader        card(PIN_RFID_SS, PIN_RFID_RST);
static FingerprintReader finger(Serial2);

/** How often a missing or wedged RC522 is retried. */
static const unsigned long RFID_RECOVER_MS = 10000;

static unsigned long lastRecoveryAt = 0;

static void printBanner()
{
    Serial.println();
    Serial.println(F("=================================================="));
    Serial.println(F(" ESP32 — RC522 card reader + AS608 fingerprint"));
    Serial.println(F("=================================================="));
}

static void printStatus()
{
    Serial.print(F("[rc522] "));
    Serial.println(card.isPresent()
        ? F("online")
        : F("not answering — check wiring, and that VCC is 3.3 V, not 5 V"));

    Serial.print(F("[as608] "));

    if (finger.isPresent()) {
        Serial.println("online — " + String(finger.storedCount()) + " of "
            + String(finger.capacity()) + " templates stored");
    } else {
        Serial.println(F("not answering — check TX/RX are crossed, and the baud rate"));
    }
}

static void printHelp()
{
    Serial.println(F("commands:"));
    Serial.println(F("  help              this list"));
    Serial.println(F("  status            what each reader is doing"));
    Serial.println(F("  enroll <slot>     enrol a finger into slot 1-127"));
    Serial.println(F("  delete <slot>     remove one template"));
    Serial.println(F("  empty             remove every template (no undo)"));
    Serial.println(F("  count             how many templates are stored"));
}

/** Act on one line typed into the Serial Monitor. */
static void handleCommand(String line)
{
    line.trim();

    if (line.length() == 0) {
        return;
    }

    String verb = line;
    String argument = "";
    const int space = line.indexOf(' ');

    if (space > 0) {
        verb = line.substring(0, space);
        argument = line.substring(space + 1);
        argument.trim();
    }

    verb.toLowerCase();

    if (verb == "help" || verb == "?") {
        printHelp();
    } else if (verb == "status") {
        printStatus();
    } else if (verb == "enroll" || verb == "enrol") {
        if (argument.length() == 0) {
            Serial.println(F("[cmd] which slot? e.g. enroll 1"));
        } else {
            finger.enrol(argument.toInt());
        }
    } else if (verb == "delete") {
        if (argument.length() == 0) {
            Serial.println(F("[cmd] which slot? e.g. delete 1"));
        } else {
            finger.remove(argument.toInt());
        }
    } else if (verb == "empty") {
        finger.removeAll();
    } else if (verb == "count") {
        Serial.println("[fp] " + String(finger.storedCount()) + " of "
            + String(finger.capacity()) + " templates stored");
    } else {
        Serial.println("[cmd] unknown command: " + verb + " (try help)");
    }
}

/** Read the console without waiting on it — a line at a time, when one lands. */
static void pollConsole()
{
    static String buffer;

    while (Serial.available() > 0) {
        const char c = (char) Serial.read();

        if (c == '\n' || c == '\r') {
            if (buffer.length() > 0) {
                handleCommand(buffer);
                buffer = "";
            }
        } else if (buffer.length() < 64) {
            buffer += c;
        }
    }
}

void setup()
{
    Serial.begin(CONSOLE_BAUD);

    // Give the USB-serial link a moment to come up so the banner is not lost.
    const unsigned long waitStartedAt = millis();

    while (!Serial && millis() - waitStartedAt < 2000) {
        delay(10);
    }

    printBanner();

    card.begin();
    finger.begin(PIN_FINGER_RX, PIN_FINGER_TX, FINGER_BAUD);

    printStatus();
    Serial.println();
    printHelp();
    Serial.println();
    Serial.println(F("ready — present a card, or place an enrolled finger"));
}

void loop()
{
    // Both readers are polled every pass, and neither call waits on anything,
    // so a card presented while a finger is on the sensor is still read.
    String uid;

    if (card.poll(uid)) {
        Serial.println("[card] UID " + uid + "  (" + card.lastCardType() + ")");
    }

    const FingerMatch match = finger.poll();

    if (match.matched) {
        Serial.println("[finger] matched slot " + String(match.slot)
            + " with confidence " + String(match.score));
    } else if (match.attempted) {
        Serial.println(F("[finger] no match for that finger"));
    }

    pollConsole();

    // An RC522 that never answered, or that has wedged, is retried quietly in
    // the background rather than needing a power cycle.
    if (!card.isPresent() && millis() - lastRecoveryAt > RFID_RECOVER_MS) {
        lastRecoveryAt = millis();

        if (card.recover()) {
            Serial.println(F("[rc522] reader came back"));
        }
    }
}
