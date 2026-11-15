<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Jurisdiction;

use APP\plugins\generic\conferenceManager\classes\Jurisdiction\Generic\GenericProfile;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA\UaProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JurisdictionRegistryTest extends TestCase
{
    public function testBuiltInsAndLocaleDefaults(): void
    {
        $r = JurisdictionRegistry::withBuiltIns();
        self::assertSame(['generic', 'UA'], array_keys($r->all()));
        self::assertSame('UA', $r->defaultCodeForLocale('uk'));
        self::assertSame('UA', $r->defaultCodeForLocale('uk_UA'));
        self::assertSame('generic', $r->defaultCodeForLocale('en'));
        self::assertSame('generic', $r->defaultCodeForLocale('pl'));
    }

    public function testThirdPartyProfileCanBeRegistered(): void
    {
        $r = new JurisdictionRegistry();
        $r->register(new GenericProfile());
        self::assertFalse($r->has('UA'));
        self::assertSame('generic', $r->defaultCodeForLocale('uk'));
        $r->register(new UaProfile());
        self::assertSame('UA', $r->defaultCodeForLocale('uk'));
    }

    public function testUnknownCodeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JurisdictionRegistry::withBuiltIns()->get('XX');
    }

    public function testUaFeaturesAreNotInGeneric(): void
    {
        self::assertContains('vector', (new UaProfile())->getFeatures());
        self::assertSame([], (new GenericProfile())->getFeatures());
        self::assertSame([], (new GenericProfile())->getInstitutionExtraFields());
    }

    /** JUR-06: sample texts for the institution's notices. */
    public function testSampleTexts(): void
    {
        $ua = new UaProfile();
        $uk = (string) $ua->getSampleText('privacyNotice', 'uk_UA');
        self::assertStringContainsString('2297-VI', $uk);
        self::assertStringNotContainsString('<script', $uk);
        self::assertStringContainsString('2297-VI', (string) $ua->getSampleText('privacyNotice', 'en'));
        self::assertNotSame('', trim((string) $ua->getSampleText('retentionNotice', 'uk')));
        self::assertSame($ua->getSampleText('retentionNotice', 'en'), $ua->getSampleText('retentionNotice', 'pl'));
        self::assertNull($ua->getSampleText('../../etc/passwd', 'uk'));
        self::assertNull((new GenericProfile())->getSampleText('privacyNotice', 'uk'));
    }
}
