<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

/**
 * Rules for a receipt file (FEE-09): PDF, JPEG or PNG up to 5 MB. The type
 * comes from the first bytes of the file, never from its name or from what
 * the browser says.
 */
final class ProofFile
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> mime => extension */
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /** The type by content, or null when it is not an allowed one. */
    public static function detectMime(string $head): ?string
    {
        if (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        return null;
    }

    public static function extension(string $mime): string
    {
        return self::TYPES[$mime] ?? 'bin';
    }

    /**
     * A name safe to show and to send in a header: no path, no control
     * characters or quotes, at most 120 characters, with the right extension.
     */
    public static function displayName(string $original, string $mime): string
    {
        $base = basename(str_replace('\\', '/', $original));
        $base = (string) preg_replace('/[\x00-\x1F\x7F"\\\\\/;]+/u', '', $base);
        $base = (string) preg_replace('/\.[^.]*$/', '', $base);
        $base = trim(mb_substr($base, 0, 120));
        return ($base === '' ? 'receipt' : $base) . '.' . self::extension($mime);
    }
}
