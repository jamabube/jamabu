<?php

declare(strict_types=1);

namespace App\Core\Export;

use App\Core\Support\Html;
use ZipArchive;

/**
 * Excel (.xlsx) export.
 *
 * Writes the Office Open XML package directly with ZipArchive rather than
 * pulling in a spreadsheet library, which keeps the project dependency-free —
 * a real constraint for a LAN deployment installed from removable media.
 *
 * The output is a genuine .xlsx that Excel, LibreOffice and Google Sheets all
 * open natively, not an HTML table with a misleading extension.
 *
 * @package App\Core\Export
 * @version 1.0.0
 */
final class SpreadsheetWriter
{
    /**
     * Build an .xlsx document.
     *
     * @param list<string>              $headers
     * @param list<array<string,mixed>> $rows
     * @param list<string>|null         $columns
     * @param array<string,string>      $metadata title, organisation, generated_by
     */
    public static function build(
        array $headers,
        array $rows,
        ?array $columns = null,
        string $sheetName = 'Report',
        array $metadata = []
    ): string {
        $columns ??= $rows === [] ? [] : array_keys($rows[0]);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'vams_xlsx_');

        if ($temporaryFile === false) {
            return '';
        }

        $zip = new ZipArchive();

        if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($temporaryFile);

            return '';
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRelationships());
        $zip->addFromString('docProps/app.xml', self::applicationProperties());
        $zip->addFromString('docProps/core.xml', self::coreProperties($metadata));
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationships());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::worksheet($headers, $rows, $columns));

        $zip->close();

        $contents = (string) file_get_contents($temporaryFile);
        @unlink($temporaryFile);

        return $contents;
    }

    /**
     * Build the worksheet XML.
     *
     * @param list<string>              $headers
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $columns
     */
    private static function worksheet(array $headers, array $rows, array $columns): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Column widths sized from the header text, so a report is readable
        // without the recipient having to resize every column.
        $xml .= '<cols>';
        foreach ($headers as $index => $header) {
            $xml .= sprintf(
                '<col min="%1$d" max="%1$d" width="%2$.1f" customWidth="1"/>',
                $index + 1,
                max(12.0, min(45.0, mb_strlen($header) * 1.3 + 4))
            );
        }
        $xml .= '</cols>';

        // A frozen header row keeps the columns identifiable while scrolling.
        $xml .= '<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>';

        $xml .= '<sheetData>';
        $xml .= '<row r="1" s="1">';

        foreach ($headers as $index => $header) {
            $xml .= sprintf(
                '<c r="%s1" s="1" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                self::columnLetter($index),
                self::escape($header)
            );
        }

        $xml .= '</row>';

        $rowNumber = 1;

        foreach ($rows as $row) {
            $rowNumber++;
            $xml .= sprintf('<row r="%d">', $rowNumber);

            foreach ($columns as $index => $column) {
                $value  = $row[$column] ?? '';
                $letter = self::columnLetter($index);

                // Numbers are written as numbers so the recipient can sum and
                // sort them; everything else goes out as inline text.
                if (is_int($value) || is_float($value)) {
                    $xml .= sprintf('<c r="%s%d"><v>%s</v></c>', $letter, $rowNumber, (string) $value);
                    continue;
                }

                $text = self::stringify($value);

                if ($text === '') {
                    continue;
                }

                $xml .= sprintf(
                    '<c r="%s%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                    $letter,
                    $rowNumber,
                    self::escape($text)
                );
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        // An auto-filter over the used range, so the recipient can slice the
        // report without rebuilding it.
        if ($headers !== [] && $rowNumber > 1) {
            $xml .= sprintf(
                '<autoFilter ref="A1:%s%d"/>',
                self::columnLetter(count($headers) - 1),
                $rowNumber
            );
        }

        return $xml . '</worksheet>';
    }

    /**
     * Convert a zero-based index into a spreadsheet column letter.
     */
    private static function columnLetter(int $index): string
    {
        $letter = '';

        for ($remaining = $index; $remaining >= 0; $remaining = intdiv($remaining, 26) - 1) {
            $letter = chr(65 + ($remaining % 26)) . $letter;
        }

        return $letter;
    }

    private static function escape(string $value): string
    {
        // Control characters are illegal in XML content and would produce a
        // file the spreadsheet refuses to open.
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
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

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        // Sheet names may not exceed 31 characters or contain : \ / ? * [ ]
        $safeName = mb_substr((string) preg_replace('/[:\\\\\/?*\[\]]/', '-', $sheetName), 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape($safeName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            // A named default style is required for a fully conformant
            // package; without it some readers warn and substitute their own.
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function applicationProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>' . self::escape((string) config('app.name', 'VAMS')) . '</Application>'
            . '</Properties>';
    }

    /**
     * @param array<string,string> $metadata
     */
    private static function coreProperties(array $metadata): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::escape($metadata['title'] ?? 'Report') . '</dc:title>'
            . '<dc:creator>' . self::escape($metadata['generated_by'] ?? 'VAMS') . '</dc:creator>'
            . '<cp:lastModifiedBy>' . self::escape($metadata['generated_by'] ?? 'VAMS') . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . now()->format('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>';
    }
}
