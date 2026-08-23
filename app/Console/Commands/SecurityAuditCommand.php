<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Repositories\DeviceRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;

/**
 * Audit the deployment's security posture.
 *
 * Checks the settings and the data an installation can get wrong without any
 * error appearing: debug left on in production, cookies not marked secure,
 * request signing turned off, accounts still holding their first password,
 * station keys that have never been rotated.
 *
 * Findings carry a severity. The exit code reflects the worst one, so this can
 * gate a deployment: 2 for a critical finding, 1 for a warning, 0 for clean.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class SecurityAuditCommand extends Command
{
    protected string $name = 'security:audit';
    protected string $description = 'Audit configuration and data for security weaknesses.';
    protected string $usage = 'php bin/console security:audit [--quiet-when-clean]';

    private const CRITICAL = 'critical';
    private const WARNING  = 'warning';
    private const PASS     = 'pass';

    /** @var list<array{severity:string,area:string,finding:string,remedy:string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->auditApplication();
        $this->auditTransport();
        $this->auditSession();
        $this->auditPasswordPolicy();
        $this->auditDeviceApi();
        $this->auditFilePermissions();
        $this->auditAccounts();
        $this->auditStations();
        $this->auditOutstandingEvents();

        $critical = $this->countOf(self::CRITICAL);
        $warnings = $this->countOf(self::WARNING);

        if ($critical === 0 && $warnings === 0 && $this->hasOption('quiet-when-clean')) {
            return 0;
        }

        $this->output->title('Security audit');
        $this->output->table(
            ['', 'Area', 'Finding', 'Remedy'],
            array_map(
                fn (array $finding): array => [
                    $this->badge($finding['severity']),
                    $finding['area'],
                    $finding['finding'],
                    $finding['remedy'],
                ],
                $this->findings
            )
        );

        if ($critical > 0) {
            $this->output->error(sprintf(
                '%d critical finding(s) and %d warning(s). Do not treat this installation as production-ready.',
                $critical,
                $warnings
            ));

            return 2;
        }

        if ($warnings > 0) {
            $this->output->warning(sprintf('%d warning(s). Nothing critical.', $warnings));

            return 1;
        }

        $this->output->success('No weaknesses found in the checks this command performs.');

        return 0;
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    private function auditApplication(): void
    {
        $production = $this->app->isProduction();

        if ((bool) config('app.debug', false)) {
            $this->add(
                $production ? self::CRITICAL : self::WARNING,
                'Application',
                'Debug mode is enabled' . ($production ? ' in production' : ''),
                'Set APP_DEBUG=false. Debug output includes stack traces and query text.'
            );
        } else {
            $this->pass('Application', 'Debug mode is off');
        }

        $key = (string) config('app.key', '');

        if ($key === '' || str_contains($key, 'change-me')) {
            $this->add(
                self::CRITICAL,
                'Application',
                'The application key is unset or still the placeholder',
                'Run php bin/console key:generate. Without it, signed values are forgeable.'
            );
        } else {
            $this->pass('Application', 'An application key is set');
        }

        if ($production && (bool) config('app.maintenance.enabled', false)) {
            $this->add(
                self::WARNING,
                'Application',
                'Maintenance mode is on',
                'Only administrators can reach the system. Turn it off when the work is done.'
            );
        }
    }

    private function auditTransport(): void
    {
        if (!(bool) config('security.transport.force_https', true)) {
            $this->add(
                self::CRITICAL,
                'Transport',
                'HTTPS is not enforced',
                'Set FORCE_HTTPS=true. Over http, session cookies and device API keys travel in clear text.'
            );
        } else {
            $this->pass('Transport', 'HTTPS is enforced');
        }

        if (!(bool) config('security.transport.hsts.enabled', true)) {
            $this->add(
                self::WARNING,
                'Transport',
                'HSTS is disabled',
                'Set HSTS_ENABLED=true so a browser will not be talked back down to http.'
            );
        }

        if (!(bool) config('security.csp.enabled', true)) {
            $this->add(
                self::WARNING,
                'Transport',
                'The content security policy is disabled',
                'Re-enable it: it is the control that stops injected script from running.'
            );
        } elseif ((bool) config('security.csp.report_only', false)) {
            $this->add(
                self::WARNING,
                'Transport',
                'The content security policy is in report-only mode',
                'Set CSP_REPORT_ONLY=false once the policy is settled; until then it blocks nothing.'
            );
        }
    }

    private function auditSession(): void
    {
        if (!(bool) config('session.cookie.secure', true)) {
            $this->add(
                self::CRITICAL,
                'Session',
                'The session cookie is not marked secure',
                'Set SESSION_SECURE_COOKIE=true so the cookie is never sent over http.'
            );
        } else {
            $this->pass('Session', 'The session cookie is marked secure');
        }

        if (!(bool) config('session.cookie.http_only', true)) {
            $this->add(
                self::CRITICAL,
                'Session',
                'The session cookie is readable by script',
                'Enable http_only: without it, one injected script reads every signed-in session.'
            );
        }

        if (strtolower((string) config('session.cookie.same_site', 'Lax')) === 'none') {
            $this->add(
                self::WARNING,
                'Session',
                'SameSite is None',
                'Use Lax unless a cross-site embed genuinely requires None.'
            );
        }

        $lifetime = (int) config('session.lifetime', 1800);

        if ($lifetime > 7200) {
            $this->add(
                self::WARNING,
                'Session',
                sprintf('The idle timeout is %d minutes', (int) round($lifetime / 60)),
                'A guardhouse terminal is a shared machine. Shorten SESSION_LIFETIME.'
            );
        }

        if (!(bool) config('security.csrf.enabled', true)) {
            $this->add(
                self::CRITICAL,
                'Session',
                'CSRF protection is disabled',
                'Re-enable it. Every state-changing form depends on it.'
            );
        }
    }

    private function auditPasswordPolicy(): void
    {
        $minimum = (int) config('security.password.min_length', 12);

        if ($minimum < 12) {
            $this->add(
                self::WARNING,
                'Passwords',
                sprintf('The minimum length is %d characters', $minimum),
                'Twelve is the floor this system was designed around.'
            );
        } else {
            $this->pass('Passwords', sprintf('Minimum length is %d', $minimum));
        }

        $cost = (int) config('security.password.bcrypt_cost', 12);

        if ($cost < 10) {
            $this->add(
                self::WARNING,
                'Passwords',
                sprintf('The bcrypt cost is %d', $cost),
                'Below 10, a stolen hash is cheap to attack. Raise BCRYPT_COST.'
            );
        }

        if ((int) config('security.lockout.max_attempts', 5) <= 0) {
            $this->add(
                self::CRITICAL,
                'Passwords',
                'Account lockout is disabled',
                'Set LOGIN_MAX_ATTEMPTS. Without it, passwords can be tried without limit.'
            );
        }
    }

    private function auditDeviceApi(): void
    {
        if (!(bool) config('api.device.require_signature', true)) {
            $this->add(
                self::CRITICAL,
                'Device API',
                'Request signing is not required',
                'Set it back on: without a signature, a captured request can be replayed to open a gate.'
            );
        } else {
            $this->pass('Device API', 'Requests must be signed');
        }

        $tolerance = (int) config('api.device.timestamp_tolerance', 120);

        if ($tolerance > 600) {
            $this->add(
                self::WARNING,
                'Device API',
                sprintf('The timestamp tolerance is %d seconds', $tolerance),
                'A wide window lengthens how long a captured request stays usable.'
            );
        }

        if (!(bool) config('api.rate_limit.enabled', true)) {
            $this->add(
                self::WARNING,
                'Device API',
                'Rate limiting is disabled',
                'Re-enable it so a misbehaving station cannot exhaust the server.'
            );
        }
    }

    /**
     * Check that the environment file is not world-readable.
     *
     * Only meaningful on a POSIX filesystem. On Windows the permission bits
     * PHP reports do not describe the actual ACL, so the check is skipped
     * rather than reporting something untrue.
     */
    private function auditFilePermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $envFile = $this->app->basePath('.env');

        if (!is_file($envFile)) {
            return;
        }

        $mode = fileperms($envFile) & 0o777;

        if (($mode & 0o044) !== 0) {
            $this->add(
                self::CRITICAL,
                'Filesystem',
                sprintf('.env is readable by other accounts (mode %o)', $mode),
                'Run chmod 600 .env. It holds the database password and the application key.'
            );
        } else {
            $this->pass('Filesystem', sprintf('.env permissions are %o', $mode));
        }
    }

    private function auditAccounts(): void
    {
        $users = $this->service(UserRepository::class);

        // An account that has never changed its issued password is one whose
        // password somebody else has seen.
        $unchanged = (int) $users->countWhereMustChangePassword();

        if ($unchanged > 0) {
            $this->add(
                self::WARNING,
                'Accounts',
                sprintf('%d account(s) still hold an issued password', $unchanged),
                'Their first sign-in will force a change; chase any that have never signed in.'
            );
        } else {
            $this->pass('Accounts', 'No account is holding an issued password');
        }

        $expired = $users->withExpiredPasswords((int) config('security.password.max_age_days', 90));

        if ($expired !== []) {
            $this->add(
                self::WARNING,
                'Accounts',
                sprintf('%d account(s) have passwords past their maximum age', count($expired)),
                'They will be prompted at the next sign-in.'
            );
        }

        $locked = $users->countLocked();

        if ($locked > 0) {
            $this->add(
                self::WARNING,
                'Accounts',
                sprintf('%d account(s) are locked', $locked),
                'Repeated lockouts on one account are worth investigating rather than just clearing.'
            );
        }
    }

    private function auditStations(): void
    {
        $devices = $this->service(DeviceRepository::class);
        $rotationDays = 365;
        $stale = 0;
        $total = 0;

        foreach ($devices->allWithStatus() as $device) {
            if ((string) $device['status'] === 'decommissioned') {
                continue;
            }

            $total++;

            $issued = (string) ($device['api_key_rotated_at'] ?? $device['api_key_issued_at'] ?? '');

            if ($issued === '') {
                continue;
            }

            if (strtotime($issued) < time() - ($rotationDays * 86400)) {
                $stale++;
            }
        }

        if ($total === 0) {
            return;
        }

        if ($stale > 0) {
            $this->add(
                self::WARNING,
                'Stations',
                sprintf('%d station key(s) are over a year old', $stale),
                'Rotate with device:rotate-key. Each rotation needs the station reflashed, so plan it.'
            );
        } else {
            $this->pass('Stations', sprintf('All %d station key(s) are within a year', $total));
        }
    }

    private function auditOutstandingEvents(): void
    {
        $unresolved = $this->service(SecurityEventRepository::class)->countUnresolved();

        if ($unresolved > 0) {
            $this->add(
                self::WARNING,
                'Events',
                sprintf('%d security event(s) are unresolved', $unresolved),
                'Review them under Security. An unread alert is the same as no alert.'
            );
        } else {
            $this->pass('Events', 'No unresolved security events');
        }
    }

    // ------------------------------------------------------------------
    // Reporting
    // ------------------------------------------------------------------

    private function add(string $severity, string $area, string $finding, string $remedy): void
    {
        $this->findings[] = [
            'severity' => $severity,
            'area'     => $area,
            'finding'  => $finding,
            'remedy'   => $remedy,
        ];
    }

    /**
     * Record something that is correct.
     *
     * Passing checks are listed too. An audit that prints only problems leaves
     * the reader unable to tell a clean installation from one where the check
     * never ran.
     */
    private function pass(string $area, string $finding): void
    {
        $this->add(self::PASS, $area, $finding, '');
    }

    private function countOf(string $severity): int
    {
        return count(array_filter(
            $this->findings,
            static fn (array $finding): bool => $finding['severity'] === $severity
        ));
    }

    private function badge(string $severity): string
    {
        return match ($severity) {
            self::CRITICAL => $this->output->colour('CRIT', 'red', 'bold'),
            self::WARNING  => $this->output->colour('WARN', 'yellow'),
            default        => $this->output->colour(' ok ', 'green'),
        };
    }
}
