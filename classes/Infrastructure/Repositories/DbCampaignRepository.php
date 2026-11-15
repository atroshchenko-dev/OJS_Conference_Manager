<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\CampaignRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final class DbCampaignRepository implements CampaignRepository
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function create(int $contextId, int $editionId, ?int $createdBy, string $locale, string $subject, string $body, array $filter, array $recipients, DateTimeImmutable $now): int
    {
        return $this->db->transaction(function () use ($contextId, $editionId, $createdBy, $locale, $subject, $body, $filter, $recipients, $now): int {
            $id = (int) $this->db->table('conf_campaigns')->insertGetId([
                'context_id' => $contextId,
                'edition_id' => $editionId,
                'created_by' => $createdBy,
                'locale' => $locale,
                'subject' => $subject,
                'body' => $body,
                'filter' => json_encode($filter, JSON_UNESCAPED_UNICODE),
                'total' => count($recipients),
                'created_at' => DateInput::toStorage($now),
            ]);
            foreach (array_chunk($recipients, 200) as $chunk) {
                $this->db->table('conf_campaign_recipients')->insert(array_map(static fn (array $r): array => [
                    'campaign_id' => $id,
                    'participant_id' => $r['participantId'],
                    'email' => mb_substr($r['email'], 0, 255),
                    'name' => mb_substr($r['name'], 0, 255),
                    'status' => 'pending',
                ], $chunk));
            }
            return $id;
        });
    }

    public function find(int $contextId, int $id): ?array
    {
        $r = $this->db->table('conf_campaigns')->where('context_id', $contextId)->where('id', $id)->first();
        return $r === null ? null : self::campaign($r);
    }

    public function claim(int $campaignId, int $limit): array
    {
        return $this->db->transaction(function () use ($campaignId, $limit): array {
            $rows = $this->db->table('conf_campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'pending')
                ->orderBy('id')->limit($limit)->lockForUpdate()->get(['id', 'participant_id', 'email', 'name'])->all();
            if ($rows === []) {
                return [];
            }
            $ids = array_map(static fn (object $r): int => (int) $r->id, $rows);
            $taken = $this->db->table('conf_campaign_recipients')->whereIn('id', $ids)->where('status', 'pending')->update(['status' => 'sending']);
            if ($taken !== count($ids)) {
                // Another worker was faster with some of them: keep only ours.
                $mine = $this->db->table('conf_campaign_recipients')->whereIn('id', $ids)->where('status', 'sending')->pluck('id')->all();
                $rows = array_values(array_filter($rows, static fn (object $r): bool => in_array((int) $r->id, array_map('intval', $mine), true)));
            }
            return array_map(static fn (object $r): array => [
                'id' => (int) $r->id,
                'participantId' => $r->participant_id === null ? null : (int) $r->participant_id,
                'email' => (string) $r->email,
                'name' => (string) ($r->name ?? ''),
            ], $rows);
        });
    }

    public function finish(int $recipientId, bool $sent, ?string $error, DateTimeImmutable $now): void
    {
        $this->db->table('conf_campaign_recipients')->where('id', $recipientId)->update([
            'status' => $sent ? 'sent' : 'failed',
            'error' => $error === null ? null : mb_substr($error, 0, 255),
            'sent_at' => $sent ? DateInput::toStorage($now) : null,
        ]);
    }

    public function counts(int $campaignId): array
    {
        $out = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->db->table('conf_campaign_recipients')->where('campaign_id', $campaignId)
            ->groupBy('status')->selectRaw('status, COUNT(*) as n')->get() as $r) {
            $out[(string) $r->status] = (int) $r->n;
        }
        return $out;
    }

    public function failed(int $campaignId, int $limit): array
    {
        return array_map(
            static fn (object $r): array => ['email' => (string) $r->email, 'name' => (string) ($r->name ?? ''), 'error' => $r->error === null ? null : (string) $r->error],
            $this->db->table('conf_campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'failed')->orderBy('id')->limit($limit)->get()->all(),
        );
    }

    public function ofEdition(int $contextId, int $editionId, int $limit): array
    {
        return array_map(self::campaign(...), $this->db->table('conf_campaigns')->where('context_id', $contextId)->where('edition_id', $editionId)
            ->orderByDesc('id')->limit($limit)->get()->all());
    }

    /** @return array<string, mixed> */
    private static function campaign(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'contextId' => (int) $r->context_id,
            'editionId' => (int) $r->edition_id,
            'createdBy' => $r->created_by === null ? null : (int) $r->created_by,
            'locale' => (string) $r->locale,
            'subject' => (string) $r->subject,
            'body' => (string) $r->body,
            'filter' => (array) (json_decode((string) $r->filter, true) ?: []),
            'total' => (int) $r->total,
            'createdAt' => DateInput::toIsoUtc(DateInput::fromStorage((string) $r->created_at)),
        ];
    }
}
