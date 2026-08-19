<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\DepartmentRepository;
use App\Repositories\ReferenceRepository;
use App\Repositories\VehicleOwnerRepository;
use App\Responses\ApiResponse;
use App\Services\AuditService;

/**
 * Reference data — the lists that populate every dropdown, plus the
 * departments module.
 *
 * The bundle endpoint exists so a form loads its choices in one request rather
 * than five: on a guardhouse workstation over the park's wireless link, four
 * saved round trips is the difference between a form that feels instant and
 * one that does not.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class ReferenceController extends Controller
{
    /**
     * GET /api/v1/reference
     */
    public function index(Request $request): JsonResponse
    {
        $reference = $this->service(ReferenceRepository::class);

        return $this->json('Reference data retrieved.', [
            'vehicle_types'  => $reference->vehicleTypes(),
            'visitor_types'  => $reference->visitorTypes(),
            'departments'    => $this->service(DepartmentRepository::class)->selectList(),
            'codes'          => $reference->codes(),
            'modules'        => $reference->modules(),
            'owner_categories' => ['employee', 'resident', 'contractor', 'supplier', 'official', 'other'],
        ]);
    }

    /**
     * GET /api/v1/reference/vehicle-types
     */
    public function vehicleTypes(Request $request): JsonResponse
    {
        return $this->json('Vehicle types retrieved.', $this->service(ReferenceRepository::class)->vehicleTypes());
    }

    /**
     * GET /api/v1/reference/visitor-types
     */
    public function visitorTypes(Request $request): JsonResponse
    {
        return $this->json('Visitor types retrieved.', $this->service(ReferenceRepository::class)->visitorTypes());
    }

    /**
     * GET /api/v1/reference/notification-types
     */
    public function notificationTypes(Request $request): JsonResponse
    {
        return $this->json('Notification types retrieved.', $this->service(ReferenceRepository::class)->notificationTypes());
    }

    // ------------------------------------------------------------------
    // Departments
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/departments
     */
    public function departments(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'department_name', 'ASC');
        $paginator = $this->service(DepartmentRepository::class)->paginate([
            'search' => $request->string('search'),
            'status' => $request->string('status'),
        ], $options);

        return $this->paginated('Departments retrieved.', $paginator->items(), $paginator);
    }

    /**
     * POST /api/v1/departments
     */
    public function storeDepartment(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, $this->departmentRules(), $this->departmentLabels());

        $repository = $this->service(DepartmentRepository::class);

        if (($attributes['department_code'] ?? '') === '' || !isset($attributes['department_code'])) {
            $attributes['department_code'] = $repository->nextCode();
        }

        $departmentId = $repository->create($attributes);

        $this->service(AuditService::class)->created('users', 'departments', $departmentId, sprintf(
            'Department "%s" was created.',
            (string) $attributes['department_name']
        ), $attributes);

        return ApiResponse::created('The department was created.', ['department_id' => $departmentId]);
    }

    /**
     * PUT /api/v1/departments/{id}
     */
    public function updateDepartment(Request $request): JsonResponse
    {
        $departmentId = $request->routeInt('id');
        $repository   = $this->service(DepartmentRepository::class);
        $existing     = $repository->findOrFail($departmentId);

        $attributes = $this->validate($request, array_merge($this->departmentRules(), [
            'department_code' => 'required|string|max:20|unique:departments,department_code,'
                . $departmentId . ',department_id',
        ]), $this->departmentLabels());

        $repository->update($departmentId, $attributes);

        $this->service(AuditService::class)->updated('users', 'departments', $departmentId, sprintf(
            'Department "%s" was updated.',
            (string) $existing['department_name']
        ), $existing, $attributes);

        return $this->json('The department was updated.', ['department_id' => $departmentId]);
    }

    /**
     * DELETE /api/v1/departments/{id}
     *
     * Refused while people are still attached: an affiliation that points at a
     * removed department is worse than no affiliation at all.
     */
    public function destroyDepartment(Request $request): JsonResponse
    {
        $departmentId = $request->routeInt('id');
        $repository   = $this->service(DepartmentRepository::class);
        $existing     = $repository->findOrFail($departmentId);

        $members = $repository->memberCount($departmentId);

        if ($members > 0) {
            return $this->failure(
                'DEPARTMENT_IN_USE',
                sprintf('%d account(s) still belong to this department. Reassign them first.', $members),
                409,
                ['members' => $members]
            );
        }

        $owners = count($this->service(VehicleOwnerRepository::class)->query()
            ->whereEquals('department_id', $departmentId)
            ->get());

        if ($owners > 0) {
            return $this->failure(
                'DEPARTMENT_IN_USE',
                sprintf('%d vehicle owner(s) still belong to this department. Reassign them first.', $owners),
                409,
                ['owners' => $owners]
            );
        }

        $repository->delete($departmentId, $this->auth->id());

        $this->service(AuditService::class)->deleted('users', 'departments', $departmentId, sprintf(
            'Department "%s" was removed.',
            (string) $existing['department_name']
        ), $existing);

        return ApiResponse::deleted('The department was removed.');
    }

    /**
     * @return array<string,string>
     */
    private function departmentRules(): array
    {
        return [
            'department_code' => 'nullable|string|max:20|unique:departments,department_code',
            'department_name' => 'required|string|min:2|max:120',
            'description'     => 'nullable|string|max:255',
            'contact_number'  => 'nullable|phone',
            'status'          => 'nullable|in:active,inactive',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function departmentLabels(): array
    {
        return [
            'department_code' => 'Department code',
            'department_name' => 'Department name',
            'contact_number'  => 'Contact number',
        ];
    }
}
