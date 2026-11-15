<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Report;

use APP\plugins\generic\conferenceManager\classes\Domain\Report\ReportTable;
use PHPUnit\Framework\TestCase;

final class ReportTableTest extends TestCase
{
    public function testGroupsVariantsOfOneNameAndSortsByFirstColumn(): void
    {
        $t = new ReportTable(['active', 'attended']);
        $t->add('Ліцей № 1', ['active' => true, 'attended' => true]);
        $t->add(' ліцей №1 ', ['active' => true, 'attended' => false]);
        $t->add('Університет', ['active' => 1]);
        $t->add('Академія', ['active' => 1, 'unknown' => 5]);

        $rows = $t->rows();
        self::assertSame('Ліцей № 1', $rows[0]['label']);
        self::assertSame(['active' => 2, 'attended' => 1], $rows[0]['counts']);
        // Ties are sorted by label.
        self::assertSame(['Академія', 'Університет'], [$rows[1]['label'], $rows[2]['label']]);
        self::assertSame(['active' => 4, 'attended' => 1], $t->totals());
    }

    public function testCsvHasBomSemicolonsTotalsAndNoFormulas(): void
    {
        $t = new ReportTable(['n']);
        $t->add('=HYPERLINK("x")', ['n' => 1]);
        $t->add('Заклад "Світанок"', ['n' => 2]);
        $csv = $t->csv(['Установи', 'Кількість'], 'Разом');

        self::assertStringStartsWith("\u{FEFF}\"Установи\";\"Кількість\"\r\n", $csv);
        self::assertStringContainsString("\"Заклад \"\"Світанок\"\"\";\"2\"\r\n", $csv);
        self::assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\";\"1\"\r\n", $csv);
        self::assertStringEndsWith("\"Разом\";\"3\"\r\n", $csv);
    }

    public function testEmptyTable(): void
    {
        $t = new ReportTable(['a', 'b']);
        self::assertSame([], $t->rows());
        self::assertSame(['a' => 0, 'b' => 0], $t->totals());
    }
}
