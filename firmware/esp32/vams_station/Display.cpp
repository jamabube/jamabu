#include "Display.h"

#include <Wire.h>

#include "config.h"
#include "Logger.h"

static const char* TAG = "oled";

Display::Display() : panel_(OLED_WIDTH, OLED_HEIGHT, &Wire, -1) {}

bool Display::begin(uint8_t sdaPin, uint8_t sclPin, uint8_t address) {
    Wire.begin(sdaPin, sclPin);

    present_ = panel_.begin(SSD1306_SWITCHCAPVCC, address);

    if (!present_) {
        Logger::warning(TAG, "no panel found; running without a display");

        return false;
    }

    panel_.clearDisplay();
    panel_.setTextColor(SSD1306_WHITE);
    panel_.display();

    Logger::info(TAG, "display online");

    return true;
}

void Display::showBoot(const String& step) {
    if (!present_) {
        return;
    }

    panel_.clearDisplay();
    panel_.setTextSize(1);
    panel_.setCursor(0, 0);
    panel_.println(F("VAMS station"));
    panel_.drawFastHLine(0, 10, OLED_WIDTH, SSD1306_WHITE);
    panel_.setCursor(0, 16);
    printWrapped(step, 4);
    panel_.display();
}

void Display::showStatus(const String& title, const String& detail) {
    if (!present_) {
        return;
    }

    panel_.clearDisplay();
    panel_.setTextSize(1);
    panel_.setCursor(0, 0);
    panel_.println(title);
    panel_.drawFastHLine(0, 10, OLED_WIDTH, SSD1306_WHITE);
    panel_.setCursor(0, 16);
    printWrapped(detail, 5);
    panel_.display();
}

void Display::showIdle(const String& stationName,
                       const String& gateType,
                       bool online,
                       size_t queued,
                       const String& operatorName) {
    if (!present_) {
        return;
    }

    panel_.clearDisplay();

    panel_.setTextSize(1);
    panel_.setCursor(0, 0);
    panel_.print(stationName);

    // Link state sits top-right, where it can be checked at a glance.
    panel_.setCursor(OLED_WIDTH - 24, 0);
    panel_.print(online ? F("ON") : F("OFF"));

    panel_.drawFastHLine(0, 10, OLED_WIDTH, SSD1306_WHITE);

    panel_.setTextSize(2);
    panel_.setCursor(0, 18);
    panel_.print(F("READY"));

    panel_.setTextSize(1);
    panel_.setCursor(0, 40);
    panel_.print(gateType);

    panel_.setCursor(0, 50);

    if (operatorName.length() > 0) {
        panel_.print(operatorName);
    } else {
        panel_.print(F("no operator"));
    }

    if (queued > 0) {
        // The queue depth matters: it is the count of movements the record
        // does not have yet.
        panel_.setCursor(OLED_WIDTH - 36, 50);
        panel_.print(F("Q:"));
        panel_.print(queued);
    }

    panel_.display();
}

void Display::showResult(bool granted, const String& headline, const String& detail) {
    if (!present_) {
        return;
    }

    panel_.clearDisplay();

    // The verdict is inverted so it reads from further away than the detail.
    panel_.fillRect(0, 0, OLED_WIDTH, 20, SSD1306_WHITE);
    panel_.setTextColor(SSD1306_BLACK);
    panel_.setTextSize(2);
    panel_.setCursor(4, 3);
    panel_.print(granted ? F("PASS") : F("STOP"));

    panel_.setTextColor(SSD1306_WHITE);
    panel_.setTextSize(1);
    panel_.setCursor(0, 24);
    panel_.print(headline);

    panel_.setCursor(0, 36);
    printWrapped(detail, 3);

    panel_.display();
}

void Display::printWrapped(const String& text, uint8_t maxLines) {
    // At size 1 the 6-pixel font gives 21 characters across a 128-pixel panel.
    const uint8_t columns = 21;

    uint8_t line = 0;
    unsigned int start = 0;

    while (start < text.length() && line < maxLines) {
        unsigned int end = start + columns;

        if (end >= text.length()) {
            panel_.println(text.substring(start));
            break;
        }

        // Break on a space rather than mid-word where one is close enough.
        int space = -1;
        for (unsigned int i = end; i > start; i--) {
            if (text.charAt(i) == ' ') {
                space = static_cast<int>(i);
                break;
            }
        }

        if (space > static_cast<int>(start)) {
            panel_.println(text.substring(start, space));
            start = space + 1;
        } else {
            panel_.println(text.substring(start, end));
            start = end;
        }

        line++;
    }
}
