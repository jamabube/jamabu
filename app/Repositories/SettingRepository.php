<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Access to the runtime settings table.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class SettingRepository extends BaseRepository
{
    protected string $table = 'system_settings';
    protected string $primaryKey = 'setting_id';
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'setting_key', 'setting_group', 'label', 'description', 'value', 'default_value',
        'value_type', 'options', 'validation', 'is_sensitive', 'is_editable',
        'requires_restart', 'sort_order', 'updated_by',
    ];

    protected array $sortable = ['setting_key', 'setting_group', 'label', 'sort_order', 'updated_at'];
    protected array $searchable = ['setting_key', 'label', 'description'];

    /**
     * Every setting as key => row, ordered for display.
     *
     * @return array<string,array<string,mixed>>
     */
    public function allKeyed(): array
    {
        $rows   = $this->query()->orderBy('setting_group')->orderBy('sort_order')->get();
        $keyed  = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['setting_key']] = $row;
        }

        return $keyed;
    }

    /**
     * Settings grouped by their section, for the settings page.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->query()->orderBy('setting_group')->orderBy('sort_order')->get() as $row) {
            $groups[(string) $row['setting_group']][] = $row;
        }

        return $groups;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $this->findBy('setting_key', $key);
    }

    /**
     * Write a value, recording who changed it.
     */
    public function setValue(string $key, ?string $value, ?int $updatedBy): int
    {
        return $this->connection->execute(
            'UPDATE `system_settings`
                SET `value` = :value, `updated_by` = :updatedBy, `updated_at` = :updatedAt
              WHERE `setting_key` = :key AND `is_editable` = 1',
            [
                'value'     => $value,
                'updatedBy' => $updatedBy,
                'updatedAt' => $this->timestamp(),
                'key'       => $key,
            ]
        );
    }

    /**
     * Restore a setting to the value it shipped with.
     */
    public function resetToDefault(string $key, ?int $updatedBy): int
    {
        return $this->connection->execute(
            'UPDATE `system_settings`
                SET `value` = `default_value`, `updated_by` = :updatedBy, `updated_at` = :updatedAt
              WHERE `setting_key` = :key AND `is_editable` = 1',
            ['updatedBy' => $updatedBy, 'updatedAt' => $this->timestamp(), 'key' => $key]
        );
    }
}
