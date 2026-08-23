<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Support\Str;
use App\Repositories\BackupRepository;
use App\Services\BackupService;
use Throwable;

/**
 * Restore the database from a recorded backup.
 *
 * The most destructive thing in this application: it replaces every row in
 * the live database. Accordingly it names the archive, states what will be
 * overwritten, and requires the operator to type the filename rather than
 * pressing y — a habit-formed "yes" is not consent to this.
 *
 * A snapshot of the current state is taken first, so restoring the wrong
 * archive is recoverable rather than final.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class BackupRestoreCommand extends Command
{
    protected string $name = 'backup:restore';
    protected string $description = 'Restore the database from a backup. DESTROYS the current data.';
    protected string $usage = 'php bin/console backup:restore <backup-id|filename> [--no-snapshot] [--force]';

    public function handle(): int
    {
        $identifier = (string) ($this->argument(0) ?? '');

        if ($identifier === '') {
            $this->output->error('Name the backup: php bin/console backup:restore 12');
            $this->output->comment('Run backup:list to see what is available.');

            return 1;
        }

        $backups = $this->service(BackupRepository::class);

        $backup = ctype_digit($identifier)
            ? $backups->find((int) $identifier)
            : $backups->findByFilename($identifier);

        if ($backup === null) {
            $this->output->error(sprintf('No backup matches "%s".', $identifier));

            return 1;
        }

        $filename = (string) $backup['filename'];

        $this->output->title('Restore from ' . $filename);
        $this->output->table(
            ['Field', 'Value'],
            [
                ['Backup id', (string) $backup['backup_id']],
                ['Created', (string) ($backup['created_at'] ?? '')],
                ['Type', (string) $backup['backup_type']],
                ['Status', (string) $backup['status']],
                ['Size', Str::bytes((int) ($backup['file_size'] ?? 0))],
                ['Tables', (string) ($backup['table_count'] ?? 0)],
                ['Rows', number_format((int) ($backup['row_count'] ?? 0))],
            ]
        );

        $snapshot = !$this->hasOption('no-snapshot');

        $this->output->error('Every table in the current database will be overwritten.');

        if ($snapshot) {
            $this->output->comment('A snapshot of the current state will be taken first.');
        } else {
            $this->output->warning('--no-snapshot was passed: the current data will not be recoverable.');
        }

        if (!$this->confirmed($filename)) {
            $this->output->comment('Nothing was restored.');

            return 0;
        }

        $this->output->info('Restoring. Do not interrupt this.');

        try {
            $this->service(BackupService::class)->restore(
                (int) $backup['backup_id'],
                null,
                $snapshot
            );
        } catch (Throwable $e) {
            $this->output->error('The restore failed: ' . $e->getMessage());
            $this->output->comment('The database may be in a partial state. Restore the pre-restore snapshot if one was taken.');

            return 1;
        }

        $this->output->success(sprintf('The database was restored from %s.', $filename));
        $this->output->comment('Existing sign-ins now refer to the restored user table; ask everyone to sign in again.');

        return 0;
    }

    /**
     * Confirm the restore.
     *
     * Typing the filename is deliberate friction. --force exists for a
     * scripted recovery, where there is nobody at a keyboard to type it.
     */
    private function confirmed(string $filename): bool
    {
        if ($this->isForced()) {
            return true;
        }

        if (!(bool) config('backup.restore.require_confirmation', true)) {
            return $this->output->confirm('Restore now?');
        }

        $typed = $this->output->ask(sprintf('Type "%s" to confirm', $filename));

        if ($typed === $filename) {
            return true;
        }

        $this->output->warning('That did not match the filename.');

        return false;
    }
}
