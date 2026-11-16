<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

/**
 * PAY-09: state of one card payment attempt, as the gateway reports it.
 * Success is final for the invoice; a later report never undoes it, only
 * Reversed is recorded for the manager.
 */
enum GatewayStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Success = 'success';
    case Failure = 'failure';
    case Reversed = 'reversed';

    /** Still worth asking the gateway about. */
    public function isOpen(): bool
    {
        return $this === self::Created || $this === self::Pending;
    }

    /**
     * Whether a report with this status may replace the stored one:
     * stale or repeated reports are ignored.
     */
    public function canBecome(self $next): bool
    {
        return match ($this) {
            self::Created, self::Pending => $next !== self::Created,
            self::Failure => $next === self::Success || $next === self::Pending,
            self::Success => $next === self::Reversed,
            self::Reversed => false,
        };
    }
}
