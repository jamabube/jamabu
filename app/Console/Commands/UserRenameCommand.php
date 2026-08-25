<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Security\AuthGuard;
use App\Repositories\UserRepository;
use App\Services\UserService;
use Throwable;

/**
 * Change an account's username.
 *
 * The service has always supported it, but nothing exposed it: the user form
 * does not accept the field, so an installation was stuck with whatever name
 * the seeder gave the first administrator. Renaming is not cosmetic — an
 * account named "administrator" is the one every credential-stuffing attempt
 * tries first.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class UserRenameCommand extends Command
{
    protected string $name = 'user:rename';
    protected string $description = 'Change the username of an account.';
    protected string $usage = 'php bin/console user:rename <current-username|user-id> <new-username> [--force]';

    public function handle(): int
    {
        $identifier = (string) ($this->argument(0) ?? '');
        $newName    = strtolower(trim((string) ($this->argument(1) ?? '')));

        if ($identifier === '' || $newName === '') {
            $this->output->error('Name the account and the new username:');
            $this->output->comment('    php bin/console user:rename administrator maria.santos');

            return 1;
        }

        // The same shape the account form enforces, applied here so the two
        // routes cannot disagree about what a username may contain.
        if (preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $newName) !== 1) {
            $this->output->error(
                'A username must be 3 to 50 characters, using letters, digits, dots, dashes and underscores.'
            );

            return 1;
        }

        $users = $this->service(UserRepository::class);

        $user = ctype_digit($identifier)
            ? $users->findWithRole((int) $identifier)
            : $this->findByUsername($identifier);

        if ($user === null) {
            $this->output->error(sprintf('No account matches "%s".', $identifier));

            return 1;
        }

        $currentName = (string) $user['username'];

        if ($currentName === $newName) {
            $this->output->info(sprintf('"%s" already has that username. Nothing to do.', $currentName));

            return 0;
        }

        $this->output->title('Rename an account');
        $this->output->table(
            ['Field', 'Value'],
            [
                ['Account', $currentName],
                ['Name', (string) ($user['full_name'] ?? '')],
                ['Role', (string) ($user['role_name'] ?? '')],
                ['New username', $newName],
            ]
        );

        $this->output->warning('Anyone signing in as this account must use the new name from now on.');

        if (!$this->isForced()
            && !$this->output->confirm(sprintf('Rename "%s" to "%s"?', $currentName, $newName))) {
            $this->output->comment('Nothing was changed.');

            return 0;
        }

        try {
            // update() checks the name is free and records the change in the
            // audit trail. The elevation is only for the authorisation check,
            // which expects a signed-in actor and has none at a shell prompt.
            $this->service(AuthGuard::class)->withSystemAuthority(
                fn (): null => $this->service(UserService::class)->update(
                    (int) $user['user_id'],
                    ['username' => $newName]
                )
            );
        } catch (Throwable $e) {
            $this->reportFailure($e);

            return 1;
        }

        $this->output->success(sprintf('"%s" is now "%s".', $currentName, $newName));
        $this->output->comment('The password is unchanged. To issue a new one:');
        $this->output->comment('    php bin/console user:password ' . $newName);

        return 0;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByUsername(string $username): ?array
    {
        $users = $this->service(UserRepository::class);
        $row   = $users->findForAuthentication($username);

        return $row === null ? null : $users->findWithRole((int) $row['user_id']);
    }
}
