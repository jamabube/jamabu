#include "RfidReader.h"

#include <SPI.h>

#include "Logger.h"

static const char* TAG = "rc522";

RfidReader::RfidReader(uint8_t ssPin, uint8_t rstPin)
    : reader_(ssPin, rstPin), ssPin_(ssPin), rstPin_(rstPin) {}

bool RfidReader::begin() {
    SPI.begin();
    reader_.PCD_Init();

    // The antenna gain is not raised: this reader is meant to require a
    // deliberate presentation. Turning it up encourages stray reads from a
    // card in a pocket near the window, which would record movements nobody
    // made.
    delay(50);

    present_ = selfTest();

    if (present_) {
        Logger::info(TAG, "reader online");
    } else {
        Logger::error(TAG, "no response on the SPI bus — check wiring and that VCC is 3.3 V, not 5 V");
    }

    return present_;
}

bool RfidReader::selfTest() {
    byte version = reader_.PCD_ReadRegister(MFRC522::VersionReg);

    // 0x00 and 0xFF both mean "nothing is answering": a floating bus reads as
    // one or the other depending on the pull.
    return version != 0x00 && version != 0xFF;
}

bool RfidReader::recover() {
    reader_.PCD_Reset();
    delay(50);
    reader_.PCD_Init();
    delay(50);

    present_ = selfTest();

    if (present_) {
        Logger::warning(TAG, "reader recovered after a reset");
    }

    return present_;
}

bool RfidReader::poll(String& uid) {
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

    Logger::debug(TAG, "read " + uid);

    return uid.length() >= 8;
}
