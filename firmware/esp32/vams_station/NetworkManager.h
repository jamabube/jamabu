/**
 * The Wi-Fi link.
 *
 * A gate is not a desk: the access point may be at the far end of a car park,
 * the weather box heats up, and the radio drops. The station has to survive
 * that without a person walking out to it, so association is handled as a
 * state machine with backoff rather than as a blocking call in setup().
 *
 * Nothing here blocks for longer than a few milliseconds. The readers keep
 * polling while the radio is reconnecting, and a scan taken during an outage
 * goes to the queue.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Arduino.h>

class NetworkManager {
public:
    void begin(const String& ssid, const String& password, const String& hostname);

    /** Drive the connection state machine. Call every loop. */
    void update();

    bool isConnected() const;

    /** Dotted-quad address, or "0.0.0.0" when the link is down. */
    String ipAddress() const;

    /** Received signal strength in dBm, or 0 when the link is down. */
    int signalStrength() const;

    /** Consecutive failed association attempts since the last success. */
    uint8_t failureCount() const { return failures_; }

    /** Seconds the link has been continuously up, or 0 when it is down. */
    unsigned long uptimeSeconds() const;

private:
    void startAttempt();

    /**
     * How long to wait before the next attempt after a failure.
     *
     * Backoff matters here for a reason beyond politeness: retrying a dead
     * access point every second keeps the radio transmitting continuously,
     * which is the largest current draw on the board and the fastest way to
     * cook a sealed enclosure.
     */
    unsigned long backoffMs() const;

    String ssid_;
    String password_;
    String hostname_;

    bool attempting_ = false;
    unsigned long attemptStartedAt_ = 0;
    unsigned long lastFailureAt_ = 0;
    unsigned long connectedSince_ = 0;
    uint8_t failures_ = 0;
    bool wasConnected_ = false;
};
