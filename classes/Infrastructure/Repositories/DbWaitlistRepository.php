<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\WaitlistRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/** REG-12 on conf_editions.listener_seats and conf_participations.waitlisted_at (Step0016). */
final class DbWaitlistRepository implements WaitlistRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function seats(int $editionId): ?int
    {
        $v = $this->db->table('conf_editions')->where('id', $editionId)->value('listener_seats');
        return $v === null ? null : (int) $v;
    }

    public function setSeats(int $editionId, ?int $seats): void
    {
        $this->db->table('conf_editions')->where('id', $editionId)->update(['listener_seats' => $seats]);
    }

    public function taken(int $editionId): int
    {
        return DbParticipantRepository::inPersonListeners($this->db, $editionId);
    }

    public function queue(int $editionId, string $locale): array
    {
        $rows = $this->waiting($editionId)->select('pn.id', 'pn.participant_id', 'p.email', 'pn.waitlisted_at', 'pn.created_at')->get()->all();
        if ($rows === []) {
            return [];
        }
        $names = [];
        foreach ($this->db->table('conf_participant_settings')
            ->whereIn('participant_id', array_map(static fn (object $r): int => (int) $r->participant_id, $rows))
            ->whereIn('setting_name', ['givenName', 'familyName'])->get() as $s) {
            $names[(int) $s->participant_id][(string) $s->setting_name][(string) $s->locale] = (string) $s->setting_value;
        }
        $pick = static fn (array $values): string => (string) ($values[$locale] ?? (array_values($values)[0] ?? ''));
        return array_map(static fn (object $r): array => [
            'participationId' => (int) $r->id,
            'participantId' => (int) $r->participant_id,
            'email' => (string) $r->email,
            'givenName' => $pick($names[(int) $r->participant_id]['givenName'] ?? []),
            'familyName' => $pick($names[(int) $r->participant_id]['familyName'] ?? []),
            'waitlistedAt' => (string) ($r->waitlisted_at ?? $r->created_at),
        ], $rows);
    }

    public function promoteNext(int $editionId, DateTimeImmutable $now): ?int
    {
        return $this->db->transaction(function () use ($editionId, $now): ?int {
            $edition = $this->db->table('conf_editions')->where('id', $editionId)->lockForUpdate()->first();
            if ($edition === null || ($edition->listener_seats !== null && $this->taken($editionId) >= (int) $edition->listener_seats)) {
                return null;
            }
            $next = $this->waiting($editionId)->select('pn.id')->first();
            if ($next === null) {
                return null;
            }
            $this->db->table('conf_participations')->where('id', (int) $next->id)->where('status', ParticipationStatus::Waitlisted->value)
                ->update(['status' => ParticipationStatus::Active->value, 'updated_at' => DateInput::toStorage($now)]);
            return (int) $next->id;
        });
    }

    private function waiting(int $editionId): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('conf_participations as pn')
            ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('p.edition_id', $editionId)
            ->where('pn.role', ParticipantRole::Listener->value)
            ->where('pn.status', ParticipationStatus::Waitlisted->value)
            ->orderBy('pn.waitlisted_at')->orderBy('pn.id');
    }
}
