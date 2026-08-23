<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Support\Str;
use App\Repositories\BackupRepository;
use App\Services\BackupService;

/**
 * List the recorded backups and reconcile the register with the disk.
 *
 * A register that says an archive exists when the file is gone is worse than
 * having no register, because it is believed. The presence column is read
 * from the filesystem, not from the row.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class BackupListCommand extends Command
{
    protected string $name = 'backup:list';
    protected string $description = 'List recorded backups and whether each archive is still on disk.';
    protected string $usage = 'php bin/console backup:list [--limit=20] [--reconcile]';

    public function handle(): int
    {
        $backups = $this->service(BackupRepository::class);

        // --reconcile names the two ways the register and the disk can
        // disagree: an archive nothing recorded, and a record whose archive is
        // gone. It reports rather than deletes — throwing away a backup
        // because a row is missing is exactly the wrong instinct.
        if ($this->hasOption('reconcile')) {
            $result = $this->service(BackupService::class)->reconcile();

            $this->output->title('Register against disk');

            $this->reportDiscrepancy(
                'Archives on disk with no history row',
                $result['orphaned_files']
            );

            $this->reportDiscrepancy(
                'History rows whose archive is gone',
                $result['missing_files']
            );

            if ($result['orphaned_files'] === [] && $result['missing_files'] === []) {
                $this->output->success('The register and the backup directory agree.');
            }
        }

        $limit     = max(1, $this->optionInt('limit', 20));
        $directory = $this->app->basePath((string) config('backup.path', 'database/backups'));

        $page = $backups->paginate([], ['per_page' => $limit, 'page' => 1]);

        $rows    = [];
        $missing = 0;

        foreach ($page->items() as $record) {
            $filename = (string) $record['filename'];
            $present  = is_file($directory . DIRECTORY_SEPARATOR . $filename);

            if (!$present && in_array((string) $record['status'], ['completed', 'verified'], true)) {
                $missing++;
            }

            $rows[] = [
                (string) $record['backup_id'],
                $filename,
                (string) $record['backup_type'],
                $this->colourStatus((string) $record['status']),
                Str::bytes((int) ($record['file_size'] ?? 0)),
                (string) ($record['created_at'] ?? ''),
                $present
                    ? $this->output->colour('on disk', 'green')
                    : $this->output->colour('missing', 'red'),
            ];
        }

        $this->output->title('Backups');

        if ($rows === []) {
            $this->output->warning('No backups have been recorded. Run backup:create to make one.');

            return 0;
        }

        $this->output->table(
            ['ID', 'File', 'Type', 'Status', 'Size', 'Created', 'Archive'],
            $rows
        );

        $summary = $backups->summary();

        $this->output->info(sprintf(
            '%d recorded, %d successful, %d failed, %s stored in %s.',
            (int) ($summary['total'] ?? 0),
            (int) ($summary['successful'] ?? 0),
            (int) ($summary['failed'] ?? 0),
            Str::bytes((int) ($summary['total_bytes'] ?? 0)),
            $directory
        ));

        if ($missing > 0) {
            $this->output->warning(sprintf(
                '%d archive(s) listed as successful are not on disk. Run with --reconcile to correct the register.',
                $missing
            ));

            return 1;
        }

        return 0;
    }

    /**
     * @param list<string> $filenames
     */
    private function reportDiscrepancy(string $heading, array $filenames): void
    {
        if ($filenames === []) {
            return;
        }

        $this->output->warning($heading . ':');

        foreach ($filenames as $filename) {
            $this->output->comment('        ' . $filename);
        }
    }

    private function colourStatus(string $status): string
    {
        return match ($status) {
            'verified'  => $this->output->colour($status, 'green'),
            'completed' => $this->output->colour($status, 'cyan'),
            'running'   => $this->output->colour($status, 'yellow'),
            default     => $this->output->colour($status, 'red'),
        };
    }
}
