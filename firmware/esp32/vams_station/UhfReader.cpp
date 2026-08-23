#include "UhfReader.h"

#include "Logger.h"

static const char* TAG = "uhf";

// --- M100 / JRD-100 framing --------------------------------------------------
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

UhfReader::UhfReader(HardwareSerial& serial) : serial_(serial) {}

void UhfReader::begin(uint8_t rxPin, uint8_t txPin, unsigned long baud, UhfProtocol protocol) {
    protocol_ = protocol;

    serial_.begin(baud, SERIAL_8N1, rxPin, txPin);
    lineBuffer_.reserve(96);

    Logger::info(TAG, String("listening at ") + baud + " baud on RX=" + rxPin + " TX=" + txPin);

    if (protocol_ == UhfProtocol::Diagnostic) {
        Logger::warning(TAG, "diagnostic mode: frames will be printed, no tags reported");
    }
}

void UhfReader::requestContinuousRead() {
    if (protocol_ != UhfProtocol::M100Frame) {
        // A free-running reader needs no prompting, and sending an unknown
        // command to one that does could put it into a state the operator
        // then has to power-cycle out of.
        return;
    }

    // Start multiple polling, count 0xFFFF (continuous).
    const uint8_t command[] = {
        0xBB, 0x00, 0x27, 0x00, 0x03, 0x22, 0xFF, 0xFF, 0x4A, 0x7E
    };

    serial_.write(command, sizeof(command));
    serial_.flush();

    Logger::info(TAG, "asked the reader to poll continuously");
}

bool UhfReader::poll(String& epc) {
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

bool UhfReader::pollAsciiLine(String& epc) {
    while (serial_.available() > 0) {
        char c = static_cast<char>(serial_.read());

        if (c == '\r' || c == '\n') {
            if (lineBuffer_.length() == 0) {
                continue;
            }

            String candidate = sanitiseHex(lineBuffer_);
            lineBuffer_ = "";

            // A UID shorter than four bytes is not an EPC; it is noise, or a
            // status line the reader emitted alongside the tag data.
            if (candidate.length() >= 8 && candidate.length() <= 32) {
                epc = candidate;
                Logger::debug(TAG, "read " + epc);

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

bool UhfReader::pollM100Frame(String& epc) {
    while (serial_.available() > 0) {
        uint8_t byteRead = static_cast<uint8_t>(serial_.read());

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

        size_t payloadLength = (static_cast<size_t>(frame_[3]) << 8) | frame_[4];

        // header + type + command + length(2) + payload + checksum + trailer
        if (payloadLength + 7 != frameLength_) {
            Logger::debug(TAG, "frame length disagrees with its header; discarded");
            continue;
        }

        uint8_t checksum = 0;
        for (size_t i = 1; i < frameLength_ - 2; i++) {
            checksum = static_cast<uint8_t>(checksum + frame_[i]);
        }

        if (checksum != frame_[frameLength_ - 2]) {
            Logger::debug(TAG, "frame checksum failed; discarded");
            continue;
        }

        // Payload: RSSI(1) PC(2) EPC(n) CRC(2)
        if (payloadLength < 6) {
            continue;
        }

        size_t epcStart = 5 + 3;                   // past header, length, RSSI and PC
        size_t epcLength = payloadLength - 3 - 2;  // minus RSSI+PC, minus CRC

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

        // The server accepts 8 to 32 hex characters. A 96-bit EPC is 24, which
        // fits; a longer one is truncated rather than refused, because the
        // leading bytes are the ones that identify the tag.
        if (epc.length() > 32) {
            epc = epc.substring(0, 32);
        }

        if (epc.length() >= 8) {
            Logger::debug(TAG, "read " + epc);

            return true;
        }
    }

    return false;
}

void UhfReader::pollDiagnostic() {
    static uint8_t buffer[32];
    static size_t filled = 0;
    static unsigned long lastByteAt = 0;

    while (serial_.available() > 0) {
        buffer[filled++] = static_cast<uint8_t>(serial_.read());
        lastByteAt = millis();

        if (filled == sizeof(buffer)) {
            Logger::hexDump(TAG, buffer, filled);
            filled = 0;
        }
    }

    // Flush a short burst once the line has gone quiet, so a frame smaller
    // than the buffer still gets printed.
    if (filled > 0 && millis() - lastByteAt > 60) {
        Logger::hexDump(TAG, buffer, filled);
        filled = 0;
    }
}

String UhfReader::sanitiseHex(const String& raw) {
    String out;
    out.reserve(raw.length());

    for (unsigned int i = 0; i < raw.length(); i++) {
        char c = raw.charAt(i);

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
