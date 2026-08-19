<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\RfidCardRepository;
use App\Repositories\RfidTagRepository;
use App\Responses\ApiResponse;
use App\Services\RegistryService;
use App\Services\VehicleService;

/**
 * RFID inventory endpoints — windshield tags and visitor cards.
 *
 * Both are credentials the reader cannot tell apart, so their UIDs share one
 * namespace; the service enforces that when a credential is added. The two are
 * kept in separate tables because their lifecycles differ: a tag belongs to one
 * vehicle for years, a card is handed out and returned several times a day.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class RfidController extends Controller
{
    // ------------------------------------------------------------------
    // Tags
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/rfid/tags
     */
    public function tags(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(RegistryService::class)->paginateTags([
            'search'     => $request->string('search'),
            'status'     => $request->string('status'),
            'tag_type'   => $request->string('tag_type'),
            'assignment' => $request->string('assignment'),
            'expiring'   => $request->boolean('expiring'),
        ], $options);

        return $this->paginated('RFID tags retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/rfid/tags/available
     *
     * The tags a vehicle can be given. An identifier may be passed so that the
     * tag already on that vehicle stays selectable when editing it.
     */
    public function availableTags(Request $request): JsonResponse
    {
        $include = $request->integer('include_tag_id', 0);

        return $this->json(
            'Assignable tags retrieved.',
            $this->service(RfidTagRepository::class)->availableForAssignment($include > 0 ? $include : null)
        );
    }

    /**
     * GET /api/v1/rfid/tags/{id}
     */
    public function showTag(Request $request): JsonResponse
    {
        $tag = $this->service(RfidTagRepository::class)->withAssignment()
            ->whereEquals('t.rfid_tag_id', $request->routeInt('id'))
            ->first();

        if ($tag === null) {
            return $this->failure('NOT_FOUND', 'That RFID tag does not exist.', 404);
        }

        return $this->json('RFID tag retrieved.', $tag);
    }

    /**
     * POST /api/v1/rfid/tags
     */
    public function storeTag(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, [
            'rfid_uid'        => 'required|rfid_uid',
            'tag_code'        => 'nullable|string|max:20',
            'tag_type'        => 'nullable|in:uhf_windshield,uhf_sticker,hf_card,lf_tag',
            'frequency'       => 'nullable|string|max:30',
            'serial_number'   => 'nullable|string|max:60',
            'status'          => 'nullable|in:available,assigned,inactive,lost,damaged,expired,revoked',
            'activation_date' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'remarks'         => 'nullable|string|max:2000',
        ], [
            'rfid_uid'        => 'RFID UID',
            'tag_code'        => 'Tag code',
            'tag_type'        => 'Tag type',
            'activation_date' => 'Activation date',
            'expiration_date' => 'Expiry date',
        ]);

        $tagId = $this->service(RegistryService::class)->registerTag($attributes, $this->auth->id());

        return ApiResponse::created('The tag was added to the inventory.', ['rfid_tag_id' => $tagId]);
    }

    /**
     * PUT /api/v1/rfid/tags/{id}
     *
     * The UID itself is immutable. A tag with a different UID is a different
     * piece of hardware and must be registered as one, or the scan history
     * would silently change meaning.
     */
    public function updateTag(Request $request): JsonResponse
    {
        $tagId = $request->routeInt('id');

        $attributes = $this->validate($request, [
            'tag_code'        => 'required|string|max:20',
            'tag_type'        => 'required|in:uhf_windshield,uhf_sticker,hf_card,lf_tag',
            'frequency'       => 'nullable|string|max:30',
            'serial_number'   => 'nullable|string|max:60',
            'activation_date' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'remarks'         => 'nullable|string|max:2000',
        ], [
            'tag_code'        => 'Tag code',
            'tag_type'        => 'Tag type',
            'activation_date' => 'Activation date',
            'expiration_date' => 'Expiry date',
        ]);

        $this->service(RfidTagRepository::class)->update($tagId, array_merge($attributes, [
            'updated_by' => $this->auth->id(),
        ]));

        return $this->json('The tag was updated.', ['rfid_tag_id' => $tagId]);
    }

    /**
     * POST /api/v1/rfid/tags/{id}/status
     */
    public function setTagStatus(Request $request): JsonResponse
    {
        $tagId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'status' => 'required|in:available,assigned,inactive,lost,damaged,expired,revoked',
            'reason' => 'required|string|min:3|max:255',
        ], [
            'status' => 'New state',
            'reason' => 'Reason',
        ]);

        $this->service(RegistryService::class)->setTagStatus(
            $tagId,
            (string) $payload['status'],
            (string) $payload['reason'],
            $this->auth->id()
        );

        return $this->json('The tag state was changed.', [
            'rfid_tag_id' => $tagId,
            'status'      => $payload['status'],
        ]);
    }

    /**
     * POST /api/v1/rfid/tags/assign
     *
     * Attaching a tag to a vehicle goes through the vehicle service so the
     * previous tag is released and the states of both stay consistent.
     */
    public function assignTag(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'vehicle_id'  => 'required|integer|exists:vehicles,vehicle_id',
            'rfid_tag_id' => 'nullable|integer|exists:rfid_tags,rfid_tag_id',
        ], [
            'vehicle_id'  => 'Vehicle',
            'rfid_tag_id' => 'RFID tag',
        ]);

        $vehicleId = (int) $payload['vehicle_id'];
        $tagId     = isset($payload['rfid_tag_id']) && $payload['rfid_tag_id'] !== ''
            ? (int) $payload['rfid_tag_id']
            : null;

        $this->service(VehicleService::class)->update(
            $vehicleId,
            ['rfid_tag_id' => $tagId],
            $this->auth->id()
        );

        return $this->json(
            $tagId === null ? 'The tag was released from the vehicle.' : 'The tag was assigned to the vehicle.',
            ['vehicle_id' => $vehicleId, 'rfid_tag_id' => $tagId]
        );
    }

    // ------------------------------------------------------------------
    // Visitor cards
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/rfid/cards
     */
    public function cards(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(RegistryService::class)->paginateCards([
            'search' => $request->string('search'),
            'status' => $request->string('status'),
        ], $options);

        return $this->paginated('Visitor cards retrieved.', $paginator->items(), $paginator);
    }

    /**
     * POST /api/v1/rfid/cards
     */
    public function storeCard(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, [
            'card_uid'  => 'required|rfid_uid',
            'card_code' => 'nullable|string|max:20',
            'card_type' => 'nullable|in:hf_card,uhf_card,keyfob',
            'status'    => 'nullable|in:available,issued,inactive,lost,damaged,retired',
            'remarks'   => 'nullable|string|max:2000',
        ], [
            'card_uid'  => 'Card UID',
            'card_code' => 'Card number',
            'card_type' => 'Card type',
        ]);

        $cardId = $this->service(RegistryService::class)->registerCard($attributes, $this->auth->id());

        return ApiResponse::created('The card was added to the inventory.', ['rfid_card_id' => $cardId]);
    }

    /**
     * POST /api/v1/rfid/cards/{id}/status
     */
    public function setCardStatus(Request $request): JsonResponse
    {
        $cardId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'status' => 'required|in:available,issued,inactive,lost,damaged,retired',
            'reason' => 'required|string|min:3|max:255',
        ], [
            'status' => 'New state',
            'reason' => 'Reason',
        ]);

        $this->service(RegistryService::class)->setCardStatus(
            $cardId,
            (string) $payload['status'],
            (string) $payload['reason'],
            $this->auth->id()
        );

        return $this->json('The card state was changed.', [
            'rfid_card_id' => $cardId,
            'status'       => $payload['status'],
        ]);
    }

    /**
     * GET /api/v1/rfid/summary
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->json('RFID inventory summary retrieved.', $this->service(RegistryService::class)->summary());
    }

    /**
     * GET /api/v1/rfid/lookup
     *
     * Answers "what is this UID?" for the enrolment screens, so an operator can
     * hold a credential against a reader and see whether it is already known
     * before trying to register it a second time.
     */
    public function lookup(Request $request): JsonResponse
    {
        $uid = \App\Core\Support\Str::normaliseUid($request->string('uid'));

        if ($uid === '') {
            return $this->failure('INVALID_UID', 'A UID is required.', 422);
        }

        $tag  = $this->service(RfidTagRepository::class)->findByUid($uid);
        $card = $tag === null ? $this->service(RfidCardRepository::class)->findByUid($uid) : null;

        return $this->json($tag !== null || $card !== null ? 'Credential found.' : 'That UID is not registered.', [
            'uid'   => $uid,
            'kind'  => $tag !== null ? 'tag' : ($card !== null ? 'card' : null),
            'tag'   => $tag,
            'card'  => $card,
            'known' => $tag !== null || $card !== null,
        ]);
    }
}
