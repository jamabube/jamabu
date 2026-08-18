<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Exceptions\AuthorizationException;
use App\Services\SecurityEventService;
use Closure;

/**
 * Restricts the administrative interface to configured networks.
 *
 * Off by default. When a deployment lists the guardhouse and administration
 * subnets, a request from anywhere else is refused before authentication is
 * even attempted, which removes the login form as an attack surface for the
 * rest of the LAN.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class IpAllowlistMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SecurityEventService $security)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowlist */
        $allowlist = (array) config('security.ip_allowlist', []);

        if ($allowlist === []) {
            return $next($request);
        }

        // Devices authenticate with a key and are separately pinned to their
        // own addresses, so they are not subject to the interface allow-list.
        if (str_starts_with($request->path(), '/api/v1/device/')
            || str_starts_with($request->path(), '/api/v1/access/')) {
            return $next($request);
        }

        $address = $request->ip();

        foreach ($allowlist as $entry) {
            if ($this->matches($address, trim($entry))) {
                return $next($request);
            }
        }

        $this->security->record(
            'ip_not_allowed',
            sprintf('A request from %s was refused; the address is outside the configured allow-list.', $address),
            ['ip_address' => $address, 'path' => $request->path()],
            'blocked'
        );

        throw new AuthorizationException('Access from this network is not permitted.');
    }

    /**
     * Match an address against a literal address or a CIDR range.
     */
    private function matches(string $address, string $entry): bool
    {
        if ($entry === '') {
            return false;
        }

        if (!str_contains($entry, '/')) {
            return $address === $entry;
        }

        [$subnet, $prefixLength] = explode('/', $entry, 2);

        $addressBinary = inet_pton($address);
        $subnetBinary  = inet_pton($subnet);

        // A malformed allow-list entry must never accidentally match.
        if ($addressBinary === false || $subnetBinary === false) {
            return false;
        }

        if (strlen($addressBinary) !== strlen($subnetBinary)) {
            return false; // Mixing IPv4 and IPv6 never matches.
        }

        $bits = (int) $prefixLength;
        $maxBits = strlen($addressBinary) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes    = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($addressBinary, $subnetBinary, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        // Compare the partial trailing byte under a mask.
        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($addressBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }
}
