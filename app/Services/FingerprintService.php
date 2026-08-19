<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Database\Paginator;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Repositories\DeviceRepository;
use App\Repositories\FingerprintRepository;
use App\Repositories\FingerprintVerificationRepository;
use App\Repositories\OperatorSessionRepository;
use App\Repositories\UserRepository;

/**
 * Biometric enrolment and verification.
 *
 * The system never receives, stores, or transmits a fingerprint image or a
 * reconstructable template. The sensor performs the match on-device and reports
 * only which slot matched and how confidently; this service records that
 * outcome and decides what it means.
 *
 * @package App\Services
 * @version 1.0.0
 */
class FingerprintService
{
    public function __construct(
        private readonly FingerprintRepository $templates,
        private readonly FingerprintVerificationRepository $verifications,
        private readonly OperatorSessionRepository $operators,
        private readonly UserRepository $users,
        private readonly DeviceRepository $devices,
        private readonly AuditService $audit,
        private readonly SecurityEventService $security,
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        return $this->templates->paginate($filters, $options);
    }

    /**
     * Record an enrolment performed on a sensor.
     *
     * @param array<string,mixed> $attributes device_id, sensor_slot, finger_label,
     *                                        assigned_user_id | assigned_driver_id,
     *                                        checksum, quality_score
     *
     * @throws BusinessRuleException
     */
    public function enrol(array $attributes, ?int $actorId): int
    {
        $deviceId = (int) ($attributes['device_id'] ?? 0);
        $device   = $this->devices->find($deviceId);

        if ($device === null) {
            throw NotFoundException::record('Device', $deviceId);
        }

        $userId   = isset($attributes['assigned_user_id']) && $attributes['assigned_user_id'] !== ''
            ? (int) $attributes['assigned_user_id'] : null;
        $driverId = isset($attributes['assigned_driver_id']) && $attributes['assigned_driver_id'] !== ''
            ? (int) $attributes['assigned_driver_id'] : null;

        if ($userId === null && $driverId === null) {
            throw BusinessRuleException::withCode(
                'HOLDER_REQUIRED',
                'An enrolment must belong to either a system user or a driver.'
            );
        }

        if ($userId !== null && $driverId !== null) {
            throw BusinessRuleException::withCode(
                'AMBIGUOUS_HOLDER',
                'An enrolment belongs to one person; it cannot be assigned to both a user and a driver.'
            );
        }

        $slot = isset($attributes['sensor_slot']) && (int) $attributes['sensor_slot'] > 0
            ? (int) $attributes['sensor_slot']
            : $this->templates->nextAvailableSlot($deviceId);

        if ($slot === 0) {
            throw BusinessRuleException::withCode(
                'SENSOR_FULL',
                'This fingerprint sensor has no free storage slots. Remove an unused enrolment first.'
            );
        }

        // A slot already in use would silently overwrite somebody else's
        // enrolment on the hardware while the database still pointed at them.
        if ($this->templates->findBySlot($deviceId, $slot) !== null) {
            throw BusinessRuleException::withCode(
                'SLOT_OCCUPIED',
                sprintf('Slot %d on this sensor already holds an enrolment.', $slot)
            );
        }

        $templateNumber = $this->templates->nextTemplateNumber();

        $templateId = $this->connection->transaction(function () use ($attributes, $deviceId, $slot, $userId, $driverId, $templateNumber, $actorId): int {
            $id = $this->templates->create([
                'template_number'    => $templateNumber,
                'device_id'          => $deviceId,
                'sensor_slot'        => $slot,
                'finger_label'       => $attributes['finger_label'] ?? null,
                'assigned_user_id'   => $userId,
                'assigned_driver_id' => $driverId,
                // A one-way digest of what the sensor reported. It cannot be
                // turned back into biometric data; it exists so the system can
                // notice that a slot has been reprogrammed.
                'checksum'           => isset($attributes['checksum'])
                    ? hash('sha256', (string) $attributes['checksum'])
                    : null,
                'quality_score'      => isset($attributes['quality_score']) ? (int) $attributes['quality_score'] : null,
                'enrolled_at'        => now()->format('Y-m-d H:i:s'),
                'enrolled_by'        => $actorId,
                'synchronised_at'    => now()->format('Y-m-d H:i:s'),
                'status'             => 'active',
                'remarks'            => $attributes['remarks'] ?? null,
            ]);

            // The primary-enrolment shortcut lets station authentication find
            // the template without scanning the whole table.
            if ($userId !== null) {
                $this->connection->execute(
                    'UPDATE `users` SET `fingerprint_template_id` = :template WHERE `user_id` = :user AND `fingerprint_template_id` IS NULL',
                    ['template' => $id, 'user' => $userId]
                );
            }

            if ($driverId !== null) {
                $this->connection->execute(
                    'UPDATE `drivers` SET `fingerprint_template_id` = :template WHERE `driver_id` = :driver AND `fingerprint_template_id` IS NULL',
                    ['template' => $id, 'driver' => $driverId]
                );
            }

            return $id;
        });

        $this->audit->created('fingerprints', 'fingerprint_templates', $templateId, sprintf(
            'Enrolment %s was recorded in slot %d on %s.',
            $templateNumber,
            $slot,
            (string) $device['device_code']
        ), ['template_number' => $templateNumber, 'sensor_slot' => $slot]);

        return $templateId;
    }

    /**
     * Record the outcome of a verification the sensor performed.
     *
     * @return array{successful:bool,template:array<string,mixed>|null,user:array<string,mixed>|null,operator_session_id:int|null,message:string}
     */
    public function recordVerification(
        int $deviceId,
        string $deviceCode,
        ?int $sensorSlot,
        bool $matched,
        ?int $matchScore,
        string $purpose = 'operator_login'
    ): array {
        $template = $matched && $sensorSlot !== null
            ? $this->templates->findBySlot($deviceId, $sensorSlot)
            : null;

        // The sensor says it matched a slot the server does not know about.
        // That means the hardware and the register have diverged, which is a
        // security concern rather than a simple mismatch.
        if ($matched && $template === null) {
            $this->verifications->create([
                'device_id'      => $deviceId,
                'sensor_slot'    => $sensorSlot,
                'purpose'        => $purpose,
                'successful'     => 0,
                'match_score'    => $matchScore,
                'failure_reason' => 'unknown_slot',
                'verified_at'    => now()->format('Y-m-d H:i:s'),
            ]);

            $this->security->record(
                'fingerprint_failure',
                sprintf(
                    'The sensor at %s reported a match in slot %s, which holds no enrolment on record. The hardware and the register have diverged.',
                    $deviceCode,
                    $sensorSlot === null ? 'unknown' : (string) $sensorSlot
                ),
                ['device_code' => $deviceCode, 'sensor_slot' => $sensorSlot],
                'rejected',
                'critical'
            );

            return [
                'successful'          => false,
                'template'            => null,
                'user'                => null,
                'operator_session_id' => null,
                'message'             => 'This enrolment is not recognised by the server. Contact an administrator.',
            ];
        }

        if (!$matched) {
            $this->verifications->create([
                'device_id'      => $deviceId,
                'sensor_slot'    => $sensorSlot,
                'purpose'        => $purpose,
                'successful'     => 0,
                'match_score'    => $matchScore,
                'failure_reason' => 'no_match',
                'verified_at'    => now()->format('Y-m-d H:i:s'),
            ]);

            $this->escalateRepeatedFailures($deviceId, $deviceCode);

            return [
                'successful'          => false,
                'template'            => null,
                'user'                => null,
                'operator_session_id' => null,
                'message'             => 'Fingerprint not recognised. Please try again.',
            ];
        }

        /** @var array<string,mixed> $template */
        $templateId = (int) $template['template_id'];
        $userId     = $template['assigned_user_id'] === null ? null : (int) $template['assigned_user_id'];

        if ((string) $template['status'] !== 'active') {
            $this->verifications->create([
                'device_id'      => $deviceId,
                'template_id'    => $templateId,
                'user_id'        => $userId,
                'sensor_slot'    => $sensorSlot,
                'purpose'        => $purpose,
                'successful'     => 0,
                'match_score'    => $matchScore,
                'failure_reason' => 'template_' . (string) $template['status'],
                'verified_at'    => now()->format('Y-m-d H:i:s'),
            ]);

            return [
                'successful'          => false,
                'template'            => $template,
                'user'                => null,
                'operator_session_id' => null,
                'message'             => 'This enrolment is no longer active.',
            ];
        }

        $user = $userId === null ? null : $this->users->findWithRole($userId);

        // A guard whose account has been deactivated must not be able to open a
        // shift, however good their fingerprint is.
        if ($purpose === 'operator_login') {
            $refusal = $this->checkOperatorEligibility($user);

            if ($refusal !== null) {
                $this->verifications->create([
                    'device_id'      => $deviceId,
                    'template_id'    => $templateId,
                    'user_id'        => $userId,
                    'sensor_slot'    => $sensorSlot,
                    'purpose'        => $purpose,
                    'successful'     => 0,
                    'match_score'    => $matchScore,
                    'failure_reason' => $refusal[0],
                    'verified_at'    => now()->format('Y-m-d H:i:s'),
                ]);

                $this->security->record(
                    'fingerprint_failure',
                    sprintf('An operator sign-in at %s was refused: %s', $deviceCode, $refusal[1]),
                    ['device_code' => $deviceCode, 'user_id' => $userId, 'reason' => $refusal[0]],
                    'rejected'
                );

                return [
                    'successful'          => false,
                    'template'            => $template,
                    'user'                => $user,
                    'operator_session_id' => null,
                    'message'             => $refusal[1],
                ];
            }
        }

        $operatorSessionId = null;

        $this->connection->transaction(function () use ($deviceId, $templateId, $userId, $template, $sensorSlot, $purpose, $matchScore, &$operatorSessionId): void {
            $this->verifications->create([
                'device_id'   => $deviceId,
                'template_id' => $templateId,
                'user_id'     => $userId,
                'driver_id'   => $template['assigned_driver_id'] === null ? null : (int) $template['assigned_driver_id'],
                'sensor_slot' => $sensorSlot,
                'purpose'     => $purpose,
                'successful'  => 1,
                'match_score' => $matchScore,
                'verified_at' => now()->format('Y-m-d H:i:s'),
            ]);

            $this->templates->recordVerification($templateId, true);

            if ($purpose === 'operator_login' && $userId !== null) {
                $operatorSessionId = $this->operators->open(
                    $deviceId,
                    $userId,
                    $templateId,
                    (int) config('monitoring.rules.operator_session_minutes', 60),
                    $matchScore
                );
            }
        });

        if ($operatorSessionId !== null && $user !== null) {
            $this->audit->record('fingerprints', 'operator_signin', sprintf(
                '%s signed in at %s by fingerprint.',
                (string) $user['full_name'],
                $deviceCode
            ), ['record_type' => 'operator_sessions', 'record_id' => $operatorSessionId]);
        }

        return [
            'successful'          => true,
            'template'            => $template,
            'user'                => $user,
            'operator_session_id' => $operatorSessionId,
            'message'             => $user === null
                ? 'Fingerprint verified.'
                : sprintf('Welcome, %s. Monitoring mode is active.', (string) $user['full_name']),
        ];
    }

    /**
     * Reasons a matched user may not open a shift.
     *
     * @param array<string,mixed>|null $user
     *
     * @return array{0:string,1:string}|null
     */
    private function checkOperatorEligibility(?array $user): ?array
    {
        if ($user === null) {
            return ['no_user', 'This enrolment is not linked to a system account.'];
        }

        if ((string) $user['status'] !== 'active') {
            return ['account_inactive', 'This account is not active.'];
        }

        if ((int) $user['is_locked'] === 1) {
            return ['account_locked', 'This account is locked.'];
        }

        // Only somebody who may actually operate the monitoring module should
        // be able to put a station into monitoring mode.
        $permissions = $this->users->permissionsFor((int) $user['user_id']);

        $allowed = in_array('*', $permissions, true)
            || in_array('monitoring.view', $permissions, true)
            || in_array('monitoring.*', $permissions, true);

        if (!$allowed) {
            return ['not_authorised', 'This account is not permitted to operate a monitoring station.'];
        }

        return null;
    }

    /**
     * Raise an alert when failures cluster at one station.
     */
    private function escalateRepeatedFailures(int $deviceId, string $deviceCode): void
    {
        $threshold = 5;
        $window    = 300;

        $failures = $this->verifications->recentFailuresAtDevice($deviceId, $window);

        if ($failures < $threshold) {
            return;
        }

        $this->security->record(
            'fingerprint_failure',
            sprintf(
                '%d fingerprint verifications failed at %s within %d minutes. The sensor may need cleaning, or somebody is trying fingers that are not enrolled.',
                $failures,
                $deviceCode,
                (int) ($window / 60)
            ),
            ['device_code' => $deviceCode, 'failures' => $failures],
            'alerted'
        );
    }

    /**
     * End the shift at a station.
     */
    public function signOutOperator(int $deviceId, string $reason = 'signed_out'): void
    {
        $session = $this->operators->activeForDevice($deviceId);

        if ($session === null) {
            return;
        }

        $this->operators->close((int) $session['operator_session_id'], $reason);

        $this->audit->record('fingerprints', 'operator_signout', sprintf(
            '%s signed out of the monitoring station.',
            (string) $session['full_name']
        ), ['record_type' => 'operator_sessions', 'record_id' => (int) $session['operator_session_id']]);
    }

    /**
     * Remove an enrolment.
     */
    public function remove(int $templateId, ?int $actorId): void
    {
        $template = $this->templates->findWithHolder($templateId);

        if ($template === null) {
            throw NotFoundException::record('Fingerprint enrolment', $templateId);
        }

        $this->connection->transaction(function () use ($templateId, $actorId): void {
            // The shortcut columns must be cleared first, or the foreign key
            // would keep pointing at a removed enrolment.
            $this->connection->execute(
                'UPDATE `users` SET `fingerprint_template_id` = NULL WHERE `fingerprint_template_id` = ?',
                [$templateId]
            );
            $this->connection->execute(
                'UPDATE `drivers` SET `fingerprint_template_id` = NULL WHERE `fingerprint_template_id` = ?',
                [$templateId]
            );

            $this->templates->update($templateId, ['status' => 'revoked']);
            $this->templates->delete($templateId, $actorId);
        });

        $this->audit->deleted('fingerprints', 'fingerprint_templates', $templateId, sprintf(
            'Enrolment %s was removed. The slot must also be cleared on the sensor.',
            (string) $template['template_number']
        ), ['template_number' => $template['template_number']]);
    }

    /**
     * Reconcile the server's register against what a sensor reports it holds.
     *
     * @param list<int> $slotsOnSensor
     *
     * @return array{matched:int,missing_on_sensor:list<string>,unknown_on_sensor:list<int>}
     */
    public function synchronise(int $deviceId, array $slotsOnSensor, ?int $actorId): array
    {
        $recorded = $this->templates->query()
            ->select(['template_id', 'template_number', 'sensor_slot'])
            ->whereEquals('device_id', $deviceId)
            ->get();

        $recordedSlots   = array_map(static fn (array $row): int => (int) $row['sensor_slot'], $recorded);
        $missingOnSensor = [];
        $matched         = 0;

        foreach ($recorded as $row) {
            if (in_array((int) $row['sensor_slot'], $slotsOnSensor, true)) {
                $this->templates->markSynchronised((int) $row['template_id']);
                $matched++;

                continue;
            }

            // The server thinks an enrolment exists that the sensor does not
            // have. Flagging it rather than deleting it keeps the decision with
            // the administrator.
            $this->templates->update((int) $row['template_id'], ['status' => 'pending_sync']);
            $missingOnSensor[] = (string) $row['template_number'];
        }

        $unknownOnSensor = array_values(array_diff($slotsOnSensor, $recordedSlots));

        $this->audit->record('fingerprints', 'synchronised', sprintf(
            'Sensor synchronisation on device %d: %d matched, %d missing on the sensor, %d unrecognised slots on the sensor.',
            $deviceId,
            $matched,
            count($missingOnSensor),
            count($unknownOnSensor)
        ), ['record_type' => 'devices', 'record_id' => $deviceId]);

        return [
            'matched'           => $matched,
            'missing_on_sensor' => $missingOnSensor,
            'unknown_on_sensor' => $unknownOnSensor,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [
            'statuses'       => $this->templates->statusCounts(),
            'failures_today' => $this->verifications->countFailuresBetween(
                now()->format('Y-m-d 00:00:00'),
                now()->format('Y-m-d 23:59:59')
            ),
            'operators_on_duty' => $this->operators->countActive(),
        ];
    }
}
