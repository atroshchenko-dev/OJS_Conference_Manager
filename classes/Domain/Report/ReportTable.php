<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Report;

/**
 * RPT-02: counts grouped by one dimension (section, institution, country,
 * category…). Rows are sorted by the first count, largest first, then by
 * label; the totals row adds up every column.
 */
final class ReportTable
{
    /** @var array<string, array{label: string, counts: array<string, int>}> */
    private array $rows = [];

    /** @param list<string> $columns */
    public function __construct(public readonly array $columns)
    {
    }

    /**
     * Counts one item under its group. The key groups variants of the same
     * name ("Ліцей № 1" and "ліцей №1 "), the first label seen is shown.
     *
     * @param array<string, int|bool> $values column => 1/0 or a number
     */
    public function add(string $label, array $values): void
    {
        $key = self::key($label);
        $this->rows[$key] ??= ['label' => trim($label), 'counts' => array_fill_keys($this->columns, 0)];
        foreach ($values as $column => $v) {
            if (array_key_exists($column, $this->rows[$key]['counts'])) {
                $this->rows[$key]['counts'][$column] += (int) $v;
            }
        }
    }

    /** @return list<array{label: string, counts: array<string, int>}> */
    public function rows(): array
    {
        $rows = array_values($this->rows);
        $first = $this->columns[0] ?? null;
        usort($rows, static function (array $a, array $b) use ($first): int {
            $byCount = $first === null ? 0 : $b['counts'][$first] <=> $a['counts'][$first];
            return $byCount !== 0 ? $byCount : strcmp($a['label'], $b['label']);
        });
        return $rows;
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        $out = array_fill_keys($this->columns, 0);
        foreach ($this->rows as $row) {
            foreach ($row['counts'] as $c => $n) {
                $out[$c] += $n;
            }
        }
        return $out;
    }

    /**
     * CSV for a spreadsheet: a BOM so Excel reads UTF-8, a header, the rows
     * and the totals. Cells that start like a formula are quoted with an
     * apostrophe.
     *
     * @param list<string> $header label column then one per count column
     */
    public function csv(array $header, string $totalLabel): string
    {
        $lines = [$header];
        foreach ($this->rows() as $row) {
            $lines[] = [$row['label'], ...array_map('strval', array_values($row['counts']))];
        }
        $lines[] = [$totalLabel, ...array_map('strval', array_values($this->totals()))];
        $out = "\u{FEFF}";
        foreach ($lines as $cells) {
            $out .= implode(';', array_map(static function (string $c): string {
                if ($c !== '' && in_array($c[0], ['=', '+', '-', '@'], true) && !is_numeric($c)) {
                    $c = "'" . $c;
                }
                return '"' . str_replace('"', '""', $c) . '"';
            }, $cells)) . "\r\n";
        }
        return $out;
    }

    private static function key(string $label): string
    {
        $s = mb_strtolower(trim($label));
        $s = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
        return (string) preg_replace('/\s*№\s*/u', '№', $s);
    }
}
