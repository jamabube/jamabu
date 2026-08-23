/**
 * The lamps and the buzzer.
 *
 * A guard standing at a barrier in daylight cannot read a small OLED, so the
 * green and red lamps carry the decision and the display only elaborates.
 * Every pattern is non-blocking: the station must keep reading tags while a
 * lamp is lit.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Arduino.h>

enum class Indication : uint8_t {
    Idle,
    Working,     // amber-equivalent: both lamps dim-cycled while a call is out
    Granted,
    Denied,
    Offline,     // slow red pulse: queueing rather than transmitting
    Fault        // fast red pulse: something needs a person
};

class Indicators {
public:
    void begin(uint8_t greenPin, uint8_t redPin, uint8_t buzzerPin);

    /** Set the standing pattern. */
    void set(Indication state);

    /** Drive the current pattern. Call every loop; never blocks. */
    void update();

    /** A short rising pair — a credential was accepted. */
    void chirpAccept();

    /** A low double note — a credential was refused. */
    void chirpReject();

    /** A single tick — something was read, before the verdict is known. */
    void chirpRead();

    /** Silence the buzzer regardless of what it was doing. */
    void silence();

private:
    void tone(unsigned int frequency, unsigned long durationMs);
    void applySteady(bool green, bool red);

    uint8_t greenPin_ = 0;
    uint8_t redPin_   = 0;
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
