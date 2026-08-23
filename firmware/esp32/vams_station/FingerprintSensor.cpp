#include "FingerprintSensor.h"

#include "Logger.h"

static const char* TAG = "as608";

FingerprintSensor::FingerprintSensor(HardwareSerial& serial)
    : sensor_(&serial), serial_(serial) {}

bool FingerprintSensor::begin(uint8_t rxPin, uint8_t txPin, unsigned long baud) {
    serial_.begin(baud, SERIAL_8N1, rxPin, txPin);
    delay(100);

    present_ = sensor_.verifyPassword();

    if (present_) {
        sensor_.getParameters();
        Logger::info(TAG, "sensor online, capacity " + String(sensor_.capacity)
            + ", holding " + String(storedCount()));
    } else {
        Logger::error(TAG, "no response — check the TX/RX pair is crossed and the sensor has 3.3 V");
    }

    return present_;
}

FingerprintMatch FingerprintSensor::poll() {
    FingerprintMatch result;

    if (!present_) {
        return result;
    }

    uint8_t status = sensor_.getImage();

    if (status == FINGERPRINT_NOFINGER) {
        return result;   // the common case: nobody is touching it
    }

    if (status != FINGERPRINT_OK) {
        // A read error is worth noticing but not worth reporting upstream:
        // a wet or badly placed finger produces these constantly.
        Logger::debug(TAG, "image capture failed, code " + String(status));

        return result;
    }

    result.attempted = true;

    status = sensor_.image2Tz();

    if (status != FINGERPRINT_OK) {
        Logger::debug(TAG, "image could not be converted, code " + String(status));

        return result;
    }

    status = sensor_.fingerFastSearch();

    if (status == FINGERPRINT_OK) {
        result.matched = true;
        result.slot = sensor_.fingerID;
        result.score = sensor_.confidence;

        Logger::info(TAG, "matched slot " + String(result.slot)
            + " with confidence " + String(result.score));
    } else if (status == FINGERPRINT_NOTFOUND) {
        Logger::info(TAG, "no match for the presented finger");
    } else {
        Logger::debug(TAG, "search failed, code " + String(status));
    }

    return result;
}

bool FingerprintSensor::enrol(uint16_t slot, void (*onPrompt)(const String&)) {
    if (!present_) {
        return false;
    }

    auto prompt = [onPrompt](const String& text) {
        Logger::info(TAG, text);

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
    Logger::info(TAG, "enrolled slot " + String(slot));

    return true;
}

bool FingerprintSensor::remove(uint16_t slot) {
    if (!present_) {
        return false;
    }

    bool removed = sensor_.deleteModel(slot) == FINGERPRINT_OK;

    Logger::info(TAG, removed
        ? "removed slot " + String(slot)
        : "could not remove slot " + String(slot));

    return removed;
}

uint16_t FingerprintSensor::storedCount() {
    if (!present_) {
        return 0;
    }

    if (sensor_.getTemplateCount() != FINGERPRINT_OK) {
        return 0;
    }

    return sensor_.templateCount;
}
