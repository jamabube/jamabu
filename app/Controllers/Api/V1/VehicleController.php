<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\AccessLogRepository;
use App\Repositories\VehicleRepository;
use App\Responses\ApiResponse;
use App\Services\VehicleService;

/**
 * Vehicle registry endpoints.
 *
 * A vehicle is never destroyed: deactivation is a soft delete, so the years of
 * monitoring records that point at it keep their meaning. Restoring one brings
 * back the same identifier rather than creating a second registration for the
 * same plate.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class VehicleController extends Controller
{
    /**
     * GET /api/v1/vehicles
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(VehicleService::class)->paginate([
            'search'          => $request->string('search'),
            'status'          => $request->string('status'),
            'vehicle_type_id' => $request->string('vehicle_type_id'),
            'owner_id'        => $request->string('owner_id'),
            'driver_id'       => $request->string('driver_id'),
            'presence'        => $request->string('presence'),
            'tag_state'       => $request->string('tag_state'),
            'date_from'       => $request->string('date_from'),
            'date_to'         => $request->string('date_to'),
        ], $options);

        return $this->paginated('Vehicles retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/vehicles/select
     *
     * The trimmed list a dropdown needs, without the cost of the full record.
     */
    public function select(Request $request): JsonResponse
    {
        return $this->json('Vehicle list retrieved.', $this->service(VehicleRepository::class)->selectList());
    }

    /**
     * GET /api/v1/vehicles/summary
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->json('Vehicle summary retrieved.', $this->service(VehicleService::class)->summary());
    }

    /**
     * GET /api/v1/vehicles/{id}
     */
    public function show(Request $request): JsonResponse
    {
        return $this->json(
            'Vehicle retrieved.',
            $this->service(VehicleService::class)->detail($request->routeInt('id'))
        );
    }

    /**
     * GET /api/v1/vehicles/{id}/timeline
     */
    public function timeline(Request $request): JsonResponse
    {
        $vehicleId = $request->routeInt('id');
        $limit     = min(200, max(1, $request->integer('limit', 50)));

        return $this->json('Vehicle movement history retrieved.', [
            'vehicle_id' => $vehicleId,
            'movements'  => $this->service(AccessLogRepository::class)->timelineForVehicle($vehicleId, $limit),
            'statistics' => $this->service(VehicleService::class)->statisticsFor($vehicleId),
        ]);
    }

    /**
     * POST /api/v1/vehicles
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, $this->rules(), $this->labels());

        $vehicleId = $this->service(VehicleService::class)->create($attributes, $this->auth->id());

        return ApiResponse::created('The vehicle was registered.', [
            'vehicle_id' => $vehicleId,
        ], '/api/v1/vehicles/' . $vehicleId);
    }

    /**
     * PUT /api/v1/vehicles/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $vehicleId  = $request->routeInt('id');
        $attributes = $this->validate($request, $this->rules($vehicleId), $this->labels());

        $this->service(VehicleService::class)->update($vehicleId, $attributes, $this->auth->id());

        return $this->json('The vehicle was updated.', ['vehicle_id' => $vehicleId]);
    }

    /**
     * DELETE /api/v1/vehicles/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $vehicleId = $request->routeInt('id');

        $this->service(VehicleService::class)->deactivate($vehicleId, $this->auth->id());

        return ApiResponse::deleted('The vehicle was deactivated and its tag released.');
    }

    /**
     * POST /api/v1/vehicles/{id}/restore
     */
    public function restore(Request $request): JsonResponse
    {
        $vehicleId = $request->routeInt('id');

        $this->service(VehicleService::class)->restore($vehicleId, $this->auth->id());

        return $this->json('The vehicle was restored.', ['vehicle_id' => $vehicleId]);
    }

    /**
     * The write rules, shared by create and update.
     *
     * The plate uniqueness check is delegated to the service rather than the
     * "unique" rule, because the service compares the *normalised* plate and a
     * rule comparing the raw input would let "abc 123" past "ABC 123".
     *
     * @return array<string,string>
     */
    private function rules(?int $vehicleId = null): array
    {
        return [
            'plate_number'       => 'required|plate|max:20',
            'vehicle_type_id'    => 'required|integer|exists:vehicle_types,vehicle_type_id',
            'owner_id'           => 'required|integer|exists:vehicle_owners,owner_id',
            'driver_id'          => 'nullable|integer|exists:drivers,driver_id',
            'rfid_tag_id'        => 'nullable|integer|exists:rfid_tags,rfid_tag_id',
            'brand'              => 'nullable|string|max:60',
            'model'              => 'nullable|string|max:60',
            'colour'             => 'nullable|string|max:40',
            'year_model'         => 'nullable|integer|between:1900,2100',
            'chassis_number'     => 'nullable|string|max:60',
            'engine_number'      => 'nullable|string|max:60',
            'registration_date'  => 'nullable|date',
            'insurance_provider' => 'nullable|string|max:120',
            'insurance_expiry'   => 'nullable|date',
            'status'             => ($vehicleId === null ? 'nullable' : 'required') . '|in:active,inactive,suspended,archived',
            'remarks'            => 'nullable|string|max:2000',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function labels(): array
    {
        return [
            'plate_number'    => 'Plate number',
            'vehicle_type_id' => 'Vehicle type',
            'owner_id'        => 'Owner',
            'driver_id'       => 'Assigned driver',
            'rfid_tag_id'     => 'RFID tag',
            'year_model'      => 'Year model',
        ];
    }
}
