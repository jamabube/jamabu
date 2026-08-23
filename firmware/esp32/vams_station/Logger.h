/**
 * Serial logging with levels and a millisecond stamp.
 *
 * The console is often the only window into a station mounted in a weather
 * box at a gate, so the output is kept readable rather than terse.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Arduino.h>

enum class LogLevel : uint8_t {
    Debug   = 0,
    Info    = 1,
    Warning = 2,
    Error   = 3,
    None    = 4
};

class Logger {
public:
    static void begin(unsigned long baud, LogLevel level = LogLevel::Info);

    static void setLevel(LogLevel level) { level_ = level; }
    static LogLevel level() { return level_; }

    static void debug(const String& tag, const String& message);
    static void info(const String& tag, const String& message);
    static void warning(const String& tag, const String& message);
    static void error(const String& tag, const String& message);

    /** Print a byte buffer as spaced hex — used when inspecting reader frames. */
    static void hexDump(const String& tag, const uint8_t* data, size_t length);

private:
    static void write(LogLevel level, const String& tag, const String& message);
    static const char* label(LogLevel level);

    static LogLevel level_;
};
