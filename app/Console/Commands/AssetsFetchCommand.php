<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Support\Str;
use Throwable;

/**
 * Download the third-party front-end libraries into public/assets/vendor.
 *
 * The system is built for an isolated LAN, where a CDN is not reachable. Run
 * this once on a machine that does have internet access; afterwards every
 * library is served from the installation itself.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class AssetsFetchCommand extends Command
{
    protected string $name = 'assets:fetch';
    protected string $description = 'Download the vendor front-end libraries for offline use.';
    protected string $usage = 'php bin/console assets:fetch [--force] [--only=bootstrap.css] [--check]';

    /** A library smaller than this is almost certainly an error page. */
    private const MINIMUM_PLAUSIBLE_BYTES = 1024;

    public function handle(): int
    {
        /** @var array<string,array<string,string>> $libraries */
        $libraries = (array) config('assets.vendor', []);

        if ($libraries === []) {
            $this->output->error('No vendor libraries are declared in config/assets.php.');

            return 1;
        }

        $only = $this->stringOption('only');

        if ($only !== '' && !isset($libraries[$only])) {
            $this->output->error(sprintf('No library is declared as "%s".', $only));
            $this->output->comment('Declared: ' . implode(', ', array_keys($libraries)));

            return 1;
        }

        // --check reports what is present without touching the network, which
        // is the useful mode on the isolated machine itself.
        if ($this->hasOption('check')) {
            return $this->report($libraries, $only);
        }

        $this->output->title('Fetching vendor assets');

        $fetched = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($libraries as $name => $library) {
            if ($only !== '' && $name !== $only) {
                continue;
            }

            $target = $this->app->basePath('public/' . $library['local']);

            if (is_file($target) && !$this->isForced()) {
                $this->output->comment(sprintf('  %-18s already present (%s)', $name, Str::bytes((int) filesize($target))));
                $skipped++;

                continue;
            }

            $result = $this->download($name, (string) $library['cdn'], $target);

            if ($result) {
                $fetched++;
            } else {
                $failed++;
            }
        }

        $this->output->line();

        if ($failed > 0) {
            $this->output->error(sprintf(
                '%d fetched, %d already present, %d failed.',
                $fetched,
                $skipped,
                $failed
            ));
            $this->output->comment('The interface falls back to built-in styling for anything missing,');
            $this->output->comment('so a failure here degrades the appearance rather than breaking the system.');

            return 1;
        }

        $this->output->success(sprintf('%d fetched, %d already present.', $fetched, $skipped));

        return 0;
    }

    /**
     * Fetch one library.
     *
     * The download goes to a temporary file first and is only moved into place
     * once it looks like the file it claims to be. A half-written or truncated
     * asset that replaced a working one would be worse than not fetching at
     * all, because the failure would show up later and somewhere else.
     */
    private function download(string $name, string $url, string $target): bool
    {
        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            $this->output->error(sprintf('  %-18s could not create %s', $name, $directory));

            return false;
        }

        $this->output->write(sprintf('  %-18s fetching… ', $name));

        try {
            $body = $this->request($url);
        } catch (Throwable $e) {
            $this->output->line($this->output->colour('failed', 'red'));
            $this->output->comment('        ' . $e->getMessage());

            return false;
        }

        if ($body === null || strlen($body) < self::MINIMUM_PLAUSIBLE_BYTES) {
            $this->output->line($this->output->colour('failed', 'red'));
            $this->output->comment('        The response was empty or implausibly small; it was not saved.');

            return false;
        }

        $temporary = $target . '.part';

        if (file_put_contents($temporary, $body) === false) {
            $this->output->line($this->output->colour('failed', 'red'));
            $this->output->comment('        Could not write ' . $temporary);

            return false;
        }

        if (!rename($temporary, $target)) {
            @unlink($temporary);

            $this->output->line($this->output->colour('failed', 'red'));
            $this->output->comment('        Could not move the file into place.');

            return false;
        }

        $this->output->line($this->output->colour('ok', 'green') . ' ' . Str::bytes(strlen($body)));

        return true;
    }

    /**
     * Retrieve a URL over HTTPS.
     *
     * cURL when it is available, the stream wrapper otherwise: a stock XAMPP
     * has both, but a hardened PHP may have allow_url_fopen off, and a minimal
     * container may have no cURL.
     */
    private function request(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            if ($handle === false) {
                throw new \RuntimeException('cURL could not be initialised.');
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'vams-assets-fetch/' . (string) config('app.version', '1.0.0'),
            ]);

            $body   = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($handle);

            curl_close($handle);

            if ($body === false) {
                throw new \RuntimeException($error === '' ? 'The request failed.' : $error);
            }

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('The server answered ' . $status . '.');
            }

            return (string) $body;
        }

        if (!(bool) ini_get('allow_url_fopen')) {
            throw new \RuntimeException('Neither cURL nor allow_url_fopen is available.');
        }

        $context = stream_context_create([
            'http' => [
                'timeout'       => 60,
                'user_agent'    => 'vams-assets-fetch/' . (string) config('app.version', '1.0.0'),
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new \RuntimeException('The request failed.');
        }

        return $body;
    }

    /**
     * Report which libraries are present, without using the network.
     *
     * @param array<string,array<string,string>> $libraries
     */
    private function report(array $libraries, string $only): int
    {
        $rows    = [];
        $missing = 0;

        foreach ($libraries as $name => $library) {
            if ($only !== '' && $name !== $only) {
                continue;
            }

            $target  = $this->app->basePath('public/' . $library['local']);
            $present = is_file($target);

            if (!$present) {
                $missing++;
            }

            $rows[] = [
                $name,
                $library['local'],
                $present ? Str::bytes((int) filesize($target)) : '—',
                $present
                    ? $this->output->colour('present', 'green')
                    : $this->output->colour('missing', 'yellow'),
            ];
        }

        $this->output->title('Vendor assets');
        $this->output->table(['Library', 'Local path', 'Size', 'State'], $rows);

        if ($missing === 0) {
            $this->output->success('Every declared library is present.');

            return 0;
        }

        $this->output->warning(sprintf(
            '%d librar(y/ies) missing. The interface falls back to built-in styling for those.',
            $missing
        ));
        $this->output->comment('Run this command without --check on a machine with internet access.');

        return 1;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name, '');

        return is_string($value) ? trim($value) : '';
    }
}
