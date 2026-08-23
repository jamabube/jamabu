/**
 * The AS608 optical fingerprint sensor.
 *
 * The sensor holds the templates. This firmware never sees a fingerprint
 * image and never transmits one: a match produces a slot number and a
 * confidence score, and that is all that leaves the station. There is no path
 * by which biometric data reaches the network or the database.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Adafruit_Fingerprint.h>
#include <Arduino.h>
#include <HardwareSerial.h>

/** What a verification attempt produced. */
struct FingerprintMatch {
    bool attempted = false;   ///< a finger was present and was processed
    bool matched = false;     ///< it matched an enrolled template
    uint16_t slot = 0;        ///< the sensor storage position that matched
    uint16_t score = 0;       ///< the sensor's confidence, higher is better
};

class FingerprintSensor {
public:
    explicit FingerprintSensor(HardwareSerial& serial);

    bool begin(uint8_t rxPin, uint8_t txPin, unsigned long baud);

    /**
     * Look for a finger and try to match it.
     *
     * Returns immediately when no finger is present, so this can be called
     * every loop without stalling the readers.
     */
    FingerprintMatch poll();

    /**
     * Enrol a finger into a slot.
     *
     * Two impressions are taken and combined, which is what the sensor needs
     * to build a usable template. This one does block: enrolment is a
     * deliberate, supervised action at a bench, not something that happens
     * while a queue forms at the gate.
     *
     * @param onPrompt Called with text to show the person being enrolled.
     */
    bool enrol(uint16_t slot, void (*onPrompt)(const String&));

    /** Remove a template from the sensor. */
    bool remove(uint16_t slot);

    /** How many templates the sensor currently holds. */
    uint16_t storedCount();

    bool isPresent() const { return present_; }

private:
    Adafruit_Fingerprint sensor_;
    HardwareSerial& serial_;
    bool present_ = false;
};
