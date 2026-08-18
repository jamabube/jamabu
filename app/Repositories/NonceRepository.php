<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\DatabaseException;

/**
 * Replay protection store.
 *
 * A nonce is consumed by inserting it. The unique index on (identity, nonce)
 * makes the insert itself the atomic test: two concurrent requests carrying the
 * same nonce cannot both succeed, because the database rejects the second.
 * A read-then-write check would leave exactly that race open.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class NonceRepository extends BaseRepository
{
    protected string $table = 'api_nonces';
    protected string $primaryKey = 'nonce_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = ['device_id', 'identity', 'nonce', 'request_timestamp', 'expires_at', 'created_at'];

    /**
     * Attempt to consume a nonce.
     *
     * @return bool True when the nonce was fresh; false when it has been seen.
     */
    public function consume(string $identity, string $nonce, ?int $deviceId, string $requestTimestamp, int $ttlSeconds): bool
    {
        try {
            $this->connection->execute(
                'INSERT INTO `api_nonces` (`device_id`, `identity`, `nonce`, `request_timestamp`, `expires_at`, `created_at`)
                 VALUES (:device, :identity, :nonce, :requestTimestamp, :expires, :now)',
                [
                    'device'           => $deviceId,
                    'identity'         => mb_substr($identity, 0, 60),
                    'nonce'            => mb_substr($nonce, 0, 64),
                    'requestTimestamp' => $requestTimestamp,
                    'expires'          => now()->modify('+' . max(60, $ttlSeconds) . ' seconds')->format('Y-m-d H:i:s'),
                    'now'              => $this->timestamp(),
                ]
            );

            return true;
        } catch (DatabaseException $e) {
            // 1062 is a duplicate-key violation, which here means precisely
            // "this nonce has already been used" — a replay. Anything else is
            // a genuine fault and must not be swallowed.
            $driverMessage = (string) ($e->context()['driver_message'] ?? '');

            if (str_contains($driverMessage, '1062') || str_contains($driverMessage, 'Duplicate entry')) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Remove nonces whose replay window has closed.
     */
    public function prune(): int
    {
        return $this->connection->execute(
            'DELETE FROM `api_nonces` WHERE `expires_at` < ?',
            [$this->timestamp()]
        );
    }

    public function countActive(): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `api_nonces` WHERE `expires_at` >= ?',
            [$this->timestamp()]
        );
    }
}
