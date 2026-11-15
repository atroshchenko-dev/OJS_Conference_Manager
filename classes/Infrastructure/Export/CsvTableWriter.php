<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Export;

/**
 * CSV for spreadsheets: UTF-8 with BOM, ";" between cells, amounts with a
 * decimal comma, as Excel with a Ukrainian or most European locales
 * opens it.
 */
final class CsvTableWriter implements TableWriter
{
    /** @var resource|null */
    private $out = null;
    private string $path = '';

    public function open(array $header): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'cmcsv');
        $out = fopen($this->path, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write the export file');
        }
        $this->out = $out;
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';', '"', '');
    }

    public function row(array $row, array $numeric): void
    {
        if ($this->out === null) {
            return;
        }
        $cells = [];
        foreach ($row as $i => $v) {
            $v = (string) $v;
            if (!($numeric[$i] ?? false) && preg_match('/^[=+\-@]/', $v)) {
                // A cell that starts like a formula stays text in a spreadsheet.
                $v = "'" . $v;
            }
            $cells[] = ($numeric[$i] ?? false) ? str_replace('.', ',', $v) : $v;
        }
        fputcsv($this->out, $cells, ';', '"', '');
    }

    public function close(): string
    {
        if ($this->out !== null) {
            fclose($this->out);
            $this->out = null;
        }
        return $this->path;
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function extension(): string
    {
        return 'csv';
    }
}
