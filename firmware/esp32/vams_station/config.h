/**
 * Compile-time configuration: pin assignments and fixed limits.
 *
 * Anything an administrator might want to retune at runtime — the heartbeat
 * interval, the debounce window, whether an operator must sign on — is served
 * by the server instead and lives in RuntimeConfig. What remains here is the
 * wiring, which cannot change without a screwdriver.
 *
 * Hardware this firmware is written for:
 *   ESP32 development board (WROOM-32 or equivalent)
 *   MFRC522 RFID reader   — 13.56 MHz HF, SPI, a few centimetres
 *   UHF reader            — 860-960 MHz, UART, metres (model configurable)
 *   AS608 fingerprint     — optical, UART
 *   SSD1306 OLED 128x64   — I2C, optional
 *
 * The two RFID readers do different jobs. The UHF reader picks up a
 * windshield tag as a vehicle approaches, which is what makes a drive-through
 * lane possible. The RC522 reads a card held against it at the guardhouse
 * window — visitor cards, and any credential handed over by hand. Both feed
 * the same endpoint: the server resolves a UID against the tag register and
 * then the card register, so it does not need to be told which reader saw it.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Arduino.h>

// ---------------------------------------------------------------------------
// Identity
// ---------------------------------------------------------------------------

#define VAMS_FIRMWARE_VERSION "1.0.0"

// ---------------------------------------------------------------------------
// Pin assignments
//
// Chosen to avoid the pins that cannot be used freely on an ESP32:
//   GPIO 6-11   wired to the SPI flash; using them bricks the boot
//   GPIO 0,2,12,15  strapping pins, held at boot to select the boot mode
//   GPIO 34-39  input only, no pull-ups, no output
//
// The RC522 reset line is on 27 rather than the 22 many tutorials use,
// because 22 is the I2C clock for the display.
// ---------------------------------------------------------------------------

// MFRC522 over VSPI.
#define PIN_RFID_SS    5    // SDA/SS on the module silkscreen
#define PIN_RFID_RST   27
#define PIN_RFID_SCK   18
#define PIN_RFID_MISO  19
#define PIN_RFID_MOSI  23

// UHF reader over UART1.
//
// GPIO 35 is input-only, which is all a receive line needs. The default UART1
// pins (9 and 10) are wired to the SPI flash and cannot be used.
//
// Check the reader's logic level before wiring it. Many UHF modules run their
// UART at 5 V; the ESP32 is not 5 V tolerant and its receive pin will be
// damaged. If the reader's TX idles at 5 V, put a level shifter — or at
// minimum a divider — between them.
#define PIN_UHF_RX     35   // ESP32 receives here  <- reader TX
#define PIN_UHF_TX     13   // ESP32 transmits here -> reader RX
#define UHF_BAUD       115200

// AS608 over UART2. Cross the pair: sensor TX goes to the ESP32 RX.
#define PIN_FINGER_RX  16   // ESP32 receives here  <- sensor TX
#define PIN_FINGER_TX  17   // ESP32 transmits here -> sensor RX
#define FINGER_BAUD    57600

// SSD1306 over I2C.
#define PIN_OLED_SDA   21
#define PIN_OLED_SCL   22
#define OLED_ADDRESS   0x3C
#define OLED_WIDTH     128
#define OLED_HEIGHT    64

// Operator feedback.
#define PIN_LED_GREEN  25
#define PIN_LED_RED    26
#define PIN_BUZZER     33

// Held at boot to clear stored credentials; also signs the operator out
// during normal running.
#define PIN_BUTTON     32

// ---------------------------------------------------------------------------
// Fixed limits
// ---------------------------------------------------------------------------

/**
 * Scans held while the network is down.
 *
 * The server serves its own bound in the configuration payload; this is the
 * ceiling the hardware can hold regardless. Each entry is small, but the queue
 * is persisted to flash, and an unbounded one would wear it out.
 */
#define QUEUE_CAPACITY 64

/** Longest a single HTTP request may take before it is abandoned. */
#define HTTP_TIMEOUT_MS 8000

/** How long a decision stays on the display before it returns to idle. */
#define RESULT_DISPLAY_MS 4000

/** Wi-Fi association attempt length, before the radio is cycled. */
#define WIFI_CONNECT_TIMEOUT_MS 20000

/**
 * A card left resting on the reader is read continuously. Anything sooner
 * than this after the previous read of the same UID is the same presentation,
 * not a second one.
 *
 * The server applies its own duplicate-suppression window as well; this one
 * exists so the station does not spend the network on transmissions the
 * server would only discard.
 */
#define SAME_CARD_LOCKOUT_MS 3000

/**
 * A vehicle sitting in range of the UHF reader is read over and over. The
 * lockout above applies per UID to both readers, but the UHF antenna also has
 * a wider field, so the same tag can be seen for as long as the vehicle is
 * stationary at the barrier. This is the longer window applied to UHF reads.
 */
#define SAME_TAG_LOCKOUT_MS 8000

/** Serial console speed. */
#define SERIAL_BAUD 115200

/**
 * Dump every byte arriving from the UHF reader to the serial console instead
 * of parsing it.
 *
 * Set this when commissioning a reader whose framing is not yet known: hold a
 * tag to the antenna, read the hex the console prints, and pick or write the
 * matching protocol in UhfReader. See the README section "Bringing up an
 * unknown UHF reader".
 */
#define UHF_DIAGNOSTIC_MODE 0
