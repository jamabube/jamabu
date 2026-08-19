<?php

declare(strict_types=1);

namespace App\Core\Console;

/**
 * Console output helper.
 *
 * Colour is emitted only when the stream is a terminal, so redirecting the
 * output of a scheduled task into a log file produces clean text.
 *
 * @package App\Core\Console
 * @version 1.0.0
 */
final class Output
{
    private bool $decorated;

    private const COLOURS = [
        'reset'   => "\033[0m",
        'bold'    => "\033[1m",
        'dim'     => "\033[2m",
        'red'     => "\033[31m",
        'green'   => "\033[32m",
        'yellow'  => "\033[33m",
        'blue'    => "\033[34m",
        'magenta' => "\033[35m",
        'cyan'    => "\033[36m",
        'grey'    => "\033[90m",
    ];

    public function __construct(?bool $decorated = null)
    {
        $this->decorated = $decorated ?? (PHP_SAPI === 'cli' && stream_isatty(STDOUT));
    }

    public function write(string $message = ''): void
    {
        fwrite(STDOUT, $message);
    }

    public function line(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public function title(string $message): void
    {
        $this->line();
        $this->line($this->colour($message, 'bold', 'cyan'));
        $this->line($this->colour(str_repeat('=', min(80, max(10, strlen($message)))), 'grey'));
    }

    public function success(string $message): void
    {
        $this->line($this->colour('  OK  ', 'green', 'bold') . ' ' . $message);
    }

    public function info(string $message): void
    {
        $this->line($this->colour(' INFO ', 'blue') . ' ' . $message);
    }

    public function warning(string $message): void
    {
        $this->line($this->colour(' WARN ', 'yellow', 'bold') . ' ' . $message);
    }

    public function error(string $message): void
    {
        fwrite(STDERR, $this->colour(' FAIL ', 'red', 'bold') . ' ' . $message . PHP_EOL);
    }

    public function comment(string $message): void
    {
        $this->line($this->colour($message, 'grey'));
    }

    /**
     * Render a table with aligned columns.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        // Widths are measured in characters rather than bytes, so a cell
        // containing an em dash or an accented name still lines up.
        $widths = array_map(static fn (string $header): int => mb_strlen($header), $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen(self::plain((string) $cell)));
            }
        }

        $separator = '+' . implode('+', array_map(
            static fn (int $width): string => str_repeat('-', $width + 2),
            $widths
        )) . '+';

        $this->line($separator);
        $this->line($this->renderRow($headers, $widths, true));
        $this->line($separator);

        foreach ($rows as $row) {
            $this->line($this->renderRow(array_values($row), $widths));
        }

        $this->line($separator);
    }

    /**
     * @param list<string> $cells
     * @param list<int>    $widths
     */
    private function renderRow(array $cells, array $widths, bool $header = false): string
    {
        $parts = [];

        foreach ($widths as $index => $width) {
            $cell    = (string) ($cells[$index] ?? '');
            $padding = $width - mb_strlen(self::plain($cell));
            $parts[] = ' ' . ($header ? $this->colour($cell, 'bold') : $cell) . str_repeat(' ', max(0, $padding)) . ' ';
        }

        return '|' . implode('|', $parts) . '|';
    }

    /**
     * Ask the operator to confirm a destructive action.
     */
    public function confirm(string $question, bool $default = false): bool
    {
        $suffix = $default ? '[Y/n]' : '[y/N]';
        $this->write($this->colour($question . ' ' . $suffix . ' ', 'yellow'));

        $answer = trim((string) fgets(STDIN));

        if ($answer === '') {
            return $default;
        }

        return in_array(strtolower($answer), ['y', 'yes'], true);
    }

    public function ask(string $question, string $default = ''): string
    {
        $hint = $default === '' ? '' : ' (' . $default . ')';
        $this->write($this->colour($question . $hint . ': ', 'cyan'));

        $answer = trim((string) fgets(STDIN));

        return $answer === '' ? $default : $answer;
    }

    /**
     * Read a value without echoing it. Falls back to a visible prompt on a
     * platform where the terminal cannot be put into no-echo mode.
     */
    public function askHidden(string $question): string
    {
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('shell_exec')) {
            return $this->ask($question . ' (input will be visible)');
        }

        $this->write($this->colour($question . ': ', 'cyan'));
        shell_exec('stty -echo 2>/dev/null');
        $answer = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        $this->line();

        return $answer;
    }

    /**
     * Apply colour codes when the output is decorated.
     */
    public function colour(string $text, string ...$styles): string
    {
        if (!$this->decorated) {
            return $text;
        }

        $prefix = '';
        foreach ($styles as $style) {
            $prefix .= self::COLOURS[$style] ?? '';
        }

        return $prefix === '' ? $text : $prefix . $text . self::COLOURS['reset'];
    }

    /**
     * Strip colour codes so column widths are measured on visible characters.
     */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
}
