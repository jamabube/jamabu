<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Repositories\VehicleRepository;
use App\Services\VehicleService;
use Tests\TestCase;

/**
 * A plate may be registered exactly once, and attempting otherwise must read
 * as a conflict rather than as a crash.
 *
 * The interesting case is the race: the service checks before inserting, but
 * two operators registering the same plate at the same moment can both pass
 * that check. Only the database can settle it, and what it produces — a raw
 * driver exception — must never reach the guardhouse as a stack trace.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class DuplicatePlateTest extends TestCase
{
    protected bool $requiresDatabase = true;

    private VehicleService $vehicles;
    private VehicleRepository $repository;

    /** @var list<int> Vehicles created here, removed on the way out. */
    private array $created = [];

    public function description(): string
    {
        return 'Duplicate plate numbers are refused as a conflict, not a crash';
    }

    public function setUp(): void
    {
        $this->vehicles   = $this->app->make(VehicleService::class);
        $this->repository = $this->app->make(VehicleRepository::class);
    }

    public function tearDown(): void
    {
        foreach ($this->created as $vehicleId) {
            $this->repository->forceDelete($vehicleId);
        }

        $this->created = [];
    }

    /**
     * @return array<string,mixed>
     */
    private function attributes(string $plate): array
    {
        $owner = $this->app->make(\App\Repositories\VehicleOwnerRepository::class)->query()->first();
        $type  = $this->app->make(\App\Core\Database\Connection::class)
            ->selectOne('SELECT `vehicle_type_id` FROM `vehicle_types` LIMIT 1');

        return [
            'plate_number'    => $plate,
            'vehicle_type_id' => (int) $type['vehicle_type_id'],
            'owner_id'        => (int) $owner['owner_id'],
        ];
    }

    public function testASecondRegistrationOfTheSamePlateIsRefused(): void
    {
        $plate = 'TST ' . random_int(1000, 9999);

        $this->created[] = $this->vehicles->create($this->attributes($plate), null);

        $this->assertThrows(
            fn (): int => $this->vehicles->create($this->attributes($plate), null),
            'a duplicate plate is refused',
            ConflictException::class
        );
    }

    public function testTheRefusalNamesThePlateAndAnswers409(): void
    {
        $plate = 'TST ' . random_int(1000, 9999);

        $this->created[] = $this->vehicles->create($this->attributes($plate), null);

        try {
            $this->vehicles->create($this->attributes($plate), null);

            $this->assertTrue(false, 'the duplicate was refused');
        } catch (ConflictException $e) {
            $this->assertSame(409, $e->statusCode(), 'a duplicate answers 409 Conflict');
            $this->assertTrue(
                str_contains($e->getMessage(), 'plate number'),
                'the message says what conflicted'
            );
        }
    }

    public function testCaseAndSpacingDoNotCreateASecondRegistration(): void
    {
        $plate = 'TST ' . random_int(1000, 9999);

        $this->created[] = $this->vehicles->create($this->attributes($plate), null);

        // The plate is normalised before it is stored and before it is
        // compared, so a lower-case entry with extra spacing is the same
        // vehicle rather than a new one.
        $this->assertThrows(
            fn (): int => $this->vehicles->create(
                $this->attributes('  ' . strtolower($plate) . '  '),
                null
            ),
            'a differently typed version of the same plate is refused',
            ConflictException::class
        );
    }

    public function testTheDatabaseItselfRefusesADuplicateEvenWhenTheCheckIsBypassed(): void
    {
        $plate = 'TST ' . random_int(1000, 9999);
        $first = $this->vehicles->create($this->attributes($plate), null);

        $this->created[] = $first;

        /*
         * This is the race, reproduced: the repository is used directly, so
         * the service's pre-check never runs and the insert reaches the unique
         * index exactly as a concurrent second request would. The service must
         * still turn what comes back into a conflict.
         */
        $this->assertThrows(
            function () use ($plate): void {
                $attributes = $this->attributes($plate);
                $attributes['plate_number'] = \App\Core\Support\Str::normalisePlate($plate);
                $attributes['vehicle_code'] = 'RACE-' . random_int(10000, 99999);

                $this->repository->create($attributes);
            },
            'the unique index refuses the insert the pre-check would have caught',
            \App\Exceptions\DatabaseException::class
        );
    }
}
