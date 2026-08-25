<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Security\AuthGuard;
use App\Repositories\DepartmentRepository;
use App\Repositories\RoleRepository;
use App\Services\UserService;
use Throwable;

/**
 * Create a user account from the command line.
 *
 * The web interface is the normal way to add staff. This exists for the case
 * the web interface cannot help with: every administrator account is locked
 * out, and somebody with shell access needs a way back in.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class UserCreateCommand extends Command
{
    protected string $name = 'user:create';
    protected string $description = 'Create a user account and print its temporary password.';
    protected string $usage = 'php bin/console user:create [--username=] [--email=] [--name=] [--role=] [--department=] [--password=]';

    public function handle(): int
    {
        $roles = $this->service(RoleRepository::class);

        $username = $this->stringOption('username') ?: $this->output->ask('Username');
        $fullName = $this->stringOption('name') ?: $this->output->ask('Full name');
        $email    = $this->stringOption('email') ?: $this->output->ask('Email address');
        $roleSlug = $this->stringOption('role');

        if ($roleSlug === '') {
            $this->output->title('Available roles');
            $this->output->table(
                ['Slug', 'Role', 'Members'],
                array_map(
                    static fn (array $role): array => [
                        (string) $role['role_slug'],
                        (string) $role['role_name'],
                        (string) ($role['user_count'] ?? 0),
                    ],
                    $roles->allWithCounts()
                )
            );

            $roleSlug = $this->output->ask('Role slug');
        }

        $role = $roles->findBySlug($roleSlug);

        if ($role === null) {
            $this->output->error(sprintf('No role has the slug "%s".', $roleSlug));

            return 1;
        }

        // full_name is a generated column: the table stores the parts and
        // derives the whole, so a single typed name has to be split before it
        // can be inserted.
        [$firstName, $lastName] = $this->splitName($fullName);

        if ($firstName === '' || $lastName === '') {
            $this->output->error('Give a first and a last name, for example: --name="Maria Santos"');

            return 1;
        }

        $attributes = [
            'username'   => $username,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $email,
            'role_id'    => (int) $role['role_id'],
            'status'     => 'active',
        ];

        $departmentCode = $this->stringOption('department');

        if ($departmentCode !== '') {
            $department = $this->findDepartment($departmentCode);

            if ($department === null) {
                $this->output->error(sprintf('No active department matches "%s".', $departmentCode));

                return 1;
            }

            $attributes['department_id'] = (int) $department['department_id'];
        }

        // A password supplied on the command line is visible in the shell
        // history and in the process list, so it is offered but not the
        // default: leaving it out generates one that only appears here.
        $password = $this->stringOption('password');

        try {
            // Assigning a role is checked against the actor's own authority,
            // and at a shell prompt there is no signed-in actor to check. The
            // elevation is scoped to this one call.
            $result = $this->service(AuthGuard::class)->withSystemAuthority(
                fn (): array => $this->service(UserService::class)->create(
                    $attributes,
                    $password === '' ? null : $password
                )
            );
        } catch (Throwable $e) {
            $this->reportFailure($e);

            return 1;
        }

        $this->output->success(sprintf('Account "%s" was created with id %d.', $username, $result['user_id']));

        if ($password === '') {
            $this->output->line();
            $this->output->info('Temporary password: ' . $this->output->colour($result['password'], 'bold'));
            $this->output->comment('It is shown once. The account must change it at first sign-in.');
        }

        return 0;
    }

    /**
     * Split a typed name into a first and a last part.
     *
     * Everything between the two goes to the first name rather than being
     * guessed at as a middle name: "Maria Cruz Santos" is more often somebody
     * with two given names than somebody with a middle name, and the account
     * holder can correct it in their profile either way.
     *
     * @return array{0:string,1:string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        if (count($parts) < 2) {
            return [$parts[0] ?? '', ''];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /**
     * Match a department by its code or its name, either way case-insensitively.
     *
     * @return array<string,mixed>|null
     */
    private function findDepartment(string $needle): ?array
    {
        $needle = mb_strtolower($needle);

        foreach ($this->service(DepartmentRepository::class)->selectList() as $department) {
            if (mb_strtolower((string) $department['department_code']) === $needle
                || mb_strtolower((string) $department['department_name']) === $needle) {
                return $department;
            }
        }

        return null;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name, '');

        return is_string($value) ? trim($value) : '';
    }
}
