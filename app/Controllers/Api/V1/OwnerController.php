<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\VehicleOwnerRepository;
use App\Responses\ApiResponse;
use App\Services\RegistryService;

/**
 * Vehicle-owner registry endpoints.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class OwnerController extends Controller
{
    /**
     * GET /api/v1/owners
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(RegistryService::class)->paginateOwners([
            'search'         => $request->string('search'),
            'status'         => $request->string('status'),
            'owner_category' => $request->string('owner_category'),
            'department_id'  => $request->string('department_id'),
        ], $options);

        return $this->paginated('Owners retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/owners/select
     */
    public function select(Request $request): JsonResponse
    {
        return $this->json('Owner list retrieved.', $this->service(VehicleOwnerRepository::class)->selectList());
    }

    /**
     * GET /api/v1/owners/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $ownerId    = $request->routeInt('id');
        $repository = $this->service(VehicleOwnerRepository::class);
        $owner      = $repository->findWithDetail($ownerId);

        if ($owner === null) {
            return $this->failure('NOT_FOUND', 'That owner does not exist.', 404);
        }

        return $this->json('Owner retrieved.', [
            'owner'    => $owner,
            'vehicles' => $repository->vehicles($ownerId),
        ]);
    }

    /**
     * POST /api/v1/owners
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, $this->rules(), $this->labels());

        $ownerId = $this->service(RegistryService::class)->createOwner($attributes, $this->auth->id());

        return ApiResponse::created('The owner was registered.', [
            'owner_id' => $ownerId,
        ], '/api/v1/owners/' . $ownerId);
    }

    /**
     * PUT /api/v1/owners/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $ownerId    = $request->routeInt('id');
        $attributes = $this->validate($request, $this->rules($ownerId), $this->labels());

        $this->service(RegistryService::class)->updateOwner($ownerId, $attributes, $this->auth->id());

        return $this->json('The owner was updated.', ['owner_id' => $ownerId]);
    }

    /**
     * DELETE /api/v1/owners/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->service(RegistryService::class)->deactivateOwner($request->routeInt('id'), $this->auth->id());

        return ApiResponse::deleted('The owner was deactivated.');
    }

    /**
     * @return array<string,string>
     */
    private function rules(?int $ownerId = null): array
    {
        $unique = 'nullable|string|max:60|unique:vehicle_owners,government_id'
            . ($ownerId === null ? '' : ',' . $ownerId . ',owner_id');

        return [
            'first_name'     => 'required|alpha_space|max:60',
            'middle_name'    => 'nullable|alpha_space|max:60',
            'last_name'      => 'required|alpha_space|max:60',
            'suffix'         => 'nullable|string|max:10',
            'owner_category' => 'required|in:employee,resident,contractor,supplier,official,other',
            'company'        => 'nullable|string|max:120',
            'address'        => 'nullable|string|max:255',
            'contact_number' => 'nullable|phone',
            'email'          => 'nullable|email|max:150',
            'government_id'  => $unique,
            'user_id'        => 'nullable|integer|exists:users,user_id',
            'department_id'  => 'nullable|integer|exists:departments,department_id',
            'status'         => ($ownerId === null ? 'nullable' : 'required') . '|in:active,inactive',
            'remarks'        => 'nullable|string|max:2000',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function labels(): array
    {
        return [
            'first_name'     => 'First name',
            'last_name'      => 'Last name',
            'owner_category' => 'Owner category',
            'government_id'  => 'Government identification number',
            'department_id'  => 'Department',
            'user_id'        => 'Linked system user',
        ];
    }
}
