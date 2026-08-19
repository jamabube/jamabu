<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;

/**
 * Read access to the reference tables that populate dropdowns and badges.
 *
 * These lists change rarely and are read on nearly every page, so they are
 * gathered here rather than being re-queried ad hoc from a dozen controllers.
 * Nothing here writes: the reference data is owned by the seeders and the
 * settings module.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class ReferenceRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function vehicleTypes(): array
    {
        return $this->connection->select(
            "SELECT `vehicle_type_id`, `type_code`, `type_name`, `description`, `icon`
               FROM `vehicle_types`
              WHERE `status` = 'active'
              ORDER BY `sort_order`, `type_name`"
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function visitorTypes(): array
    {
        return $this->connection->select(
            "SELECT `visitor_type_id`, `type_code`, `type_name`, `description`,
                    `default_validity_hours`, `requires_authoriser`
               FROM `visitor_types`
              WHERE `status` = 'active'
              ORDER BY `sort_order`, `type_name`"
        );
    }

    /**
     * Reference codes grouped by category, as the badge helpers expect them.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function codes(): array
    {
        $rows = $this->connection->select(
            "SELECT `category`, `code`, `label`, `description`, `badge_class`
               FROM `reference_codes`
              WHERE `status` = 'active'
              ORDER BY `category`, `sort_order`, `label`"
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row['category']][] = $row;
        }

        return $grouped;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function notificationTypes(): array
    {
        return $this->connection->select(
            'SELECT `notification_type_id`, `type_key`, `type_name`, `description`,
                    `default_priority`, `audience_roles`, `channel_database`, `channel_mail`, `is_enabled`
               FROM `notification_types`
              ORDER BY `type_key`'
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function securityRules(): array
    {
        return $this->connection->select(
            'SELECT `security_rule_id`, `rule_key`, `rule_name`, `description`, `threshold_value`,
                    `window_seconds`, `action`, `severity`, `is_enabled`, `updated_at`
               FROM `security_rules`
              ORDER BY `rule_name`'
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function modules(): array
    {
        return $this->connection->select(
            "SELECT `module_id`, `module_key`, `module_name`, `description`, `icon`
               FROM `system_modules`
              WHERE `status` = 'active'
              ORDER BY `sort_order`, `module_name`"
        );
    }
}
