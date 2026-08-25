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

        /*
         * The username is not a durable way to recognise the account. It can
         * be changed — renaming the seeded administrator is good practice,
         * since that name is the first one any credential-stuffing attempt
         * tries — and after a rename this seeder found nothing under the old
         * name and tried to insert a second account, which collided on the
         * email it still holds. The failure surfaced as a bare duplicate-key
         * error in the middle of an otherwise ordinary start-up.
         *
         * What this seeder exists to guarantee is that the installation has an
         * administrator, so that is what it checks.
         */
        $existing = $this->connection->selectOne(
            'SELECT `username` FROM `users`
              WHERE `role_id` = ? AND `deleted_at` IS NULL
              ORDER BY `user_id` ASC LIMIT 1',
            [$roleId]
        );

        if ($existing !== null) {
            $this->skipped++;
            $this->output->info(sprintf(
                'Administrator "%s" already exists under that role; nothing to create.',
                (string) $existing['username']
            ));

            return;
        }

        // No administrator, but the identity this seeder would use is taken by
        // somebody else. Saying so beats a duplicate-key error from a seeder
        // the operator did not ask to run.
        $email          = (string) env('VAMS_ADMIN_EMAIL', 'administrator@forestlawn.local');
        $employeeNumber = (string) env('VAMS_ADMIN_EMPLOYEE_NUMBER', 'EMP-0001');

        foreach (['email' => $email, 'employee_number' => $employeeNumber] as $column => $value) {
            if ($this->idOf('users', $column, $value) !== null) {
                throw new RuntimeException(sprintf(
                    'Cannot create the administrator: %s "%s" already belongs to another account. '
                    . 'Set VAMS_ADMIN_%s in .env to a free value, or give that account the administrator role.',
                    str_replace('_', ' ', $column),
                    $value,
                    strtoupper($column)
                ));
            }
        }

        $supplied = (string) env('VAMS_ADMIN_PASSWORD', '');
        $password = $supplied !== '' ? $supplied : $this->generatePassword();

        $this->upsert('users', [
            'employee_number'      => $employeeNumber,
            'first_name'           => (string) env('VAMS_ADMIN_FIRST_NAME', 'System'),
            'last_name'            => (string) env('VAMS_ADMIN_LAST_NAME', 'Administrator'),
            'username'             => $username,
            'email'                => $email,
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
