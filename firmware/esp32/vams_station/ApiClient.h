/**
 * The signed HTTP client.
 *
 * Every call carries five headers the server checks before it will look at
 * the body: the device code, the API key, a timestamp, a nonce, and an
 * HMAC-SHA256 signature over a canonical form of the request. The signature
 * is what makes a captured request useless to replay, and the nonce is what
 * makes it useless to send twice.
 *
 * The canonical string, which must match DeviceAuthenticationService exactly:
 *
 *     METHOD \n /path \n timestamp \n nonce \n sha256(body)
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Arduino.h>
#include <ArduinoJson.h>

/** What came back from a call. */
struct ApiResponse {
    bool ok = false;              ///< transport succeeded and the envelope said success
    int status = 0;               ///< HTTP status, or a negative client error
    String errorCode;             ///< the server's error_code, when it sent one
    String message;               ///< human-readable, safe to show on the display
    StaticJsonDocument<1536> data;///< the envelope's data member
};

class ApiClient {
public:
    void begin(const String& baseUrl,
               const String& deviceCode,
               const String& apiKey,
               const String& rootCertificate);

    /**
     * Adopt the server's clock.
     *
     * The station has no battery-backed clock, and its timestamps have to land
     * inside the server's tolerance window or every request is refused as
     * stale. Rather than depending on an NTP server the guardhouse LAN may not
     * have, the offset is taken from the server_time every response carries.
     */
    void syncClock(const String& serverTimeIso);

    /** Whether the clock has been set from the server at least once. */
    bool clockIsSynced() const { return clockSynced_; }

    /** ISO-8601 timestamp in the server's frame of reference. */
    String timestamp() const;

    ApiResponse post(const String& path, const JsonDocument& body);
    ApiResponse get(const String& path);

    /** Seconds since the last call that reached the server. */
    unsigned long secondsSinceContact() const;

private:
    ApiResponse send(const String& method, const String& path, const String& body);

    String signature(const String& method,
                     const String& path,
                     const String& timestamp,
                     const String& nonce,
                     const String& body) const;

    static String sha256Hex(const String& input);
    static String hmacSha256Hex(const String& key, const String& message);
    static String randomNonce();

    String baseUrl_;
    String deviceCode_;
    String apiKey_;
    String signingSecret_;
    String rootCertificate_;
    bool useTls_ = false;

    // Seconds to add to the local clock to land on the server's.
    long clockOffset_ = 0;
    bool clockSynced_ = false;

    unsigned long lastContactAt_ = 0;
};
