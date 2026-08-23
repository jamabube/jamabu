/**
 * The long-range UHF reader: 860-960 MHz, metres of range.
 *
 * This is the lane reader — it picks up a windshield tag as a vehicle
 * approaches, which is what lets a gate work without the driver winding a
 * window down.
 *
 * There is no single standard for these modules. Nearly all of them speak
 * UART, but the framing differs by manufacturer, so the parsing is pluggable:
 * pick the protocol that matches the reader, or add one. Two cover most of
 * the market:
 *
 *   AsciiLine  The reader emits the EPC as hex text ending in CR/LF. Common
 *              on inexpensive modules and on almost any module switched into
 *              a "notify" or "auto-read" mode.
 *
 *   M100Frame  The length-prefixed binary frame used by the JRD-100, YRM100
 *              and the several modules built on the same Magicrf chipset —
 *              the ones usually paired with an ESP32.
 *
 * If neither fits, set UHF_DIAGNOSTIC_MODE in config.h, hold a tag to the
 * antenna, and read the raw bytes off the console. The README section
 * "Bringing up an unknown UHF reader" walks through it.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Arduino.h>
#include <HardwareSerial.h>

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
     * @param serial  The UART to use. UART1 on this board; UART2 carries the
     *                fingerprint sensor.
     */
    explicit UhfReader(HardwareSerial& serial);

    void begin(uint8_t rxPin, uint8_t txPin, unsigned long baud, UhfProtocol protocol);

    /**
     * Poll for a tag.
     *
     * @param epc Receives the EPC as upper-case hex with no separators.
     * @return true when a tag was decoded this call.
     */
    bool poll(String& epc);

    /**
     * Ask the reader to start reporting.
     *
     * Modules differ: some free-run from power-up, some need a command. This
     * sends the M100 "start multiple polling" frame when that protocol is
     * selected, and does nothing otherwise, which is correct for a free-running
     * reader.
     */
    void requestContinuousRead();

    /** Whether anything at all has arrived from the reader since boot. */
    bool hasEverResponded() const { return everResponded_; }

    void setProtocol(UhfProtocol protocol) { protocol_ = protocol; }
    UhfProtocol protocol() const { return protocol_; }

private:
    bool pollAsciiLine(String& epc);
    bool pollM100Frame(String& epc);
    void pollDiagnostic();

    /** Keep only hex digits, upper-cased. Returns "" if anything else is present. */
    static String sanitiseHex(const String& raw);

    HardwareSerial& serial_;
    UhfProtocol protocol_ = UhfProtocol::AsciiLine;
    bool everResponded_ = false;

    String lineBuffer_;

    // Frame assembly for the binary protocol.
    static const size_t FRAME_MAX = 64;
    uint8_t frame_[FRAME_MAX];
    size_t frameLength_ = 0;
    bool inFrame_ = false;
};
