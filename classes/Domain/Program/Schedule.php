<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Program;

/**
 * PRG-03: checks of a whole program: a person in two sessions at the same
 * time (presenting or chairing) and two sessions in one room at once.
 */
final class Schedule
{
    /**
     * @param list<ProgramSession> $sessions
     * @param array<int, list<int>> $presenters submission id => participant ids
     * @return list<array{participantId: int, sessionIds: list<int>}>
     */
    public static function personConflicts(array $sessions, array $presenters): array
    {
        $busy = [];
        foreach ($sessions as $s) {
            $people = $s->chairs;
            foreach ($s->items as $item) {
                array_push($people, ...($presenters[$item->submissionId] ?? []));
            }
            foreach (array_unique($people) as $p) {
                $busy[$p][] = $s;
            }
        }
        $out = [];
        foreach ($busy as $person => $list) {
            $ids = [];
            foreach ($list as $i => $a) {
                foreach (array_slice($list, $i + 1) as $b) {
                    if ($a->overlaps($b)) {
                        $ids[(int) $a->id] = true;
                        $ids[(int) $b->id] = true;
                    }
                }
            }
            if ($ids !== []) {
                $out[] = ['participantId' => (int) $person, 'sessionIds' => array_keys($ids)];
            }
        }
        return $out;
    }

    /**
     * @param list<ProgramSession> $sessions
     * @return list<int> ids of the other sessions in the same room at the same time
     */
    public static function roomClashes(ProgramSession $session, array $sessions): array
    {
        if ($session->roomId === null) {
            return [];
        }
        $out = [];
        foreach ($sessions as $other) {
            if ($other->id !== $session->id && $other->roomId === $session->roomId && $other->overlaps($session)) {
                $out[] = (int) $other->id;
            }
        }
        return $out;
    }
}
