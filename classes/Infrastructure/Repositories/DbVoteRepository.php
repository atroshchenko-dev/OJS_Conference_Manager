<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Review\Vote;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\VoteRepository;
use Illuminate\Database\Connection;

/** DEC-09 on conf_review_votes. */
final class DbVoteRepository implements VoteRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function ofSubmission(int $submissionId): array
    {
        $out = [];
        foreach ($this->db->table('conf_review_votes')->where('submission_id', $submissionId)->orderBy('updated_at')->get()->all() as $r) {
            $vote = Vote::tryFrom((string) $r->vote);
            if ($vote !== null) {
                $out[] = ['userId' => (int) $r->user_id, 'vote' => $vote, 'comment' => (string) $r->comment, 'updatedAt' => (string) $r->updated_at];
            }
        }
        return $out;
    }

    public function counts(array $submissionIds): array
    {
        $out = [];
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            $rows = $this->db->table('conf_review_votes')->whereIn('submission_id', $chunk)
                ->groupBy('submission_id', 'vote')->selectRaw('submission_id, vote, COUNT(*) as n')->get()->all();
            foreach ($rows as $r) {
                $id = (int) $r->submission_id;
                $out[$id] ??= ['for' => 0, 'against' => 0, 'abstain' => 0];
                if (isset($out[$id][(string) $r->vote])) {
                    $out[$id][(string) $r->vote] = (int) $r->n;
                }
            }
        }
        return $out;
    }

    public function save(int $submissionId, int $userId, Vote $vote, string $comment, string $updatedAt): void
    {
        $values = ['vote' => $vote->value, 'comment' => $comment === '' ? null : $comment, 'updated_at' => $updatedAt];
        $key = ['submission_id' => $submissionId, 'user_id' => $userId];
        $this->db->transaction(function () use ($key, $values): void {
            if ($this->db->table('conf_review_votes')->where($key)->exists()) {
                $this->db->table('conf_review_votes')->where($key)->update($values);
            } else {
                $this->db->table('conf_review_votes')->insert($key + $values);
            }
        });
    }

    public function delete(int $submissionId, int $userId): bool
    {
        return $this->db->table('conf_review_votes')->where('submission_id', $submissionId)->where('user_id', $userId)->delete() > 0;
    }
}
