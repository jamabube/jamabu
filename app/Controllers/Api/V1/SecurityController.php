<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\SecurityEventService;
use App\Services\SecurityRuleService;

/**
 * Security event endpoints.
 *
 * An event is never deleted; it is acknowledged, investigated or dismissed,
 * and each of those is itself recorded. What the security officer needs is a
 * register that cannot be tidied up after an incident.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class SecurityController extends Controller
{
    /**
     * GET /api/v1/security/events
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'occurred_at');
        $paginator = $this->service(SecurityEventRepository::class)->paginate([
            'search'     => $request->string('search'),
            'event_type' => $request->string('event_type'),
            'severity'   => $request->string('severity'),
            'status'     => $request->string('status'),
            'ip_address' => $request->string('ip_address'),
            'device_id'  => $request->string('device_id'),
            'user_id'    => $request->string('user_id'),
            'date_from'  => $request->string('date_from'),
            'date_to'    => $request->string('date_to'),
        ], $options);

        return $this->paginated('Security events retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/security/events/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $event = $this->service(SecurityEventRepository::class)->find($request->routeInt('id'));

        if ($event === null) {
            return $this->failure('NOT_FOUND', 'That security event does not exist.', 404);
        }

        return $this->json('Security event retrieved.', $event);
    }

    /**
     * GET /api/v1/security/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $to   = $request->string('date_to', now()->format('Y-m-d'));
        $from = $request->string('date_from', now()->modify('-29 days')->format('Y-m-d'));

        $repository = $this->service(SecurityEventRepository::class);

        return $this->json('Security summary retrieved.', [
            'date_from'   => $from,
            'date_to'     => $to,
            'severity'    => $repository->severityCounts($from . ' 00:00:00'),
            'trend'       => $repository->dailyTrend($from, $to),
            'unresolved'  => $repository->countUnresolved(),
            'today'       => $repository->countSince(now()->format('Y-m-d 00:00:00')),
            'active'      => $repository->activeAlerts(),
            'event_types' => $repository->eventTypes(),
            'top_sources' => $this->service(LoginAttemptRepository::class)->topFailingAddresses(),
            'failures'    => $this->service(LoginAttemptRepository::class)->countFailuresSince(
                now()->format('Y-m-d 00:00:00')
            ),
        ]);
    }

    /**
     * POST /api/v1/security/events/{id}/acknowledge
     */
    public function acknowledge(Request $request): JsonResponse
    {
        $eventId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'status' => 'nullable|in:acknowledged,investigating,resolved,dismissed',
            'notes'  => 'nullable|string|max:1000',
        ], [
            'status' => 'Outcome',
            'notes'  => 'Investigation notes',
        ]);

        $this->service(SecurityEventService::class)->acknowledge(
            $eventId,
            (int) $this->auth->id(),
            (string) ($payload['status'] ?? 'acknowledged'),
            (string) ($payload['notes'] ?? '')
        );

        return $this->json('The event was updated.', ['security_event_id' => $eventId]);
    }

    /**
     * GET /api/v1/security/rules
     */
    public function rules(Request $request): JsonResponse
    {
        return $this->json('Security rules retrieved.', $this->service(SecurityRuleService::class)->all());
    }

    /**
     * PUT /api/v1/security/rules/{id}
     *
     * Only the threshold, the window, the action and whether the rule is on
     * can change. The key is the contract with the code that enforces it.
     */
    public function updateRule(Request $request): JsonResponse
    {
        $ruleId = $request->routeInt('id');

        $attributes = $this->validate($request, [
            'threshold_value' => 'required|integer|between:1,1000000',
            'window_seconds'  => 'required|integer|between:1,86400',
            'action'          => 'required|in:log,notify,block,lock',
            'severity'        => 'required|in:low,medium,high,critical',
            'is_enabled'      => 'required|boolean',
        ], [
            'threshold_value' => 'Threshold',
            'window_seconds'  => 'Window in seconds',
            'action'          => 'Action',
            'severity'        => 'Severity',
            'is_enabled'      => 'Enabled',
        ]);

        $attributes['is_enabled'] = filter_var($attributes['is_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        $this->service(SecurityRuleService::class)->update($ruleId, $attributes, $this->auth->id());

        return $this->json('The security rule was updated.', ['security_rule_id' => $ruleId]);
    }

    /**
     * GET /api/v1/security/login-attempts
     */
    public function loginAttempts(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'attempted_at');
        $paginator = $this->service(LoginAttemptRepository::class)->paginate([
            'search'     => $request->string('search'),
            'successful' => $request->string('successful'),
            'date_from'  => $request->string('date_from'),
            'date_to'    => $request->string('date_to'),
        ], $options);

        return $this->paginated('Sign-in attempts retrieved.', $paginator->items(), $paginator);
    }
}
