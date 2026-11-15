<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Review;

use APP\plugins\generic\conferenceManager\classes\Domain\Review\ConflictCheck;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\ReviewSettings;
use PHPUnit\Framework\TestCase;

final class ConflictCheckTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('університет крок', ConflictCheck::normalize(' Університет «КРОК» '));
        self::assertSame(ConflictCheck::normalize("Прем'єр-коледж"), ConflictCheck::normalize('Прем’єр коледж'));
    }

    public function testParts(): void
    {
        self::assertSame(['кафедра права університет крок', 'кафедра права', 'університет крок'], array_keys(ConflictCheck::parts('Кафедра права, Університет «КРОК»')));
        self::assertSame([], ConflictCheck::parts('НАН'), 'too short to compare');
    }

    public function testFind(): void
    {
        $editors = [
            ['userId' => 1, 'name' => 'A', 'affiliations' => ['Київський університет']],
            ['userId' => 2, 'name' => 'B', 'affiliations' => ['Кафедра педагогіки; Інститут X']],
            ['userId' => 3, 'name' => 'C', 'affiliations' => []],
        ];
        $authors = [
            ['name' => 'P', 'affiliations' => ['Інститут X']],
            ['name' => 'Q', 'affiliations' => ['київський УНІВЕРСИТЕТ.']],
        ];
        $found = ConflictCheck::find($editors, $authors);
        self::assertSame([[1, 'Q'], [2, 'P']], array_map(static fn (array $c): array => [$c['userId'], $c['author']], $found));
        self::assertSame('Інститут X', $found[1]['affiliation']);
        self::assertSame([], ConflictCheck::find($editors, [['name' => 'R', 'affiliations' => ['Львівський університет']]]));
        self::assertSame([], ConflictCheck::find([['userId' => 1, 'name' => 'A', 'affiliations' => ['ROR https://ror.org/01']]], [['name' => 'R', 'affiliations' => ['ROR https://ror.org/02']]]));
    }

    public function testSettings(): void
    {
        $s = ReviewSettings::fromArray(['abstractFormId' => '3', 'paperFormId' => 'x']);
        self::assertSame(['abstractFormId' => 3, 'paperFormId' => null, 'biddingUntil' => null, 'reviewersPerSubmission' => 2], $s->toArray());
        $b = ReviewSettings::fromArray(['biddingUntil' => '2027-02-30', 'reviewersPerSubmission' => '11']);
        self::assertSame([null, 2], [$b->biddingUntil, $b->reviewersPerSubmission]);
        $b = ReviewSettings::fromArray(['biddingUntil' => '2027-03-01', 'reviewersPerSubmission' => '3']);
        self::assertSame([true, true, false], [$b->biddingOpenOn('2027-02-28'), $b->biddingOpenOn('2027-03-01'), $b->biddingOpenOn('2027-03-02')]);
        self::assertSame(3, $b->reviewersPerSubmission);
        self::assertSame([3, null, null], [$s->formForRound(1), $s->formForRound(2), $s->formForRound(5)]);
    }
}
