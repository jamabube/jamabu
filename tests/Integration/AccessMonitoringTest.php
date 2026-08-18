<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Connection;
use App\DTO\ScanRequest;
use App\Repositories\AccessDenialRepository;
use App\Repositories\AccessLogRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\OperatorSessionRepository;
use App\Repositories\RfidTagRepository;
use App\Repositories\VehicleRepository;
use App\Services\AccessMonitoringService;
use Tests\TestCase;

/**
 * Exercises the access decision engine against a live database.
 *
 * Every rule the specification states about vehicle movement is asserted here,
 * including the ones that only fail under concurrency or at a boundary.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class AccessMonitoringTest extends TestCase
{
    protected bool $requiresDatabase = true;

    private const TAG_UID     = 'FEED0001';
    private const PLATE       = 'TST 0001';
    private const DEVICE_CODE = 'ESP32-MONITOR-TEST';

    private AccessMonitoringService $monitoring;
    private AccessLogRepository $accessLogs;
    private AccessDenialRepository $denials;
    private VehicleRepository $vehicles;
    private RfidTagRepository $tags;
    private DeviceRepository $devices;
    private Connection $connection;

    /** @var array<string,mixed> */
    private array $device = [];

    private int $vehicleId = 0;
    private int $tagId = 0;

    public function description(): string
    {
        return 'Entry and exit decision rules';
    }

    public function setUp(): void
    {
        $this->monitoring = $this->app->make(AccessMonitoringService::class);
        $this->accessLogs = $this->app->make(AccessLogRepository::class);
        $this->denials    = $this->app->make(AccessDenialRepository::class);
        $this->vehicles   = $this->app->make(VehicleRepository::class);
        $this->tags       = $this->app->make(RfidTagRepository::class);
        $this->devices    = $this->app->make(DeviceRepository::class);
        $this->connection = $this->app->make(Connection::class);

        // The engine's operator rule is exercised in its own test; the rest run
        // with it off so each assertion isolates the rule it is about.
        \App\Core\Config::set('monitoring.rules.require_operator_authentication', false);
        \App\Core\Config::set('monitoring.rules.minimum_stay_seconds', 0);
        \App\Core\Config::set('monitoring.rules.duplicate_scan_window_seconds', 0);

        $this->device    = $this->ensureDevice();
        $this->tagId     = $this->ensureTag();
        $this->vehicleId = $this->ensureVehicle();

        $this->clearHistory();
    }

    public function tearDown(): void
    {
        $this->clearHistory();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function ensureDevice(): array
    {
        $existing = $this->devices->findByCode(self::DEVICE_CODE);

        if ($existing !== null) {
            $this->devices->reinstate((int) $existing['device_id']);
            $this->devices->update((int) $existing['device_id'], ['gate_type' => 'both']);

            return $this->devices->findByCode(self::DEVICE_CODE) ?? $existing;
        }

        $credentials = $this->app->make(\App\Services\DeviceAuthenticationService::class)->issueApiKey();

        $id = $this->devices->create([
            'device_code'         => self::DEVICE_CODE,
            'device_name'         => 'Monitoring Rule Test Station',
            'api_key_hash'        => $credentials['hash'],
            'api_key_prefix'      => $credentials['prefix'],
            'signing_secret_hash' => $credentials['signing_hash'],
            'gate_type'           => 'both',
            'heartbeat_interval'  => 30,
            'status'              => 'active',
        ]);

        return $this->devices->find($id) ?? [];
    }

    private function ensureTag(): int
    {
        $existing = $this->tags->findByUid(self::TAG_UID);

        if ($existing !== null) {
            $this->tags->update((int) $existing['rfid_tag_id'], [
                'status'          => 'assigned',
                'expiration_date' => now()->modify('+1 year')->format('Y-m-d'),
            ]);

            return (int) $existing['rfid_tag_id'];
        }

        return $this->tags->create([
            'rfid_uid'        => self::TAG_UID,
            'tag_code'        => 'TAG-TEST01',
            'tag_type'        => 'uhf_windshield',
            'status'          => 'assigned',
            'activation_date' => now()->format('Y-m-d'),
            'expiration_date' => now()->modify('+1 year')->format('Y-m-d'),
        ]);
    }

    private function ensureVehicle(): int
    {
        $existing = $this->vehicles->findByPlate(self::PLATE);

        if ($existing !== null) {
            $this->vehicles->update((int) $existing['vehicle_id'], ['status' => 'active']);
            $this->vehicles->assignTag((int) $existing['vehicle_id'], $this->tagId, null);

            return (int) $existing['vehicle_id'];
        }

        $ownerId = (int) ($this->connection->scalar('SELECT `owner_id` FROM `vehicle_owners` LIMIT 1') ?? 0);
        $typeId  = (int) ($this->connection->scalar('SELECT `vehicle_type_id` FROM `vehicle_types` LIMIT 1') ?? 0);

        $id = $this->vehicles->create([
            'vehicle_code'    => 'VEH-TEST01',
            'plate_number'    => self::PLATE,
            'vehicle_type_id' => $typeId,
            'owner_id'        => $ownerId,
            'brand'           => 'Test',
            'model'           => 'Harness',
            'status'          => 'active',
        ]);

        $this->vehicles->assignTag($id, $this->tagId, null);

        return $id;
    }

    /**
     * Remove anything previous runs left behind, so each run starts clean.
     */
    private function clearHistory(): void
    {
        $this->connection->execute(
            'DELETE FROM `vehicle_access_logs` WHERE `vehicle_id` = ? OR `scanned_uid` = ?',
            [$this->vehicleId, self::TAG_UID]
        );
        $this->connection->execute('DELETE FROM `access_denials` WHERE `scanned_uid` IN (?, ?)', [self::TAG_UID, 'BADC0DE1']);
    }

    private function scan(string $accessType, ?string $uid = null, ?string $scannedAt = null): \App\DTO\AccessDecision
    {
        return $this->monitoring->process(
            new ScanRequest(
                rfidUid: $uid ?? self::TAG_UID,
                accessType: $accessType,
                deviceId: (int) $this->device['device_id'],
                deviceCode: self::DEVICE_CODE,
                scannedAt: $scannedAt,
                requestId: bin2hex(random_bytes(8)),
                ipAddress: '192.168.10.21'
            ),
            $this->device
        );
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testEntryIsRecorded(): void
    {
        $decision = $this->scan('entry');

        $this->assertTrue($decision->granted, 'a registered vehicle is granted entry');
        $this->assertSame('granted', $decision->resultCode, 'the result is granted');
        $this->assertNotNull($decision->accessLogId, 'a monitoring record was created');
        $this->assertMatches('/^ACC-\d{8}-\d{4}-[A-Z0-9]{4}$/', (string) $decision->transactionReference, 'a transaction reference was issued');

        $record = $this->accessLogs->find((int) $decision->accessLogId);

        $this->assertSame('inside', (string) $record['status'], 'the visit is open');
        $this->assertNull($record['exit_time'], 'no exit time is recorded yet');
        $this->assertSame(self::PLATE, (string) $record['plate_number'], 'the plate is captured on the record');
    }

    public function testDuplicateEntryIsRefused(): void
    {
        $this->scan('entry');
        $decision = $this->scan('entry');

        $this->assertFalse($decision->granted, 'a second entry without an exit is refused');
        $this->assertSame('denied_duplicate_entry', $decision->resultCode, 'the refusal names the duplicate');
        $this->assertSame(1, $this->accessLogs->countWhere('vehicle_id', $this->vehicleId), 'only one record exists');
    }

    public function testExitClosesTheVisit(): void
    {
        $entry = $this->scan('entry');
        $exit  = $this->scan('exit');

        $this->assertTrue($exit->granted, 'the exit is granted');
        $this->assertSame($entry->accessLogId, $exit->accessLogId, 'the exit closes the same record the entry opened');

        $record = $this->accessLogs->find((int) $entry->accessLogId);

        $this->assertSame('completed', (string) $record['status'], 'the visit is completed');
        $this->assertNotNull($record['exit_time'], 'the exit time is recorded');
        $this->assertNotNull($record['duration_seconds'], 'the stay duration is computed by the database');
    }

    public function testExitWithoutEntryIsRefused(): void
    {
        $decision = $this->scan('exit');

        $this->assertFalse($decision->granted, 'an exit with no open entry is refused');
        $this->assertSame('denied_no_active_entry', $decision->resultCode, 'the refusal names the missing entry');
    }

    public function testEntryIsPossibleAgainAfterExit(): void
    {
        $this->scan('entry');
        $this->scan('exit');
        $second = $this->scan('entry');

        $this->assertTrue($second->granted, 'the vehicle may enter again once it has left');
        $this->assertSame(2, $this->accessLogs->countWhere('vehicle_id', $this->vehicleId), 'two separate visits are recorded');
    }

    public function testUnknownTagIsRefusedAndCreatesNoAccessRecord(): void
    {
        $before = $this->accessLogs->count();

        $decision = $this->scan('entry', 'BADC0DE1');

        $this->assertFalse($decision->granted, 'an unregistered tag is refused');
        $this->assertSame('denied_unknown_tag', $decision->resultCode, 'the refusal names the unknown tag');
        $this->assertSame($before, $this->accessLogs->count(), 'no monitoring record is created for an unknown tag');

        // The rejection must still be investigable, which is what the separate
        // denial table is for.
        $this->assertGreaterThan(
            0,
            $this->denials->countRecentForUid('BADC0DE1', 60),
            'the rejection is recorded as a denial'
        );
    }

    public function testInactiveVehicleIsRefused(): void
    {
        $this->vehicles->update($this->vehicleId, ['status' => 'inactive']);

        $decision = $this->scan('entry');

        $this->assertFalse($decision->granted, 'an inactive vehicle is refused');
        $this->assertSame('denied_inactive_vehicle', $decision->resultCode, 'the refusal names the vehicle state');

        $this->vehicles->update($this->vehicleId, ['status' => 'active']);
    }

    public function testSuspendedVehicleIsRefused(): void
    {
        $this->vehicles->update($this->vehicleId, ['status' => 'suspended']);

        $decision = $this->scan('entry');

        $this->assertSame('denied_suspended_vehicle', $decision->resultCode, 'a suspended vehicle is refused');

        $this->vehicles->update($this->vehicleId, ['status' => 'active']);
    }

    public function testExpiredTagIsRefused(): void
    {
        $this->tags->update($this->tagId, ['expiration_date' => now()->modify('-1 day')->format('Y-m-d')]);

        $decision = $this->scan('entry');

        $this->assertSame('denied_expired_tag', $decision->resultCode, 'an expired tag is refused');

        $this->tags->update($this->tagId, ['expiration_date' => now()->modify('+1 year')->format('Y-m-d')]);
    }

    public function testLostTagIsRefused(): void
    {
        $this->tags->update($this->tagId, ['status' => 'lost']);

        $decision = $this->scan('entry');

        $this->assertSame('denied_lost_tag', $decision->resultCode, 'a tag reported lost is refused');

        $this->tags->update($this->tagId, ['status' => 'assigned']);
    }

    public function testTagStateIsCheckedBeforeVehicleState(): void
    {
        // Both are wrong at once. The tag is the credential presented, so the
        // guard should be told about the tag, not sent to look at the vehicle.
        $this->tags->update($this->tagId, ['status' => 'lost']);
        $this->vehicles->update($this->vehicleId, ['status' => 'inactive']);

        $decision = $this->scan('entry');

        $this->assertSame('denied_lost_tag', $decision->resultCode, 'the credential problem is reported first');

        $this->tags->update($this->tagId, ['status' => 'assigned']);
        $this->vehicles->update($this->vehicleId, ['status' => 'active']);
    }

    public function testMinimumStayPreventsAnImmediateExit(): void
    {
        \App\Core\Config::set('monitoring.rules.minimum_stay_seconds', 300);

        $this->scan('entry');
        $decision = $this->scan('exit');

        $this->assertFalse($decision->granted, 'an exit moments after entry is refused');
        $this->assertSame('denied_minimum_stay', $decision->resultCode, 'the refusal names the minimum stay');

        \App\Core\Config::set('monitoring.rules.minimum_stay_seconds', 0);
    }

    public function testDuplicateScansAreSuppressedNotRefused(): void
    {
        \App\Core\Config::set('monitoring.rules.duplicate_scan_window_seconds', 60);

        $first  = $this->scan('entry');
        $second = $this->scan('entry');

        $this->assertTrue($first->granted, 'the first read is processed');

        // A long-range reader reports the same tag repeatedly as a vehicle
        // rolls past. Showing the guard an error for that would be misleading.
        $this->assertTrue($second->granted, 'the repeat read is acknowledged rather than refused');
        $this->assertTrue($second->duplicateSuppressed, 'the repeat is marked as suppressed');
        $this->assertSame(1, $this->accessLogs->countWhere('vehicle_id', $this->vehicleId), 'no second record is created');

        \App\Core\Config::set('monitoring.rules.duplicate_scan_window_seconds', 0);
    }

    public function testAnEntryLaneStationCannotRecordAnExit(): void
    {
        $this->devices->update((int) $this->device['device_id'], ['gate_type' => 'entry']);
        $this->device = $this->devices->findByCode(self::DEVICE_CODE) ?? $this->device;

        $decision = $this->scan('exit');

        $this->assertFalse($decision->granted, 'an entry-lane station cannot record an exit');
        $this->assertSame('denied_device', $decision->resultCode, 'the refusal names the station role');

        $this->devices->update((int) $this->device['device_id'], ['gate_type' => 'both']);
        $this->device = $this->devices->findByCode(self::DEVICE_CODE) ?? $this->device;
    }

    public function testOperatorRequirementIsEnforced(): void
    {
        \App\Core\Config::set('monitoring.rules.require_operator_authentication', true);

        $this->app->make(OperatorSessionRepository::class)
            ->closeAllForDevice((int) $this->device['device_id'], 'administrator');

        $decision = $this->scan('entry');

        $this->assertFalse($decision->granted, 'a station with no operator on duty cannot record a movement');
        $this->assertSame('denied_operator', $decision->resultCode, 'the refusal names the missing operator');

        \App\Core\Config::set('monitoring.rules.require_operator_authentication', false);
    }

    public function testAnOperatorOnDutyIsRecordedAgainstTheMovement(): void
    {
        \App\Core\Config::set('monitoring.rules.require_operator_authentication', true);

        $operators = $this->app->make(OperatorSessionRepository::class);
        $userId    = (int) $this->connection->scalar("SELECT `user_id` FROM `users` WHERE `status` = 'active' LIMIT 1");

        $sessionId = $operators->open((int) $this->device['device_id'], $userId, null, 60, 92);

        $decision = $this->scan('entry');

        $this->assertTrue($decision->granted, 'a movement is recorded once an operator is on duty');

        $record = $this->accessLogs->find((int) $decision->accessLogId);

        $this->assertSame($userId, (int) $record['entry_operator_id'], 'the accountable operator is recorded');
        $this->assertSame($sessionId, (int) $record['entry_operator_session_id'], 'the shift is recorded');

        $operators->close($sessionId, 'signed_out');
        \App\Core\Config::set('monitoring.rules.require_operator_authentication', false);
    }

    public function testAQueuedScanKeepsItsOriginalTime(): void
    {
        // A station that lost the network replays what it queued; the record
        // must reflect when the vehicle actually arrived, not when the server
        // finally heard about it.
        $happenedAt = now()->modify('-45 minutes')->format('Y-m-d H:i:s');

        $decision = $this->scan('entry', null, $happenedAt);

        $this->assertTrue($decision->granted, 'a queued scan is accepted');

        $record = $this->accessLogs->find((int) $decision->accessLogId);

        $this->assertSame($happenedAt, (string) $record['entry_time'], 'the original time is preserved');
    }

    public function testAFutureDatedScanFallsBackToTheServerClock(): void
    {
        // A station with a wrong clock must not be able to write a record dated
        // next year.
        $decision = $this->scan('entry', null, now()->modify('+3 days')->format('Y-m-d H:i:s'));

        $this->assertTrue($decision->granted, 'the scan is still processed');

        $record    = $this->accessLogs->find((int) $decision->accessLogId);
        $entryTime = strtotime((string) $record['entry_time']);

        $this->assertTrue(
            $entryTime !== false && $entryTime <= time() + 5,
            'an implausible device timestamp is replaced with the server time'
        );
    }

    public function testTheDatabaseRefusesASecondOpenVisit(): void
    {
        $this->scan('entry');

        // Bypassing the service entirely: even a direct write must not be able
        // to put a vehicle inside twice.
        $this->assertThrows(
            fn () => $this->connection->execute(
                'INSERT INTO `vehicle_access_logs`
                    (`transaction_reference`, `vehicle_id`, `scanned_uid`, `entry_device_id`,
                     `entry_time`, `access_type`, `status`, `result`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'ACC-DIRECT-' . random_int(1000, 9999),
                    $this->vehicleId,
                    self::TAG_UID,
                    (int) $this->device['device_id'],
                    now()->format('Y-m-d H:i:s'),
                    'entry',
                    'inside',
                    'granted',
                ]
            ),
            'the schema itself refuses a second open visit for one vehicle'
        );
    }
}
