<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Repositories\UserRepository;
use App\Services\UserService;
use Throwable;

/**
 * Reset a user's password, and optionally unlock the account.
 *
 * The recovery path when every administrator is locked out: the web interface
 * cannot help then, and somebody with shell access has to be able to issue a
 * password without touching the database by hand.
 *
 * Every session belonging to the account is ended by the reset. That is not
 * incidental — after a password is issued to somebody new, an old browser
 * session that survived would be an unaccounted way in.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class UserPasswordCommand extends Command
{
    protected string $name = 'user:password';
    protected string $description = 'Reset a user password and end that account\'s sessions.';
    protected string $usage = 'php bin/console user:password <username|user-id> [--password=] [--unlock] [--force]';

    public function handle(): int
    {
        $identifier = (string) ($this->argument(0) ?? '');

        if ($identifier === '') {
            $identifier = $this->output->ask('Username or user id');
        }

        $user = $this->resolve($identifier);

        if ($user === null) {
            $this->output->error(sprintf('No account matches "%s".', $identifier));

            return 1;
        }

        $userId   = (int) $user['user_id'];
        $username = (string) $user['username'];

        $this->output->title('Password reset');
        $this->output->table(
            ['Field', 'Value'],
            [
                ['User', $username],
                ['Name', (string) ($user['full_name'] ?? '')],
                ['Role', (string) ($user['role_name'] ?? '')],
                ['Status', (string) ($user['status'] ?? '')],
                ['Locked until', (string) ($user['locked_until'] ?? '—')],
            ]
        );

        if (!$this->isForced()
            && !$this->output->confirm(sprintf('Reset the password for "%s" and end its sessions?', $username))) {
            $this->output->comment('Nothing was changed.');

            return 0;
        }

        // A password given on the command line is visible in the shell history
        // and in the process list. Prompting keeps it off both.
        $supplied = $this->option('password');
        $password = is_string($supplied) && $supplied !== '' ? $supplied : null;

        if ($password === null && !$this->hasOption('generate')) {
            $typed = $this->output->askHidden('New password (blank to generate one)');

            $password = $typed === '' ? null : $typed;
        }

        try {
            $issued = $this->service(UserService::class)->resetPassword($userId, $password);
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());

            return 1;
        }

        // resetPassword already clears a lock, so --unlock is only meaningful
        // as a statement of intent; it is reported rather than acted on twice.
        if ($this->hasOption('unlock')) {
            $this->output->info('The account lock was cleared as part of the reset.');
        }

        $this->output->success(sprintf('The password for "%s" was reset.', $username));

        if ($password === null) {
            $this->output->line();
            $this->output->info('Temporary password: ' . $this->output->colour($issued, 'bold'));
        }

        $this->output->comment('The account must change it at the next sign-in.');

        return 0;
    }

    /**
     * Find the account by numeric id or by username.
     *
     * @return array<string,mixed>|null
     */
    private function resolve(string $identifier): ?array
    {
        $users = $this->service(UserRepository::class);

        if (ctype_digit($identifier)) {
            return $users->findWithRole((int) $identifier);
        }

        $row = $users->findForAuthentication($identifier);

        return $row === null ? null : $users->findWithRole((int) $row['user_id']);
    }
}
