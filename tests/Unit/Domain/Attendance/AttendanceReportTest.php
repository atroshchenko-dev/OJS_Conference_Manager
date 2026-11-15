<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Attendance;

use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\AttendanceReport;
use PHPUnit\Framework\TestCase;

final class AttendanceReportTest extends TestCase
{
    public function testDurations(): void
    {
        $cases = [
            '75' => 75.0, '75,5' => 75.5, '01:15:30' => 75.5, '1:15' => 75.0,
            '1h 15m 30s' => 75.5, '1 год 15 хв' => 75.0, '75 mins' => 75.0,
            '45 хвилин' => 45.0, '30 сек' => 0.5, '2 години' => 120.0,
        ];
        foreach ($cases as $in => $out) {
            self::assertEqualsWithDelta($out, AttendanceReport::minutes((string) $in), 1e-9, (string) $in);
        }
        self::assertNull(AttendanceReport::minutes(''));
        self::assertNull(AttendanceReport::minutes('—'));
        self::assertNull(AttendanceReport::minutes('5 hamsters'));
    }

    public function testZoomCsvWithSemicolonsAndWindows1251(): void
    {
        $zoom = "Ім'я (справжнє ім'я);Електронна пошта користувача;Тривалість (хвилини)\n"
            . "Петренко;petro@uni.edu.ua;40\n"
            . "Петренко;PETRO@uni.edu.ua;15\n"
            . "Без адреси;;50\n"
            . "Дивний;x@y.ua;невідомо\n";
        $report = AttendanceReport::parse((string) mb_convert_encoding($zoom, 'Windows-1251', 'UTF-8'));
        self::assertNotNull($report);
        self::assertSame(['petro@uni.edu.ua' => 55], $report->minutes);
        self::assertSame(2, $report->skipped);
        self::assertSame(4, $report->rows);
    }

    public function testGoogleMeetCommaCsvAndNoHeader(): void
    {
        $meet = "\xEF\xBB\xBFFirst name,Last name,Email,Duration,Time joined\nOlha,K,olha@example.org,1:02:00,10:00\n";
        self::assertSame(['olha@example.org' => 62], AttendanceReport::parse($meet)?->minutes);
        self::assertNull(AttendanceReport::parse("name,phone\nA,1\n"));
    }
}
