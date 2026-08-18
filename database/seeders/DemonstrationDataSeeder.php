<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database\Seeder;
use App\Core\Security\Hasher;
use App\Core\Support\Str;
use DateTimeImmutable;

/**
 * Loads a realistic demonstration data set.
 *
 * Everything here is fictitious. The seeder exists so a fresh installation can
 * be demonstrated and acceptance-tested with populated dashboards, charts and
 * reports, and so the schema's constraints are exercised end to end. It refuses
 * to run against a production environment unless explicitly forced.
 *
 * @package Database\Seeders
 * @version 1.0.0
 */
final class DemonstrationDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'Demo!Pass2026';

    /** Days of history to generate. */
    private const HISTORY_DAYS = 30;

    /** @var array<string,int> Cached identifiers created by this run. */
    private array $ids = [];

    public function description(): string
    {
        return 'Demonstration users, registry, devices and 30 days of monitoring history';
    }

    public function run(): void
    {
        $this->output->warning('Loading demonstration data. Every record below is fictitious.');

        $this->seedUsers();
        $this->seedDevices();
        $this->seedOwnersAndDrivers();
        $this->seedTagsAndVehicles();
        $this->seedVisitorCards();
        $this->seedFingerprints();
        $this->seedAccessHistory();
        $this->seedVisitorActivity();
        $this->seedHeartbeats();
        $this->seedSecurityEvents();
        $this->seedNotifications();
    }

    // ------------------------------------------------------------------
    // People
    // ------------------------------------------------------------------

    private function seedUsers(): void
    {
        $hash = Hasher::make(self::DEMO_PASSWORD);

        $users = [
            ['EMP-0002', 'Marisol',  'Reyes',    'supervisor',  'mreyes',    'm.reyes@forestlawn.local',    'SEC', 'Security Supervisor'],
            ['EMP-0003', 'Danilo',   'Cruz',     'security',    'dcruz',     'd.cruz@forestlawn.local',     'SEC', 'Security Guard'],
            ['EMP-0004', 'Josefina', 'Bautista', 'security',    'jbautista', 'j.bautista@forestlawn.local', 'SEC', 'Security Guard'],
            ['EMP-0005', 'Rogelio',  'Santiago', 'security',    'rsantiago', 'r.santiago@forestlawn.local', 'SEC', 'Security Guard'],
            ['EMP-0006', 'Teresita', 'Villanueva','auditor',    'tvillanueva','t.villanueva@forestlawn.local','ADM','Internal Auditor'],
        ];

        foreach ($users as [$employeeNumber, $first, $last, $role, $username, $email, $department, $position]) {
            $this->ids['user.' . $username] = $this->upsert('users', [
                'employee_number'     => $employeeNumber,
                'first_name'          => $first,
                'last_name'           => $last,
                'username'            => $username,
                'email'               => $email,
                'password_hash'       => $hash,
                'password_changed_at' => $this->now(),
                'must_change_password'=> 1,
                'role_id'             => (int) $this->idOf('roles', 'role_slug', $role),
                'department_id'       => $this->idOf('departments', 'department_code', $department),
                'position'            => $position,
                'status'              => 'active',
                'created_by'          => 1,
            ], ['username'], ['password_hash', 'must_change_password']);
        }

        $this->output->comment(sprintf('        Demonstration users share the password: %s', self::DEMO_PASSWORD));
    }

    // ------------------------------------------------------------------
    // Infrastructure
    // ------------------------------------------------------------------

    private function seedDevices(): void
    {
        $devices = [
            ['ESP32-ENTRY-01', 'Main Gate Entry Station', 'entry', 'Main Gate — North Lane',  '5C:CF:7F:1A:2B:01', '192.168.10.21'],
            ['ESP32-EXIT-01',  'Main Gate Exit Station',  'exit',  'Main Gate — South Lane',  '5C:CF:7F:1A:2B:02', '192.168.10.22'],
        ];

        foreach ($devices as [$code, $name, $gateType, $location, $mac, $ip]) {
            // The demonstration key is fixed so the firmware sample in
            // firmware/esp32 can talk to a freshly seeded server without an
            // extra registration step. Rotate it before any real deployment.
            $apiKey = 'demo-' . strtolower(str_replace('ESP32-', '', $code)) . '-' . str_repeat('0', 8);

            $this->ids['device.' . $code] = $this->upsert('devices', [
                'device_code'         => $code,
                'device_name'         => $name,
                'description'         => 'Demonstration monitoring station.',
                'api_key_hash'        => Hasher::hashToken($apiKey),
                'api_key_prefix'      => substr($apiKey, 0, 12),
                'signing_secret_hash' => Hasher::hashToken($apiKey . '-signing'),
                'mac_address'         => $mac,
                'ip_address'          => $ip,
                'firmware_version'    => '1.0.0',
                'location'            => $location,
                'gate_type'           => $gateType,
                'gate_label'          => $location,
                'installation_date'   => now()->modify('-90 days')->format('Y-m-d'),
                'heartbeat_interval'  => 30,
                'last_heartbeat_at'   => $this->now(),
                'last_communication_at' => $this->now(),
                'signal_strength'     => -1 * random_int(45, 70),
                'uptime_seconds'      => random_int(60000, 900000),
                'restart_count'       => random_int(0, 3),
                'communication_count' => random_int(20000, 60000),
                'health_score'        => random_int(88, 99),
                'status'              => 'active',
                'created_by'          => 1,
            ], ['device_code'], ['api_key_hash', 'signing_secret_hash', 'last_heartbeat_at', 'health_score']);
        }

        $this->output->comment('        Demonstration API keys: demo-entry-01-00000000 / demo-exit-01-00000000');
    }

    // ------------------------------------------------------------------
    // Registry
    // ------------------------------------------------------------------

    private function seedOwnersAndDrivers(): void
    {
        $people = [
            ['OWN-0001', 'Alfredo',  'Mendoza',   'employee',   'Forest Lawn Memorial Park', '0917-555-0101'],
            ['OWN-0002', 'Carmela',  'Dizon',     'employee',   'Forest Lawn Memorial Park', '0917-555-0102'],
            ['OWN-0003', 'Bernardo', 'Lim',       'contractor', 'Lim Landscaping Services',  '0917-555-0103'],
            ['OWN-0004', 'Editha',   'Ramos',     'resident',   null,                        '0917-555-0104'],
            ['OWN-0005', 'Fernando', 'Alcantara', 'supplier',   'Alcantara Marble Works',    '0917-555-0105'],
            ['OWN-0006', 'Gloria',   'Pascual',   'employee',   'Forest Lawn Memorial Park', '0917-555-0106'],
            ['OWN-0007', 'Hector',   'Navarro',   'official',   'City Health Office',        '0917-555-0107'],
            ['OWN-0008', 'Imelda',   'Soriano',   'employee',   'Forest Lawn Memorial Park', '0917-555-0108'],
        ];

        foreach ($people as $index => [$code, $first, $last, $category, $company, $contact]) {
            $ownerId = $this->upsert('vehicle_owners', [
                'owner_code'     => $code,
                'first_name'     => $first,
                'last_name'      => $last,
                'owner_category' => $category,
                'company'        => $company,
                'address'        => sprintf('%d Sampaguita Street, Barangay San Roque', 100 + $index),
                'contact_number' => $contact,
                'email'          => strtolower($first[0] . '.' . $last) . '@example.local',
                'status'         => 'active',
                'created_by'     => 1,
            ], ['owner_code']);

            $this->ids['owner.' . $code] = $ownerId;

            // Every owner in the demonstration set also drives.
            $driverCode = 'DRV-' . substr($code, 4);

            $this->ids['driver.' . $driverCode] = $this->upsert('drivers', [
                'driver_code'    => $driverCode,
                'first_name'     => $first,
                'last_name'      => $last,
                'address'        => sprintf('%d Sampaguita Street, Barangay San Roque', 100 + $index),
                'birth_date'     => sprintf('19%02d-%02d-%02d', random_int(60, 95), random_int(1, 12), random_int(1, 28)),
                'gender'         => $index % 2 === 0 ? 'male' : 'female',
                'contact_number' => $contact,
                'government_id'  => 'N01-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                'licence_expiry' => now()->modify('+' . random_int(60, 900) . ' days')->format('Y-m-d'),
                'emergency_contact_name'   => 'Emergency Contact ' . ($index + 1),
                'emergency_contact_number' => '0918-555-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'owner_id'       => $ownerId,
                'status'         => 'active',
                'created_by'     => 1,
            ], ['driver_code']);
        }
    }

    private function seedTagsAndVehicles(): void
    {
        $vehicles = [
            ['VEH-0001', 'ABC 1234', 'CAR',  'Toyota',    'Vios',      'Silver', 2019, 'OWN-0001', 'active'],
            ['VEH-0002', 'DEF 5678', 'SUV',  'Mitsubishi','Montero',   'Black',  2021, 'OWN-0002', 'active'],
            ['VEH-0003', 'GHI 9012', 'PICK', 'Ford',      'Ranger',    'White',  2020, 'OWN-0003', 'active'],
            ['VEH-0004', 'JKL 3456', 'CAR',  'Honda',     'City',      'Blue',   2018, 'OWN-0004', 'active'],
            ['VEH-0005', 'MNO 7890', 'TRK',  'Isuzu',     'Elf',       'White',  2017, 'OWN-0005', 'active'],
            ['VEH-0006', 'PQR 2345', 'VAN',  'Nissan',    'Urvan',     'Grey',   2022, 'OWN-0006', 'active'],
            ['VEH-0007', 'STU 6789', 'CAR',  'Suzuki',    'Ertiga',    'Red',    2016, 'OWN-0007', 'inactive'],
            ['VEH-0008', 'VWX 0123', 'MC',   'Yamaha',    'Mio',       'Blue',   2023, 'OWN-0008', 'active'],
            ['VEH-0009', 'YZA 4567', 'HRS',  'Toyota',    'Hiace',     'Black',  2021, 'OWN-0001', 'active'],
            ['VEH-0010', 'BCD 8901', 'SVC',  'Kia',       'K2500',     'White',  2020, 'OWN-0002', 'active'],
        ];

        foreach ($vehicles as $index => [$code, $plate, $type, $brand, $model, $colour, $year, $ownerCode, $status]) {
            $tagCode = 'TAG-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $uid     = strtoupper(bin2hex(pack('N', 0xA0000000 + $index * 7919)));

            $tagId = $this->upsert('rfid_tags', [
                'rfid_uid'        => $uid,
                'tag_code'        => $tagCode,
                'tag_type'        => 'uhf_windshield',
                'frequency'       => '865-868 MHz',
                'serial_number'   => 'SN' . str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
                'status'          => $status === 'active' ? 'assigned' : 'inactive',
                'activation_date' => now()->modify('-' . (120 - $index) . ' days')->format('Y-m-d'),
                'expiration_date' => now()->modify('+' . (300 + $index * 10) . ' days')->format('Y-m-d'),
                'created_by'      => 1,
            ], ['tag_code'], ['status']);

            $driverCode = 'DRV-' . substr($ownerCode, 4);

            $this->ids['vehicle.' . $code] = $this->upsert('vehicles', [
                'vehicle_code'      => $code,
                'plate_number'      => $plate,
                'rfid_tag_id'       => $tagId,
                'vehicle_type_id'   => (int) $this->idOf('vehicle_types', 'type_code', $type),
                'brand'             => $brand,
                'model'             => $model,
                'colour'            => $colour,
                'year_model'        => $year,
                'owner_id'          => $this->ids['owner.' . $ownerCode],
                'driver_id'         => $this->ids['driver.' . $driverCode] ?? null,
                'registration_date' => now()->modify('-' . (120 - $index) . ' days')->format('Y-m-d'),
                'insurance_expiry'  => now()->modify('+' . (200 + $index * 15) . ' days')->format('Y-m-d'),
                'status'            => $status,
                'created_by'        => 1,
            ], ['vehicle_code'], ['status']);

            $this->ids['tag.' . $code] = $tagId;
            $this->ids['uid.' . $code] = 0; // placeholder; the UID itself is read back below
        }
    }

    private function seedVisitorCards(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->upsert('rfid_cards', [
                'card_uid'  => strtoupper(bin2hex(pack('N', 0xC0000000 + $i * 104729))),
                'card_code' => 'VC-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'card_type' => 'hf_card',
                'status'    => 'available',
                'created_by'=> 1,
            ], ['card_code'], ['status']);
        }

        $visitors = [
            ['VIS-0001', 'Ricardo',  'Fajardo',  'GUEST',  null],
            ['VIS-0002', 'Lourdes',  'Aguilar',  'GUEST',  null],
            ['VIS-0003', 'Manuel',   'Ocampo',   'SUPP',   'Ocampo Flower Supply'],
            ['VIS-0004', 'Perlita',  'Gonzales', 'CONTR',  'Gonzales Masonry'],
            ['VIS-0005', 'Salvador', 'Torres',   'SVCPRV', 'Torres Funeral Services'],
            ['VIS-0006', 'Nenita',   'Castillo', 'OFFCL',  'City Environment Office'],
        ];

        foreach ($visitors as $index => [$code, $first, $last, $type, $company]) {
            $this->ids['visitor.' . $code] = $this->upsert('visitors', [
                'visitor_code'    => $code,
                'first_name'      => $first,
                'last_name'       => $last,
                'visitor_type_id' => $this->idOf('visitor_types', 'type_code', $type),
                'company'         => $company,
                'contact_number'  => '0920-555-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'government_id'   => 'ID-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                'status'          => 'active',
                'created_by'      => 1,
            ], ['visitor_code']);
        }
    }

    private function seedFingerprints(): void
    {
        $entryDevice = $this->ids['device.ESP32-ENTRY-01'] ?? null;
        $slot        = 0;

        foreach (['mreyes', 'dcruz', 'jbautista', 'rsantiago'] as $username) {
            $userId = $this->ids['user.' . $username] ?? null;

            if ($userId === null) {
                continue;
            }

            $templateId = $this->upsert('fingerprint_templates', [
                'template_number' => 'FP-' . str_pad((string) (++$slot), 6, '0', STR_PAD_LEFT),
                'device_id'       => $entryDevice,
                'sensor_slot'     => $slot,
                'finger_label'    => 'right_thumb',
                'assigned_user_id'=> $userId,
                // A non-reversible checksum of what the sensor reported, never
                // the biometric data itself.
                'checksum'        => hash('sha256', 'demo-template-' . $slot),
                'quality_score'   => random_int(72, 96),
                'enrolled_by'     => 1,
                'last_verified_at'=> $this->now(),
                'verification_count' => random_int(20, 200),
                'synchronised_at' => $this->now(),
                'status'          => 'active',
            ], ['template_number'], ['status']);

            $this->connection->execute(
                'UPDATE `users` SET `fingerprint_template_id` = :template WHERE `user_id` = :user',
                ['template' => $templateId, 'user' => $userId]
            );

            $this->ids['template.' . $username] = $templateId;
        }
    }

    // ------------------------------------------------------------------
    // Monitoring history
    // ------------------------------------------------------------------

    /**
     * Generate a month of realistic movements, weighted towards office hours.
     */
    private function seedAccessHistory(): void
    {
        if ($this->tableHasRows('vehicle_access_logs')) {
            $this->output->comment('        Monitoring history already present; skipping.');

            return;
        }

        /** @var list<array<string,mixed>> $vehicles */
        $vehicles = $this->connection->select(
            "SELECT v.`vehicle_id`, v.`plate_number`, v.`driver_id`, v.`rfid_tag_id`, t.`rfid_uid`
               FROM `vehicles` v
               JOIN `rfid_tags` t ON t.`rfid_tag_id` = v.`rfid_tag_id`
              WHERE v.`status` = 'active'"
        );

        if ($vehicles === []) {
            return;
        }

        $entryDevice = $this->ids['device.ESP32-ENTRY-01'] ?? null;
        $exitDevice  = $this->ids['device.ESP32-EXIT-01'] ?? null;
        $guards      = array_values(array_filter([
            $this->ids['user.dcruz'] ?? null,
            $this->ids['user.jbautista'] ?? null,
            $this->ids['user.rsantiago'] ?? null,
        ]));

        $sequence = 0;
        $created  = 0;

        $now = now();

        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 0; $daysAgo--) {
            $day = now()->modify(sprintf('-%d days', $daysAgo));

            // Weekends are quieter, which makes the charts look like a real
            // operation rather than uniform noise.
            $isWeekend  = in_array((int) $day->format('N'), [6, 7], true);
            $movements  = $isWeekend ? random_int(4, 9) : random_int(10, 22);

            for ($i = 0; $i < $movements; $i++) {
                $vehicle   = $vehicles[array_rand($vehicles)];
                $vehicleId = (int) $vehicle['vehicle_id'];

                $entryAt = $this->randomTimeOfDay($day);

                // Today's slots run to the evening, so a randomly chosen hour
                // can land after the moment of seeding. A monitoring record
                // dated in the future would be nonsense, so it is skipped.
                if ($entryAt > $now) {
                    continue;
                }

                $exitAt = $entryAt->modify('+' . random_int(12, 260) . ' minutes');

                // Movements whose exit has not come around yet are skipped
                // here; the open visits that give the dashboard live activity
                // are created deliberately afterwards, so the demonstration
                // looks the same whatever hour it is seeded at.
                if ($exitAt > $now) {
                    continue;
                }

                $stillInside = false;

                $guard = $guards === [] ? null : $guards[array_rand($guards)];

                $this->connection->execute(
                    'INSERT INTO `vehicle_access_logs`
                        (`transaction_reference`, `vehicle_id`, `driver_id`, `rfid_tag_id`, `scanned_uid`,
                         `plate_number`, `entry_device_id`, `entry_time`, `entry_operator_id`, `entry_verification`,
                         `exit_device_id`, `exit_time`, `exit_operator_id`, `exit_verification`,
                         `access_type`, `status`, `result`, `is_visitor`, `created_at`)
                     VALUES
                        (:reference, :vehicle, :driver, :tag, :uid,
                         :plate, :entryDevice, :entryTime, :entryOperator, :entryVerification,
                         :exitDevice, :exitTime, :exitOperator, :exitVerification,
                         :accessType, :status, :result, 0, :createdAt)',
                    [
                        'reference'         => $this->reference('ACC', ++$sequence),
                        'vehicle'           => $vehicleId,
                        'driver'            => $vehicle['driver_id'] === null ? null : (int) $vehicle['driver_id'],
                        'tag'               => (int) $vehicle['rfid_tag_id'],
                        'uid'               => (string) $vehicle['rfid_uid'],
                        'plate'             => (string) $vehicle['plate_number'],
                        'entryDevice'       => $entryDevice,
                        'entryTime'         => $entryAt->format('Y-m-d H:i:s'),
                        'entryOperator'     => $guard,
                        'entryVerification' => 'rfid',
                        'exitDevice'        => $stillInside ? null : $exitDevice,
                        'exitTime'          => $exitAt?->format('Y-m-d H:i:s'),
                        'exitOperator'      => $stillInside ? null : $guard,
                        'exitVerification'  => $stillInside ? null : 'rfid',
                        'accessType'        => $stillInside ? 'entry' : 'exit',
                        'status'            => $stillInside ? 'inside' : 'completed',
                        'result'            => 'granted',
                        'createdAt'         => $entryAt->format('Y-m-d H:i:s'),
                    ]
                );

                $created++;
            }
        }

        $this->inserted += $created;

        $this->openCurrentVisits($vehicles, $entryDevice, $guards, $sequence);
        $this->seedDenials($entryDevice, $guards);
    }

    /**
     * Leave a handful of vehicles inside so the live dashboard, the
     * "vehicles currently inside" screen and the presence indicators all have
     * something to show.
     *
     * @param list<array<string,mixed>> $vehicles
     * @param list<int>                 $guards
     */
    private function openCurrentVisits(array $vehicles, ?int $entryDevice, array $guards, int $sequence): void
    {
        // Only vehicles with no open visit are eligible: the schema permits
        // exactly one, and the seeder must not be the thing that violates the
        // rule it is demonstrating.
        $candidates = $vehicles;
        shuffle($candidates);

        $opened = 0;

        foreach ($candidates as $vehicle) {
            if ($opened >= 4) {
                break;
            }

            $vehicleId = (int) $vehicle['vehicle_id'];

            $alreadyInside = (int) $this->connection->scalar(
                "SELECT COUNT(*) FROM `vehicle_access_logs` WHERE `vehicle_id` = ? AND `status` = 'inside'",
                [$vehicleId]
            ) > 0;

            if ($alreadyInside) {
                continue;
            }

            $entryAt = now()->modify('-' . random_int(15, 240) . ' minutes');

            $this->connection->execute(
                'INSERT INTO `vehicle_access_logs`
                    (`transaction_reference`, `vehicle_id`, `driver_id`, `rfid_tag_id`, `scanned_uid`,
                     `plate_number`, `entry_device_id`, `entry_time`, `entry_operator_id`, `entry_verification`,
                     `access_type`, `status`, `result`, `is_visitor`, `created_at`)
                 VALUES
                    (:reference, :vehicle, :driver, :tag, :uid,
                     :plate, :entryDevice, :entryTime, :entryOperator, :entryVerification,
                     :accessType, :status, :result, 0, :createdAt)',
                [
                    'reference'         => $this->reference('ACC', ++$sequence),
                    'vehicle'           => $vehicleId,
                    'driver'            => $vehicle['driver_id'] === null ? null : (int) $vehicle['driver_id'],
                    'tag'               => (int) $vehicle['rfid_tag_id'],
                    'uid'               => (string) $vehicle['rfid_uid'],
                    'plate'             => (string) $vehicle['plate_number'],
                    'entryDevice'       => $entryDevice,
                    'entryTime'         => $entryAt->format('Y-m-d H:i:s'),
                    'entryOperator'     => $guards === [] ? null : $guards[array_rand($guards)],
                    'entryVerification' => 'rfid',
                    'accessType'        => 'entry',
                    'status'            => 'inside',
                    'result'            => 'granted',
                    'createdAt'         => $entryAt->format('Y-m-d H:i:s'),
                ]
            );

            $this->inserted++;
            $opened++;
        }
    }

    /**
     * A handful of rejected scans, so the denial analytics are not empty.
     *
     * @param list<int> $guards
     */
    private function seedDenials(?int $entryDevice, array $guards): void
    {
        $reasons = [
            ['denied_unknown_tag',      'RFID tag is not registered'],
            ['denied_expired_tag',      'RFID tag has expired'],
            ['denied_inactive_vehicle', 'Vehicle is inactive'],
            ['denied_duplicate_entry',  'Vehicle is already inside'],
            ['denied_no_active_entry',  'Vehicle has no open entry record'],
        ];

        for ($i = 0; $i < 18; $i++) {
            [$code, $reason] = $reasons[array_rand($reasons)];
            $occurredAt = $this->randomTimeOfDay(now()->modify('-' . random_int(0, self::HISTORY_DAYS) . ' days'));

            $this->connection->execute(
                'INSERT INTO `access_denials`
                    (`device_id`, `scanned_uid`, `attempted_type`, `reason_code`, `reason`,
                     `operator_id`, `ip_address`, `occurred_at`)
                 VALUES (:device, :uid, :type, :code, :reason, :operator, :ip, :occurredAt)',
                [
                    'device'     => $entryDevice,
                    'uid'        => strtoupper(bin2hex(random_bytes(4))),
                    'type'       => random_int(0, 4) === 0 ? 'exit' : 'entry',
                    'code'       => $code,
                    'reason'     => $reason,
                    'operator'   => $guards === [] ? null : $guards[array_rand($guards)],
                    'ip'         => '192.168.10.' . random_int(20, 30),
                    'occurredAt' => $occurredAt->format('Y-m-d H:i:s'),
                ]
            );

            $this->inserted++;
        }
    }

    private function seedVisitorActivity(): void
    {
        if ($this->tableHasRows('visitor_logs')) {
            return;
        }

        /** @var list<array<string,mixed>> $cards */
        $cards = $this->connection->select("SELECT `rfid_card_id` FROM `rfid_cards` WHERE `status` = 'available' LIMIT 12");
        /** @var list<array<string,mixed>> $visitors */
        $visitors = $this->connection->select('SELECT `visitor_id` FROM `visitors`');

        if ($cards === [] || $visitors === []) {
            return;
        }

        $purposes = [
            'Visiting an interment site',
            'Delivery of memorial materials',
            'Contracted grounds maintenance',
            'Funeral service coordination',
            'Regulatory inspection',
        ];

        for ($i = 1; $i <= 14; $i++) {
            $daysAgo  = random_int(0, self::HISTORY_DAYS);
            $issuedAt = $this->randomTimeOfDay(now()->modify('-' . $daysAgo . ' days'));
            $validTo  = $issuedAt->modify('+12 hours');

            $entryAt = $issuedAt->modify('+' . random_int(2, 20) . ' minutes');

            // Anything that has not happened yet is left unrecorded rather
            // than dated into the future.
            if ($entryAt > now()) {
                continue;
            }

            $exitAt = $entryAt->modify('+' . random_int(20, 180) . ' minutes');
            $isOpen = $exitAt > now();

            if ($isOpen) {
                $exitAt = null;
            }

            $this->connection->execute(
                'INSERT INTO `visitor_logs`
                    (`pass_reference`, `visitor_id`, `rfid_card_id`, `purpose`, `destination`,
                     `vehicle_plate`, `vehicle_description`, `companions`, `authorized_by`, `issued_by`,
                     `issued_at`, `valid_from`, `valid_until`, `entry_time`, `exit_time`, `status`, `created_at`)
                 VALUES
                    (:reference, :visitor, :card, :purpose, :destination,
                     :plate, :description, :companions, :authorisedBy, :issuedBy,
                     :issuedAt, :validFrom, :validUntil, :entryTime, :exitTime, :status, :createdAt)',
                [
                    'reference'    => $this->reference('VIS', $i),
                    'visitor'      => (int) $visitors[array_rand($visitors)]['visitor_id'],
                    'card'         => (int) $cards[($i - 1) % count($cards)]['rfid_card_id'],
                    'purpose'      => $purposes[array_rand($purposes)],
                    'destination'  => 'Garden of Peace, Section ' . chr(65 + random_int(0, 5)),
                    'plate'        => sprintf('%s %d', strtoupper(substr(md5((string) $i), 0, 3)), random_int(1000, 9999)),
                    'description'  => 'Private vehicle',
                    'companions'   => random_int(0, 4),
                    'authorisedBy' => $this->ids['user.mreyes'] ?? 1,
                    'issuedBy'     => $this->ids['user.dcruz'] ?? 1,
                    'issuedAt'     => $issuedAt->format('Y-m-d H:i:s'),
                    'validFrom'    => $issuedAt->format('Y-m-d H:i:s'),
                    'validUntil'   => $validTo->format('Y-m-d H:i:s'),
                    'entryTime'    => $entryAt->format('Y-m-d H:i:s'),
                    'exitTime'     => $exitAt?->format('Y-m-d H:i:s'),
                    'status'       => $isOpen ? 'inside' : 'completed',
                    'createdAt'    => $issuedAt->format('Y-m-d H:i:s'),
                ]
            );

            $this->inserted++;
        }
    }

    private function seedHeartbeats(): void
    {
        if ($this->tableHasRows('device_heartbeats')) {
            return;
        }

        /** @var list<array<string,mixed>> $devices */
        $devices = $this->connection->select('SELECT `device_id`, `firmware_version`, `ip_address` FROM `devices`');

        foreach ($devices as $device) {
            // Two hours of 30-second beats is enough to draw the health charts
            // without bloating the demonstration data set.
            for ($minutesAgo = 120; $minutesAgo >= 0; $minutesAgo -= 5) {
                $this->connection->execute(
                    'INSERT INTO `device_heartbeats`
                        (`device_id`, `firmware_version`, `ip_address`, `signal_strength`, `free_heap_bytes`,
                         `heap_total_bytes`, `memory_usage_pct`, `cpu_usage_pct`, `temperature_c`,
                         `uptime_seconds`, `queued_requests`, `reported_status`, `received_at`)
                     VALUES (:device, :firmware, :ip, :signal, :freeHeap, :totalHeap, :memory, :cpu, :temperature,
                             :uptime, :queued, :status, :receivedAt)',
                    [
                        'device'      => (int) $device['device_id'],
                        'firmware'    => $device['firmware_version'],
                        'ip'          => $device['ip_address'],
                        'signal'      => -1 * random_int(45, 72),
                        'freeHeap'    => random_int(140000, 210000),
                        'totalHeap'   => 327680,
                        'memory'      => round(random_int(3500, 5800) / 100, 2),
                        'cpu'         => round(random_int(800, 3400) / 100, 2),
                        'temperature' => round(random_int(3200, 4600) / 100, 2),
                        'uptime'      => 900000 - ($minutesAgo * 60),
                        'queued'      => random_int(0, 1),
                        'status'      => 'ready',
                        'receivedAt'  => now()->modify('-' . $minutesAgo . ' minutes')->format('Y-m-d H:i:s'),
                    ]
                );

                $this->inserted++;
            }
        }
    }

    private function seedSecurityEvents(): void
    {
        if ($this->tableHasRows('security_events')) {
            return;
        }

        $events = [
            ['failed_login',       'medium',   'Three consecutive failed sign-in attempts for username "dcruz".',           'rejected'],
            ['unknown_rfid',       'high',     'An unregistered RFID tag was presented at the entry station.',              'rejected'],
            ['unknown_device',     'critical', 'A request arrived from device code "ESP32-UNKNOWN-99", which is not registered.', 'rejected'],
            ['rate_limit',         'medium',   'The access-scan rate limit was exceeded by 192.168.10.21.',                 'rate_limited'],
            ['replay_attack',      'critical', 'A previously used nonce was presented by ESP32-ENTRY-01.',                  'rejected'],
            ['fingerprint_failure','high',     'Four consecutive fingerprint verification failures at the entry station.',  'rejected'],
            ['account_locked',     'high',     'Account "rsantiago" was locked after exceeding the failed-attempt threshold.', 'account_locked'],
            ['flood_detected',     'critical', 'Request flooding detected from 192.168.10.77; the source was blocked.',     'blocked'],
        ];

        foreach ($events as $index => [$type, $severity, $description, $action]) {
            $this->connection->execute(
                'INSERT INTO `security_events`
                    (`event_type`, `severity`, `description`, `ip_address`, `action_taken`, `status`, `occurred_at`)
                 VALUES (:type, :severity, :description, :ip, :action, :status, :occurredAt)',
                [
                    'type'        => $type,
                    'severity'    => $severity,
                    'description' => $description,
                    'ip'          => '192.168.10.' . random_int(20, 90),
                    'action'      => $action,
                    'status'      => $index < 3 ? 'new' : ($index < 6 ? 'acknowledged' : 'resolved'),
                    'occurredAt'  => now()->modify('-' . random_int(1, 20) . ' days')->format('Y-m-d H:i:s'),
                ]
            );

            $this->inserted++;
        }
    }

    private function seedNotifications(): void
    {
        if ($this->tableHasRows('notifications')) {
            return;
        }

        $notifications = [
            ['device.offline',   'Monitoring station offline',  'ESP32-EXIT-01 stopped reporting for four minutes.',        'critical', 'fa-plug-circle-xmark'],
            ['rfid.unknown',     'Unregistered tag presented',  'An unknown RFID tag was read at the entry station.',       'high',     'fa-tag'],
            ['backup.completed', 'Backup completed',            'The scheduled database backup finished successfully.',     'normal',   'fa-database'],
            ['security.alert',   'Security event requires review','A replay attempt was blocked at the entry station.',     'critical', 'fa-shield-halved'],
            ['vehicle.entered',  'Vehicle entered',             'ABC 1234 entered through the Main Gate.',                  'low',      'fa-right-to-bracket'],
            ['user.created',     'New user account',            'Account "tvillanueva" was created with the Auditor role.', 'normal',   'fa-user-plus'],
        ];

        foreach ($notifications as $index => [$type, $title, $description, $priority, $icon]) {
            $this->connection->execute(
                'INSERT INTO `notifications`
                    (`type_key`, `title`, `description`, `priority`, `recipient_id`, `icon`, `is_read`, `created_at`)
                 VALUES (:type, :title, :description, :priority, :recipient, :icon, :isRead, :createdAt)',
                [
                    'type'        => $type,
                    'title'       => $title,
                    'description' => $description,
                    'priority'    => $priority,
                    'recipient'   => 1,
                    'icon'        => $icon,
                    'isRead'      => $index > 3 ? 1 : 0,
                    'createdAt'   => now()->modify('-' . $index . ' hours')->format('Y-m-d H:i:s'),
                ]
            );

            $this->inserted++;
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Pick a plausible time of day, concentrated in visiting hours.
     */
    private function randomTimeOfDay(DateTimeImmutable $day): DateTimeImmutable
    {
        // Weighted hour distribution: a memorial park is busiest mid-morning
        // and mid-afternoon.
        $hours  = [6, 7, 8, 8, 9, 9, 9, 10, 10, 10, 11, 11, 12, 13, 13, 14, 14, 15, 15, 15, 16, 16, 17, 18];
        $hour   = $hours[array_rand($hours)];

        return $day->setTime($hour, random_int(0, 59), random_int(0, 59));
    }

    /**
     * Build a transaction reference in the same shape the application uses.
     */
    private function reference(string $prefix, int $sequence): string
    {
        return sprintf('%s-%s-%04d-%s', $prefix, now()->format('Ymd'), $sequence, Str::randomCode(4));
    }
}
