<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Support\Str;
use App\Services\BackupService;
use Throwable;

/**
 * Produce a database backup.
 *
 * Written to be run from a scheduler as well as by hand, so it says what it
 * did on one line, exits non-zero when it failed, and never asks a question.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class BackupCreateCommand extends Command
{
    protected string $name = 'backup:create';
    protected string $description = 'Produce a verified database backup.';
    protected string $usage = 'php bin/console backup:create [--scheduled] [--uploads]';

    public function handle(): int
    {
        // The type is recorded on the history row and is what distinguishes a
        // nightly task from somebody clicking the button, which matters when
        // working out why an archive exists.
        $type = $this->hasOption('scheduled') ? 'scheduled' : 'manual';

        $this->output->title('Creating a backup');
        $this->output->comment('Reading the schema and data through the application connection.');

        try {
            $record = $this->service(BackupService::class)->create(
                $type,
                null,
                $this->hasOption('uploads')
            );
        } catch (Throwable $e) {
            $this->output->error('The backup failed: ' . $e->getMessage());

            return 1;
        }

        $this->output->table(
            ['Field', 'Value'],
            [
                ['File', (string) $record['filename']],
                ['Size', Str::bytes((int) ($record['file_size'] ?? 0))],
                ['Tables', (string) ($record['table_count'] ?? 0)],
                ['Rows', number_format((int) ($record['row_count'] ?? 0))],
                ['Duration', ((int) ($record['duration_ms'] ?? 0)) . ' ms'],
                ['Checksum', substr((string) ($record['checksum'] ?? ''), 0, 32) . '…'],
                ['Verification', (string) ($record['verification_result'] ?? 'not verified')],
            ]
        );

        $status = (string) $record['status'];

        if ($status === 'verified') {
            $this->output->success('Backup complete and verified against its checksum.');

            return 0;
        }

        // A backup that exists but could not be read back is worth flagging:
        // it may still restore, and it may not, and the difference is only
        // discovered at the worst possible moment.
        $this->output->warning(sprintf(
            'The backup was written but its verification returned "%s".',
            (string) ($record['verification_result'] ?? 'unknown')
        ));

        return 0;
    }
}
