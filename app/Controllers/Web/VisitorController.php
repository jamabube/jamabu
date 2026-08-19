<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\ReferenceRepository;
use App\Repositories\RfidCardRepository;
use App\Repositories\VisitorLogRepository;
use App\Repositories\VisitorRepository;
use App\Services\VisitorService;

/**
 * Visitor pages: the register of people and the passes issued to them.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class VisitorController extends Controller
{
    /**
     * GET /visitors
     */
    public function index(Request $request): Response
    {
        return $this->render('pages/visitors/index', [
            'title'        => 'Visitors',
            'summary'      => $this->service(VisitorService::class)->summary(),
            'visitorTypes' => $this->service(ReferenceRepository::class)->visitorTypes(),
            'cards'        => $this->service(RfidCardRepository::class)->available(),
            'inside'       => $this->service(VisitorLogRepository::class)->currentlyInside(),
            'can'          => [
                'create'    => $this->auth->can('visitors.create'),
                'update'    => $this->auth->can('visitors.update'),
                'issue'     => $this->auth->can('visitors.issue_pass'),
                'revoke'    => $this->auth->can('visitors.revoke_pass'),
                'blacklist' => $this->auth->can('visitors.blacklist'),
                'export'    => $this->auth->can('visitors.export'),
            ],
        ]);
    }

    /**
     * GET /visitors/{id}
     */
    public function show(Request $request): Response
    {
        $visitorId = $request->routeInt('id');
        $visitor   = $this->service(VisitorRepository::class)->findWithDetail($visitorId);

        if ($visitor === null) {
            return $this->render('errors/404', ['title' => 'Visitor not found'], 404);
        }

        return $this->render('pages/visitors/show', [
            'title'   => 'Visitor detail',
            'visitor' => $visitor,
            'history' => $this->service(VisitorLogRepository::class)->forVisitor($visitorId),
        ]);
    }
}
