# Vehicle access monitoring station — ESP32 firmware

Firmware for the gate stations of the Forest Lawn Memorial Park vehicle access
monitoring system. One station is installed at each gate. It reads a
windshield tag as a vehicle approaches, or a card presented at the guardhouse
window, asks the server whether the vehicle may pass, and shows the answer.

The station never decides anything itself. It reports what it read; the server
applies the access rules and returns the verdict. A station that cannot reach
the server queues the scan to flash and shows `Offline` — it does not fall
back to letting vehicles through.

---

## 1. Hardware

| Part | Detail |
|---|---|
| ESP32 board | Either an **ESP32-WROOM-32** development board (4 MB flash) or an **Arduino Nano ESP32**. The two have different pin maps; the sketch picks the right one from **Tools → Board** — see §2 |
| MFRC522 | 13.56 MHz RFID reader, SPI, a few centimetres of range |
| UHF reader | 860–960 MHz, UART. Model configurable — see §6 |
| AS608 | Optical fingerprint sensor, UART |
| SSD1306 | 128×64 OLED, I2C. Optional |
| 2 × LED | Green and red, each with a 220 Ω series resistor |
| Passive buzzer | Driven by LEDC, not `tone()` |
| Momentary push button | To ground; the internal pull-up is used |

### Why two RFID readers

They do different jobs and are not interchangeable.

The **UHF reader** sees a passive windshield tag from several metres. That
range is what makes a drive-through lane possible: the vehicle is identified
while it is still approaching, and the barrier is already deciding by the time
it arrives.

The **RC522** reads at about three centimetres. It is the guardhouse-window
reader — visitor cards, and any credential handed over by hand. It physically
cannot read a tag on a moving vehicle.

Both feed the same endpoint. The server resolves a UID against the tag
register and then the card register, so the firmware does not have to tell it
which reader saw what.

---

## 2. Wiring

All peripherals run at **3.3 V**. The ESP32 is not 5 V tolerant.

Two boards are supported and they are **not** wired the same way. The sketch
chooses its pin map from whatever is selected in **Tools → Board**, so pick
that first and then wire to the matching table. Selecting the wrong board
produces a station that compiles and flashes cleanly and then reads nothing.

### If you are using an Arduino Nano ESP32

That board carries an ESP32-S3, which numbers its pins differently. Wire to
the **silkscreen labels**, which is what the sketch uses:

| Peripheral | Signal | Nano ESP32 pin |
|---|---|---|
| MFRC522 | SDA / SS | D10 |
| | SCK | D13 |
| | MOSI | D11 |
| | MISO | D12 |
| | RST | D9 |
| UHF reader | reader TX → | D5 |
| | reader RX ← | D6 |
| AS608 | sensor TX → | D7 |
| | sensor RX ← | D8 |
| SSD1306 | SDA | A4 |
| | SCL | A5 |
| Green LED | anode | D2 |
| Red LED | anode | D3 |
| Buzzer | + | D4 |
| Button | one leg | A0 (other leg to GND) |

The SPI and I2C assignments are the board's defaults, so the libraries find
them without being told. Everything else in this section — the 3.3 V rule,
the UHF logic-level warning, the power note — applies to both boards; only
the pin numbers differ.

### If you are using an ESP32-WROOM-32 dev board

The tables below are for that board.

#### MFRC522 → ESP32 (VSPI)

| Module pin | ESP32 |
|---|---|
| SDA / SS | GPIO 5 |
| SCK | GPIO 18 |
| MOSI | GPIO 23 |
| MISO | GPIO 19 |
| RST | GPIO 27 |
| 3.3V | 3V3 |
| GND | GND |

`RST` is on 27, not the 22 many tutorials use, because 22 is the I2C clock for
the display.

#### UHF reader → ESP32 (UART1)

| Reader pin | ESP32 |
|---|---|
| TX | GPIO 35 |
| RX | GPIO 13 |
| VCC | see below |
| GND | GND (common with the ESP32) |

**Check the logic level before you connect the reader's TX.** Many UHF modules
run their UART at 5 V. Feeding 5 V into GPIO 35 will damage the pin. If the
reader's TX idles at 5 V, put a level shifter between them, or at minimum a
divider (1 kΩ from reader TX to GPIO 35, 2 kΩ from GPIO 35 to GND).

**Do not power the reader from the ESP32's regulator.** A UHF module
transmitting draws far more than an ESP32 board's 3.3 V regulator can supply —
typically several hundred milliamps at 3.3–5 V, with peaks well above that at
full output power. Give it its own supply and tie the grounds together. A
station that reboots whenever a tag comes into range is almost always this.

GPIO 35 is input-only, which is all a receive line needs. The default UART1
pins (9 and 10) are wired to the SPI flash and cannot be used.

#### AS608 → ESP32 (UART2)

| Sensor pin | ESP32 |
|---|---|
| TX | GPIO 16 |
| RX | GPIO 17 |
| VCC | 3V3 |
| GND | GND |

The pair is crossed: the sensor's TX goes to the ESP32's RX.

#### SSD1306 → ESP32 (I2C)

| Panel pin | ESP32 |
|---|---|
| SDA | GPIO 21 |
| SCL | GPIO 22 |
| VCC | 3V3 |
| GND | GND |

Optional. If no panel answers at `0x3C` the station runs exactly as it would
otherwise — the lamps and the buzzer already carry the decision.

#### Indicators and button

| Part | ESP32 |
|---|---|
| Green LED (anode, 220 Ω) | GPIO 25 |
| Red LED (anode, 220 Ω) | GPIO 26 |
| Buzzer (+) | GPIO 33 |
| Button | GPIO 32 to GND |

#### Pins to leave alone

GPIO 6–11 are wired to the SPI flash; using them stops the board booting.
GPIO 0, 2, 12 and 15 are strapping pins, sampled at boot to choose the boot
mode. GPIO 34–39 are input-only with no internal pull-ups.

---

## 3. Arduino IDE setup

### Board package

Which package you install depends on the board in your hand.

**Arduino Nano ESP32.** Nothing to add by hand: **Tools → Board → Boards
Manager**, search `nano esp32`, install **Arduino ESP32 Boards**, then
**Tools → Board → Arduino ESP32 Boards → Arduino Nano ESP32**. That package
is built on core 3.x, which the sketch handles.

**ESP32-WROOM-32 dev board.**

1. **File → Preferences → Additional boards manager URLs**, add:

   ```
   https://espressif.github.io/arduino-esp32/package_esp32_index.json
   ```

2. **Tools → Board → Boards Manager**, search `esp32`, install
   **esp32 by Espressif Systems**. Either the 2.0.x or the 3.x series works.

3. **Tools → Board → ESP32 Arduino → ESP32 Dev Module.**

   Board settings that matter:

   | Setting | Value |
   |---|---|
   | Upload Speed | 921600 (drop to 115200 if uploads fail) |
   | Flash Frequency | 80 MHz |
   | Partition Scheme | Default 4MB with spiffs |
   | Core Debug Level | None |

Whichever you choose, **the board selected here decides the pin map** the
sketch compiles in. Selecting a board that is not an ESP32 at all stops the
compile with a message saying so, rather than producing a binary wired for
the wrong chip.

### Libraries

**Sketch → Include Library → Manage Libraries**, then install each by name:

| Library | Author | Version |
|---|---|---|
| `ArduinoJson` | Benoit Blanchon | **6.21.x — not 7.x** |
| `MFRC522` | GithubCommunity | 1.4.10 or later |
| `Adafruit Fingerprint Sensor Library` | Adafruit | 2.1.0 or later |
| `Adafruit SSD1306` | Adafruit | 2.5.7 or later |
| `Adafruit GFX Library` | Adafruit | 1.11.5 or later |
| `Adafruit BusIO` | Adafruit | pulled in as a dependency |

Accept the "install all dependencies" prompt when the IDE offers it for the
Adafruit libraries.

The ArduinoJson version is not a preference. This firmware uses the version 6
`StaticJsonDocument` API, which version 7 removed. Installing 7.x produces a
wall of compiler errors about undeclared identifiers.

`WiFi`, `HTTPClient`, `WiFiClientSecure`, `Preferences` and `mbedtls` all come
with the ESP32 board package. Nothing needs installing for them.

### The sketch folder

The Arduino IDE requires the folder name and the `.ino` name to match. Keep
the folder called `vams_station`, containing:

```
vams_station/
├── vams_station.ino     <- the whole firmware
├── secrets.h            <- you create this; never committed
└── secrets.h.example
```

The firmware is one file. Open `vams_station.ino` and flash it; the only other
file the compiler needs is `secrets.h`, which you create in §4 and which is
kept separate so credentials never reach version control.

Inside the sketch the code is laid out in the order it is built up, and each
section carries a numbered banner so it can be found by scrolling:

| § | Section |
|---|---|
| 1 | pins and fixed limits — the wiring, which needs a screwdriver to change |
| 2 | `Logger` — levelled serial output |
| 3 | `Indicators` — lamps and buzzer, non-blocking |
| 4 | `Display` — SSD1306, optional |
| 5 | `NetworkManager` — Wi-Fi association with backoff |
| 6 | `ApiClient` — signed HTTP, clock sync, envelope parsing |
| 7 | `ScanQueue` — the offline queue, persisted to flash |
| 8 | `RfidReader` — MFRC522 |
| 9 | `UhfReader` — the long-range reader and its pluggable framing |
| 10 | `FingerprintSensor` — AS608 |
| 11 | the station: state machine, the loop, the decisions about when to talk |
| 12 | the serial console |

---

## 4. Credentials

**Do this before the first compile.** `secrets.h` is not in the repository, so
a fresh checkout fails with:

```
fatal error: secrets.h: No such file or directory
```

That is the expected state, not a broken download. The file carries the key
that lets this station write to the monitoring record, so it is listed in
`.gitignore` and **must never be committed** — which means every person who
checks the project out creates their own.

In the Arduino IDE: **Sketch → Add File…** will not do it (that copies an
existing file). Either copy `secrets.h.example` to `secrets.h` in the
`vams_station` folder with Explorer, or use the IDE's **⋮ → New Tab** button
at the right of the tab bar, name the tab `secrets.h`, and paste the contents
of `secrets.h.example` into it. Then fill in the six values:

```cpp
#define WIFI_SSID      "ForestLawn-Ops"
#define WIFI_PASSWORD  "..."
#define API_BASE_URL   "https://vams.forestlawn.local"
#define API_ROOT_CA    ""
#define DEVICE_CODE    "ESP32-ENTRY-01"
#define DEVICE_API_KEY "..."
```

Register the station first, either in **Devices → Register** in the web
interface or with:

```
php bin/console device:register --code=ESP32-ENTRY-01 --gate=entry
```

The API key is shown **once**. The server stores only a hash of it, so a lost
key cannot be recovered — only rotated, which then requires reflashing.

### TLS

Prefer `https`. Over plain `http` the API key travels in a request header in
clear text, and on a guardhouse LAN with a shared Wi-Fi password that is a
real exposure rather than a theoretical one.

An installation using a self-signed or private-CA certificate must pin it in
`API_ROOT_CA` as a PEM string:

```cpp
#define API_ROOT_CA \
"-----BEGIN CERTIFICATE-----\n" \
"MIIDdzCCAl+gAwIBAgIEAgAAuTANBgkqhkiG9w0BAQUFADBaMQswCQYDVQQGEwJJ\n" \
...
"-----END CERTIFICATE-----\n"
```

Leaving it empty makes the station accept any certificate, which removes the
protection `https` was added for. It is tolerable on a closed bench and
nowhere else. The station logs a warning at boot when it is empty.

### Pointing a station at a development server on your laptop

During development the server runs on your machine, and `start.bat` binds it
to `localhost:8080`. **`localhost` on the ESP32 means the ESP32 itself.** A
station configured with `http://localhost:8080` talks to nothing and reports
the server as unreachable, no matter how well the laptop serves the site in
its own browser.

Four things have to be true instead:

1. **Bind the server to the laptop's LAN address**, not to `localhost`. Find
   it with `ipconfig` — the `IPv4 Address` of the adapter that is on the same
   Wi-Fi as the station, usually `192.168.x.x` — and start with:

   ```
   start.bat --host 192.168.1.42
   ```

   The site then answers on `http://192.168.1.42:8080/` for the whole
   network, and `APP_URL` is set to match so the links in the interface stay
   correct.

2. **Let the port through Windows Firewall.** The first time PHP binds a
   non-loopback address Windows raises a prompt; allow it on **Private
   networks**. If the prompt was dismissed earlier, add the rule by hand:

   ```
   netsh advfirewall firewall add rule name="VAMS dev server" ^
       dir=in action=allow protocol=TCP localport=8080
   ```

3. **Put both on the same 2.4 GHz network.** An ESP32 has no 5 GHz radio. On
   a dual-band router advertising one name for both bands the laptop may
   silently be on the 5 GHz half — that is the same network to you and a
   different one to the station. A phone hotspot set to 2.4 GHz is the
   quickest way to rule this out.

4. **Use `http` in `secrets.h`**, since the development server has no
   certificate:

   ```cpp
   #define API_BASE_URL "http://192.168.1.42:8080"
   #define API_ROOT_CA  ""
   ```

   The firmware selects TLS from the scheme, so plain `http` works with no
   other change. It is for the bench only: the API key travels in a header in
   clear text — see the warning above.

The laptop's address changes when DHCP reassigns it, and the station carries
the old one until it is reflashed. Reserving the address in the router, or
giving the laptop a static one, saves reflashing every few days.

A last caveat: the PHP development server handles one request at a time. A
station mid-request makes the browser feel briefly unresponsive. That is the
server, not the firmware, and it does not happen under a real web server.

---

## 5. How a request is authenticated

Every call carries five headers the server checks before it looks at the body:

| Header | Contents |
|---|---|
| `X-Device-Id` | the registered device code |
| `X-Api-Key` | the key issued at registration |
| `X-Timestamp` | ISO-8601, UTC |
| `X-Nonce` | 32 hex characters, never repeated |
| `X-Signature` | HMAC-SHA256, lower-case hex |

The signature is taken over a canonical string:

```
METHOD \n /path \n timestamp \n nonce \n sha256(body)
```

with the key `<api key>-signing`. The server builds the same string in
`DeviceAuthenticationService` and compares in constant time. The timestamp
must land inside the server's tolerance window (120 seconds by default) and
the nonce must not have been seen before, which is what makes a captured
request useless to replay.

The station has no battery-backed clock. Rather than depending on an NTP
server the guardhouse LAN may not have, it takes the offset from the
`server_time` every response carries, and corrects continuously.

If every request comes back `401 SIGNATURE_INVALID`, the cause is almost
always one of: the wrong API key, a clock that has not synced yet (the first
request after a cold boot can fail for this and succeed on the retry), or a
proxy rewriting the path.

---

## 6. Bringing up an unknown UHF reader

There is no single standard for these modules. Nearly all of them speak UART,
but the framing differs by manufacturer. The firmware ships with two parsers:

| Protocol | Reader |
|---|---|
| `M100Frame` | Magicrf M100 / JRD-100 / YRM100 and the modules built on that chipset — the ones usually sold for ESP32 use. **The default.** |
| `AsciiLine` | Hex text, one tag per line, ending in CR/LF. Common on inexpensive modules and on almost any module switched into a "notify" or "auto-read" mode. |

Work through this in order.

**Step 1 — find the baud rate.** 115200 is the default here and the most
common. 9600 and 57600 also appear. If the reader's datasheet says otherwise,
change `UHF_BAUD` in §1 of the sketch.

**Step 2 — turn on diagnostic mode.** Open **Tools → Serial Monitor** at
115200 and type:

```
uhf diag
```

No reflashing: the console switches the parser at runtime. Hold a tag in front
of the antenna. Diagnostic mode dumps every byte arriving on UART1 as hex and
reports no tags.

To make a station boot straight into it — useful when the reader is being
commissioned by someone who will not have a serial monitor open — set
`#define UHF_DIAGNOSTIC_MODE 1` in §1 instead.

**Step 3 — read what came out.**

*Nothing at all.* The reader is not talking to you. In order of likelihood:
TX and RX are not crossed; the baud rate is wrong; the reader needs a command
before it will report (try each parser's start command by setting the protocol
and watching again); the reader is browning out — see the power note in §2.

*Readable hex text*, something like `E2 00 34 12 01 3B 18 00 25 30 8A 41` in
ASCII, ending in `0D 0A` — use `AsciiLine`.

*Frames starting `BB` and ending `7E`* — that is the M100 family; the default
`M100Frame` is correct, and the reader is working. Turn diagnostic mode back
off.

*Anything else.* You have the framing in front of you: note where the length
byte sits, where the EPC begins, and how long it is, then add a parser to
`UhfReader` in the sketch's §9 alongside the two that are there. Each is about thirty
lines. Add a value to `UhfProtocol`, a branch in `poll()`, a name in
`protocolName()`, and select it in `bootPeripherals()`.

**Step 4 — turn diagnostic mode off.** In diagnostic mode the station reads no
tags at all. Send `uhf m100` or `uhf ascii` — whichever step 3 pointed at —
and set the same one as the default in `bootPeripherals()` before the station
goes to a gate. A station left in diagnostic mode passes no vehicles.

If the reader is silent for the first two minutes after boot, the station
reports a `UHF_SILENT` fault to the server, so this shows up in the device
list rather than being discovered by a queue of cars.

---

## 7. Operating

### Boot sequence

The display shows each step, so a station that hangs shows where it stopped:
`Starting` → `RFID reader` → `UHF reader` → `Fingerprint` → `Wi-Fi` →
`Registering` → idle.

A peripheral that does not answer is not fatal. The station carries on and
reports the fault to the server after it registers; a station whose
fingerprint sensor has failed is still worth having at a gate.

### The lamps

| Pattern | Meaning |
|---|---|
| Both dim-cycling | working — a request is out |
| Steady green | access granted |
| Steady red | access refused |
| Slow red pulse | offline; scans are being queued |
| Fast red pulse | needs a person — credentials refused, or the queue is full |

### The button

| Action | Effect |
|---|---|
| Held 2 s while running | signs the operator out and stops monitoring |
| Held at power-up | clears the queue and the cached name |

The boot-time reset cannot strand a station: the credentials are compiled in,
so it clears held data and nothing that would stop the station coming back up.

### Operator sign-on

When the server's `require_operator_authentication` rule is on, the station is
out of service until a guard signs on with a fingerprint. Scans taken before
that are still queued — a gate that quietly discards reads is worse than one
that refuses them — and the display says `Not on duty`.

The sensor holds the templates. **This firmware never sees a fingerprint image
and never transmits one.** A match produces a slot number and a confidence
score, and that is all that leaves the station. There is no code path by which
biometric data reaches the network or the database.

A guard has to be enrolled into the sensor before any of this works, and that
is done from the serial console at a bench — see §9.

### Going offline

Scans taken while the link is down are written to flash with the moment they
actually happened, and sent when it returns. The server accepts the
device-supplied timestamp precisely so a replayed queue lands in the record at
the right time rather than bunched at the moment of reconnection.

The queue holds 64 scans and survives a power cut. When it fills, the station
shows `Queue full` and the fast red pulse: at that point movements are being
lost and somebody needs to know.

Held scans go out one at a time rather than in a burst, so the station keeps
reading tags while it catches up and a fleet reconnecting after an outage does
not all transmit at once.

---

## 8. Troubleshooting

| Symptom | Cause |
|---|---|
| `fatal error: secrets.h: No such file or directory` | you have not created `secrets.h` yet — see §4; it is deliberately not in the repository |
| `'ledcSetup' was not declared` / `'ledcAttach' was not declared` | the sketch handles both core versions; if this appears, **Tools → Board** is not set to an ESP32 |
| Compiles and flashes, then reads nothing at all | the wrong board is selected — an Arduino Nano ESP32 and an ESP32-WROOM-32 have different pin maps, see §2 |
| Boot loops when a vehicle approaches | UHF reader powered from the ESP32 regulator; see §2 |
| `RC522 did not answer` | SPI wiring, or the module is on 5 V; it needs 3.3 V |
| RC522 works cold, stops after an hour | long or unshielded SPI cable — the station retries every 30 s and logs when it recovers |
| `AS608 did not answer` | TX/RX not crossed, or the baud rate is not 57600 |
| Every request `401` | wrong API key, clock not yet synced, or a proxy rewriting the path |
| `429` at a busy gate | the server's `access-scan` rate limit; raise it in **Settings → Security** |
| Display blank, everything else fine | no panel at `0x3C`; harmless, the station does not need it |
| Wi-Fi connects then drops | the backoff in `NetworkManager` is working as intended; check signal strength in the device diagnostics |

Send `log debug` on the serial console for a much more verbose log, and
`status` for a single screen covering the link, the server, the readers, the
operator and the queue. Both are described in §9 below.

---

## 9. The serial console

Open **Tools → Serial Monitor** at 115200 and press Enter. The console is
read a character at a time and acted on at the newline, so typing at it never
stops the gate working.

| Command | Does |
|---|---|
| `help` | the list |
| `status` | link, server, readers, operator, queue — one screen |
| `queue` | how many scans are held, and the oldest |
| `queue clear` | discards them; they are never recorded, so say it deliberately |
| `config` | re-reads the configuration from the server and prints what is in force |
| `enrol <slot>` | enrols a finger into a sensor slot, 1 upward |
| `delete <slot>` | removes a template from the sensor |
| `uhf ascii\|m100\|diag` | changes how UHF frames are read, until the next reboot |
| `log debug\|info\|warn\|error\|none` | log verbosity |
| `reset` | clears the held queue and the cached station name |
| `restart` | reboots |

### Enrolling a guard

This is the one command that blocks: the sensor needs two impressions from
someone who is standing there, and the station stops reading tags for the
half minute it takes. Do it at a bench, not at an open gate.

```
enrol 4
```

Follow the prompts on the OLED and the console. Then record slot 4 against
that guard in the web interface — **the sensor and the server keep separate
records, and a template the server does not know about matches nothing.**

Slots are numbered from 1 to match the server's enrolment records. The AS608
itself numbers from 0; the firmware refuses slot 0 rather than leave an
off-by-one that would show up later as the wrong guard being recognised.

No fingerprint image is involved at any point. Enrolment happens inside the
sensor; a later match yields a slot number and a confidence score, and that
is the whole of what the station sees or transmits.

---

## 10. What lives where

Everything is in `vams_station.ino`, sectioned as listed in §3. Two things are
deliberately outside it:

| File | Why it is separate |
|---|---|
| `secrets.h` | carries the API key, so it must never be committed — it is listed in `.gitignore` |
| `secrets.h.example` | the template to copy, with no real credentials in it |

Anything an administrator might want to retune — the heartbeat interval, the
debounce window, whether an operator must sign on, the gate's role — is served
by the server and applied at runtime; the station re-reads it every fifteen
minutes, so a change made in the web interface reaches a running gate without
anyone driving out to it. Section 1 of the sketch holds only what cannot
change without rewiring.
