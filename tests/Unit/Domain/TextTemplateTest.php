<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\TextTemplate;
use PHPUnit\Framework\TestCase;

final class TextTemplateTest extends TestCase
{
    public function testRendersKnownAndKeepsUnknownVariables(): void
    {
        $t = new TextTemplate('Оргвнесок {invoiceNumber}, {payerName}; {nope}');
        self::assertSame('Оргвнесок CONF-2027-0001, Іваненко; {nope}', $t->render([
            'invoiceNumber' => 'CONF-2027-0001',
            'payerName' => 'Іваненко',
        ]));
        self::assertSame(['invoiceNumber', 'payerName', 'nope'], $t->variables());
        self::assertSame(['nope'], $t->unknownVariables(['invoiceNumber', 'payerName']));
    }
}
