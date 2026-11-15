<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Review\Bid;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\BidRepository;
use Illuminate\Database\Connection;

/** DEC-10 on conf_review_bids. */
final class DbBidRepository implements BidRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function ofSubmissions(array $submissionIds): array
    {
        $out = [];
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            foreach ($this->db->table('conf_review_bids')->whereIn('submission_id', $chunk)->get(['submission_id', 'user_id', 'bid'])->all() as $r) {
                $bid = Bid::tryFrom((string) $r->bid);
                if ($bid !== null) {
                    $out[(int) $r->submission_id][(int) $r->user_id] = $bid;
                }
            }
        }
        return $out;
    }

    public function save(int $submissionId, int $userId, ?Bid $bid, string $updatedAt): void
    {
        $key = ['submission_id' => $submissionId, 'user_id' => $userId];
        if ($bid === null) {
            $this->db->table('conf_review_bids')->where($key)->delete();
            return;
        }
        $values = ['bid' => $bid->value, 'updated_at' => $updatedAt];
        $this->db->transaction(function () use ($key, $values): void {
            if ($this->db->table('conf_review_bids')->where($key)->exists()) {
                $this->db->table('conf_review_bids')->where($key)->update($values);
            } else {
                $this->db->table('conf_review_bids')->insert($key + $values);
            }
        });
    }
}
