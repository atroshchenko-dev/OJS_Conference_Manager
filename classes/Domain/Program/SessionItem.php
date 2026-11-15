<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Program;

/** PRG-02: a paper in a session; null duration takes the slot of the session. */
final class SessionItem
{
    public function __construct(
        public readonly int $submissionId,
        public readonly int $seq,
        public readonly ?int $durationMin = null,
    ) {
    }
}
