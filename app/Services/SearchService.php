<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Security\AuthGuard;
use App\Core\Support\Str;

/**
 * The global search bar.
 *
 * Results are grouped by module and every group is gated on the permission for
 * that module, so search can never become a way to see records the user could
 * not otherwise reach.
 *
 * @package App\Services
 * @version 1.0.0
 */
class SearchService
{
    /** Results returned per module. */
    private const PER_MODULE = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuthGuard $auth
    ) {
    }

    /**
     * Search across every module the user may see.
     *
     * @return array<string,array{label:string,icon:string,results:list<array<string,mixed>>}>
     */
    public function search(string $term): array
    {
        $term = trim($term);

        // A one-character term matches nearly everything and costs a full scan
        // on several tables for no useful result.
        if (mb_strlen($term) < 2) {
            return [];
        }

        $groups = [];

        foreach ($this->providers() as $key => $provider) {
            if ($this->auth->cannot($provider['permission'])) {
                continue;
            }

            $results = ($provider['query'])($term);

            if ($results !== []) {
                $groups[$key] = [
                    'label'   => $provider['label'],
                    'icon'    => $provider['icon'],
                    'results' => $results,
                ];
            }
        }

        return $groups;
    }

    /**
     * The search providers, one per module.
     *
     * @return array<string,array{label:string,icon:string,permission:string,query:callable(string):list<array<string,mixed>>}>
     */
    private function providers(): array
    {
        return [
            'vehicles' => [
                'label'      => 'Vehicles',
                'icon'       => 'fa-car',
                'permission' => 'vehicles.view',
                'query'      => fn (string $term): array => $this->rows(
                    "SELECT `vehicle_id` AS `id`, `plate_number` AS `title`,
                            CONCAT_WS(' — ', `brand`, `model`, `owner_name`) AS `subtitle`,
                            `status`, CONCAT('/vehicles/', `vehicle_id`) AS `link`
                       FROM `v_vehicle_directory`
                      WHERE `deleted_at` IS NULL
                        AND (`plate_number` LIKE :term OR `vehicle_code` LIKE :term
                             OR `owner_name` LIKE :term OR `rfid_uid` LIKE :term
                             OR `brand` LIKE :term OR `model` LIKE :term)
                      ORDER BY `plate_number`
                      LIMIT " . self::PER_MODULE,
                    $term
                ),
            ],
            'drivers' => [
                'label'      => 'Drivers',
                'icon'       => 'fa-id-card',
                'permission' => 'drivers.view',
                'query'      => fn (string $term): array => $this->rows(
                    "SELECT `driver_id` AS `id`, `full_name` AS `title`,
                            CONCAT_WS(' — ', `driver_code`, `contact_number`) AS `subtitle`,
                            `status`, CONCAT('/drivers/', `driver_id`) AS `link`
                       FROM `drivers`
                      WHERE `deleted_at` IS NULL
                        AND (`full_name` LIKE :term OR `driver_code` LIKE :term
                             OR `contact_number` LIKE :term OR `government_id` LIKE :term)
                      ORDER BY `full_name`
                      LIMIT " . self::PER_MODULE,
                    $term
                ),
            ],
            'owners' => [
                'label'      => 'Owners',
                'icon'       => 'fa-user-tie',
                'permission' => 'owners.view',
                'query'      => fn (string $term): array => $this->rows(
                    "SELECT `owner_id` AS `id`, `full_name` AS `title`,
                            CONCAT_WS(' — ', `owner_code`, `company`) AS `subtitle`,
                            `status`, CONCAT('/owners/', `owner_id`) AS `link`
                       FROM `vehicle_owners`
                      WHERE `deleted_at` IS NULL
                        AND (`full_name` LIKE :term OR `owner_code` LIKE :term OR `company` LIKE :term)
                      ORDER BY `full_name`
                      LIMIT " . self::PER_MODULE,
                    $term
                ),
            ],
            'visitors' => [
                'label'      => 'Visitors',
                'icon'       => 'fa-user-clock',
                'permission' => 'visitors.view',
                'query'      => fn (string $term): array => $this->rows(
                    "SELECT `visitor_id` AS `id`, `full_name` AS `title`,
                            CONCAT_WS(' — ', `visitor_code`, `company`) AS `subtitle`,
                            `status`, CONCAT('/visitors/', `visitor_id`) AS `link`
                       FROM `visitors`
                      WHERE `deleted_at` IS NULL
                        AND (`full_name` LIKE :term OR `visitor_code` LIKE :term OR `company` LIKE :term)
                      ORDER BY `full_name`
                      LIMIT " . self::PER_MODULE,
                    $term
                ),
            ],
            'rfid' => [
                'label'      => 'RFID',
                'icon'       => 'fa-tags',
                'permission' => 'rfid.view',
                'query'      => fn (string $term): array => $this->rows(
                    "SELECT `rfid_tag_id` AS `id`, `tag_code` AS `title`,
                            CONCAT('UID ', `rfid_uid`) AS `subtitle`,
                            `status`, CONCAT('/rfid/tags/', `rfid_tag_id`) AS `link`
                       FROM `rfid_tags`
                      WHERE `deleted_at` IS NULL AND (`tag_code` LIKE :term OR `rfid_uid` LIKE :term OR `serial_number` LIKE :term)
                      ORDER BY `tag_code`
                      LIMIT " . self::PER_MODULE,
                    $term
                ),
            ],
            'monitoring' => [
                'label'      => 'Monitoring records',
                'icon'       => 'fa-clock-rotate-left',
                'permission' => 'monitoring.view',
                'query'      => fn (string $term): array => $this->rows(
                    "SELECT `access_log_id` AS `id`, `transaction_reference` AS `title`,
                            CONCAT_WS(' — ', `plate_number`, `entry_time`) AS `subtitle`,
                            `status`, CONCAT('/monitoring/records/', `access_log_id`) AS `link`
                       FROM `v_access_monitoring`
                      WHERE `transaction_reference` LIKE :term OR `plate_number` LIKE :term
                            OR `scanned_uid` LIKE :term OR `owner_name` LIKE :term
                      ORDER BY `entry_time` DESC
                      LIMIT " . self::PER_MODULE,
                    $term
                ),
            ],
            'devices' => [
                'label'      => 'Devices',
                'icon'       => 'fa-microchip',
                'permission' => 'devices.view',
                'query'      => fn (string $term): array => $this->rows(
                    "SELECT `device_id` AS `id`, `device_name` AS `title`,
                            CONCAT_WS(' — ', `device_code`, `location`) AS `subtitle`,
                            `connectivity` AS `status`, CONCAT('/devices/', `device_id`) AS `link`
                       FROM `v_device_status`
                      WHERE `deleted_at` IS NULL
                        AND (`device_name` LIKE :term OR `device_code` LIKE :term
                             OR `location` LIKE :term OR `mac_address` LIKE :term OR `ip_address` LIKE :term)
                      ORDER BY `device_name`
                      LIMIT " . self::PER_MODULE,
                    $term
                ),
            ],
            'users' => [
                'label'      => 'Users',
                'icon'       => 'fa-users',
                'permission' => 'users.view',
                'query'      => fn (string $term): array => $this->rows(
                    "SELECT `user_id` AS `id`, `full_name` AS `title`,
                            CONCAT_WS(' — ', `username`, `role_name`) AS `subtitle`,
                            `status`, CONCAT('/users/', `user_id`) AS `link`
                       FROM `v_user_directory`
                      WHERE `deleted_at` IS NULL
                        AND (`full_name` LIKE :term OR `username` LIKE :term
                             OR `email` LIKE :term OR `employee_number` LIKE :term)
                      ORDER BY `full_name`
                      LIMIT " . self::PER_MODULE,
                    $term
                ),
            ],
        ];
    }

    /**
     * Run a search query with the term bound as a wildcard pattern.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, string $term): array
    {
        // Wildcards inside the term are neutralised so a search for "100%"
        // does not become a match-everything pattern.
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';

        return $this->connection->select($sql, ['term' => $pattern]);
    }

    /**
     * A flat list for the type-ahead dropdown.
     *
     * @return list<array<string,mixed>>
     */
    public function quick(string $term, int $limit = 10): array
    {
        $flat = [];

        foreach ($this->search($term) as $moduleKey => $group) {
            foreach ($group['results'] as $result) {
                $flat[] = array_merge($result, [
                    'module'      => $moduleKey,
                    'module_label'=> $group['label'],
                    'icon'        => $group['icon'],
                ]);

                if (count($flat) >= $limit) {
                    return $flat;
                }
            }
        }

        return $flat;
    }

    /**
     * Detect that a term looks like an RFID UID, so a guard scanning a tag into
     * the search box lands on the right record immediately.
     */
    public function looksLikeRfidUid(string $term): bool
    {
        $normalised = Str::normaliseUid($term);

        return strlen($normalised) >= 8 && strlen($normalised) === strlen(trim($term));
    }
}
