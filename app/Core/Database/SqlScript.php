<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Splits a SQL script into individual statements.
 *
 * A naive explode(';') breaks on the first semicolon inside a string literal,
 * a comment, or a trigger body. This scanner tracks quoting and comment state
 * and honours the DELIMITER directive, which is how the MySQL client itself
 * handles routines whose bodies contain semicolons.
 *
 * @package App\Core\Database
 * @version 1.0.0
 */
final class SqlScript
{
    /**
     * @return list<string> Statements with trailing delimiters removed.
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $delimiter  = ';';
        $length     = strlen($sql);
        $index      = 0;
        $atLineStart = true;

        while ($index < $length) {
            $char = $sql[$index];

            // A DELIMITER directive may only appear at the start of a line and
            // applies from the following line onwards.
            if ($atLineStart && trim($buffer) === '' && self::matchesDelimiterDirective($sql, $index, $newDelimiter, $consumed)) {
                $delimiter = $newDelimiter;
                $index    += $consumed;
                $buffer    = '';
                continue;
            }

            $atLineStart = false;

            // Line comment: "-- " or "#" to end of line.
            if (($char === '-' && substr($sql, $index, 2) === '--' && self::isCommentStart($sql, $index))
                || $char === '#') {
                $newline = strpos($sql, "\n", $index);
                $index   = $newline === false ? $length : $newline + 1;
                $atLineStart = true;
                continue;
            }

            // Block comment.
            if ($char === '/' && substr($sql, $index, 2) === '/*') {
                $end   = strpos($sql, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;
                continue;
            }

            // Quoted literal or identifier: copy verbatim, honouring escapes.
            if ($char === "'" || $char === '"' || $char === '`') {
                $literal = self::readQuoted($sql, $index, $char);
                $buffer .= $literal;
                continue;
            }

            // Statement terminator.
            if (substr($sql, $index, strlen($delimiter)) === $delimiter) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                $index += strlen($delimiter);
                continue;
            }

            if ($char === "\n") {
                $atLineStart = true;
            }

            $buffer .= $char;
            $index++;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    /**
     * Detect "DELIMITER x" at the current position.
     */
    private static function matchesDelimiterDirective(
        string $sql,
        int $index,
        ?string &$delimiter,
        ?int &$consumed
    ): bool {
        if (preg_match('/\GDELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/i', $sql, $matches, 0, $index) !== 1) {
            return false;
        }

        $delimiter = $matches[1];
        $consumed  = strlen($matches[0]);

        return true;
    }

    /**
     * A "--" only opens a comment when followed by whitespace or end of line,
     * so the decrement operator in an expression is not mistaken for one.
     */
    private static function isCommentStart(string $sql, int $index): bool
    {
        $next = $sql[$index + 2] ?? "\n";

        return $next === ' ' || $next === "\t" || $next === "\n" || $next === "\r";
    }

    /**
     * Read a quoted string or identifier, advancing the cursor past it.
     */
    private static function readQuoted(string $sql, int &$index, string $quote): string
    {
        $length = strlen($sql);
        $result = $quote;
        $index++;

        while ($index < $length) {
            $char = $sql[$index];

            // Backslash escapes the next character inside a string literal.
            if ($char === '\\' && $quote !== '`' && $index + 1 < $length) {
                $result .= $char . $sql[$index + 1];
                $index  += 2;
                continue;
            }

            // A doubled quote is an escaped quote, not a terminator.
            if ($char === $quote) {
                if (($sql[$index + 1] ?? '') === $quote) {
                    $result .= $quote . $quote;
                    $index  += 2;
                    continue;
                }

                $result .= $quote;
                $index++;

                return $result;
            }

            $result .= $char;
            $index++;
        }

        return $result;
    }

    /**
     * Extract the @UP or @DOWN section of a migration file.
     */
    public static function section(string $contents, string $section): string
    {
        $marker = '-- @' . strtoupper($section);
        $start  = strpos($contents, $marker);

        if ($start === false) {
            return $section === 'UP' ? $contents : '';
        }

        $body      = substr($contents, $start + strlen($marker));
        $otherMark = strtoupper($section) === 'UP' ? '-- @DOWN' : '-- @UP';
        $end       = strpos($body, $otherMark);

        return $end === false ? $body : substr($body, 0, $end);
    }
}
