<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Program;

/** PRG-02: kinds of program sessions. A break has no papers. */
enum SessionType: string
{
    case Plenary = 'plenary';
    case Section = 'section';
    case Poster = 'poster';
    case Seminar = 'seminar';
    case Break = 'break';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.program.session.' . $this->value;
    }

    public function hasPapers(): bool
    {
        return $this !== self::Break;
    }
}
