#include "Indicators.h"

// The ESP32 core drives a passive buzzer through the LEDC peripheral; a
// dedicated channel keeps it away from anything else using PWM.
static const uint8_t BUZZER_CHANNEL = 0;
static const uint8_t BUZZER_RESOLUTION = 8;

void Indicators::begin(uint8_t greenPin, uint8_t redPin, uint8_t buzzerPin) {
    greenPin_  = greenPin;
    redPin_    = redPin;
    buzzerPin_ = buzzerPin;

    pinMode(greenPin_, OUTPUT);
    pinMode(redPin_, OUTPUT);

    ledcSetup(BUZZER_CHANNEL, 2000, BUZZER_RESOLUTION);
    ledcAttachPin(buzzerPin_, BUZZER_CHANNEL);
    ledcWrite(BUZZER_CHANNEL, 0);

    applySteady(false, false);
    state_ = Indication::Idle;
    stateSince_ = millis();
}

void Indicators::set(Indication state) {
    if (state_ == state) {
        return;
    }

    state_ = state;
    stateSince_ = millis();
    lastToggle_ = stateSince_;
    phase_ = false;
}

void Indicators::update() {
    unsigned long now = millis();

    // --- Tones -------------------------------------------------------------

    if (toneActive_) {
        if (now - toneStartedAt_ >= toneDuration_[tonePlaying_]) {
            tonePlaying_++;

            if (tonePlaying_ >= toneCount_) {
                ledcWrite(BUZZER_CHANNEL, 0);
                toneActive_ = false;
                toneCount_ = 0;
            } else {
                unsigned int frequency = toneFreq_[tonePlaying_];

                if (frequency == 0) {
                    ledcWrite(BUZZER_CHANNEL, 0);   // a rest between notes
                } else {
                    ledcWriteTone(BUZZER_CHANNEL, frequency);
                }

                toneStartedAt_ = now;
            }
        }
    }

    // --- Lamps -------------------------------------------------------------

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
            // Both lamps alternating reads as "busy" without implying either
            // verdict.
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

void Indicators::chirpRead() {
    toneFreq_[0] = 2200; toneDuration_[0] = 40;
    toneCount_ = 1;
    tonePlaying_ = 0;
    toneStartedAt_ = millis();
    toneActive_ = true;
    ledcWriteTone(BUZZER_CHANNEL, toneFreq_[0]);
}

void Indicators::chirpAccept() {
    toneFreq_[0] = 1800; toneDuration_[0] = 90;
    toneFreq_[1] = 2600; toneDuration_[1] = 140;
    toneCount_ = 2;
    tonePlaying_ = 0;
    toneStartedAt_ = millis();
    toneActive_ = true;
    ledcWriteTone(BUZZER_CHANNEL, toneFreq_[0]);
}

void Indicators::chirpReject() {
    // Low, twice, with a gap: distinguishable from the accept pair without
    // having to look up.
    toneFreq_[0] = 420; toneDuration_[0] = 180;
    toneFreq_[1] = 0;   toneDuration_[1] = 80;
    toneFreq_[2] = 420; toneDuration_[2] = 320;
    toneCount_ = 3;
    tonePlaying_ = 0;
    toneStartedAt_ = millis();
    toneActive_ = true;
    ledcWriteTone(BUZZER_CHANNEL, toneFreq_[0]);
}

void Indicators::silence() {
    ledcWrite(BUZZER_CHANNEL, 0);
    toneActive_ = false;
    toneCount_ = 0;
}

void Indicators::applySteady(bool green, bool red) {
    digitalWrite(greenPin_, green ? HIGH : LOW);
    digitalWrite(redPin_, red ? HIGH : LOW);
}
