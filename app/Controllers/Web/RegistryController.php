<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\DepartmentRepository;
use App\Repositories\DriverRepository;
use App\Repositories\ReferenceRepository;
use App\Repositories\RfidTagRepository;
use App\Repositories\VehicleOwnerRepository;
use App\Services\RegistryService;
use App\Services\VehicleService;

/**
 * Registry pages: vehicles, drivers and owners.
 *
 * The three are one module in practice — a vehicle form needs the owner list
 * and the driver list, and a driver page shows their vehicles — so they share
 * a controller rather than three that each depend on the same four
 * repositories.
 *
 * Each page renders its shell and the reference data its forms need; the
 * tables themselves are filled from the API, so paging and searching never
 * reload the page.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class RegistryController extends Controller
{
    /**
     * GET /vehicles
     */
    public function vehicles(Request $request): Response
    {
        return $this->render('pages/registry/vehicles', [
            'title'        => 'Vehicles',
            'summary'      => $this->service(VehicleService::class)->summary(),
            'vehicleTypes' => $this->service(ReferenceRepository::class)->vehicleTypes(),
            'owners'       => $this->service(VehicleOwnerRepository::class)->selectList(),
            'drivers'      => $this->service(DriverRepository::class)->selectList(),
            'tags'         => $this->service(RfidTagRepository::class)->availableForAssignment(),
            'can'          => [
                'create'  => $this->auth->can('vehicles.create'),
                'update'  => $this->auth->can('vehicles.update'),
                'delete'  => $this->auth->can('vehicles.delete'),
                'restore' => $this->auth->can('vehicles.restore'),
                'export'  => $this->auth->can('vehicles.export'),
                'assign'  => $this->auth->can('rfid.assign'),
            ],
        ]);
    }

    /**
     * GET /vehicles/{id}
     */
    public function vehicle(Request $request): Response
    {
        $vehicleId = $request->routeInt('id');
        $service   = $this->service(VehicleService::class);

        return $this->render('pages/registry/vehicle-detail', [
            'title'      => 'Vehicle detail',
            'detail'     => $service->detail($vehicleId),
            'statistics' => $service->statisticsFor($vehicleId),
        ]);
    }

    /**
     * GET /drivers
     */
    public function drivers(Request $request): Response
    {
        return $this->render('pages/registry/drivers', [
            'title'   => 'Drivers',
            'statuses' => $this->service(DriverRepository::class)->statusCounts(),
            'owners'  => $this->service(VehicleOwnerRepository::class)->selectList(),
            'can'     => [
                'create' => $this->auth->can('drivers.create'),
                'update' => $this->auth->can('drivers.update'),
                'delete' => $this->auth->can('drivers.delete'),
                'export' => $this->auth->can('drivers.export'),
            ],
        ]);
    }

    /**
     * GET /drivers/{id}
     */
    public function driver(Request $request): Response
    {
        return $this->render('pages/registry/driver-detail', [
            'title'  => 'Driver detail',
            'detail' => $this->service(RegistryService::class)->driverDetail($request->routeInt('id')),
        ]);
    }

    /**
     * GET /owners
     */
    public function owners(Request $request): Response
    {
        return $this->render('pages/registry/owners', [
            'title'       => 'Vehicle owners',
            'departments' => $this->service(DepartmentRepository::class)->selectList(),
            'categories'  => ['employee', 'resident', 'contractor', 'supplier', 'official', 'other'],
            'can'         => [
                'create' => $this->auth->can('owners.create'),
                'update' => $this->auth->can('owners.update'),
                'delete' => $this->auth->can('owners.delete'),
            ],
        ]);
    }

    /**
     * GET /owners/{id}
     */
    public function owner(Request $request): Response
    {
        $ownerId    = $request->routeInt('id');
        $repository = $this->service(VehicleOwnerRepository::class);
        $owner      = $repository->findWithDetail($ownerId);

        if ($owner === null) {
            return $this->render('errors/404', ['title' => 'Owner not found'], 404);
        }

        return $this->render('pages/registry/owner-detail', [
            'title'    => 'Owner detail',
            'owner'    => $owner,
            'vehicles' => $repository->vehicles($ownerId),
        ]);
    }
}
