# RC522 + AS608 on one ESP32

A single Arduino IDE sketch that runs an **MFRC522** 13.56 MHz card reader and
an **AS608** optical fingerprint sensor on the same ESP32 at the same time.

It stands alone: no Wi-Fi, no server, no display. Present a card and the UID is
printed; place an enrolled finger and the slot and confidence are printed.
Fingers are enrolled from the Serial Monitor.

This is the bench version of the two readers used by the gate station in
[`../vams_station`](../vams_station). Start here to prove the wiring and the
libraries work, then move to the station firmware for the networked system.

---

## 1. Hardware

| Part | Detail |
|---|---|
| ESP32 board | An **ESP32-WROOM-32** development board or an **Arduino Nano ESP32**. The two have different pin maps; the sketch picks the right one from **Tools → Board** |
| MFRC522 | 13.56 MHz RFID reader, SPI. **3.3 V only** |
| AS608 | Optical fingerprint sensor, UART, 3.3 V |

Both modules run from the board's `3V3` pin. The two together draw well under
200 mA, which a USB-powered devkit supplies without trouble.

---

## 2. Wiring

### ESP32-WROOM-32

| RC522 pin | ESP32 | | AS608 pin | ESP32 |
|---|---|---|---|---|
| SDA / SS | GPIO 5 | | VCC | 3V3 |
| SCK | GPIO 18 | | GND | GND |
| MOSI | GPIO 23 | | TX | GPIO 16 |
| MISO | GPIO 19 | | RX | GPIO 17 |
| RST | GPIO 27 | | | |
| 3.3V | 3V3 | | | |
| GND | GND | | | |

### Arduino Nano ESP32

| RC522 pin | Nano ESP32 | | AS608 pin | Nano ESP32 |
|---|---|---|---|---|
| SDA / SS | D10 | | VCC | 3V3 |
| SCK | D13 | | GND | GND |
| MOSI | D11 | | TX | D7 |
| MISO | D12 | | RX | D8 |
| RST | D9 | | | |
| 3.3V | 3V3 | | | |
| GND | GND | | | |

The D-numbers are the ones printed on the Nano ESP32; they are not GPIO
numbers. The sketch defines both maps and selects by board, so nothing needs
editing when moving between the two.

### Two mistakes that cost an afternoon

**The RC522 is a 3.3 V part.** Its silkscreen says 3.3V and it means it. On
5 V it often keeps working for a while before it dies, which makes the failure
look like flaky wiring rather than what it is.

**The AS608's TX goes to the ESP32's RX, and its RX to the ESP32's TX.**
Crossed is correct. Straight-through gives a sensor that never answers, which
on the serial log is indistinguishable from a dead sensor.

---

## 3. Libraries

Install through **Sketch → Include Library → Manage Libraries**:

- **MFRC522** by GithubCommunity
- **Adafruit Fingerprint Sensor Library**

and the ESP32 boards package through **Tools → Board → Boards Manager**
("esp32" by Espressif Systems).

---

## 4. Running it

1. Open `rc522_as608/rc522_as608.ino` in the Arduino IDE.
2. Select your board under **Tools → Board**.
3. Upload, then open **Tools → Serial Monitor** at **115200 baud**.

On boot the sketch reports what each reader is doing:

```
==================================================
 ESP32 — RC522 card reader + AS608 fingerprint
==================================================
[rc522] online
[as608] online — 3 of 127 templates stored

ready — present a card, or place an enrolled finger
```

Then, as things are presented:

```
[card] UID 04A2B3C4D5E6F0  (MIFARE Ultralight or Ultralight C)
[finger] matched slot 1 with confidence 142
[finger] no match for that finger
```

---

## 5. Serial Monitor commands

Type a line and press Enter. The Serial Monitor's line ending must be
**Newline** or **Both NL & CR** — with "No line ending" nothing is ever sent.

| Command | What it does |
|---|---|
| `help` | list the commands |
| `status` | what each reader is doing right now |
| `enroll <slot>` | enrol a finger into slot 1–127 |
| `delete <slot>` | remove one template |
| `empty` | remove every template — there is no undo |
| `count` | how many templates are stored |

Enrolment takes two impressions of the same finger and combines them; follow
the prompts, lifting the finger when asked. This is the only part of the sketch
that blocks, deliberately — it is a supervised action at a bench, and the
prompts have to be read and followed in order.

---

## 6. How both readers share the board

Every pass of `loop()` polls the card reader, then the fingerprint sensor, then
the console. None of the three waits on anything:

- the RC522 answers "no card" from a couple of register reads;
- the AS608 answers `FINGERPRINT_NOFINGER` from one short UART exchange;
- the console is drained only of the bytes already sitting in the buffer.

So a card presented while a finger is resting on the sensor is still read, and
neither reader can starve the other. The one exception is `enroll`, above.

Two other details keep the log honest. A card left on the antenna reports
several times a second, and a finger resting on the sensor matches over and
over; both are suppressed for two seconds after a read, so one presentation
reads as one event while a deliberate second tap still counts twice.

---

## 7. When a reader does not come up

**`[rc522] not answering`** — the module is silent on the SPI bus. Check the
supply is 3.3 V, that MOSI/MISO are not swapped, and that SS and RST match the
pins above. The sketch retries a silent or wedged reader every ten seconds and
prints `[rc522] reader came back` when it recovers, so fixing a loose wire
needs no reset.

**`[as608] not answering`** — usually TX/RX straight through instead of
crossed. If the wiring is right, the sensor may have been reconfigured to a
different baud rate: change `FINGER_BAUD` in the sketch from `57600` to `9600`
or `115200`. A sensor whose ring lights up on power-up but never answers is
almost always one of these two.

**Cards read but the UID differs each tap** — that is the card, not the
wiring. Some MIFARE cards emit a random UID on every presentation; they cannot
be identified by UID alone.
