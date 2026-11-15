<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

/**
 * The consent text a person agrees to (REG-02, DAT-01). Its version is a
 * short hash of the notice in all its languages, so the version does not
 * depend on the interface language, and any change asks for a new consent.
 */
final class ConsentText
{
    public readonly string $version;

    public function __construct(public readonly string $text, ?string $versionSource = null)
    {
        $this->version = self::versionOf($versionSource ?? $text);
    }

    /**
     * @param array<string, string> $byLocale
     */
    public static function sourceOf(array $byLocale): string
    {
        ksort($byLocale);
        return (string) json_encode($byLocale, JSON_UNESCAPED_UNICODE);
    }

    public static function versionOf(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        return substr(hash('sha256', $normalized), 0, 16);
    }
}
