#include "ApiClient.h"

#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <esp_system.h>
#include <limits.h>
#include <mbedtls/md.h>
#include <time.h>

#include "config.h"
#include "Logger.h"

static const char* TAG = "api";

void ApiClient::begin(const String& baseUrl,
                      const String& deviceCode,
                      const String& apiKey,
                      const String& rootCertificate) {
    baseUrl_ = baseUrl;

    while (baseUrl_.endsWith("/")) {
        baseUrl_.remove(baseUrl_.length() - 1);
    }

    deviceCode_ = deviceCode;
    apiKey_ = apiKey;

    // The server derives the signing secret from the API key the same way.
    signingSecret_ = apiKey + "-signing";

    rootCertificate_ = rootCertificate;
    useTls_ = baseUrl_.startsWith("https://");

    if (!useTls_) {
        Logger::warning(TAG, "the server URL is plain http — the API key travels in clear text");
    } else if (rootCertificate_.length() == 0) {
        Logger::warning(TAG, "no certificate pinned — any server presenting https will be trusted");
    }
}

void ApiClient::syncClock(const String& serverTimeIso) {
    if (serverTimeIso.length() < 19) {
        return;
    }

    // "2026-08-23T17:45:21+08:00" — parse the civil part and the offset
    // separately so the result is a true epoch rather than a local reading.
    struct tm parts = {};

    parts.tm_year = serverTimeIso.substring(0, 4).toInt() - 1900;
    parts.tm_mon  = serverTimeIso.substring(5, 7).toInt() - 1;
    parts.tm_mday = serverTimeIso.substring(8, 10).toInt();
    parts.tm_hour = serverTimeIso.substring(11, 13).toInt();
    parts.tm_min  = serverTimeIso.substring(14, 16).toInt();
    parts.tm_sec  = serverTimeIso.substring(17, 19).toInt();

    time_t civil = mktime(&parts);

    if (civil == static_cast<time_t>(-1)) {
        Logger::warning(TAG, "could not read the server clock from '" + serverTimeIso + "'");

        return;
    }

    // mktime read the civil time as local. The station's TZ is UTC, so the
    // only correction needed is the offset the server declared.
    long offsetSeconds = 0;

    int signPosition = -1;
    for (unsigned int i = 19; i < serverTimeIso.length(); i++) {
        char c = serverTimeIso.charAt(i);

        if (c == '+' || c == '-') {
            signPosition = static_cast<int>(i);
            break;
        }

        if (c == 'Z' || c == 'z') {
            signPosition = -2;   // explicit UTC
            break;
        }
    }

    if (signPosition >= 0) {
        int hours   = serverTimeIso.substring(signPosition + 1, signPosition + 3).toInt();
        int minutes = serverTimeIso.substring(signPosition + 4, signPosition + 6).toInt();

        offsetSeconds = (hours * 3600L) + (minutes * 60L);

        if (serverTimeIso.charAt(signPosition) == '+') {
            offsetSeconds = -offsetSeconds;   // subtract to reach UTC
        }
    }

    time_t serverEpoch = civil + offsetSeconds;

    clockOffset_ = static_cast<long>(serverEpoch - time(nullptr));
    clockSynced_ = true;

    Logger::info(TAG, "clock synced to the server, offset " + String(clockOffset_) + "s");
}

String ApiClient::timestamp() const {
    time_t now = time(nullptr) + clockOffset_;

    struct tm utc;
    gmtime_r(&now, &utc);

    char buffer[32];
    strftime(buffer, sizeof(buffer), "%Y-%m-%dT%H:%M:%SZ", &utc);

    return String(buffer);
}

unsigned long ApiClient::secondsSinceContact() const {
    if (lastContactAt_ == 0) {
        return ULONG_MAX;
    }

    return (millis() - lastContactAt_) / 1000UL;
}

ApiResponse ApiClient::post(const String& path, const JsonDocument& body) {
    String encoded;
    serializeJson(body, encoded);

    return send("POST", path, encoded);
}

ApiResponse ApiClient::get(const String& path) {
    // The signature covers the hash of an empty body, which is what the
    // server computes for a GET.
    return send("GET", path, "");
}

ApiResponse ApiClient::send(const String& method, const String& path, const String& body) {
    ApiResponse response;

    if (WiFi.status() != WL_CONNECTED) {
        response.status = -1;
        response.errorCode = "NO_NETWORK";
        response.message = "No network";

        return response;
    }

    String stamp = timestamp();
    String nonce = randomNonce();
    String sign  = signature(method, path, stamp, nonce, body);

    HTTPClient http;
    WiFiClientSecure secureClient;
    WiFiClient plainClient;

    bool opened = false;

    if (useTls_) {
        if (rootCertificate_.length() > 0) {
            secureClient.setCACert(rootCertificate_.c_str());
        } else {
            // Documented in secrets.h.example: acceptable on a bench, not in
            // an installation.
            secureClient.setInsecure();
        }

        opened = http.begin(secureClient, baseUrl_ + path);
    } else {
        opened = http.begin(plainClient, baseUrl_ + path);
    }

    if (!opened) {
        response.status = -2;
        response.errorCode = "BAD_URL";
        response.message = "Bad server URL";

        return response;
    }

    http.setTimeout(HTTP_TIMEOUT_MS);
    http.setConnectTimeout(HTTP_TIMEOUT_MS);

    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("X-Device-Id", deviceCode_);
    http.addHeader("X-Api-Key", apiKey_);
    http.addHeader("X-Timestamp", stamp);
    http.addHeader("X-Nonce", nonce);
    http.addHeader("X-Signature", sign);
    http.addHeader("X-Firmware-Version", VAMS_FIRMWARE_VERSION);

    int status = (method == "GET")
        ? http.GET()
        : http.POST(reinterpret_cast<uint8_t*>(const_cast<char*>(body.c_str())), body.length());

    response.status = status;

    if (status <= 0) {
        response.errorCode = "TRANSPORT";
        response.message = HTTPClient::errorToString(status);

        Logger::warning(TAG, method + " " + path + " failed: " + response.message);

        http.end();

        return response;
    }

    String payload = http.getString();
    http.end();

    lastContactAt_ = millis();

    StaticJsonDocument<2048> envelope;
    DeserializationError parseError = deserializeJson(envelope, payload);

    if (parseError) {
        response.errorCode = "BAD_JSON";
        response.message = "Unreadable reply";

        Logger::warning(TAG, method + " " + path + " returned unparseable JSON");

        return response;
    }

    response.ok = envelope["success"].as<bool>() && status >= 200 && status < 300;
    response.message = envelope["message"].as<String>();

    if (!response.ok) {
        response.errorCode = envelope["error_code"].as<String>();

        Logger::warning(TAG, method + " " + path + " -> " + String(status)
            + " " + response.errorCode + ": " + response.message);
    }

    if (!envelope["data"].isNull()) {
        response.data.set(envelope["data"]);
    }

    // Every response carries the server clock; taking it here means the
    // station corrects its drift continuously rather than only at startup.
    if (!envelope["data"]["server_time"].isNull()) {
        syncClock(envelope["data"]["server_time"].as<String>());
    }

    return response;
}

String ApiClient::signature(const String& method,
                            const String& path,
                            const String& stamp,
                            const String& nonce,
                            const String& body) const {
    String canonical = method;
    canonical.toUpperCase();

    // The server normalises the path to a single leading slash and no
    // trailing one before signing; the same normalisation has to happen here
    // or every signature is rejected.
    String normalisedPath = path;
    while (normalisedPath.startsWith("/")) {
        normalisedPath.remove(0, 1);
    }
    while (normalisedPath.endsWith("/")) {
        normalisedPath.remove(normalisedPath.length() - 1);
    }

    canonical += "\n/";
    canonical += normalisedPath;
    canonical += "\n";
    canonical += stamp;
    canonical += "\n";
    canonical += nonce;
    canonical += "\n";
    canonical += sha256Hex(body);

    return hmacSha256Hex(signingSecret_, canonical);
}

String ApiClient::sha256Hex(const String& input) {
    uint8_t digest[32];

    const mbedtls_md_info_t* info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);

    mbedtls_md_context_t context;
    mbedtls_md_init(&context);
    mbedtls_md_setup(&context, info, 0);
    mbedtls_md_starts(&context);
    mbedtls_md_update(&context, reinterpret_cast<const unsigned char*>(input.c_str()), input.length());
    mbedtls_md_finish(&context, digest);
    mbedtls_md_free(&context);

    String out;
    out.reserve(64);

    for (int i = 0; i < 32; i++) {
        char byteText[3];
        snprintf(byteText, sizeof(byteText), "%02x", digest[i]);
        out += byteText;
    }

    return out;
}

String ApiClient::hmacSha256Hex(const String& key, const String& message) {
    uint8_t digest[32];

    const mbedtls_md_info_t* info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);

    mbedtls_md_context_t context;
    mbedtls_md_init(&context);
    mbedtls_md_setup(&context, info, 1);   // 1: HMAC
    mbedtls_md_hmac_starts(&context, reinterpret_cast<const unsigned char*>(key.c_str()), key.length());
    mbedtls_md_hmac_update(&context, reinterpret_cast<const unsigned char*>(message.c_str()), message.length());
    mbedtls_md_hmac_finish(&context, digest);
    mbedtls_md_free(&context);

    String out;
    out.reserve(64);

    for (int i = 0; i < 32; i++) {
        char byteText[3];
        snprintf(byteText, sizeof(byteText), "%02x", digest[i]);
        out += byteText;
    }

    return out;
}

String ApiClient::randomNonce() {
    // esp_random() draws on the hardware RNG once the radio is up, which is
    // the case by the time anything is sent. A predictable nonce would let a
    // captured request be replayed.
    String out;
    out.reserve(32);

    for (int i = 0; i < 4; i++) {
        char part[9];
        snprintf(part, sizeof(part), "%08x", static_cast<unsigned int>(esp_random()));
        out += part;
    }

    return out;
}
