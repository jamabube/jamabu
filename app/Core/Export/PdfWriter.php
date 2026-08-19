<?php

declare(strict_types=1);

namespace App\Core\Export;

/**
 * PDF report generator.
 *
 * Emits a PDF 1.4 document directly, using only the fourteen standard Type 1
 * fonts every reader has built in. That keeps the project dependency-free and
 * keeps the output small — a month of monitoring records renders in kilobytes
 * rather than the megabytes an HTML-to-PDF pipeline would produce.
 *
 * Scope is deliberate: paginated tables with a header, footer, page numbering
 * and a summary block. That is what the reporting module needs. It is not a
 * general-purpose layout engine.
 *
 * @package App\Core\Export
 * @version 1.0.0
 */
final class PdfWriter
{
    /** Page geometry in PostScript points (A4). */
    private const PAGE_WIDTH  = 595.28;
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN      = 36.0;

    private const FONT_REGULAR = 'F1';
    private const FONT_BOLD    = 'F2';

    /** @var list<string> Content streams, one per page. */
    private array $pages = [];

    private string $currentPage = '';
    private float $cursorY;
    private int $pageNumber = 0;

    private string $title;
    private string $subtitle;
    private string $organisation;
    private string $generatedBy;
    private bool $landscape;

    /** @var list<string> Filter descriptions printed under the heading. */
    private array $filters;

    public function __construct(
        string $title,
        string $subtitle = '',
        string $organisation = '',
        string $generatedBy = '',
        array $filters = [],
        bool $landscape = false
    ) {
        $this->title        = $title;
        $this->subtitle     = $subtitle;
        $this->organisation = $organisation !== '' ? $organisation : (string) config('app.organization', '');
        $this->generatedBy  = $generatedBy;
        $this->filters      = $filters;
        $this->landscape    = $landscape;
        $this->cursorY      = $this->pageHeight() - self::MARGIN;
    }

    private function pageWidth(): float
    {
        return $this->landscape ? self::PAGE_HEIGHT : self::PAGE_WIDTH;
    }

    private function pageHeight(): float
    {
        return $this->landscape ? self::PAGE_WIDTH : self::PAGE_HEIGHT;
    }

    private function contentWidth(): float
    {
        return $this->pageWidth() - (self::MARGIN * 2);
    }

    // ------------------------------------------------------------------
    // Content
    // ------------------------------------------------------------------

    /**
     * Render a table, paginating as needed.
     *
     * @param list<string>              $headers
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $columns Row keys in output order.
     * @param list<float>|null          $weights Relative column widths.
     */
    public function table(array $headers, array $rows, array $columns, ?array $weights = null): self
    {
        if ($this->pageNumber === 0) {
            $this->startPage();
        }

        $widths = $this->columnWidths($headers, $weights);

        $this->tableHeader($headers, $widths);

        foreach ($rows as $row) {
            // A row that would fall below the footer starts a new page, and the
            // column headings are repeated so the page stands on its own.
            if ($this->cursorY < self::MARGIN + 48) {
                $this->finishPage();
                $this->startPage();
                $this->tableHeader($headers, $widths);
            }

            $this->tableRow($row, $columns, $widths);
        }

        return $this;
    }

    /**
     * Render a summary block of label/value pairs.
     *
     * @param array<string,string|int|float> $pairs
     */
    public function summary(string $heading, array $pairs): self
    {
        if ($this->pageNumber === 0) {
            $this->startPage();
        }

        if ($this->cursorY < self::MARGIN + 80) {
            $this->finishPage();
            $this->startPage();
        }

        $this->cursorY -= 8;
        $this->text($heading, self::MARGIN, $this->cursorY, 11, self::FONT_BOLD, [0.12, 0.31, 0.47]);
        $this->cursorY -= 16;

        // Two columns, so a summary of eight figures does not run down a whole
        // page when it could sit in four lines.
        $columnWidth = $this->contentWidth() / 2;
        $index       = 0;

        foreach ($pairs as $label => $value) {
            $column = $index % 2;
            $x      = self::MARGIN + ($column * $columnWidth);

            $this->text((string) $label . ':', $x, $this->cursorY, 9, self::FONT_BOLD);
            $this->text(
                $this->truncate((string) $value, $columnWidth - 130, 9),
                $x + 125,
                $this->cursorY,
                9,
                self::FONT_REGULAR
            );

            if ($column === 1) {
                $this->cursorY -= 14;
            }

            $index++;
        }

        if ($index % 2 === 1) {
            $this->cursorY -= 14;
        }

        $this->cursorY -= 6;

        return $this;
    }

    /**
     * Render a paragraph of text, wrapping to the content width.
     */
    public function paragraph(string $text, float $size = 9.5): self
    {
        if ($this->pageNumber === 0) {
            $this->startPage();
        }

        foreach ($this->wrap($text, $this->contentWidth(), $size) as $line) {
            if ($this->cursorY < self::MARGIN + 40) {
                $this->finishPage();
                $this->startPage();
            }

            $this->text($line, self::MARGIN, $this->cursorY, $size, self::FONT_REGULAR);
            $this->cursorY -= $size + 3;
        }

        $this->cursorY -= 6;

        return $this;
    }

    /**
     * Render a section heading.
     */
    public function heading(string $text): self
    {
        if ($this->pageNumber === 0) {
            $this->startPage();
        }

        if ($this->cursorY < self::MARGIN + 60) {
            $this->finishPage();
            $this->startPage();
        }

        $this->cursorY -= 10;
        $this->text($text, self::MARGIN, $this->cursorY, 12, self::FONT_BOLD, [0.12, 0.31, 0.47]);
        $this->cursorY -= 6;
        $this->line(self::MARGIN, $this->cursorY, $this->pageWidth() - self::MARGIN, $this->cursorY, 0.6, [0.12, 0.31, 0.47]);
        $this->cursorY -= 14;

        return $this;
    }

    // ------------------------------------------------------------------
    // Page furniture
    // ------------------------------------------------------------------

    private function startPage(): void
    {
        $this->pageNumber++;
        $this->currentPage = '';
        $this->cursorY     = $this->pageHeight() - self::MARGIN;

        $this->pageHeader();
    }

    private function pageHeader(): void
    {
        $right = $this->pageWidth() - self::MARGIN;

        // A coloured band, so a printed report is recognisable at a glance.
        $this->rectangle(self::MARGIN, $this->cursorY - 30, $this->contentWidth(), 32, [0.12, 0.31, 0.47]);

        $this->text($this->organisation, self::MARGIN + 8, $this->cursorY - 10, 12, self::FONT_BOLD, [1, 1, 1]);
        $this->text(
            (string) config('app.name', 'Vehicle Access Monitoring System'),
            self::MARGIN + 8,
            $this->cursorY - 22,
            8,
            self::FONT_REGULAR,
            [0.85, 0.9, 0.95]
        );

        $stamp = now()->format('d M Y H:i');
        $this->text($stamp, $right - 8 - $this->textWidth($stamp, 8, self::FONT_REGULAR), $this->cursorY - 22, 8, self::FONT_REGULAR, [0.85, 0.9, 0.95]);

        $this->cursorY -= 46;

        $this->text($this->title, self::MARGIN, $this->cursorY, 14, self::FONT_BOLD);
        $this->cursorY -= 16;

        if ($this->subtitle !== '') {
            $this->text($this->subtitle, self::MARGIN, $this->cursorY, 9.5, self::FONT_REGULAR, [0.35, 0.35, 0.35]);
            $this->cursorY -= 13;
        }

        // The applied filters are printed with the report: a table of numbers
        // with no statement of what was included is not evidence of anything.
        foreach ($this->filters as $filter) {
            $this->text($filter, self::MARGIN, $this->cursorY, 8.5, self::FONT_REGULAR, [0.35, 0.35, 0.35]);
            $this->cursorY -= 11;
        }

        $this->cursorY -= 6;
    }

    private function finishPage(): void
    {
        $footerY = self::MARGIN - 6;
        $right   = $this->pageWidth() - self::MARGIN;

        $this->line(self::MARGIN, self::MARGIN + 8, $right, self::MARGIN + 8, 0.4, [0.75, 0.75, 0.75]);

        $left = $this->generatedBy === ''
            ? (string) config('app.copyright', '')
            : sprintf('Generated by %s', $this->generatedBy);

        $this->text($left, self::MARGIN, $footerY, 7.5, self::FONT_REGULAR, [0.45, 0.45, 0.45]);

        // The page number is written as "Page N" here; the total is patched in
        // once the document is complete and the count is actually known.
        $label = sprintf('Page %d of {{PAGES}}', $this->pageNumber);
        $this->text(
            $label,
            $right - $this->textWidth('Page 99 of 99', 7.5, self::FONT_REGULAR),
            $footerY,
            7.5,
            self::FONT_REGULAR,
            [0.45, 0.45, 0.45]
        );

        $this->pages[] = $this->currentPage;
        $this->currentPage = '';
    }

    /**
     * @param list<string> $headers
     * @param list<float>  $widths
     */
    private function tableHeader(array $headers, array $widths): void
    {
        $this->rectangle(self::MARGIN, $this->cursorY - 4, $this->contentWidth(), 16, [0.12, 0.31, 0.47]);

        $x = self::MARGIN + 4;

        foreach ($headers as $index => $header) {
            $this->text(
                $this->truncate($header, $widths[$index] - 6, 8.5),
                $x,
                $this->cursorY + 1,
                8.5,
                self::FONT_BOLD,
                [1, 1, 1]
            );

            $x += $widths[$index];
        }

        $this->cursorY -= 18;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string>        $columns
     * @param list<float>         $widths
     */
    private function tableRow(array $row, array $columns, array $widths): void
    {
        // Alternating shading, which is what makes a wide table readable across
        // a printed page.
        if ($this->pageNumber > 0 && (int) round($this->cursorY) % 2 === 0) {
            $this->rectangle(self::MARGIN, $this->cursorY - 3, $this->contentWidth(), 13, [0.96, 0.97, 0.98]);
        }

        $x = self::MARGIN + 4;

        foreach ($columns as $index => $column) {
            $value = $this->stringify($row[$column] ?? '');
            $width = $widths[$index] ?? 60.0;

            $this->text($this->truncate($value, $width - 6, 8), $x, $this->cursorY, 8, self::FONT_REGULAR);

            $x += $width;
        }

        $this->cursorY -= 13;
    }

    /**
     * Distribute the content width across the columns.
     *
     * @param list<string>     $headers
     * @param list<float>|null $weights
     *
     * @return list<float>
     */
    private function columnWidths(array $headers, ?array $weights): array
    {
        $count = count($headers);

        if ($count === 0) {
            return [];
        }

        $weights ??= array_fill(0, $count, 1.0);
        $total     = array_sum($weights) ?: 1.0;

        return array_map(
            fn (float $weight): float => ($weight / $total) * $this->contentWidth(),
            array_slice(array_pad($weights, $count, 1.0), 0, $count)
        );
    }

    // ------------------------------------------------------------------
    // Primitives
    // ------------------------------------------------------------------

    /**
     * @param list<float> $colour RGB, each 0..1.
     */
    private function text(string $value, float $x, float $y, float $size, string $font, array $colour = [0, 0, 0]): void
    {
        $this->currentPage .= sprintf(
            "BT %.3f %.3f %.3f rg /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $colour[0],
            $colour[1],
            $colour[2],
            $font,
            $size,
            $x,
            $y,
            $this->escape($value)
        );
    }

    /**
     * @param list<float> $colour
     */
    private function rectangle(float $x, float $y, float $width, float $height, array $colour): void
    {
        $this->currentPage .= sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $colour[0],
            $colour[1],
            $colour[2],
            $x,
            $y,
            $width,
            $height
        );
    }

    /**
     * @param list<float> $colour
     */
    private function line(float $x1, float $y1, float $x2, float $y2, float $width, array $colour): void
    {
        $this->currentPage .= sprintf(
            "%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $colour[0],
            $colour[1],
            $colour[2],
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    /**
     * Escape a string for a PDF literal, and fold it to a single-byte encoding.
     *
     * The standard fonts are WinAnsi, so a UTF-8 name has to be transliterated
     * rather than written through: emitting raw UTF-8 would render as garbage.
     */
    private function escape(string $value): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);

        if ($converted === false) {
            $converted = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $converted);
    }

    /**
     * Approximate the rendered width of a string.
     *
     * Helvetica's average advance is close enough to 0.5 em for truncation
     * decisions; exact metrics would mean embedding the AFM tables for a
     * marginal gain.
     */
    private function textWidth(string $value, float $size, string $font): float
    {
        $factor = $font === self::FONT_BOLD ? 0.55 : 0.5;

        return mb_strlen($value) * $size * $factor;
    }

    private function truncate(string $value, float $maximumWidth, float $size): string
    {
        if ($this->textWidth($value, $size, self::FONT_REGULAR) <= $maximumWidth) {
            return $value;
        }

        $characters = max(1, (int) floor($maximumWidth / ($size * 0.5)) - 1);

        return mb_substr($value, 0, $characters) . '…';
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, float $width, float $size): array
    {
        $charactersPerLine = max(10, (int) floor($width / ($size * 0.5)));

        return explode("\n", wordwrap($text, $charactersPerLine, "\n", true));
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null   => '',
            is_bool($value)   => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            is_array($value)  => (string) json_encode($value),
            default           => '',
        };
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    /**
     * Assemble the finished PDF document.
     */
    public function output(): string
    {
        if ($this->pageNumber === 0) {
            $this->startPage();
        }

        if ($this->currentPage !== '') {
            $this->finishPage();
        }

        $totalPages = count($this->pages);

        // The page total was unknown while each page was written, so the
        // placeholder is resolved now.
        $pages = array_map(
            static fn (string $content): string => str_replace('{{PAGES}}', (string) $totalPages, $content),
            $this->pages
        );

        $objects   = [];
        $pageCount = count($pages);

        // 1: catalogue, 2: page tree, 3..: fonts, then page + content pairs.
        $pageObjectIds    = [];
        $contentObjectIds = [];
        $nextId           = 6;

        foreach ($pages as $index => $content) {
            $pageObjectIds[$index]    = $nextId++;
            $contentObjectIds[$index] = $nextId++;
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = sprintf(
            '<< /Type /Pages /Count %d /Kids [%s] >>',
            $pageCount,
            implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageObjectIds))
        );
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = sprintf(
            '<< /Title (%s) /Producer (%s) /CreationDate (D:%s) >>',
            $this->escape($this->title),
            $this->escape((string) config('app.name', 'VAMS')),
            now()->format('YmdHis')
        );

        foreach ($pages as $index => $content) {
            $objects[$pageObjectIds[$index]] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] '
                . '/Resources << /Font << /%s 3 0 R /%s 4 0 R >> >> /Contents %d 0 R >>',
                $this->pageWidth(),
                $this->pageHeight(),
                self::FONT_REGULAR,
                self::FONT_BOLD,
                $contentObjectIds[$index]
            );

            $objects[$contentObjectIds[$index]] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );
        }

        ksort($objects);

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xrefOffset = strlen($pdf);
        $maximumId  = max(array_keys($objects));

        $pdf .= sprintf("xref\n0 %d\n", $maximumId + 1);
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= $maximumId; $id++) {
            $pdf .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                // A gap in the numbering must still occupy a slot in the table.
                : "0000000000 65535 f \n";
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info 5 0 R >>\nstartxref\n%d\n%%%%EOF",
            $maximumId + 1,
            $xrefOffset
        );

        return $pdf;
    }

    public function pageCount(): int
    {
        return max(1, count($this->pages));
    }
}
