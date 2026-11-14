<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

/** Lifecycle of an edition (plan 8.5). */
enum EditionStatus: string
{
    case Draft = 'draft';
    case CfpOpen = 'cfp_open';
    case CfpClosed = 'cfp_closed';
    case Held = 'held';
    case Published = 'published';
    case Archived = 'archived';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::CfpOpen, self::CfpClosed, self::Held],
            self::CfpOpen => [self::CfpClosed, self::Draft],
            self::CfpClosed => [self::CfpOpen, self::Held],
            self::Held => [self::Published, self::Archived],
            self::Published => [self::Archived],
            self::Archived => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return $next === $this || in_array($next, $this->allowedNext(), true);
    }

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.edition.status.' . $this->value;
    }
}
