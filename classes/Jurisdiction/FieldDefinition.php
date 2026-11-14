<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Jurisdiction;

/**
 * Describes an extra field a jurisdiction adds to a form (profile,
 * participant, invoice). The UI renders it, the profile validates it.
 */
final class FieldDefinition
{
    public const TYPE_TEXT = 'text';

    public function __construct(
        public readonly string $name,
        public readonly string $labelKey,
        public readonly string $type = self::TYPE_TEXT,
        public readonly bool $required = false,
        public readonly ?string $descriptionKey = null,
        public readonly int $maxLength = 255,
    ) {
    }
}
