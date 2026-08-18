<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Events\EventDispatcher;
use App\Core\Http\Request;
use App\Core\Security\AuthGuard;
use App\Core\Support\Arr;
use App\Events\SecurityAlertRaised;
use App\Repositories\SecurityEventRepository;
use Throwable;

/**
 * Records detected suspicious activity.
 *
 * One entry point for every detection in the system, so that an event always
 * carries the same evidence: what was detected, how severe it is, where it
 * came from, and what the system did about it. Critical events additionally
 * raise a domain event, which is what turns a log line into an administrator
 * notification.
 *
 * @package App\Services
 * @version 1.0.0
 */
class SecurityEventService
{
    /**
     * Severity assigned to each recognised event type.
     *
     * Keeping the mapping here rather than at each call site means a type
     * cannot be recorded as "low" in one place and "critical" in another.
     *
     * @var array<string,string>
     */
    private const SEVERITIES = [
        'failed_login'                  => 'medium',
        'account_locked'                => 'high',
        'account_inactive_login'        => 'medium',
        'password_expired_login'        => 'low',
        'session_fingerprint_mismatch'  => 'critical',
        'session_hijack_suspected'      => 'critical',
        'concurrent_session'            => 'low',
        'unauthorized_access'           => 'high',
        'privilege_escalation_attempt'  => 'critical',
        'csrf_token_invalid'            => 'high',
        'rate_limit'                    => 'medium',
        'flood_detected'                => 'critical',
        'ip_not_allowed'                => 'high',
        'unknown_device'                => 'critical',
        'invalid_api_key'               => 'critical',
        'device_credentials_missing'    => 'medium',
        'inactive_device'               => 'medium',
        'suspended_device'              => 'high',
        'stale_timestamp'               => 'medium',
        'replay_attack'                 => 'critical',
        'invalid_signature'             => 'critical',
        'firmware_rejected'             => 'medium',
        'device_ip_rejected'            => 'high',
        'device_authentication_failure' => 'high',
        'malformed_request'             => 'low',
        'unknown_rfid'                  => 'high',
        'expired_rfid'                  => 'medium',
        'inactive_vehicle_scan'         => 'medium',
        'fingerprint_failure'           => 'high',
        'upload_rejected'               => 'medium',
        'directory_traversal_attempt'   => 'critical',
    ];

    public function __construct(
        private readonly SecurityEventRepository $repository,
        private readonly AuthGuard $auth,
        private readonly EventDispatcher $events
    ) {
    }

    /**
     * Record a security event.
     *
     * @param string              $eventType   One of the recognised type keys.
     * @param string              $description Sentence describing what happened.
     * @param array<string,mixed> $detail      Structured evidence (redacted before storage).
     * @param string|null         $actionTaken What the system did: rejected, blocked, ...
     *
     * @return int|null The new event id, or null when it could not be stored.
     */
    public function record(
        string $eventType,
        string $description,
        array $detail = [],
        ?string $actionTaken = null,
        ?string $severity = null
    ): ?int {
        $severity ??= self::SEVERITIES[$eventType] ?? 'medium';

        try {
            $request = $this->currentRequest();
            $user    = $this->auth->user();

            $eventId = $this->repository->create([
                'event_type'     => $eventType,
                'severity'       => $severity,
                'description'    => mb_substr($description, 0, 500),
                'detail'         => $this->encode(Arr::redact($detail, (array) config('logging.redact', []))),
                'user_id'        => $user?->id,
                'username'       => $user?->username ?? (isset($detail['username']) ? (string) $detail['username'] : null),
                'device_id'      => $this->auth->deviceId(),
                'device_code'    => $this->auth->deviceCode() ?? (isset($detail['device_code']) ? (string) $detail['device_code'] : null),
                'ip_address'     => $request?->ip(),
                'user_agent'     => $request?->userAgent(),
                'request_id'     => $request?->requestId(),
                'request_method' => $request?->method(),
                'request_path'   => $request === null ? null : mb_substr($request->path(), 0, 255),
                'action_taken'   => $actionTaken,
                'status'         => 'new',
                'occurred_at'    => now()->format('Y-m-d H:i:s'),
            ]);

            // Everything is written to the security channel too, so the
            // evidence survives even if the database is later restored from a
            // backup taken before the incident.
            logger()->channel('security')->warning($description, [
                'event_type'   => $eventType,
                'severity'     => $severity,
                'action_taken' => $actionTaken,
                'detail'       => $detail,
            ]);

            // High and critical events are what an administrator must be told
            // about; lower ones are reviewed in the security module.
            if (in_array($severity, ['high', 'critical'], true)) {
                $this->events->dispatch(new SecurityAlertRaised(
                    eventId: $eventId,
                    eventType: $eventType,
                    severity: $severity,
                    description: $description
                ));
            }

            return $eventId;
        } catch (Throwable $e) {
            logger()->channel('security')->error('Security event could not be stored', [
                'event_type'  => $eventType,
                'description' => $description,
                'reason'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a failed authentication attempt.
     */
    public function failedLogin(string $username, string $reason, int $attemptsSoFar): ?int
    {
        return $this->record(
            'failed_login',
            sprintf('Failed sign-in attempt for username "%s" (%s).', $username, $reason),
            ['username' => $username, 'reason' => $reason, 'attempts' => $attemptsSoFar],
            'rejected'
        );
    }

    /**
     * Record an authorisation refusal.
     */
    public function unauthorized(string $permission, string $path): ?int
    {
        return $this->record(
            'unauthorized_access',
            sprintf('Access to "%s" was refused; the account does not hold "%s".', $path, $permission),
            ['required_permission' => $permission, 'path' => $path],
            'rejected'
        );
    }

    /**
     * Whether one source has crossed a repetition threshold for an event type.
     *
     * Used to escalate a series of individually unremarkable events into a
     * single alert once the pattern becomes suspicious.
     */
    public function hasExceededThreshold(string $eventType, string $ipAddress, int $threshold, int $windowSeconds): bool
    {
        return $this->repository->countRecent($eventType, $ipAddress, $windowSeconds) >= $threshold;
    }

    /**
     * Acknowledge an event and record who did so.
     */
    public function acknowledge(int $eventId, int $userId, string $status = 'acknowledged', string $notes = ''): void
    {
        $this->repository->update($eventId, [
            'status'           => $status,
            'acknowledged_by'  => $userId,
            'acknowledged_at'  => now()->format('Y-m-d H:i:s'),
            'resolution_notes' => $notes === '' ? null : $notes,
        ]);
    }

    /**
     * @param array<string,mixed> $detail
     */
    private function encode(array $detail): ?string
    {
        if ($detail === []) {
            return null;
        }

        $json = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    private function currentRequest(): ?Request
    {
        $application = app();

        return $application->bound(Request::class) ? $application->make(Request::class) : null;
    }

    /**
     * The severity the service assigns to a type. Exposed so the interface can
     * colour a filter drop-down consistently with stored events.
     */
    public static function severityFor(string $eventType): string
    {
        return self::SEVERITIES[$eventType] ?? 'medium';
    }

    /**
     * @return array<string,string>
     */
    public static function knownEventTypes(): array
    {
        return self::SEVERITIES;
    }
}
