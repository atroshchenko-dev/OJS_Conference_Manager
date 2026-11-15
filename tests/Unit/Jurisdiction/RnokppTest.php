<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Jurisdiction;

use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PayerDetails;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\Generic\GenericProfile;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA\Rnokpp;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA\UaProfile;
use PHPUnit\Framework\TestCase;

final class RnokppTest extends TestCase
{
    public function testCheckDigit(): void
    {
        foreach (['000000000', '123456789', '987654321', '311111111'] as $nine) {
            $code = $nine . Rnokpp::checkDigit($nine);
            self::assertTrue(Rnokpp::isValid($code), $code);
            self::assertFalse(Rnokpp::isValid($nine . ((Rnokpp::checkDigit($nine) + 1) % 10)), $code . ' with a wrong digit');
        }
        self::assertFalse(Rnokpp::isValid('12345'));
        self::assertFalse(Rnokpp::isValid('12345678901'));
        self::assertFalse(Rnokpp::isValid('12345678a0'));
    }

    public function testPayerDetails(): void
    {
        $ua = new UaProfile();
        [$d, $e] = PayerDetails::fromInput(['kind' => 'organization', 'organization' => 'Школа', 'legalId' => '02070944'], $ua, false);
        self::assertTrue($e->isEmpty());
        self::assertTrue($d?->isOrganization());
        [$d, $e] = PayerDetails::fromInput(['kind' => 'organization', 'organization' => '', 'legalId' => ''], $ua, false);
        self::assertNull($d);
        self::assertTrue($e->has('organization') && $e->has('legalId'));
        [, $e] = PayerDetails::fromInput(['kind' => 'company'], $ua, false);
        self::assertTrue($e->has('kind'));
        [$d] = PayerDetails::fromInput(['kind' => 'person', 'taxId' => 'x'], $ua, false);
        self::assertSame('', $d?->taxId, 'not asked: not stored');
        // Another jurisdiction has its own rules for the codes.
        [$d, $e] = PayerDetails::fromInput(['kind' => 'person', 'taxId' => 'AB-1234'], new GenericProfile(), true);
        self::assertTrue($e->isEmpty());
        self::assertSame('AB-1234', $d?->taxId);
        self::assertSame(['kind' => 'person', 'organization' => '', 'legalId' => '', 'taxId' => 'AB-1234'], PayerDetails::fromArray($d?->toArray() ?? [])->toArray());
    }
}
