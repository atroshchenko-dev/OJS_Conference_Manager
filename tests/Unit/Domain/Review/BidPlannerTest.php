<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Review;

use APP\plugins\generic\conferenceManager\classes\Domain\Review\Bid;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\BidPlanner;
use PHPUnit\Framework\TestCase;

final class BidPlannerTest extends TestCase
{
    /** @return list<array{0: int, 1: int}> */
    private static function pairs(array $plan): array
    {
        return array_map(static fn (array $p): array => [$p['submissionId'], $p['userId']], $plan);
    }

    public function testScarceSubmissionsFirstAndLoadIsBalanced(): void
    {
        $subs = [1 => ['assigned' => [], 'authors' => []], 2 => ['assigned' => [], 'authors' => []]];
        // Submission 2 has one willing reviewer, so it takes them first.
        $bids = [1 => [7 => Bid::Eager, 8 => Bid::Willing], 2 => [7 => Bid::Willing]];
        self::assertSame([[2, 7], [1, 7]], self::pairs(BidPlanner::plan($subs, $bids, [], [7, 8], 1)));
        // With load: the eager reviewer is busy, the willing one is still second.
        self::assertSame([[2, 7], [1, 7], [1, 8]], self::pairs(BidPlanner::plan($subs, $bids, [7 => 5], [7, 8], 2)));
        // Equal bids: the less loaded first, counting what this plan already gave.
        $bids = [1 => [7 => Bid::Willing, 8 => Bid::Willing], 2 => [7 => Bid::Willing, 8 => Bid::Willing]];
        self::assertSame([[1, 7], [2, 8]], self::pairs(BidPlanner::plan($subs, $bids, [], [7, 8], 1)));
    }

    public function testNeverProposed(): void
    {
        $subs = [1 => ['assigned' => [5], 'authors' => [6]]];
        $bids = [1 => [5 => Bid::Eager, 6 => Bid::Eager, 7 => Bid::Unwilling, 8 => Bid::Conflict, 9 => Bid::Eager, 10 => Bid::Willing]];
        // 5 assigned, 6 author, 7 and 8 refused, 9 not a reviewer any more; one more is needed.
        self::assertSame([[1, 10]], self::pairs(BidPlanner::plan($subs, $bids, [], [5, 6, 7, 8, 10], 2)));
        self::assertSame([], BidPlanner::plan($subs, $bids, [], [5, 6, 7, 8, 10], 1), 'already enough');
        self::assertSame([0, 0, 1, 2], [Bid::Conflict->weight(), Bid::Unwilling->weight(), Bid::Willing->weight(), Bid::Eager->weight()]);
    }
}
