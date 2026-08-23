<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Support\Str;
use App\Repositories\ApiRequestLogRepository;
use App\Repositories\DeviceHeartbeatRepository;
use App\Repositories\ErrorLogRepository;
use App\Repositories\NonceRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\RateLimitRepository;

/**
 * Apply the configured retention to the logs.
 *
 * Never runs on its own. Retention is applied only when an administrator
 * schedules this command, so nothing disappears from the record without
 * somebody having decided that it should.
 *
 * Two categories are exempt whatever the configuration says. Audit records
 * and security events are the evidence the system exists to produce, and the
 * repositories that hold them refuse deletion outright — a retention setting
 * cannot quietly override that, and this command reports the refusal rather
 * than hiding it.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class LogPruneCommand extends Command
{
    protected string $name = 'log:prune';
    protected string $description = 'Apply the configured log retention. Audit and security records are never removed.';
    protected string $usage = 'php bin/console log:prune [--dry-run] [--only=api|heartbeats|notifications|errors|files|transient]';

    /**
     * Categories whose records are immutable, whatever retention is set.
     *
     * @var list<string>
     */
    private const IMMUTABLE = ['audit_logs', 'security_events'];

    public function handle(): int
    {
        /** @var array<string,int> $retention */
        $retention = (array) config('logging.retention_days', []);

        $only   = $this->stringOption('only');
        $dryRun = $this->hasOption('dry-run');

        $this->output->title('Log retention');

        if ($dryRun) {
            $this->output->comment('Dry run: nothing will be deleted.');
        }

        $rows    = [];
        $removed = 0;

        foreach ($this->categories($retention) as $key => $task) {
            if ($only !== '' && $only !== $key) {
                continue;
            }

            $days = $task['days'];

            if ($days <= 0) {
                $rows[] = [$task['label'], 'indefinite', '—', 'retained'];

                continue;
            }

            if ($dryRun) {
                $rows[] = [$task['label'], $days . ' days', '—', 'would prune'];

                continue;
            }

            $count = ($task['run'])();
            $removed += $count;

            $rows[] = [$task['label'], $days . ' days', number_format($count), $count === 0 ? 'nothing to do' : 'pruned'];
        }

        // The transient tables are not evidence and are not covered by a
        // retention setting: an expired nonce or a spent rate-limit counter is
        // dead weight the moment it lapses.
        if ($only === '' || $only === 'transient') {
            if ($dryRun) {
                $rows[] = ['Expired nonces', 'on expiry', '—', 'would prune'];
                $rows[] = ['Spent rate-limit counters', 'on expiry', '—', 'would prune'];
            } else {
                $nonces = $this->service(NonceRepository::class)->prune();
                $limits = $this->service(RateLimitRepository::class)->prune();

                $removed += $nonces + $limits;

                $rows[] = ['Expired nonces', 'on expiry', number_format($nonces), $nonces === 0 ? 'nothing to do' : 'pruned'];
                $rows[] = ['Spent rate-limit counters', 'on expiry', number_format($limits), $limits === 0 ? 'nothing to do' : 'pruned'];
            }
        }

        $this->output->table(['Category', 'Retention', 'Removed', 'Result'], $rows);

        $this->reportImmutable($retention);

        if ($dryRun) {
            $this->output->info('Dry run complete. Nothing was removed.');

            return 0;
        }

        $this->output->success(sprintf('%s record(s) removed.', number_format($removed)));

        return 0;
    }

    /**
     * The prunable categories, each with its retention and the call that does it.
     *
     * @param array<string,int> $retention
     *
     * @return array<string,array{label:string,days:int,run:callable():int}>
     */
    private function categories(array $retention): array
    {
        return [
            'api' => [
                'label' => 'API request log',
                'days'  => (int) ($retention['api_request_logs'] ?? 0),
                'run'   => fn (): int => $this->service(ApiRequestLogRepository::class)
                    ->prune((int) ($retention['api_request_logs'] ?? 0)),
            ],
            'heartbeats' => [
                'label' => 'Device heartbeats',
                'days'  => (int) ($retention['device_heartbeats'] ?? 0),
                'run'   => fn (): int => $this->service(DeviceHeartbeatRepository::class)
                    ->prune((int) ($retention['device_heartbeats'] ?? 0)),
            ],
            'notifications' => [
                'label' => 'Read notifications',
                'days'  => (int) ($retention['notifications'] ?? 0),
                'run'   => fn (): int => $this->service(NotificationRepository::class)
                    ->prune((int) ($retention['notifications'] ?? 0)),
            ],
            'errors' => [
                'label' => 'Resolved error records',
                'days'  => (int) ($retention['error_logs'] ?? 0),
                'run'   => fn (): int => $this->service(ErrorLogRepository::class)
                    ->prune((int) ($retention['error_logs'] ?? 0)),
            ],
            'files' => [
                'label' => 'Rotated log files',
                'days'  => (int) ($retention['files'] ?? 0),
                'run'   => fn (): int => $this->pruneLogFiles((int) ($retention['files'] ?? 0)),
            ],
        ];
    }

    /**
     * Delete rotated log files past their retention.
     *
     * Eligibility is by modification time, so the file currently being
     * appended to is never a candidate however the retention is set.
     */
    private function pruneLogFiles(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        $cutoff  = time() - ($retentionDays * 86400);
        $removed = 0;
        $bytes   = 0;

        /** @var array<string,array<string,mixed>> $channels */
        $channels = (array) config('logging.channels', []);

        $directories = [];

        foreach ($channels as $channel) {
            $path = (string) ($channel['path'] ?? '');

            if ($path !== '') {
                $directories[$path] = true;
            }
        }

        foreach (array_keys($directories) as $relative) {
            $directory = $this->app->basePath($relative);

            if (!is_dir($directory)) {
                continue;
            }

            // The logger writes one file per channel per day, named
            // "<channel>-YYYY-MM-DD.log". Today's file matches this pattern
            // too, which is why eligibility is decided by modification time
            // rather than by the name: a file still being appended to cannot
            // be older than the cutoff.
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log') ?: [] as $file) {
                if (filemtime($file) >= $cutoff) {
                    continue;
                }

                $size = (int) filesize($file);

                if (@unlink($file)) {
                    $removed++;
                    $bytes += $size;
                }
            }
        }

        if ($bytes > 0) {
            $this->output->comment(sprintf('        %s of log files reclaimed.', Str::bytes($bytes)));
        }

        return $removed;
    }

    /**
     * Say plainly that the immutable categories were not touched.
     *
     * @param array<string,int> $retention
     */
    private function reportImmutable(array $retention): void
    {
        foreach (self::IMMUTABLE as $category) {
            $days = (int) ($retention[$category] ?? 0);

            if ($days <= 0) {
                continue;
            }

            $this->output->warning(sprintf(
                '%s is configured with a %d-day retention, but these records are immutable and were not removed.',
                str_replace('_', ' ', ucfirst($category)),
                $days
            ));
        }
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name, '');

        return is_string($value) ? trim($value) : '';
    }
}
