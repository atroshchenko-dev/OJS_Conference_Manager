<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Export;

/**
 * A minimal Office Open XML workbook with one sheet (FEE-12), without a
 * third-party library: the rows go to a temporary XML file and are zipped
 * at the end. Text is stored inline, amounts as numbers, the header row is
 * bold and frozen. Needs the zip extension of PHP.
 */
final class XlsxTableWriter implements TableWriter
{
    /** @var resource|null */
    private $sheet = null;
    private string $sheetPath = '';
    private int $rowNo = 0;
    private int $columns = 0;

    public static function isAvailable(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    public function open(array $header): void
    {
        $this->sheetPath = (string) tempnam(sys_get_temp_dir(), 'cmxls');
        $sheet = fopen($this->sheetPath, 'wb');
        if ($sheet === false) {
            throw new \RuntimeException('Cannot write the export file');
        }
        $this->sheet = $sheet;
        $this->columns = count($header);
        fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="' . max(1, $this->columns) . '" width="20" customWidth="1"/></cols><sheetData>');
        $this->write($header, [], 1);
    }

    public function row(array $row, array $numeric): void
    {
        $this->write($row, $numeric, 0);
    }

    /**
     * @param list<string|null> $row
     * @param list<bool> $numeric
     */
    private function write(array $row, array $numeric, int $style): void
    {
        if ($this->sheet === null) {
            return;
        }
        $this->rowNo++;
        $xml = '<row r="' . $this->rowNo . '">';
        foreach ($row as $i => $v) {
            $ref = self::column($i) . $this->rowNo;
            $v = (string) $v;
            if ($v === '') {
                continue;
            }
            if (($numeric[$i] ?? false) && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
                $xml .= '<c r="' . $ref . '" s="2"><v>' . $v . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">'
                    . htmlspecialchars(self::clean($v), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        fwrite($this->sheet, $xml . '</row>');
    }

    public function close(): string
    {
        if ($this->sheet === null) {
            return '';
        }
        fwrite($this->sheet, '</sheetData></worksheet>');
        fclose($this->sheet);
        $this->sheet = null;

        $path = (string) tempnam(sys_get_temp_dir(), 'cmxlsx');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot write the export file');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($this->sheetPath);
        return $path;
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    /** A, B, …, Z, AA, … */
    private static function column(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    /** Characters XML 1.0 does not allow. */
    private static function clean(string $v): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $v);
    }
}
