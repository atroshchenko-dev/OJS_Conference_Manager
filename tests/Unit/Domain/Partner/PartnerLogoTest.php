<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Partner;

use APP\plugins\generic\conferenceManager\classes\Domain\Partner\PartnerLogo;
use APP\plugins\generic\conferenceManager\classes\Domain\Partner\PartnerRole;
use PHPUnit\Framework\TestCase;

final class PartnerLogoTest extends TestCase
{
    public function testImagesAreRecognisedByContent(): void
    {
        self::assertSame('png', PartnerLogo::extension("\x89PNG\r\n\x1a\n\0\0\0\rIHDR"));
        self::assertSame('jpg', PartnerLogo::extension("\xFF\xD8\xFF\xE0\0\x10JFIF"));
        self::assertSame('webp', PartnerLogo::extension('RIFF' . "\x24\0\0\0" . 'WEBPVP8 '));
    }

    public function testOtherFilesAreRefused(): void
    {
        self::assertNull(PartnerLogo::extension('<svg xmlns="http://www.w3.org/2000/svg">'));
        self::assertNull(PartnerLogo::extension('<?xml version="1.0"?><svg'));
        self::assertNull(PartnerLogo::extension('GIF89a'));
        self::assertNull(PartnerLogo::extension('%PDF-1.7'));
        self::assertNull(PartnerLogo::extension(''));
    }

    public function testRolesHaveLabelsAndCoOrganisersComeFirst(): void
    {
        self::assertSame(PartnerRole::CoOrganizer, PartnerRole::cases()[0]);
        foreach (PartnerRole::cases() as $role) {
            self::assertStringStartsWith('plugins.generic.conferenceManager.partner.role.', $role->labelKey());
        }
    }
}
