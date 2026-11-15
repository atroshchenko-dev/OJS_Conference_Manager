<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Attendance;

use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\CheckinToken;
use PHPUnit\Framework\TestCase;

final class CheckinTokenTest extends TestCase
{
    public function testRoundTripAndForgery(): void
    {
        $t = CheckinToken::make(42, 'secret');
        self::assertMatchesRegularExpression('/^42-[0-9a-f]{20}$/', $t);
        self::assertSame(42, CheckinToken::read($t, 'secret'));
        self::assertNull(CheckinToken::read($t, 'other secret'), 'another site');
        self::assertNull(CheckinToken::read('43' . substr($t, 2), 'secret'), 'another participation with the same MAC');
        self::assertNull(CheckinToken::read($t, ''), 'no secret, no tickets');
        foreach (['', '42', '0-' . str_repeat('a', 20), '42-' . str_repeat('A', 20), $t . 'x', '../' . $t] as $bad) {
            self::assertNull(CheckinToken::read($bad, 'secret'), $bad);
        }
    }
}
