/**
 * Scans held while the network is down.
 *
 * A gate does not stop working because the Wi-Fi did. A scan the station
 * cannot transmit is stored with the moment it actually happened and sent
 * when the link returns; the server accepts a device-supplied timestamp
 * precisely so a replayed queue lands in the record at the right time rather
 * than bunched at the moment of reconnection.
 *
 * The queue survives a power cut: it is held in flash through Preferences,
 * not only in RAM. A station that loses power mid-shift comes back with its
 * unsent movements intact.
 *
 * @file
 * @version 1.0.0
 */

#pragma once

#include <Arduino.h>

#include "config.h"

struct QueuedScan {
    char uid[33];          ///< upper-case hex, 32 characters at most
    char occurredAt[25];   ///< ISO-8601, the moment the tag was read
    char accessType[8];    ///< "entry", "exit" or "" to let the server decide
    uint8_t attempts;      ///< transmissions tried so far
};

class ScanQueue {
public:
    /** Load anything left over from a previous run. */
    void begin();

    /**
     * Add a scan.
     *
     * @return false when the queue is full. The caller must tell the operator:
     *         a silently dropped movement is a hole in the record.
     */
    bool enqueue(const String& uid, const String& occurredAt, const String& accessType);

    /** Look at the oldest entry without removing it. */
    bool peek(QueuedScan& scan) const;

    /** Remove the oldest entry, after it has been accepted by the server. */
    void pop();

    /** Note that the oldest entry failed to send. */
    void recordAttempt();

    size_t size() const { return count_; }
    bool isEmpty() const { return count_ == 0; }
    bool isFull() const { return count_ >= QUEUE_CAPACITY; }

    /** Discard everything. Used only by the held-button reset. */
    void clear();

private:
    void persist();
    void restore();

    QueuedScan entries_[QUEUE_CAPACITY];
    size_t head_ = 0;
    size_t count_ = 0;

    /**
     * Flash is only written when the queue actually changed, and the whole
     * queue is written as one blob rather than entry by entry: NVS wears with
     * the number of writes, and a gate can generate a lot of them.
     */
    bool dirty_ = false;
};
