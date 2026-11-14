<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Shared;

/**
 * Collects validation errors as field => list of locale keys.
 * Keys are translated by the HTTP layer; the domain never translates.
 */
final class ValidationErrors
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function add(string $field, string $messageKey): void
    {
        $this->errors[$field][] = $messageKey;
    }

    public function merge(self $other, string $prefix = ''): void
    {
        foreach ($other->toArray() as $field => $keys) {
            foreach ($keys as $key) {
                $this->add($prefix . $field, $key);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    public function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->errors;
    }
}
