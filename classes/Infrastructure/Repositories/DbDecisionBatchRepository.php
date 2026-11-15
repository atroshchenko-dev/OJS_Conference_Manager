<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Decision\DecisionBatchRepository;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final class DbDecisionBatchRepository implements DecisionBatchRepository
{
    private const T = 'conf_decision_batches';
    private const I = 'conf_decision_batch_items';

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function create(int $contextId, int $editionId, int $decision, int $editorId, array $submissionIds, DateTimeImmutable $now): int
    {
        return (int) $this->db->transaction(function () use ($contextId, $editionId, $decision, $editorId, $submissionIds, $now): int {
            $id = (int) $this->db->table(self::T)->insertGetId([
                'context_id' => $contextId,
                'edition_id' => $editionId,
                'decision' => $decision,
                'editor_id' => $editorId,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            foreach (array_chunk($submissionIds, 100) as $chunk) {
                $this->db->table(self::I)->insert(array_map(static fn (int $s): array => [
                    'batch_id' => $id,
                    'submission_id' => $s,
                    'status' => 'pending',
                ], $chunk));
            }
            return $id;
        });
    }

    public function find(int $contextId, int $batchId): ?array
    {
        $row = $this->db->table(self::T)->where('id', $batchId)->where('context_id', $contextId)->first();
        if ($row === null) {
            return null;
        }
        $items = $this->db->table(self::I)->where('batch_id', $batchId)->orderBy('submission_id')->get()->all();
        return [
            'id' => (int) $row->id,
            'contextId' => (int) $row->context_id,
            'editionId' => (int) $row->edition_id,
            'decision' => (int) $row->decision,
            'editorId' => (int) $row->editor_id,
            'createdAt' => $row->created_at === null ? null : str_replace(' ', 'T', (string) $row->created_at) . 'Z',
            'items' => array_map(static fn (object $i): array => [
                'submissionId' => (int) $i->submission_id,
                'status' => (string) $i->status,
                'error' => $i->error === null ? null : (string) $i->error,
            ], $items),
        ];
    }

    public function ofEdition(int $contextId, int $editionId, int $limit): array
    {
        return array_map('intval', $this->db->table(self::T)->where('context_id', $contextId)->where('edition_id', $editionId)
            ->orderByDesc('id')->limit($limit)->pluck('id')->all());
    }

    public function claim(int $batchId, int $submissionId, DateTimeImmutable $now): bool
    {
        return $this->db->table(self::I)->where('batch_id', $batchId)->where('submission_id', $submissionId)->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => $now->format('Y-m-d H:i:s')]) === 1;
    }

    public function finish(int $batchId, int $submissionId, ?string $error, DateTimeImmutable $now): void
    {
        $this->db->table(self::I)->where('batch_id', $batchId)->where('submission_id', $submissionId)->update([
            'status' => $error === null ? 'done' : 'failed',
            'error' => $error === null ? null : mb_substr($error, 0, 255),
            'finished_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
