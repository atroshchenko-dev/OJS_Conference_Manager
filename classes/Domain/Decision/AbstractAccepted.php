<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Decision;

/**
 * DEC-07: in the two-phase mode, the decision after the abstract round:
 * "abstract accepted, send the full text". The submission stays in review,
 * the author uploads the paper by the paper deadline of the edition, and
 * the editor opens the next round for the paper.
 */
final class AbstractAccepted
{
    public const DECISION = 905;
    public const EMAIL_KEY = 'CONF_ABSTRACT_ACCEPTED';
}
