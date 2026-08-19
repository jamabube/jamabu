<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\DriverRepository;
use App\Responses\ApiResponse;
use App\Services\RegistryService;

/**
 * Driver registry endpoints.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class DriverController extends Controller
{
    /**
     * GET /api/v1/drivers
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(RegistryService::class)->paginateDrivers([
            'search'      => $request->string('search'),
            'status'      => $request->string('status'),
            'owner_id'    => $request->string('owner_id'),
            'licence'     => $request->string('licence'),
            'fingerprint' => $request->string('fingerprint'),
        ], $options);

        return $this->paginated('Drivers retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/drivers/select
     */
    public function select(Request $request): JsonResponse
    {
        return $this->json('Driver list retrieved.', $this->service(DriverRepository::class)->selectList());
    }

    /**
     * GET /api/v1/drivers/{id}
     */
    public function show(Request $request): JsonResponse
    {
        return $this->json(
            'Driver retrieved.',
            $this->service(RegistryService::class)->driverDetail($request->routeInt('id'))
        );
    }

    /**
     * POST /api/v1/drivers
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, $this->rules(), $this->labels());

        $driverId = $this->service(RegistryService::class)->createDriver($attributes, $this->auth->id());

        return ApiResponse::created('The driver was registered.', [
            'driver_id' => $driverId,
        ], '/api/v1/drivers/' . $driverId);
    }

    /**
     * PUT /api/v1/drivers/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $driverId   = $request->routeInt('id');
        $attributes = $this->validate($request, $this->rules($driverId), $this->labels());

        $this->service(RegistryService::class)->updateDriver($driverId, $attributes, $this->auth->id());

        return $this->json('The driver was updated.', ['driver_id' => $driverId]);
    }

    /**
     * DELETE /api/v1/drivers/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->service(RegistryService::class)->deactivateDriver($request->routeInt('id'), $this->auth->id());

        return ApiResponse::deleted('The driver was deactivated and unassigned from their vehicles.');
    }

    /**
     * @return array<string,string>
     */
    private function rules(?int $driverId = null): array
    {
        // The licence number is the one field that must not repeat: two driver
        // records sharing one licence make the access history ambiguous.
        $unique = 'nullable|string|max:60|unique:drivers,government_id'
            . ($driverId === null ? '' : ',' . $driverId . ',driver_id');

        return [
            'first_name'               => 'required|alpha_space|max:60',
            'middle_name'              => 'nullable|alpha_space|max:60',
            'last_name'                => 'required|alpha_space|max:60',
            'suffix'                   => 'nullable|string|max:10',
            'address'                  => 'nullable|string|max:255',
            'birth_date'               => 'nullable|date',
            'gender'                   => 'nullable|in:male,female,other,undisclosed',
            'civil_status'             => 'nullable|in:single,married,widowed,separated,undisclosed',
            'contact_number'           => 'nullable|phone',
            'email'                    => 'nullable|email|max:150',
            'government_id'            => $unique,
            'licence_expiry'           => 'nullable|date',
            'emergency_contact_name'   => 'nullable|string|max:120',
            'emergency_contact_number' => 'nullable|phone',
            'owner_id'                 => 'nullable|integer|exists:vehicle_owners,owner_id',
            'user_id'                  => 'nullable|integer|exists:users,user_id',
            'status'                   => ($driverId === null ? 'nullable' : 'required') . '|in:active,inactive,suspended',
            'remarks'                  => 'nullable|string|max:2000',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function labels(): array
    {
        return [
            'first_name'               => 'First name',
            'last_name'                => 'Last name',
            'government_id'            => 'Licence number',
            'licence_expiry'           => 'Licence expiry',
            'emergency_contact_name'   => 'Emergency contact name',
            'emergency_contact_number' => 'Emergency contact number',
            'owner_id'                 => 'Linked owner',
            'user_id'                  => 'Linked system user',
        ];
    }
}
