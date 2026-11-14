<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * What the application layer needs to know about an OJS context.
 * Filled by Core\ContextGateway, so services stay testable without OJS.
 */
final class ContextInfo
{
    /**
     * @param list<string> $formLocales
     * @param array<string, string> $name localized context name
     */
    public function __construct(
        public readonly int $id,
        public readonly string $primaryLocale,
        public readonly array $formLocales,
        public readonly array $name = [],
        public readonly string $contactEmail = '',
        public readonly string $serverTimezone = 'UTC',
    ) {
    }
}
