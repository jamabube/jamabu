/**
 * The SSD1306 OLED.
 *
 * Optional: if no panel answers on the bus the station runs exactly as it
 * would otherwise, because the lamps and the buzzer already carry the
 * decision. Every method is safe to call when the display is absent.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <Arduino.h>

class Display {
public:
    Display();

    bool begin(uint8_t sdaPin, uint8_t sclPin, uint8_t address);

    /** The resting screen: station name, gate role, link state, queue depth. */
    void showIdle(const String& stationName,
                  const String& gateType,
                  bool online,
                  size_t queued,
                  const String& operatorName);

    /** A decision. Large verdict, plate underneath, reason if refused. */
    void showResult(bool granted, const String& headline, const String& detail);

    /** A transient status line: connecting, sending, that sort of thing. */
    void showStatus(const String& title, const String& detail);

    /** Boot progress, so a station that hangs shows where it stopped. */
    void showBoot(const String& step);

    bool isPresent() const { return present_; }

private:
    /** Break text onto lines that fit the panel at the current size. */
    void printWrapped(const String& text, uint8_t maxLines);

    Adafruit_SSD1306 panel_;
    bool present_ = false;
};
