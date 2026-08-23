#include "ScanQueue.h"

#include <Preferences.h>

#include "Logger.h"

static const char* TAG = "queue";
static const char* NAMESPACE = "vams";
static const char* BLOB_KEY = "scanq";

// A version byte in front of the blob, so firmware that changes the entry
// layout discards an old queue rather than reading it as garbage.
static const uint8_t BLOB_VERSION = 1;

struct QueueBlob {
    uint8_t version;
    uint16_t count;
    QueuedScan entries[QUEUE_CAPACITY];
};

void ScanQueue::begin() {
    restore();

    if (count_ > 0) {
        Logger::info(TAG, String(count_) + " scan(s) recovered from the previous run");
    }
}

bool ScanQueue::enqueue(const String& uid, const String& occurredAt, const String& accessType) {
    if (isFull()) {
        Logger::error(TAG, "queue is full; the scan of " + uid + " could not be held");

        return false;
    }

    size_t slot = (head_ + count_) % QUEUE_CAPACITY;

    QueuedScan& entry = entries_[slot];

    strncpy(entry.uid, uid.c_str(), sizeof(entry.uid) - 1);
    entry.uid[sizeof(entry.uid) - 1] = '\0';

    strncpy(entry.occurredAt, occurredAt.c_str(), sizeof(entry.occurredAt) - 1);
    entry.occurredAt[sizeof(entry.occurredAt) - 1] = '\0';

    strncpy(entry.accessType, accessType.c_str(), sizeof(entry.accessType) - 1);
    entry.accessType[sizeof(entry.accessType) - 1] = '\0';

    entry.attempts = 0;

    count_++;
    dirty_ = true;
    persist();

    Logger::info(TAG, "held " + uid + " for later; " + String(count_) + " waiting");

    return true;
}

bool ScanQueue::peek(QueuedScan& scan) const {
    if (count_ == 0) {
        return false;
    }

    scan = entries_[head_];

    return true;
}

void ScanQueue::pop() {
    if (count_ == 0) {
        return;
    }

    head_ = (head_ + 1) % QUEUE_CAPACITY;
    count_--;
    dirty_ = true;
    persist();
}

void ScanQueue::recordAttempt() {
    if (count_ == 0) {
        return;
    }

    // Saturating: the counter is only there to inform the operator, and
    // wrapping it would make a stuck entry look fresh.
    if (entries_[head_].attempts < 255) {
        entries_[head_].attempts++;
        dirty_ = true;
    }
}

void ScanQueue::clear() {
    head_ = 0;
    count_ = 0;
    dirty_ = true;
    persist();

    Logger::warning(TAG, "queue cleared");
}

void ScanQueue::persist() {
    if (!dirty_) {
        return;
    }

    Preferences preferences;

    if (!preferences.begin(NAMESPACE, false)) {
        Logger::error(TAG, "could not open storage; the queue is held in RAM only");

        return;
    }

    QueueBlob blob;
    blob.version = BLOB_VERSION;
    blob.count = static_cast<uint16_t>(count_);

    // Written from the head so the blob is always in order and the reader
    // does not need to know where the ring wrapped.
    for (size_t i = 0; i < count_; i++) {
        blob.entries[i] = entries_[(head_ + i) % QUEUE_CAPACITY];
    }

    size_t used = sizeof(uint8_t) + sizeof(uint16_t) + (count_ * sizeof(QueuedScan));

    preferences.putBytes(BLOB_KEY, &blob, used);
    preferences.end();

    dirty_ = false;
}

void ScanQueue::restore() {
    Preferences preferences;

    if (!preferences.begin(NAMESPACE, true)) {
        return;   // nothing stored yet, which is the normal first boot
    }

    size_t stored = preferences.getBytesLength(BLOB_KEY);

    if (stored < sizeof(uint8_t) + sizeof(uint16_t) || stored > sizeof(QueueBlob)) {
        preferences.end();

        return;
    }

    QueueBlob blob;
    preferences.getBytes(BLOB_KEY, &blob, stored);
    preferences.end();

    if (blob.version != BLOB_VERSION) {
        Logger::warning(TAG, "stored queue is from an older firmware; discarded");

        return;
    }

    size_t recovered = blob.count;

    if (recovered > QUEUE_CAPACITY) {
        recovered = QUEUE_CAPACITY;
    }

    for (size_t i = 0; i < recovered; i++) {
        entries_[i] = blob.entries[i];
    }

    head_ = 0;
    count_ = recovered;
}
