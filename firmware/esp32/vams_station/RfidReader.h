/**
 * The MFRC522 reader: 13.56 MHz, a few centimetres of range.
 *
 * This is the guardhouse-window reader. A visitor card or a credential handed
 * over by hand is presented to it deliberately; it cannot read a windshield
 * tag on a moving vehicle, which is what the UHF reader is for.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Arduino.h>
#include <MFRC522.h>

class RfidReader {
public:
    RfidReader(uint8_t ssPin, uint8_t rstPin);

    /** Bring up SPI and the reader. Returns false if the module is silent. */
    bool begin();

    /**
     * Poll for a card.
     *
     * @param uid Receives the UID as upper-case hex with no separators, which
     *            is the form the server stores and compares.
     * @return true when a new card was read this call.
     */
    bool poll(String& uid);

    /** Whether the module answered on the bus at startup. */
    bool isPresent() const { return present_; }

    /**
     * Re-run the self test and reinitialise if the module has stopped
     * answering. An RC522 on a long or noisy cable does occasionally wedge,
     * and recovering beats waiting for someone to power-cycle the box.
     */
    bool recover();

private:
    bool selfTest();

    MFRC522 reader_;
    uint8_t ssPin_;
    uint8_t rstPin_;
    bool present_ = false;
};
