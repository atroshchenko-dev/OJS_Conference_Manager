<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Review;

/**
 * DEC-10: proposes reviewers for submissions from their bids. Submissions
 * with the fewest willing reviewers go first; among the reviewers the
 * eager ones before the willing, then the least loaded. A reviewer who
 * did not bid, refused or declared a conflict is never proposed, nor an
 * author of the submission, nor one already assigned.
 */
final class BidPlanner
{
    /**
     * @param array<int, array{assigned: list<int>, authors: list<int>}> $submissions by id
     * @param array<int, array<int, Bid>> $bids submission => user => bid
     * @param array<int, int> $loads reviewer => open review assignments
     * @param list<int> $reviewers who may be proposed
     * @return list<array{submissionId: int, userId: int, bid: Bid}>
     */
    public static function plan(array $submissions, array $bids, array $loads, array $reviewers, int $perSubmission): array
    {
        $allowed = array_fill_keys($reviewers, true);
        $candidates = [];
        foreach ($submissions as $id => $s) {
            $skip = array_fill_keys(array_merge($s['assigned'], $s['authors']), true);
            $list = [];
            foreach ($bids[$id] ?? [] as $userId => $bid) {
                if ($bid->weight() > 0 && isset($allowed[$userId]) && !isset($skip[$userId])) {
                    $list[$userId] = $bid;
                }
            }
            $need = $perSubmission - count($s['assigned']);
            if ($need > 0 && $list !== []) {
                $candidates[$id] = ['need' => $need, 'list' => $list];
            }
        }
        uksort($candidates, static fn (int $a, int $b): int => [count($candidates[$a]['list']), $a] <=> [count($candidates[$b]['list']), $b]);
        $out = [];
        foreach ($candidates as $id => $c) {
            $users = array_keys($c['list']);
            usort($users, static fn (int $a, int $b): int => [-$c['list'][$a]->weight(), $loads[$a] ?? 0, $a] <=> [-$c['list'][$b]->weight(), $loads[$b] ?? 0, $b]);
            foreach (array_slice($users, 0, $c['need']) as $userId) {
                $out[] = ['submissionId' => $id, 'userId' => $userId, 'bid' => $c['list'][$userId]];
                $loads[$userId] = ($loads[$userId] ?? 0) + 1;
            }
        }
        return $out;
    }
}
