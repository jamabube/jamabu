<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;

/**
 * Generate the application key and write it into the environment file.
 *
 * The key seeds the session fingerprint. Regenerating it on a running system
 * invalidates every open session, so an existing key is left alone unless the
 * operator passes --force and confirms.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class KeyGenerateCommand extends Command
{
    protected string $name = 'key:generate';
    protected string $description = 'Generate the application key in .env, creating the file from the template if needed.';
    protected string $usage = 'php bin/console key:generate [--force] [--show]';

    public function handle(): int
    {
        $key = bin2hex(random_bytes(32));

        // --show prints a key without touching anything, for an operator
        // filling in a deployment's environment by hand.
        if ($this->hasOption('show')) {
            $this->output->line($key);

            return 0;
        }

        $path     = $this->app->basePath('.env');
        $template = $this->app->basePath('.env.example');

        if (!is_file($path)) {
            if (!is_file($template)) {
                $this->output->error('Neither .env nor .env.example is present; nothing to write to.');

                return 1;
            }

            if (!@copy($template, $path)) {
                $this->output->error('Could not create .env from the template. Check the directory permissions.');

                return 1;
            }

            $this->output->info('Created .env from .env.example.');
        }

        $contents = (string) file_get_contents($path);
        $existing = $this->currentKey($contents);

        if ($existing !== '' && !$this->isForced()) {
            $this->output->warning('An application key is already set.');
            $this->output->line('  Replacing it signs out every open session. Pass --force to replace it anyway.');

            return 0;
        }

        if ($existing !== '' && !$this->output->confirm('Replace the key and sign out every open session?', false)) {
            $this->output->info('Left the existing key in place.');

            return 0;
        }

        $updated = $this->withKey($contents, $key);

        if (@file_put_contents($path, $updated, LOCK_EX) === false) {
            $this->output->error('Could not write to .env. Check the file permissions.');

            return 1;
        }

        // The key itself is not printed: it would otherwise sit in the
        // terminal scrollback and in any transcript of the install.
        $this->output->success('Application key generated and written to .env.');

        return 0;
    }

    /**
     * Read the key currently set in the file, if any.
     *
     * Horizontal whitespace only: \s would match the newline and capture the
     * following line, so an empty APP_KEY= would read as whatever came next.
     * The template's inline comment is stripped the same way the environment
     * parser strips it, so a fresh copy reads as having no key rather than as
     * having the comment for a key.
     */
    private function currentKey(string $contents): string
    {
        if (preg_match('/^APP_KEY[^\S\r\n]*=([^\r\n]*)$/m', $contents, $matches) !== 1) {
            return '';
        }

        $value = $matches[1];

        if (str_contains($value, ' #')) {
            $value = substr($value, 0, (int) strpos($value, ' #'));
        }

        return trim($value, " \t\"'");
    }

    /**
     * Replace the APP_KEY line, or append one when the file has none.
     */
    private function withKey(string $contents, string $key): string
    {
        $line = 'APP_KEY=' . $key;

        if (preg_match('/^APP_KEY[^\S\r\n]*=[^\r\n]*$/m', $contents) === 1) {
            return (string) preg_replace('/^APP_KEY[^\S\r\n]*=[^\r\n]*$/m', $line, $contents, 1);
        }

        return rtrim($contents, "\r\n") . PHP_EOL . $line . PHP_EOL;
    }
}
