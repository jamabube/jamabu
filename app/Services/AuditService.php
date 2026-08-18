<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http\Request;
use App\Core\Security\AuthGuard;
use App\Core\Support\Arr;
use App\Repositories\AuditLogRepository;
use Throwable;

/**
 * Writes the audit trail.
 *
 * Every significant action funnels through this service so that the recorded
 * shape is identical everywhere: who did it, from where, to which record, and
 * what changed. Values for sensitive fields are redacted before storage — an
 * audit record proves that a password was changed without disclosing it.
 *
 * A failure to write an audit record never aborts the action that triggered
 * it; it is logged to the file channel instead, so the incident is still
 * visible without a guard being unable to record a vehicle because the audit
 * table was momentarily unavailable.
 *
 * @package App\Services
 * @version 1.0.0
 */
class AuditService
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly AuthGuard $auth
    ) {
    }

    /**
     * Record an action.
     *
     * @param string              $module      Module the action belongs to.
     * @param string              $action      Verb: created, updated, deleted, login, export...
     * @param string              $description Human-readable sentence for the viewer.
     * @param array<string,mixed> $context     Optional record_type/record_id/old/new overrides.
     */
    public function record(string $module, string $action, string $description, array $context = []): void
    {
        try {
            $request = $this->currentRequest();
            $user    = $this->auth->user();

            /** @var array<string,mixed>|null $old */
            $old = $context['old'] ?? null;
            /** @var array<string,mixed>|null $new */
            $new = $context['new'] ?? null;

            // Only the fields that actually changed are stored, which keeps the
            // record readable and avoids copying whole rows into the log.
            if (is_array($old) && is_array($new)) {
                [$old, $new] = Arr::diff($old, $new);
            }

            $redact = (array) config('logging.redact', []);

            $this->repository->create([
                'user_id'        => $user?->id,
                'username'       => $user?->username ?? ($this->auth->isDevice() ? 'device:' . $this->auth->deviceCode() : null),
                'role_name'      => $user?->roleName,
                'device_id'      => $this->auth->deviceId(),
                'module'         => $module,
                'action'         => $action,
                'description'    => mb_substr($description, 0, 255),
                'record_type'    => isset($context['record_type']) ? (string) $context['record_type'] : null,
                'record_id'      => isset($context['record_id']) ? (string) $context['record_id'] : null,
                'old_values'     => $this->encode(is_array($old) ? Arr::redact($old, $redact) : null),
                'new_values'     => $this->encode(is_array($new) ? Arr::redact($new, $redact) : null),
                'ip_address'     => $request?->ip(),
                'user_agent'     => $request?->userAgent(),
                'browser'        => $this->browser($request?->userAgent() ?? ''),
                'platform'       => $this->platform($request?->userAgent() ?? ''),
                'request_id'     => $request?->requestId(),
                'request_method' => $request?->method(),
                'request_path'   => $request === null ? null : mb_substr($request->path(), 0, 255),
                'status'         => (string) ($context['status'] ?? 'success'),
                'created_at'     => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // The action succeeded; only its record failed. Preserve the
            // evidence in the file log and carry on.
            logger()->channel('audit')->error('Audit record could not be stored', [
                'module'      => $module,
                'action'      => $action,
                'description' => $description,
                'reason'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record the creation of a record.
     *
     * @param array<string,mixed> $values
     */
    public function created(string $module, string $recordType, int|string $recordId, string $description, array $values = []): void
    {
        $this->record($module, 'created', $description, [
            'record_type' => $recordType,
            'record_id'   => $recordId,
            'new'         => $values,
        ]);
    }

    /**
     * Record an update, storing only the fields that changed.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function updated(string $module, string $recordType, int|string $recordId, string $description, array $before, array $after): void
    {
        $this->record($module, 'updated', $description, [
            'record_type' => $recordType,
            'record_id'   => $recordId,
            'old'         => $before,
            'new'         => $after,
        ]);
    }

    /**
     * @param array<string,mixed> $values
     */
    public function deleted(string $module, string $recordType, int|string $recordId, string $description, array $values = []): void
    {
        $this->record($module, 'deleted', $description, [
            'record_type' => $recordType,
            'record_id'   => $recordId,
            'old'         => $values,
        ]);
    }

    /**
     * Record an action that was attempted and refused.
     *
     * @param array<string,mixed> $context
     */
    public function failed(string $module, string $action, string $description, array $context = []): void
    {
        $this->record($module, $action, $description, $context + ['status' => 'failure']);
    }

    /**
     * @param array<string,mixed>|null $values
     */
    private function encode(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /**
     * The request currently being served, when there is one.
     *
     * Console commands also write audit records and have no request, so this
     * resolves optionally rather than being injected.
     */
    private function currentRequest(): ?Request
    {
        $application = app();

        return $application->bound(Request::class) ? $application->make(Request::class) : null;
    }

    /**
     * Identify the browser family from a user-agent string.
     *
     * Deliberately coarse: the audit trail needs "which browser", not a full
     * device fingerprint.
     */
    private function browser(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Edg/')                                   => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/')                               => 'Firefox',
            str_contains($userAgent, 'Chrome/')                                => 'Chrome',
            str_contains($userAgent, 'Safari/')                                => 'Safari',
            str_contains($userAgent, 'ESP32'), str_contains($userAgent, 'VAMS-Device') => 'ESP32 firmware',
            str_contains($userAgent, 'curl/')                                  => 'curl',
            default                                                            => 'Other',
        };
    }

    /**
     * Identify the operating-system family from a user-agent string.
     */
    private function platform(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Windows NT 10.0'), str_contains($userAgent, 'Windows NT 11') => 'Windows',
            str_contains($userAgent, 'Windows')  => 'Windows',
            str_contains($userAgent, 'Android')  => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux')    => 'Linux',
            str_contains($userAgent, 'ESP32')    => 'ESP32',
            default                              => 'Other',
        };
    }
}
