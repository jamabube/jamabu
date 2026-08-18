<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Fixed-window request counters.
 *
 * Counters live in the database rather than in process memory so that a limit
 * holds across every PHP worker — an in-memory counter is trivially defeated
 * by spreading requests over several workers.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class RateLimitRepository extends BaseRepository
{
    protected string $table = 'rate_limit_counters';
    protected string $primaryKey = 'counter_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = ['bucket', 'identity', 'window_start', 'window_seconds', 'hits', 'blocked_until', 'last_hit_at'];

    /**
     * Register a hit and return the running count for the current window.
     *
     * The upsert is a single statement so two concurrent requests cannot both
     * read "1" and both write "2".
     */
    public function hit(string $bucket, string $identity, int $windowSeconds): int
    {
        $windowStart = $this->windowStart($windowSeconds);

        $this->connection->execute(
            'INSERT INTO `rate_limit_counters`
                (`bucket`, `identity`, `window_start`, `window_seconds`, `hits`, `last_hit_at`)
             VALUES (:bucket, :identity, :windowStart, :windowSeconds, 1, :now)
             ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `last_hit_at` = VALUES(`last_hit_at`)',
            [
                'bucket'        => $bucket,
                'identity'      => mb_substr($identity, 0, 100),
                'windowStart'   => $windowStart,
                'windowSeconds' => $windowSeconds,
                'now'           => $this->timestamp(),
            ]
        );

        return (int) $this->connection->scalar(
            'SELECT `hits` FROM `rate_limit_counters`
              WHERE `bucket` = ? AND `identity` = ? AND `window_start` = ?',
            [$bucket, mb_substr($identity, 0, 100), $windowStart]
        );
    }

    /**
     * The count so far without registering a hit.
     */
    public function currentHits(string $bucket, string $identity, int $windowSeconds): int
    {
        return (int) $this->connection->scalar(
            'SELECT `hits` FROM `rate_limit_counters`
              WHERE `bucket` = ? AND `identity` = ? AND `window_start` = ?',
            [$bucket, mb_substr($identity, 0, 100), $this->windowStart($windowSeconds)]
        );
    }

    /**
     * Total hits from one identity across every bucket in a period. Used by
     * flood detection, which cares about overall volume rather than one route.
     */
    public function totalHitsSince(string $identity, int $seconds): int
    {
        return (int) $this->connection->scalar(
            'SELECT COALESCE(SUM(`hits`), 0) FROM `rate_limit_counters`
              WHERE `identity` = ? AND `last_hit_at` >= ?',
            [
                mb_substr($identity, 0, 100),
                now()->modify('-' . max(1, $seconds) . ' seconds')->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Block an identity until a moment in time.
     */
    public function block(string $bucket, string $identity, int $windowSeconds, string $until): void
    {
        $this->connection->execute(
            'INSERT INTO `rate_limit_counters`
                (`bucket`, `identity`, `window_start`, `window_seconds`, `hits`, `blocked_until`, `last_hit_at`)
             VALUES (:bucket, :identity, :windowStart, :windowSeconds, 1, :until, :now)
             ON DUPLICATE KEY UPDATE `blocked_until` = VALUES(`blocked_until`), `last_hit_at` = VALUES(`last_hit_at`)',
            [
                'bucket'        => $bucket,
                'identity'      => mb_substr($identity, 0, 100),
                'windowStart'   => $this->windowStart($windowSeconds),
                'windowSeconds' => $windowSeconds,
                'until'         => $until,
                'now'           => $this->timestamp(),
            ]
        );
    }

    /**
     * Seconds remaining on an active block, or zero when not blocked.
     */
    public function blockedFor(string $identity): int
    {
        $until = $this->connection->scalar(
            'SELECT MAX(`blocked_until`) FROM `rate_limit_counters`
              WHERE `identity` = ? AND `blocked_until` IS NOT NULL AND `blocked_until` > ?',
            [mb_substr($identity, 0, 100), $this->timestamp()]
        );

        if ($until === null) {
            return 0;
        }

        $expiresAt = strtotime((string) $until);

        return $expiresAt === false ? 0 : max(0, $expiresAt - time());
    }

    /**
     * Clear every counter and block for an identity.
     */
    public function reset(string $identity): int
    {
        return $this->connection->execute(
            'DELETE FROM `rate_limit_counters` WHERE `identity` = ?',
            [mb_substr($identity, 0, 100)]
        );
    }

    /**
     * Discard windows that have closed and blocks that have lapsed.
     */
    public function prune(): int
    {
        return $this->connection->execute(
            'DELETE FROM `rate_limit_counters`
              WHERE `last_hit_at` < :cutoff
                AND (`blocked_until` IS NULL OR `blocked_until` < :now)',
            [
                'cutoff' => now()->modify('-1 day')->format('Y-m-d H:i:s'),
                'now'    => $this->timestamp(),
            ]
        );
    }

    /**
     * Identities currently blocked, for the security dashboard.
     *
     * @return list<array<string,mixed>>
     */
    public function activeBlocks(): array
    {
        return $this->connection->select(
            'SELECT `identity`, `bucket`, `hits`, `blocked_until`, `last_hit_at`
               FROM `rate_limit_counters`
              WHERE `blocked_until` IS NOT NULL AND `blocked_until` > ?
              ORDER BY `blocked_until` DESC',
            [$this->timestamp()]
        );
    }

    /**
     * Align a moment onto the start of its fixed window.
     *
     * Bucketing this way means every worker computes the same window boundary
     * from the clock alone, with no coordination.
     */
    private function windowStart(int $windowSeconds): string
    {
        $windowSeconds = max(1, $windowSeconds);
        $timestamp     = time();

        return date('Y-m-d H:i:s', $timestamp - ($timestamp % $windowSeconds));
    }
}
