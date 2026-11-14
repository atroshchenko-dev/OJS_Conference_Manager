<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

use DateTimeImmutable;

/**
 * Whether submission and registration are open at a moment (EDN-03).
 * Deadlines are exclusive: at the deadline moment the window is closed.
 */
final class EditionWindows
{
    public static function submissionsOpenAt(Edition $edition, DateTimeImmutable $now): bool
    {
        if ($edition->submissionMode === SubmissionMode::None) {
            return false;
        }
        if ($edition->status !== EditionStatus::CfpOpen) {
            return false;
        }
        $opens = $edition->date('cfpOpensAt');
        $closes = $edition->date('abstractDeadline');
        return $opens !== null && $now >= $opens && ($closes === null || $now < $closes);
    }

    /** Full texts are accepted until the paper deadline (two-step mode only). */
    public static function papersOpenAt(Edition $edition, DateTimeImmutable $now): bool
    {
        if ($edition->submissionMode !== SubmissionMode::AbstractsAndPapers) {
            return false;
        }
        if (in_array($edition->status, [EditionStatus::Draft, EditionStatus::Published, EditionStatus::Archived], true)) {
            return false;
        }
        $closes = $edition->date('paperDeadline');
        return $closes === null || $now < $closes;
    }

    public static function registrationOpenAt(Edition $edition, DateTimeImmutable $now): bool
    {
        if (!in_array($edition->status, [EditionStatus::CfpOpen, EditionStatus::CfpClosed], true)) {
            return false;
        }
        $closes = $edition->date('registrationClosesAt') ?? $edition->date('endsAt');
        return $closes === null || $now < $closes;
    }
}
