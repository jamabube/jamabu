<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database\Seeder;
use App\Core\Security\Hasher;
use RuntimeException;

/**
 * Creates the first administrator account.
 *
 * The password comes from VAMS_ADMIN_PASSWORD when set; otherwise a strong one
 * is generated and printed once. It is never written to a file or a log, and
 * the account is flagged must_change_password so the operator has to replace
 * it at first sign-in.
 *
 * Re-running this seeder never resets an existing account's password.
 *
 * @package Database\Seeders
 * @version 1.0.0
 */
final class AdministratorSeeder extends Seeder
{
    public function description(): string
    {
        return 'Initial administrator account';
    }

    public function run(): void
    {
        $roleId = $this->idOf('roles', 'role_slug', 'administrator');

        if ($roleId === null) {
            throw new RuntimeException('The administrator role is missing; run the RBAC seeder first.');
        }

        $username = (string) env('VAMS_ADMIN_USERNAME', 'administrator');

        if ($this->idOf('users', 'username', $username) !== null) {
            $this->skipped++;
            $this->output->info(sprintf('Administrator "%s" already exists; password left unchanged.', $username));

            return;
        }

        $supplied = (string) env('VAMS_ADMIN_PASSWORD', '');
        $password = $supplied !== '' ? $supplied : $this->generatePassword();

        $this->upsert('users', [
            'employee_number'      => (string) env('VAMS_ADMIN_EMPLOYEE_NUMBER', 'EMP-0001'),
            'first_name'           => (string) env('VAMS_ADMIN_FIRST_NAME', 'System'),
            'last_name'            => (string) env('VAMS_ADMIN_LAST_NAME', 'Administrator'),
            'username'             => $username,
            'email'                => (string) env('VAMS_ADMIN_EMAIL', 'administrator@forestlawn.local'),
            'password_hash'        => Hasher::make($password),
            'password_changed_at'  => $this->now(),
            // Even an operator-supplied password must be replaced with one only
            // that person knows: the value passed on the command line is
            // visible in the shell history.
            'must_change_password' => 1,
            'role_id'              => $roleId,
            'department_id'        => $this->idOf('departments', 'department_code', 'ICT'),
            'position'             => 'System Administrator',
            'status'               => 'active',
        ], ['username']);

        $this->announce($username, $password, $supplied === '');
    }

    /**
     * Generate a password that satisfies the configured complexity policy.
     */
    private function generatePassword(): string
    {
        $length = max(16, (int) config('security.password.min_length', 12) + 4);

        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower   = 'abcdefghijkmnopqrstuvwxyz';
        $digits  = '23456789';
        $symbols = '!@#$%^&*-_=+?';
        $all     = $upper . $lower . $digits . $symbols;

        // Seed one character from each required class so the result cannot
        // accidentally fail the policy it was generated for.
        $characters = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle with a cryptographic source rather than shuffle().
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    /**
     * Print the credentials once, prominently.
     */
    private function announce(string $username, string $password, bool $generated): void
    {
        $this->output->line();
        $this->output->line($this->output->colour(str_repeat('*', 72), 'yellow'));
        $this->output->line($this->output->colour('  ADMINISTRATOR ACCOUNT CREATED', 'yellow', 'bold'));
        $this->output->line($this->output->colour(str_repeat('*', 72), 'yellow'));
        $this->output->line();
        $this->output->line('  Username: ' . $this->output->colour($username, 'bold'));

        if ($generated) {
            $this->output->line('  Password: ' . $this->output->colour($password, 'bold', 'green'));
            $this->output->line();
            $this->output->line($this->output->colour(
                '  This password is shown once and is not stored anywhere in readable form.',
                'yellow'
            ));
            $this->output->line($this->output->colour('  Record it now, then sign in and change it.', 'yellow'));
        } else {
            $this->output->line('  Password: ' . $this->output->colour('(as supplied in VAMS_ADMIN_PASSWORD)', 'grey'));
            $this->output->line();
            $this->output->line($this->output->colour(
                '  Remove VAMS_ADMIN_PASSWORD from the environment now that the account exists.',
                'yellow'
            ));
        }

        $this->output->line();
        $this->output->line('  The account must change its password at first sign-in.');
        $this->output->line($this->output->colour(str_repeat('*', 72), 'yellow'));
        $this->output->line();
    }
}
