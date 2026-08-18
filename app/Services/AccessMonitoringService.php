<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Events\EventDispatcher;
use App\DTO\AccessDecision;
use App\DTO\ScanRequest;
use App\Events\VehicleEntered;
use App\Events\VehicleExited;
use App\Events\AccessDenied;
use App\Repositories\AccessDenialRepository;
use App\Repositories\AccessLogRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\OperatorSessionRepository;
use App\Repositories\RfidCardRepository;
use App\Repositories\RfidTagRepository;
use App\Repositories\VehicleRepository;
use App\Repositories\VisitorLogRepository;
use Throwable;

/**
 * The access decision engine.
 *
 * This is the heart of the system: it turns a tag read into either an official
 * monitoring record or a recorded refusal. Every rule the specification states
 * is applied here, in a fixed order, and the write happens inside a transaction
 * so a partially-recorded movement can never exist.
 *
 * The ordering is deliberate. Cheap checks that need no database work run
 * before expensive ones; checks about the *credential* run before checks about
 * the *vehicle*, so a stolen tag is reported as a tag problem rather than as a
 * vehicle problem; and the duplicate-suppression check runs early, because a
 * long-range reader legitimately reports the same tag several times as a
 * vehicle rolls past the antenna and none of the later work is needed for it.
 *
 * @package App\Services
 * @version 1.0.0
 */
class AccessMonitoringService
{
    public function __construct(
        private readonly AccessLogRepository $accessLogs,
        private readonly AccessDenialRepository $denials,
        private readonly VehicleRepository $vehicles,
        private readonly RfidTagRepository $tags,
        private readonly RfidCardRepository $cards,
        private readonly VisitorLogRepository $visitorLogs,
        private readonly DeviceRepository $devices,
        private readonly OperatorSessionRepository $operators,
        private readonly SecurityEventService $security,
        private readonly AuditService $audit,
        private readonly EventDispatcher $events,
        private readonly Connection $connection
    ) {
    }

    /**
     * Process a scan and return the decision.
     *
     * @param array<string,mixed> $device The authenticated device record.
     */
    public function process(ScanRequest $scan, array $device): AccessDecision
    {
        // Stage 1: the station itself must be permitted to record this kind of
        // movement. An exit-lane reader recording an entry would corrupt the
        // presence figures for everyone.
        if (!$this->devices->permitsAccessType($device, $scan->accessType)) {
            return $this->refuse($scan, 'denied_device', sprintf(
                'This station records %s transactions only.',
                (string) $device['gate_type']
            ));
        }

        // Stage 2: an operator must be on duty, when policy requires one.
        $scan = $this->attachOperator($scan);

        if ($this->requiresOperator() && $scan->operatorSessionId === null) {
            return $this->refuse($scan, 'denied_operator', 'No authenticated operator is on duty at this station.');
        }

        // Stage 3: duplicate suppression, before any lookup work.
        $duplicate = $this->detectDuplicate($scan);
        if ($duplicate !== null) {
            return $duplicate;
        }

        $this->devices->recordScan($scan->deviceId);

        // Stage 4: resolve the credential. A visitor card and a windshield tag
        // are different objects with different rules, so they diverge here.
        $tag = $this->tags->findByUid($scan->rfidUid);

        if ($tag !== null) {
            return $this->processRegisteredVehicle($scan, $tag);
        }

        $card = $this->cards->findByUid($scan->rfidUid);

        if ($card !== null) {
            return $this->processVisitor($scan, $card);
        }

        // Stage 5: the credential is unknown to the system entirely.
        return $this->refuseUnknownTag($scan);
    }

    // ------------------------------------------------------------------
    // Registered vehicles
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $tag
     */
    private function processRegisteredVehicle(ScanRequest $scan, array $tag): AccessDecision
    {
        $tagId = (int) $tag['rfid_tag_id'];
        $this->tags->recordScan($tagId);

        // Credential state first: an expired or revoked tag is a tag problem,
        // and reporting it as a vehicle problem would send the guard chasing
        // the wrong thing.
        $tagFailure = $this->checkTagState($tag);

        if ($tagFailure !== null) {
            return $this->refuse($scan, $tagFailure[0], $tagFailure[1], ['rfid_tag_id' => $tagId]);
        }

        $vehicle = $this->vehicles->findByRfidUid($scan->rfidUid);

        // A registered tag attached to no vehicle is an inventory error, not an
        // access request; it must not silently grant entry.
        if ($vehicle === null || $vehicle['vehicle_id'] === null) {
            return $this->refuse(
                $scan,
                'denied_unknown_tag',
                'This tag is registered but is not attached to any vehicle.',
                ['rfid_tag_id' => $tagId]
            );
        }

        $vehicleId    = (int) $vehicle['vehicle_id'];
        $plateNumber  = (string) $vehicle['plate_number'];
        $vehicleState = $this->checkVehicleState($vehicle);

        if ($vehicleState !== null) {
            return $this->refuse($scan, $vehicleState[0], $vehicleState[1], [
                'rfid_tag_id'  => $tagId,
                'vehicle_id'   => $vehicleId,
                'plate_number' => $plateNumber,
            ]);
        }

        return $scan->accessType === 'entry'
            ? $this->recordEntry($scan, $vehicle, $tagId)
            : $this->recordExit($scan, $vehicle, $tagId);
    }

    /**
     * Create the entry record.
     *
     * @param array<string,mixed> $vehicle
     */
    private function recordEntry(ScanRequest $scan, array $vehicle, int $tagId): AccessDecision
    {
        $vehicleId = (int) $vehicle['vehicle_id'];

        // "A vehicle cannot enter twice without first exiting."
        $openVisit = $this->accessLogs->openVisitForVehicle($vehicleId);

        if ($openVisit !== null) {
            return $this->refuse($scan, 'denied_duplicate_entry', sprintf(
                'This vehicle is already inside; it entered at %s.',
                (string) $openVisit['entry_time']
            ), [
                'vehicle_id'   => $vehicleId,
                'plate_number' => (string) $vehicle['plate_number'],
                'rfid_tag_id'  => $tagId,
                'entered_at'   => (string) $openVisit['entry_time'],
            ]);
        }

        $occurredAt = $scan->occurredAt();
        $reference  = $this->accessLogs->nextReference();

        try {
            $accessLogId = $this->connection->transaction(function () use ($scan, $vehicle, $tagId, $occurredAt, $reference): int {
                $id = $this->accessLogs->create([
                    'transaction_reference' => $reference,
                    'vehicle_id'            => (int) $vehicle['vehicle_id'],
                    'driver_id'             => $vehicle['driver_id'] === null ? null : (int) $vehicle['driver_id'],
                    'rfid_tag_id'           => $tagId,
                    'scanned_uid'           => $scan->rfidUid,
                    'plate_number'          => (string) $vehicle['plate_number'],
                    'entry_device_id'       => $scan->deviceId,
                    'entry_time'            => $occurredAt,
                    'entry_operator_id'     => $scan->operatorUserId,
                    'entry_operator_session_id' => $scan->operatorSessionId,
                    'entry_verification'    => $scan->verificationMethod,
                    'entry_request_id'      => $scan->requestId === '' ? null : $scan->requestId,
                    'access_type'           => 'entry',
                    'status'                => 'inside',
                    'result'                => 'granted',
                    'is_visitor'            => 0,
                    'remarks'               => $scan->remarks,
                ]);

                if ($scan->operatorSessionId !== null) {
                    $this->operators->recordTransaction($scan->operatorSessionId, $this->operatorSessionMinutes());
                }

                return $id;
            });
        } catch (Throwable $e) {
            // The unique index on the open-visit key is the last line of
            // defence against two stations scanning the same vehicle at once.
            // Losing that race is a duplicate entry, not a system fault.
            if ($this->isDuplicateOpenVisit($e)) {
                return $this->refuse($scan, 'denied_duplicate_entry', 'This vehicle is already inside.', [
                    'vehicle_id'   => $vehicleId,
                    'plate_number' => (string) $vehicle['plate_number'],
                ]);
            }

            throw $e;
        }

        $payload = [
            'vehicle_id'   => $vehicleId,
            'plate_number' => (string) $vehicle['plate_number'],
            'owner_name'   => (string) ($vehicle['owner_name'] ?? ''),
            'driver_name'  => (string) ($vehicle['driver_name'] ?? ''),
            'vehicle_type' => (string) ($vehicle['vehicle_type'] ?? ''),
            'entry_time'   => $occurredAt,
            'access_type'  => 'entry',
        ];

        $this->audit->record('monitoring', 'entry', sprintf(
            'Vehicle %s entered through %s.',
            (string) $vehicle['plate_number'],
            $scan->deviceCode
        ), ['record_type' => 'vehicle_access_logs', 'record_id' => $accessLogId]);

        $this->events->dispatch(new VehicleEntered(
            accessLogId: $accessLogId,
            vehicleId: $vehicleId,
            plateNumber: (string) $vehicle['plate_number'],
            ownerName: (string) ($vehicle['owner_name'] ?? ''),
            deviceId: $scan->deviceId,
            deviceCode: $scan->deviceCode,
            occurredAt: $occurredAt
        ));

        return AccessDecision::granted('Access granted.', $payload, $accessLogId, $reference);
    }

    /**
     * Close the open visit.
     *
     * @param array<string,mixed> $vehicle
     */
    private function recordExit(ScanRequest $scan, array $vehicle, int $tagId): AccessDecision
    {
        $vehicleId = (int) $vehicle['vehicle_id'];
        $openVisit = $this->accessLogs->openVisitForVehicle($vehicleId);

        // "A vehicle cannot exit before it enters."
        if ($openVisit === null) {
            return $this->refuse($scan, 'denied_no_active_entry', sprintf(
                'No open entry record exists for %s.',
                (string) $vehicle['plate_number']
            ), [
                'vehicle_id'   => $vehicleId,
                'plate_number' => (string) $vehicle['plate_number'],
                'rfid_tag_id'  => $tagId,
            ]);
        }

        $occurredAt = $scan->occurredAt();
        $entryTime  = strtotime((string) $openVisit['entry_time']);
        $exitTime   = strtotime($occurredAt);
        $stay       = $entryTime === false || $exitTime === false ? 0 : $exitTime - $entryTime;

        // A single pass of a vehicle past two antennas can otherwise produce an
        // entry and an exit seconds apart.
        $minimumStay = (int) config('monitoring.rules.minimum_stay_seconds', 15);

        if ($minimumStay > 0 && $stay < $minimumStay) {
            return $this->refuse($scan, 'denied_minimum_stay', sprintf(
                'This vehicle entered %d second(s) ago; an exit is not accepted within %d seconds of entry.',
                max(0, $stay),
                $minimumStay
            ), [
                'vehicle_id'    => $vehicleId,
                'plate_number'  => (string) $vehicle['plate_number'],
                'entered_at'    => (string) $openVisit['entry_time'],
                'stay_seconds'  => max(0, $stay),
            ]);
        }

        $accessLogId = (int) $openVisit['access_log_id'];

        $this->connection->transaction(function () use ($scan, $accessLogId, $occurredAt): void {
            $updated = $this->accessLogs->recordExit($accessLogId, [
                'exit_device_id'           => $scan->deviceId,
                'exit_time'                => $occurredAt,
                'exit_operator_id'         => $scan->operatorUserId,
                'exit_operator_session_id' => $scan->operatorSessionId,
                'exit_verification'        => $scan->verificationMethod,
                'exit_request_id'          => $scan->requestId === '' ? null : $scan->requestId,
            ]);

            // The WHERE clause requires status 'inside', so zero rows means
            // another request closed this visit first.
            if ($updated === 0) {
                throw new \RuntimeException('The visit was closed by another request.');
            }

            if ($scan->operatorSessionId !== null) {
                $this->operators->recordTransaction($scan->operatorSessionId, $this->operatorSessionMinutes());
            }
        });

        $payload = [
            'vehicle_id'       => $vehicleId,
            'plate_number'     => (string) $vehicle['plate_number'],
            'owner_name'       => (string) ($vehicle['owner_name'] ?? ''),
            'driver_name'      => (string) ($vehicle['driver_name'] ?? ''),
            'entry_time'       => (string) $openVisit['entry_time'],
            'exit_time'        => $occurredAt,
            'duration_seconds' => $stay,
            'duration'         => \App\Core\Support\Str::duration($stay),
            'access_type'      => 'exit',
        ];

        $this->audit->record('monitoring', 'exit', sprintf(
            'Vehicle %s exited through %s after %s.',
            (string) $vehicle['plate_number'],
            $scan->deviceCode,
            \App\Core\Support\Str::duration($stay)
        ), ['record_type' => 'vehicle_access_logs', 'record_id' => $accessLogId]);

        $this->events->dispatch(new VehicleExited(
            accessLogId: $accessLogId,
            vehicleId: $vehicleId,
            plateNumber: (string) $vehicle['plate_number'],
            ownerName: (string) ($vehicle['owner_name'] ?? ''),
            deviceId: $scan->deviceId,
            deviceCode: $scan->deviceCode,
            occurredAt: $occurredAt,
            durationSeconds: $stay
        ));

        return AccessDecision::granted(
            'Exit recorded. Thank you.',
            $payload,
            $accessLogId,
            (string) $openVisit['transaction_reference']
        );
    }

    // ------------------------------------------------------------------
    // Visitors
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $card
     */
    private function processVisitor(ScanRequest $scan, array $card): AccessDecision
    {
        $cardId = (int) $card['rfid_card_id'];
        $this->cards->recordScan($cardId);

        if (in_array((string) $card['status'], ['lost', 'damaged', 'retired', 'inactive'], true)) {
            return $this->refuse($scan, 'denied_inactive_tag', sprintf(
                'This visitor card is marked %s.',
                (string) $card['status']
            ), ['rfid_card_id' => $cardId]);
        }

        $pass = $this->visitorLogs->openPassForCard($cardId);

        // A card with no open pass has been presented by somebody who was never
        // issued it, or after their visit closed.
        if ($pass === null) {
            return $this->refuse(
                $scan,
                'denied_visitor_expired',
                'This visitor card has not been issued for a current visit.',
                ['rfid_card_id' => $cardId, 'card_code' => (string) $card['card_code']]
            );
        }

        $visitorLogId = (int) $pass['visitor_log_id'];

        if ((int) ($pass['is_blacklisted'] ?? 0) === 1) {
            return $this->refuse($scan, 'denied_business_rule', 'This visitor is barred from entry.', [
                'rfid_card_id'   => $cardId,
                'visitor_log_id' => $visitorLogId,
                'visitor_name'   => (string) ($pass['visitor_name'] ?? ''),
            ]);
        }

        $validUntil = strtotime((string) $pass['valid_until']);
        $validFrom  = strtotime((string) $pass['valid_from']);

        if ($validUntil !== false && $validUntil < time()) {
            return $this->refuse($scan, 'denied_visitor_expired', sprintf(
                'This visitor pass expired at %s.',
                (string) $pass['valid_until']
            ), ['rfid_card_id' => $cardId, 'visitor_log_id' => $visitorLogId]);
        }

        if ($validFrom !== false && $validFrom > time()) {
            return $this->refuse($scan, 'denied_visitor_expired', sprintf(
                'This visitor pass is not valid until %s.',
                (string) $pass['valid_from']
            ), ['rfid_card_id' => $cardId, 'visitor_log_id' => $visitorLogId]);
        }

        return $scan->accessType === 'entry'
            ? $this->recordVisitorEntry($scan, $pass, $cardId)
            : $this->recordVisitorExit($scan, $pass, $cardId);
    }

    /**
     * @param array<string,mixed> $pass
     */
    private function recordVisitorEntry(ScanRequest $scan, array $pass, int $cardId): AccessDecision
    {
        $visitorLogId = (int) $pass['visitor_log_id'];

        if ($this->accessLogs->openVisitForVisitorLog($visitorLogId) !== null) {
            return $this->refuse($scan, 'denied_duplicate_entry', 'This visitor is already inside.', [
                'visitor_log_id' => $visitorLogId,
                'visitor_name'   => (string) ($pass['visitor_name'] ?? ''),
            ]);
        }

        $occurredAt = $scan->occurredAt();
        $reference  = $this->accessLogs->nextReference();

        $accessLogId = $this->connection->transaction(function () use ($scan, $pass, $cardId, $occurredAt, $reference, $visitorLogId): int {
            $id = $this->accessLogs->create([
                'transaction_reference' => $reference,
                'visitor_log_id'        => $visitorLogId,
                'rfid_card_id'          => $cardId,
                'scanned_uid'           => $scan->rfidUid,
                'plate_number'          => $pass['vehicle_plate'] ?? null,
                'entry_device_id'       => $scan->deviceId,
                'entry_time'            => $occurredAt,
                'entry_operator_id'     => $scan->operatorUserId,
                'entry_operator_session_id' => $scan->operatorSessionId,
                'entry_verification'    => 'visitor_card',
                'entry_request_id'      => $scan->requestId === '' ? null : $scan->requestId,
                'access_type'           => 'entry',
                'status'                => 'inside',
                'result'                => 'granted',
                'is_visitor'            => 1,
                'remarks'               => $scan->remarks,
            ]);

            $this->visitorLogs->markEntered($visitorLogId, $occurredAt);

            if ($scan->operatorSessionId !== null) {
                $this->operators->recordTransaction($scan->operatorSessionId, $this->operatorSessionMinutes());
            }

            return $id;
        });

        $payload = [
            'visitor_log_id' => $visitorLogId,
            'visitor_name'   => (string) ($pass['visitor_name'] ?? ''),
            'purpose'        => (string) ($pass['purpose'] ?? ''),
            'plate_number'   => (string) ($pass['vehicle_plate'] ?? ''),
            'valid_until'    => (string) $pass['valid_until'],
            'entry_time'     => $occurredAt,
            'access_type'    => 'entry',
            'is_visitor'     => true,
        ];

        $this->audit->record('monitoring', 'entry', sprintf(
            'Visitor %s entered through %s.',
            (string) ($pass['visitor_name'] ?? 'unknown'),
            $scan->deviceCode
        ), ['record_type' => 'vehicle_access_logs', 'record_id' => $accessLogId]);

        $this->events->dispatch(new VehicleEntered(
            accessLogId: $accessLogId,
            vehicleId: null,
            plateNumber: (string) ($pass['vehicle_plate'] ?? ''),
            ownerName: (string) ($pass['visitor_name'] ?? ''),
            deviceId: $scan->deviceId,
            deviceCode: $scan->deviceCode,
            occurredAt: $occurredAt,
            isVisitor: true
        ));

        return AccessDecision::granted('Access granted. Welcome.', $payload, $accessLogId, $reference);
    }

    /**
     * @param array<string,mixed> $pass
     */
    private function recordVisitorExit(ScanRequest $scan, array $pass, int $cardId): AccessDecision
    {
        $visitorLogId = (int) $pass['visitor_log_id'];
        $openVisit    = $this->accessLogs->openVisitForVisitorLog($visitorLogId);

        if ($openVisit === null) {
            return $this->refuse($scan, 'denied_no_active_entry', 'No open entry record exists for this visitor.', [
                'visitor_log_id' => $visitorLogId,
                'rfid_card_id'   => $cardId,
            ]);
        }

        $occurredAt  = $scan->occurredAt();
        $entryTime   = strtotime((string) $openVisit['entry_time']);
        $exitTime    = strtotime($occurredAt);
        $stay        = $entryTime === false || $exitTime === false ? 0 : $exitTime - $entryTime;
        $accessLogId = (int) $openVisit['access_log_id'];

        $this->connection->transaction(function () use ($scan, $accessLogId, $occurredAt, $visitorLogId, $cardId): void {
            $this->accessLogs->recordExit($accessLogId, [
                'exit_device_id'           => $scan->deviceId,
                'exit_time'                => $occurredAt,
                'exit_operator_id'         => $scan->operatorUserId,
                'exit_operator_session_id' => $scan->operatorSessionId,
                'exit_verification'        => 'visitor_card',
                'exit_request_id'          => $scan->requestId === '' ? null : $scan->requestId,
            ]);

            $this->visitorLogs->markExited($visitorLogId, $occurredAt);

            // The card returns to the pool the moment the visit closes, so it
            // can be issued to the next visitor without a manual step.
            $this->cards->markReturned($cardId);

            if ($scan->operatorSessionId !== null) {
                $this->operators->recordTransaction($scan->operatorSessionId, $this->operatorSessionMinutes());
            }
        });

        $payload = [
            'visitor_log_id'   => $visitorLogId,
            'visitor_name'     => (string) ($pass['visitor_name'] ?? ''),
            'entry_time'       => (string) $openVisit['entry_time'],
            'exit_time'        => $occurredAt,
            'duration_seconds' => $stay,
            'duration'         => \App\Core\Support\Str::duration($stay),
            'access_type'      => 'exit',
            'is_visitor'       => true,
        ];

        $this->audit->record('monitoring', 'exit', sprintf(
            'Visitor %s exited through %s; the card was returned to the pool.',
            (string) ($pass['visitor_name'] ?? 'unknown'),
            $scan->deviceCode
        ), ['record_type' => 'vehicle_access_logs', 'record_id' => $accessLogId]);

        $this->events->dispatch(new VehicleExited(
            accessLogId: $accessLogId,
            vehicleId: null,
            plateNumber: (string) ($pass['vehicle_plate'] ?? ''),
            ownerName: (string) ($pass['visitor_name'] ?? ''),
            deviceId: $scan->deviceId,
            deviceCode: $scan->deviceCode,
            occurredAt: $occurredAt,
            durationSeconds: $stay,
            isVisitor: true
        ));

        return AccessDecision::granted(
            'Exit recorded. Thank you.',
            $payload,
            $accessLogId,
            (string) $openVisit['transaction_reference']
        );
    }

    // ------------------------------------------------------------------
    // Rule checks
    // ------------------------------------------------------------------

    /**
     * Reasons a tag may not be used, or null when it is fine.
     *
     * @param array<string,mixed> $tag
     *
     * @return array{0:string,1:string}|null
     */
    private function checkTagState(array $tag): ?array
    {
        $status = (string) $tag['status'];

        if ($status === 'lost') {
            return ['denied_lost_tag', 'This tag has been reported lost and cannot be used.'];
        }

        if (in_array($status, ['inactive', 'damaged', 'revoked'], true)
            && (bool) config('monitoring.rules.reject_inactive_tag', true)) {
            return ['denied_inactive_tag', sprintf('This tag is marked %s.', $status)];
        }

        if ((bool) config('monitoring.rules.reject_expired_tag', true)) {
            $expiration = $tag['expiration_date'] ?? null;

            if ($status === 'expired') {
                return ['denied_expired_tag', 'This tag has expired.'];
            }

            if ($expiration !== null) {
                $expiresAt = strtotime((string) $expiration . ' 23:59:59');

                if ($expiresAt !== false && $expiresAt < time()) {
                    return ['denied_expired_tag', sprintf('This tag expired on %s.', (string) $expiration)];
                }
            }
        }

        return null;
    }

    /**
     * Reasons a vehicle may not move, or null when it is fine.
     *
     * @param array<string,mixed> $vehicle
     *
     * @return array{0:string,1:string}|null
     */
    private function checkVehicleState(array $vehicle): ?array
    {
        $status = (string) ($vehicle['status'] ?? 'active');

        if ($status === 'suspended') {
            return ['denied_suspended_vehicle', 'This vehicle is suspended from entering the premises.'];
        }

        if ($status !== 'active' && (bool) config('monitoring.rules.reject_inactive_vehicle', true)) {
            return ['denied_inactive_vehicle', sprintf('This vehicle is %s.', $status)];
        }

        if ((bool) config('monitoring.rules.require_driver_assignment', false)
            && ($vehicle['driver_id'] ?? null) === null) {
            return ['denied_business_rule', 'This vehicle has no authorised driver assigned.'];
        }

        if (($vehicle['driver_status'] ?? 'active') === 'suspended') {
            return ['denied_business_rule', 'The driver assigned to this vehicle is suspended.'];
        }

        return null;
    }

    /**
     * Detect a repeated read of the same tag within the debounce window.
     */
    private function detectDuplicate(ScanRequest $scan): ?AccessDecision
    {
        // A queued scan replayed after an outage is deliberately exempt: it may
        // legitimately be minutes old and must not be mistaken for an echo.
        if ($scan->isQueuedReplay()) {
            return null;
        }

        $window = (int) config('monitoring.rules.duplicate_scan_window_seconds', 10);

        if ($window <= 0) {
            return null;
        }

        $recent = $this->accessLogs->lastMovementForUid($scan->rfidUid, $window);

        if ($recent === null || (string) $recent['access_type'] !== $scan->accessType) {
            return null;
        }

        return AccessDecision::duplicateSuppressed(
            'This tag was already processed a moment ago.',
            [
                'plate_number' => (string) ($recent['plate_number'] ?? ''),
                'access_type'  => $scan->accessType,
            ],
            (int) $recent['access_log_id'],
            (string) $recent['transaction_reference']
        );
    }

    /**
     * Attach the operator currently on duty at the station.
     */
    private function attachOperator(ScanRequest $scan): ScanRequest
    {
        $session = $this->operators->activeForDevice($scan->deviceId);

        if ($session === null) {
            return $scan;
        }

        return $scan->withOperator(
            (int) $session['user_id'],
            (int) $session['operator_session_id']
        );
    }

    private function requiresOperator(): bool
    {
        return (bool) config('monitoring.rules.require_operator_authentication', true);
    }

    private function operatorSessionMinutes(): int
    {
        return (int) config('monitoring.rules.operator_session_minutes', 60);
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    /**
     * Record a refusal and build the decision.
     *
     * @param array<string,mixed> $context
     */
    private function refuse(ScanRequest $scan, string $reasonCode, string $message, array $context = []): AccessDecision
    {
        $securityEventId = null;

        // A refusal that indicates a security concern, rather than an ordinary
        // operational one, additionally raises an event.
        if (in_array($reasonCode, ['denied_lost_tag', 'denied_suspended_vehicle', 'denied_business_rule'], true)) {
            $securityEventId = $this->security->record(
                'inactive_vehicle_scan',
                sprintf('%s at %s: %s', ucfirst(str_replace('_', ' ', $reasonCode)), $scan->deviceCode, $message),
                array_merge($context, ['rfid_uid' => $scan->rfidUid, 'device_code' => $scan->deviceCode]),
                'rejected'
            );
        } elseif ($reasonCode === 'denied_expired_tag') {
            $securityEventId = $this->security->record(
                'expired_rfid',
                sprintf('An expired tag was presented at %s.', $scan->deviceCode),
                array_merge($context, ['rfid_uid' => $scan->rfidUid]),
                'rejected'
            );
        }

        $this->denials->create([
            'device_id'         => $scan->deviceId,
            'scanned_uid'       => $scan->rfidUid,
            'attempted_type'    => $scan->accessType,
            'reason_code'       => $reasonCode,
            'reason'            => mb_substr($message, 0, 255),
            'vehicle_id'        => $context['vehicle_id'] ?? null,
            'rfid_tag_id'       => $context['rfid_tag_id'] ?? null,
            'rfid_card_id'      => $context['rfid_card_id'] ?? null,
            'visitor_log_id'    => $context['visitor_log_id'] ?? null,
            'plate_number'      => $context['plate_number'] ?? null,
            'operator_id'       => $scan->operatorUserId,
            'ip_address'        => $scan->ipAddress === '' ? null : $scan->ipAddress,
            'request_id'        => $scan->requestId === '' ? null : $scan->requestId,
            'security_event_id' => $securityEventId,
            'occurred_at'       => $scan->occurredAt(),
        ]);

        $this->audit->failed('monitoring', $scan->accessType, sprintf(
            'A %s scan at %s was refused: %s',
            $scan->accessType,
            $scan->deviceCode,
            $message
        ));

        $this->events->dispatch(new AccessDenied(
            reasonCode: $reasonCode,
            message: $message,
            rfidUid: $scan->rfidUid,
            deviceId: $scan->deviceId,
            deviceCode: $scan->deviceCode,
            plateNumber: isset($context['plate_number']) ? (string) $context['plate_number'] : null
        ));

        return AccessDecision::denied($reasonCode, $message, $context);
    }

    /**
     * Handle a credential the system has never seen.
     */
    private function refuseUnknownTag(ScanRequest $scan): AccessDecision
    {
        $message = 'This tag is not registered. The vehicle is not authorised.';

        // Repeated presentation of the same unknown tag is a different concern
        // from a single stray read, and is escalated accordingly.
        $repeats = $this->denials->countRecentForUid($scan->rfidUid, 300);

        $securityEventId = $this->security->record(
            'unknown_rfid',
            $repeats >= 3
                ? sprintf('An unregistered tag (%s) has been presented %d times at %s in five minutes.', $scan->rfidUid, $repeats + 1, $scan->deviceCode)
                : sprintf('An unregistered tag (%s) was presented at %s.', $scan->rfidUid, $scan->deviceCode),
            ['rfid_uid' => $scan->rfidUid, 'device_code' => $scan->deviceCode, 'repeats' => $repeats + 1],
            'rejected',
            $repeats >= 3 ? 'critical' : 'high'
        );

        $this->denials->create([
            'device_id'         => $scan->deviceId,
            'scanned_uid'       => $scan->rfidUid,
            'attempted_type'    => $scan->accessType,
            'reason_code'       => 'denied_unknown_tag',
            'reason'            => $message,
            'operator_id'       => $scan->operatorUserId,
            'ip_address'        => $scan->ipAddress === '' ? null : $scan->ipAddress,
            'request_id'        => $scan->requestId === '' ? null : $scan->requestId,
            'security_event_id' => $securityEventId,
            'occurred_at'       => $scan->occurredAt(),
        ]);

        $this->events->dispatch(new AccessDenied(
            reasonCode: 'denied_unknown_tag',
            message: $message,
            rfidUid: $scan->rfidUid,
            deviceId: $scan->deviceId,
            deviceCode: $scan->deviceCode
        ));

        return AccessDecision::denied('denied_unknown_tag', $message, ['rfid_uid' => $scan->rfidUid]);
    }

    /**
     * Whether a throwable is the open-visit uniqueness violation.
     */
    private function isDuplicateOpenVisit(Throwable $e): bool
    {
        $message = $e->getMessage();

        if ($e instanceof \App\Exceptions\DatabaseException) {
            $message .= ' ' . (string) ($e->context()['driver_message'] ?? '');
        }

        return str_contains($message, 'uq_access_logs_open_vehicle')
            || str_contains($message, 'uq_access_logs_open_visitor');
    }

    // ------------------------------------------------------------------
    // Administrative operations
    // ------------------------------------------------------------------

    /**
     * Close a visit that never received an exit scan.
     */
    public function forceClose(int $accessLogId, int $closedBy, string $reason, ?string $exitTime = null): void
    {
        $record = $this->accessLogs->findOrFail($accessLogId);

        if ((string) $record['status'] !== 'inside') {
            throw \App\Exceptions\BusinessRuleException::withCode(
                'VISIT_NOT_OPEN',
                'This visit is already closed.'
            );
        }

        $exitTime ??= now()->format('Y-m-d H:i:s');

        $this->connection->transaction(function () use ($accessLogId, $exitTime, $closedBy, $reason, $record): void {
            $this->accessLogs->forceClose($accessLogId, $exitTime, $closedBy, $reason);

            if ($record['visitor_log_id'] !== null) {
                $this->visitorLogs->markExited((int) $record['visitor_log_id'], $exitTime);

                if ($record['rfid_card_id'] !== null) {
                    $this->cards->markReturned((int) $record['rfid_card_id']);
                }
            }
        });

        $this->audit->record('monitoring', 'force_close', sprintf(
            'Visit %s was closed administratively: %s',
            (string) $record['transaction_reference'],
            $reason
        ), ['record_type' => 'vehicle_access_logs', 'record_id' => $accessLogId]);
    }

    /**
     * Attach an administrative note beside a monitoring record.
     */
    public function annotate(int $accessLogId, string $annotation, int $annotatedBy): void
    {
        $record = $this->accessLogs->findOrFail($accessLogId);

        $this->accessLogs->annotate($accessLogId, $annotation, $annotatedBy);

        $this->audit->record('monitoring', 'annotated', sprintf(
            'An annotation was added to monitoring record %s.',
            (string) $record['transaction_reference']
        ), ['record_type' => 'vehicle_access_logs', 'record_id' => $accessLogId]);
    }
}
