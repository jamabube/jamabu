#include "Logger.h"

LogLevel Logger::level_ = LogLevel::Info;

void Logger::begin(unsigned long baud, LogLevel level) {
    Serial.begin(baud);

    // A board that has just been flashed prints boot chatter; a short pause
    // keeps the first real line from landing in the middle of it.
    delay(200);

    level_ = level;
}

void Logger::debug(const String& tag, const String& message) {
    write(LogLevel::Debug, tag, message);
}

void Logger::info(const String& tag, const String& message) {
    write(LogLevel::Info, tag, message);
}

void Logger::warning(const String& tag, const String& message) {
    write(LogLevel::Warning, tag, message);
}

void Logger::error(const String& tag, const String& message) {
    write(LogLevel::Error, tag, message);
}

void Logger::write(LogLevel level, const String& tag, const String& message) {
    if (static_cast<uint8_t>(level) < static_cast<uint8_t>(level_)) {
        return;
    }

    // Seconds since boot, which is what matters when reading back a log to
    // work out how long a station ran before it faulted.
    unsigned long ms = millis();

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

void Logger::hexDump(const String& tag, const uint8_t* data, size_t length) {
    if (static_cast<uint8_t>(LogLevel::Debug) < static_cast<uint8_t>(level_)) {
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

const char* Logger::label(LogLevel level) {
    switch (level) {
        case LogLevel::Debug:   return "DEBUG";
        case LogLevel::Info:    return "INFO ";
        case LogLevel::Warning: return "WARN ";
        case LogLevel::Error:   return "ERROR";
        default:                return "     ";
    }
}
