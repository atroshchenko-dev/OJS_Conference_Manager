<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Shared;

/**
 * Immutable map locale => text. Empty strings are dropped.
 */
final class LocalizedText
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<array-key, mixed> $values untrusted input; non-string keys are ignored
     */
    public function __construct(array $values = [])
    {
        $clean = [];
        foreach ($values as $locale => $text) {
            if (!is_string($locale) || !is_scalar($text)) {
                continue;
            }
            $text = trim((string) $text);
            if ($text !== '') {
                $clean[$locale] = $text;
            }
        }
        ksort($clean);
        $this->values = $clean;
    }

    public function get(string $locale): ?string
    {
        return $this->values[$locale] ?? null;
    }

    /**
     * Text in the requested locale, then in the fallback locale, then any.
     */
    public function resolve(string $locale, ?string $fallbackLocale = null): ?string
    {
        return $this->values[$locale]
            ?? ($fallbackLocale !== null ? ($this->values[$fallbackLocale] ?? null) : null)
            ?? ($this->values === [] ? null : reset($this->values));
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return array_keys($this->values);
    }
}
