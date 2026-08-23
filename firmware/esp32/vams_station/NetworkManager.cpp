#include "NetworkManager.h"

#include <WiFi.h>

#include "Logger.h"
#include "config.h"

namespace {
const char* TAG = "wifi";

/** Backoff schedule in seconds, then held at the last value. */
const unsigned long BACKOFF_SECONDS[] = {2, 5, 10, 20, 30, 60};
const uint8_t BACKOFF_STEPS = sizeof(BACKOFF_SECONDS) / sizeof(BACKOFF_SECONDS[0]);
}  // namespace

void NetworkManager::begin(const String& ssid, const String& password, const String& hostname)
{
    ssid_ = ssid;
    password_ = password;
    hostname_ = hostname;

    WiFi.persistent(false);
    WiFi.mode(WIFI_STA);
    WiFi.setHostname(hostname_.c_str());

    // Modem sleep saves power but adds latency to every request and, on some
    // access points, drops the association outright. A mains-powered gate
    // station gains nothing from it.
    WiFi.setSleep(false);

    // The reconnection is driven from update() so the backoff is honoured;
    // leaving the SDK to retry on its own would race with it.
    WiFi.setAutoReconnect(false);

    startAttempt();
}

void NetworkManager::startAttempt()
{
    Logger::info(TAG, "Associating with " + ssid_);

    WiFi.disconnect(false, false);
    WiFi.begin(ssid_.c_str(), password_.c_str());

    attempting_ = true;
    attemptStartedAt_ = millis();
}

unsigned long NetworkManager::backoffMs() const
{
    const uint8_t index = failures_ == 0 ? 0
                        : (failures_ - 1 < BACKOFF_STEPS ? failures_ - 1 : BACKOFF_STEPS - 1);

    return BACKOFF_SECONDS[index] * 1000UL;
}

void NetworkManager::update()
{
    const bool connected = WiFi.status() == WL_CONNECTED;

    if (connected) {
        if (!wasConnected_) {
            wasConnected_ = true;
            attempting_ = false;
            failures_ = 0;
            connectedSince_ = millis();

            Logger::info(TAG, "Connected as " + ipAddress()
                              + " (" + String(signalStrength()) + " dBm)");
        }

        return;
    }

    if (wasConnected_) {
        wasConnected_ = false;
        connectedSince_ = 0;
        Logger::warning(TAG, "Link lost");
    }

    if (attempting_) {
        if (millis() - attemptStartedAt_ < WIFI_CONNECT_TIMEOUT_MS) {
            return;
        }

        attempting_ = false;
        lastFailureAt_ = millis();

        if (failures_ < 255) {
            failures_++;
        }

        Logger::warning(TAG, "Association timed out (attempt " + String(failures_)
                             + "), retrying in " + String(backoffMs() / 1000) + "s");

        // The radio is cycled rather than merely retried. An ESP32 that has
        // failed to associate several times in a row is usually in a state the
        // next WiFi.begin() alone will not clear.
        WiFi.disconnect(true, false);
        WiFi.mode(WIFI_OFF);
        WiFi.mode(WIFI_STA);
        WiFi.setHostname(hostname_.c_str());

        return;
    }

    if (millis() - lastFailureAt_ >= backoffMs()) {
        startAttempt();
    }
}

bool NetworkManager::isConnected() const
{
    return WiFi.status() == WL_CONNECTED;
}

String NetworkManager::ipAddress() const
{
    return isConnected() ? WiFi.localIP().toString() : String("0.0.0.0");
}

int NetworkManager::signalStrength() const
{
    return isConnected() ? WiFi.RSSI() : 0;
}

unsigned long NetworkManager::uptimeSeconds() const
{
    if (connectedSince_ == 0) {
        return 0;
    }

    return (millis() - connectedSince_) / 1000UL;
}
