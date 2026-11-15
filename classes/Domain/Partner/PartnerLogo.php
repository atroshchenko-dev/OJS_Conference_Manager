<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Partner;

/**
 * INS-04: a logo is a PNG, JPEG or WebP image of at most 1 MB, recognised
 * by its content, not by its name. SVG is refused: it can carry scripts.
 */
final class PartnerLogo
{
    public const MAX_BYTES = 1048576;

    /** The file extension for the image, or null when it is not one we accept. */
    public static function extension(string $head): ?string
    {
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return null;
    }
}
