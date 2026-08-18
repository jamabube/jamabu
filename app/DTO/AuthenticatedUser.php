<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Immutable snapshot of the signed-in user.
 *
 * Only the fields the request actually needs are carried, so a password hash
 * or a fingerprint reference can never be reached from a template.
 *
 * @package App\DTO
 * @version 1.0.0
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public int $id,
        public string $username,
        public string $fullName,
        public string $email,
        public int $roleId,
        public string $roleName,
        public string $roleSlug,
        public ?int $departmentId = null,
        public ?string $departmentName = null,
        public ?string $profilePicture = null,
        public ?string $employeeNumber = null,
        public ?string $passwordChangedAt = null,
        public ?string $lastLoginAt = null,
        public bool $mustChangePassword = false
    ) {
    }

    /**
     * Build from a users-table row joined with roles and departments.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                 (int) $row['user_id'],
            username:           (string) $row['username'],
            fullName:           trim((string) ($row['full_name'] ?? '')),
            email:              (string) ($row['email'] ?? ''),
            roleId:             (int) ($row['role_id'] ?? 0),
            roleName:           (string) ($row['role_name'] ?? ''),
            roleSlug:           (string) ($row['role_slug'] ?? ''),
            departmentId:       isset($row['department_id']) ? (int) $row['department_id'] : null,
            departmentName:     isset($row['department_name']) ? (string) $row['department_name'] : null,
            profilePicture:     isset($row['profile_picture']) ? (string) $row['profile_picture'] : null,
            employeeNumber:     isset($row['employee_number']) ? (string) $row['employee_number'] : null,
            passwordChangedAt:  isset($row['password_changed_at']) ? (string) $row['password_changed_at'] : null,
            lastLoginAt:        isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            mustChangePassword: (bool) ($row['must_change_password'] ?? false),
        );
    }

    /**
     * Initials used by the avatar placeholder in the navigation bar.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->fullName)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return strtoupper(substr($this->username, 0, 2));
        }

        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }

        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id'         => $this->id,
            'username'        => $this->username,
            'full_name'       => $this->fullName,
            'email'           => $this->email,
            'role_id'         => $this->roleId,
            'role_name'       => $this->roleName,
            'role_slug'       => $this->roleSlug,
            'department_id'   => $this->departmentId,
            'department_name' => $this->departmentName,
            'profile_picture' => $this->profilePicture,
            'employee_number' => $this->employeeNumber,
            'last_login_at'   => $this->lastLoginAt,
        ];
    }
}
