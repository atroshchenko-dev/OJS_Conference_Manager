<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Decision;

/**
 * A decision the manager may record for many abstracts at once (DEC-03,
 * DEC-04): one of 901–904 or the core "decline" in the submission stage.
 */
final class BulkDecision
{
    /** OJS Decision::INITIAL_DECLINE. */
    public const DECLINE = 8;

    /** At most this many submissions per run. */
    public const MAX_ITEMS = 200;

    /** @return list<int> */
    public static function codes(): array
    {
        return [...array_map(static fn (AcceptanceDecision $d): int => $d->value, AcceptanceDecision::cases()), self::DECLINE];
    }

    public static function isAllowed(int $code): bool
    {
        return in_array($code, self::codes(), true);
    }
}
