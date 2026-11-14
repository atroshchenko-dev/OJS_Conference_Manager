<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Shared;

/**
 * Minimal "{variable}" template used for payment purposes, numbers etc.
 * Unknown variables are reported by validate() and left as-is by render().
 */
final class TextTemplate
{
    public function __construct(private readonly string $template)
    {
    }

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $this->template, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * @param list<string> $allowed
     * @return list<string> unknown variable names
     */
    public function unknownVariables(array $allowed): array
    {
        return array_values(array_diff($this->variables(), $allowed));
    }

    /**
     * @param array<string, string|int> $values
     */
    public function render(array $values): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0],
            $this->template,
        );
    }

    public function __toString(): string
    {
        return $this->template;
    }
}
