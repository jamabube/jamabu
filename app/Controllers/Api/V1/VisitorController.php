<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\RfidCardRepository;
use App\Repositories\VisitorLogRepository;
use App\Repositories\VisitorRepository;
use App\Responses\ApiResponse;
use App\Services\VisitorService;

/**
 * Visitor registry and temporary-pass endpoints.
 *
 * Two related but distinct things live here: the person (a visitor record,
 * reused on every return visit) and the pass (one visit, one card, one
 * validity window). Keeping them apart is what makes "this person has been
 * here eleven times" answerable.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class VisitorController extends Controller
{
    // ------------------------------------------------------------------
    // Visitors
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/visitors
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(VisitorService::class)->paginateVisitors([
            'search'          => $request->string('search'),
            'status'          => $request->string('status'),
            'visitor_type_id' => $request->string('visitor_type_id'),
            'blacklisted'     => $request->string('blacklisted'),
        ], $options);

        return $this->paginated('Visitors retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/visitors/select
     */
    public function select(Request $request): JsonResponse
    {
        return $this->json('Visitor list retrieved.', $this->service(VisitorRepository::class)->selectList());
    }

    /**
     * GET /api/v1/visitors/summary
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->json('Visitor summary retrieved.', $this->service(VisitorService::class)->summary());
    }

    /**
     * GET /api/v1/visitors/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $visitorId = $request->routeInt('id');
        $visitor   = $this->service(VisitorRepository::class)->findWithDetail($visitorId);

        if ($visitor === null) {
            return $this->failure('NOT_FOUND', 'That visitor does not exist.', 404);
        }

        return $this->json('Visitor retrieved.', [
            'visitor' => $visitor,
            'history' => $this->service(VisitorLogRepository::class)->forVisitor($visitorId),
        ]);
    }

    /**
     * POST /api/v1/visitors
     *
     * Registering a visitor who has been here before updates the existing
     * record rather than creating a second one; the service decides that from
     * the government identification number.
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, $this->visitorRules(), $this->visitorLabels());

        $visitorId = $this->service(VisitorService::class)->registerVisitor($attributes, $this->auth->id());

        return ApiResponse::created('The visitor record was saved.', [
            'visitor_id' => $visitorId,
        ], '/api/v1/visitors/' . $visitorId);
    }

    /**
     * PUT /api/v1/visitors/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $visitorId  = $request->routeInt('id');
        $attributes = $this->validate($request, $this->visitorRules(), $this->visitorLabels());

        $this->service(VisitorRepository::class)->update($visitorId, array_merge($attributes, [
            'updated_by' => $this->auth->id(),
        ]));

        return $this->json('The visitor record was updated.', ['visitor_id' => $visitorId]);
    }

    /**
     * POST /api/v1/visitors/{id}/blacklist
     */
    public function setBlacklist(Request $request): JsonResponse
    {
        $visitorId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'blacklisted' => 'required|boolean',
            'reason'      => 'nullable|string|max:255',
        ], [
            'blacklisted' => 'Barred',
            'reason'      => 'Reason',
        ]);

        $blacklisted = filter_var($payload['blacklisted'], FILTER_VALIDATE_BOOLEAN);

        // Barring someone from the park is a decision that must always carry a
        // reason; lifting it does not need one.
        if ($blacklisted && trim((string) ($payload['reason'] ?? '')) === '') {
            return ApiResponse::validationFailed([
                'reason' => ['A reason is required when barring a visitor.'],
            ]);
        }

        $this->service(VisitorService::class)->setBlacklisted(
            $visitorId,
            $blacklisted,
            isset($payload['reason']) && is_string($payload['reason']) ? $payload['reason'] : null,
            (int) $this->auth->id()
        );

        return $this->json(
            $blacklisted ? 'The visitor was barred from entry.' : 'The visitor is no longer barred.',
            ['visitor_id' => $visitorId, 'blacklisted' => $blacklisted]
        );
    }

    // ------------------------------------------------------------------
    // Passes
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/visitors/passes
     */
    public function passes(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'issued_at');
        $paginator = $this->service(VisitorService::class)->paginatePasses([
            'search'       => $request->string('search'),
            'status'       => $request->string('status'),
            'visitor_id'   => $request->string('visitor_id'),
            'rfid_card_id' => $request->string('rfid_card_id'),
            'visitor_type' => $request->string('visitor_type'),
            'overdue'      => $request->boolean('overdue'),
            'date_from'    => $request->string('date_from'),
            'date_to'      => $request->string('date_to'),
        ], $options);

        return $this->paginated('Visitor passes retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/visitors/passes/inside
     */
    public function inside(Request $request): JsonResponse
    {
        return $this->json('Visitors currently inside retrieved.', $this->service(VisitorLogRepository::class)->currentlyInside());
    }

    /**
     * GET /api/v1/visitors/passes/{id}
     */
    public function showPass(Request $request): JsonResponse
    {
        $pass = $this->service(VisitorLogRepository::class)->findInView($request->routeInt('id'));

        if ($pass === null) {
            return $this->failure('NOT_FOUND', 'That visitor pass does not exist.', 404);
        }

        return $this->json('Visitor pass retrieved.', $pass);
    }

    /**
     * GET /api/v1/visitors/cards/available
     *
     * The cards the guardhouse can hand out right now.
     */
    public function availableCards(Request $request): JsonResponse
    {
        return $this->json('Available visitor cards retrieved.', $this->service(RfidCardRepository::class)->available());
    }

    /**
     * POST /api/v1/visitors/passes
     */
    public function issuePass(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, [
            'visitor_id'          => 'required|integer|exists:visitors,visitor_id',
            'rfid_card_id'        => 'nullable|integer|exists:rfid_cards,rfid_card_id',
            'purpose'             => 'required|string|min:3|max:255',
            'destination'         => 'nullable|string|max:120',
            'vehicle_plate'       => 'nullable|plate|max:20',
            'vehicle_description' => 'nullable|string|max:120',
            'companions'          => 'nullable|integer|between:0,60',
            'authorized_by'       => 'nullable|integer|exists:users,user_id',
            'hours'               => 'nullable|integer|between:1,168',
            'remarks'             => 'nullable|string|max:2000',
        ], [
            'visitor_id'    => 'Visitor',
            'rfid_card_id'  => 'Visitor card',
            'purpose'       => 'Purpose of visit',
            'vehicle_plate' => 'Vehicle plate',
            'authorized_by' => 'Authorised by',
            'hours'         => 'Validity in hours',
        ]);

        $passId = $this->service(VisitorService::class)->issuePass($attributes, $this->auth->id());

        return ApiResponse::created('The visitor pass was issued.', [
            'visitor_log_id' => $passId,
        ], '/api/v1/visitors/passes/' . $passId);
    }

    /**
     * POST /api/v1/visitors/passes/{id}/revoke
     */
    public function revokePass(Request $request): JsonResponse
    {
        $passId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'reason' => 'required|string|min:5|max:255',
        ], [
            'reason' => 'Reason',
        ]);

        $this->service(VisitorService::class)->revokePass($passId, (string) $payload['reason'], (int) $this->auth->id());

        return $this->json('The pass was revoked and the card released.', ['visitor_log_id' => $passId]);
    }

    /**
     * @return array<string,string>
     */
    private function visitorRules(): array
    {
        return [
            'first_name'      => 'required|alpha_space|max:60',
            'middle_name'     => 'nullable|alpha_space|max:60',
            'last_name'       => 'required|alpha_space|max:60',
            'visitor_type_id' => 'nullable|integer|exists:visitor_types,visitor_type_id',
            'company'         => 'nullable|string|max:120',
            'contact_number'  => 'nullable|phone',
            'email'           => 'nullable|email|max:150',
            'address'         => 'nullable|string|max:255',
            'government_id'   => 'nullable|string|max:60',
            'status'          => 'nullable|in:active,inactive',
            'remarks'         => 'nullable|string|max:2000',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function visitorLabels(): array
    {
        return [
            'first_name'      => 'First name',
            'last_name'       => 'Last name',
            'visitor_type_id' => 'Visitor type',
            'government_id'   => 'Government identification number',
        ];
    }
}
