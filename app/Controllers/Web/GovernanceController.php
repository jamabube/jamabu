<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\ApiRequestLogRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\ErrorLogRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\SecurityRuleService;
use App\Services\SystemHealthService;

/**
 * Governance pages: the audit trail, security events, the error register, the
 * API traffic log and the system health report.
 *
 * These five are the read-only side of the system — the record of what
 * happened rather than the machinery that made it happen — so they share a
 * controller and a shape: a shell, its filter options, and a table filled from
 * the API.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class GovernanceController extends Controller
{
    /**
     * GET /audit
     */
    public function audit(Request $request): Response
    {
        return $this->render('pages/governance/audit', [
            'title'     => 'Audit logs',
            'filters'   => $this->service(AuditLogRepository::class)->filterOptions(),
            'canExport' => $this->auth->can('audit.export'),
        ]);
    }

    /**
     * GET /security
     */
    public function security(Request $request): Response
    {
        $repository = $this->service(SecurityEventRepository::class);

        return $this->render('pages/governance/security', [
            'title'      => 'Security events',
            'eventTypes' => $repository->eventTypes(),
            'severity'   => $repository->severityCounts(now()->modify('-29 days')->format('Y-m-d 00:00:00')),
            'unresolved' => $repository->countUnresolved(),
            'rules'      => $this->service(SecurityRuleService::class)->all(),
            'can'        => [
                'acknowledge' => $this->auth->can('security.acknowledge'),
                'manageRules' => $this->auth->can('security.manage_rules'),
                'export'      => $this->auth->can('security.export'),
            ],
        ]);
    }

    /**
     * GET /errors
     */
    public function errors(Request $request): Response
    {
        $repository = $this->service(ErrorLogRepository::class);

        return $this->render('pages/governance/errors', [
            'title'      => 'Error logs',
            'modules'    => $repository->modules(),
            'unresolved' => $repository->countUnresolved(),
            'can'        => [
                'resolve' => $this->auth->can('errors.resolve'),
                'export'  => $this->auth->can('errors.export'),
            ],
        ]);
    }

    /**
     * GET /api-management
     */
    public function api(Request $request): Response
    {
        $since = now()->modify('-24 hours')->format('Y-m-d H:i:s');
        $logs  = $this->service(ApiRequestLogRepository::class);

        return $this->render('pages/governance/api', [
            'title'       => 'API management',
            'devices'     => $this->service(DeviceRepository::class)->allWithStatus(),
            'performance' => $logs->performanceSince($since),
            'busiest'     => $logs->busiestEndpoints($since),
            'canViewLogs' => $this->auth->can('api.logs'),
        ]);
    }

    /**
     * GET /health
     */
    public function health(Request $request): Response
    {
        $service = $this->service(SystemHealthService::class);

        return $this->render('pages/governance/health', [
            'title'       => 'System health',
            'report'      => $service->report(),
            'environment' => $service->environment(),
        ]);
    }
}
