<?php

declare(strict_types=1);

namespace App\Core\Export;

/**
 * CSV export.
 *
 * Two details matter beyond joining values with commas. A UTF-8 byte-order mark
 * is emitted so Excel opens accented characters correctly rather than as
 * mojibake, and any value that begins with a formula character is prefixed so
 * a spreadsheet does not execute exported data as a formula.
 *
 * @package App\Core\Export
 * @version 1.0.0
 */
final class CsvWriter
{
    /**
     * Build a CSV document.
     *
     * @param list<string>              $headers
     * @param list<array<string,mixed>> $rows
     * @param list<string>|null         $columns Row keys, in output order.
     */
    public static function build(array $headers, array $rows, ?array $columns = null, string $delimiter = ','): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        // The BOM is what makes Excel read the file as UTF-8.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, array_map([self::class, 'neutralise'], $headers), $delimiter, '"', '\\');

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns ?? array_keys($row) as $column) {
                $line[] = self::neutralise(self::stringify($row[$column] ?? ''));
            }

            fputcsv($handle, $line, $delimiter, '"', '\\');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }

    /**
     * Prevent a spreadsheet from treating exported text as a formula.
     *
     * A plate number or remark beginning with =, +, - or @ would otherwise be
     * evaluated when the file is opened, which is a genuine injection route
     * into whoever opens the export.
     */
    private static function neutralise(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'" . $value
            : $value;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null   => '',
            is_bool($value)   => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            is_array($value)  => (string) json_encode($value),
            default           => '',
        };
    }
}
