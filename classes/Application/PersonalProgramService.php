<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramSession;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\SessionType;
use DateTimeImmutable;

/**
 * PRG-09: the person's own program. A signed-in user puts sessions of a
 * published program into it; sessions with their own talk or that they
 * chair are in it anyway.
 */
final class PersonalProgramService
{
    public const REASON_PICKED = 'picked';
    public const REASON_TALK = 'talk';
    public const REASON_CHAIR = 'chair';

    public function __construct(
        private readonly ProgramRepository $program,
        private readonly EditionRepository $editions,
    ) {
    }

    /** False when the session is not one of a published program of this context. */
    public function pick(ContextInfo $context, int $editionId, int $sessionId, int $userId, bool $on, DateTimeImmutable $now): bool
    {
        $edition = $this->editions->find($context->id, $editionId);
        if ($edition === null || !ProgramService::isPublic($edition) || $userId <= 0) {
            return false;
        }
        $session = $this->program->findSession($editionId, $sessionId);
        if ($session === null || $session->type === SessionType::Break) {
            return false;
        }
        return $this->program->pick($userId, $editionId, $sessionId, $on, $now);
    }

    /**
     * Sessions of the edition in the person's program with why they are there.
     *
     * @param list<int> $participantIds the person's participants (any edition)
     * @return array<int, list<string>> session id => reasons
     */
    public function mine(Edition $edition, int $userId, array $participantIds): array
    {
        return $this->reasons((int) $edition->id, $this->program->sessions((int) $edition->id), $userId, $participantIds);
    }

    /**
     * The cabinet: sessions of the person's program that have not ended,
     * grouped by edition.
     *
     * @param list<int> $participantIds
     * @param list<int> $editionIds editions the person takes part in
     * @return list<array{editionId: int, editionName: string, editionPath: string, timezone: string,
     *     sessions: list<array{id: int, type: string, title: string, startsAt: DateTimeImmutable, endsAt: DateTimeImmutable, reasons: list<string>}>}>
     */
    public function upcoming(ContextInfo $context, int $userId, array $participantIds, array $editionIds, string $locale, DateTimeImmutable $now): array
    {
        $ids = array_values(array_unique(array_merge($editionIds, array_values($this->program->pickedBy($userId)))));
        $out = [];
        foreach ($ids as $editionId) {
            $edition = $this->editions->find($context->id, $editionId);
            if ($edition === null || !ProgramService::isPublic($edition)) {
                continue;
            }
            $sessions = $this->program->sessions($editionId);
            $reasons = $this->reasons($editionId, $sessions, $userId, $participantIds);
            $list = [];
            foreach ($sessions as $s) {
                if ($s->id === null || !isset($reasons[$s->id]) || $s->endsAt <= $now) {
                    continue;
                }
                $list[] = [
                    'id' => $s->id,
                    'type' => $s->type->value,
                    'title' => (string) $s->text('title')->resolve($locale, $context->primaryLocale),
                    'startsAt' => $s->startsAt,
                    'endsAt' => $s->endsAt,
                    'reasons' => $reasons[$s->id],
                ];
            }
            if ($list !== []) {
                $out[] = [
                    'editionId' => $editionId,
                    'editionName' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale),
                    'editionPath' => $edition->path,
                    'timezone' => $edition->timezone,
                    'sessions' => $list,
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['sessions'][0]['startsAt'] <=> $b['sessions'][0]['startsAt']);
        return $out;
    }

    /**
     * @param list<ProgramSession> $sessions
     * @param list<int> $participantIds
     * @return array<int, list<string>>
     */
    private function reasons(int $editionId, array $sessions, int $userId, array $participantIds): array
    {
        $out = [];
        foreach ($this->program->pickedBy($userId) as $sessionId => $edition) {
            if ($edition === $editionId) {
                $out[$sessionId][] = self::REASON_PICKED;
            }
        }
        $mine = array_flip($participantIds);
        $papers = [];
        foreach ($sessions as $s) {
            foreach ($s->items as $item) {
                $papers[$item->submissionId] = (int) $s->id;
            }
            if ($s->id !== null && array_intersect_key(array_flip($s->chairs), $mine) !== []) {
                $out[$s->id][] = self::REASON_CHAIR;
            }
        }
        foreach ($this->program->presenters(array_keys($papers)) as $submissionId => $presenters) {
            if (array_intersect_key(array_flip($presenters), $mine) !== []) {
                $sessionId = $papers[$submissionId];
                if (!in_array(self::REASON_TALK, $out[$sessionId] ?? [], true)) {
                    $out[$sessionId][] = self::REASON_TALK;
                }
            }
        }
        return $out;
    }
}
