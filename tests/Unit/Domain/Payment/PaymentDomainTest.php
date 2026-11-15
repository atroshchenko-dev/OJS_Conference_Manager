<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\AmountInWords;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceDocument;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceNumber;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentReference;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRule;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use PHPUnit\Framework\TestCase;

/**
 * Amount in words, payment references, numbers, rule choice and the
 * invoice document (FEE-05, FEE-06, PAY-01, PAY-07).
 */
final class PaymentDomainTest extends TestCase
{
    /** @return iterable<string, array{int, string, string, string}> */
    public static function amounts(): iterable
    {
        yield 'hundred fifty' => [15000, 'UAH', 'uk', "Сто п'ятдесят гривень 00 копійок"];
        yield 'one, feminine' => [121101, 'UAH', 'uk', 'Одна тисяча двісті одинадцять гривень 01 копійка'];
        yield 'two, few' => [202, 'UAH', 'uk', 'Дві гривні 02 копійки'];
        yield 'teens' => [1112, 'UAH', 'uk', 'Одинадцять гривень 12 копійок'];
        yield 'zero' => [50, 'UAH', 'uk', 'Нуль гривень 50 копійок'];
        yield 'million' => [200000000, 'UAH', 'uk', 'Два мільйони гривень 00 копійок'];
        yield 'thousands few' => [2300000, 'UAH', 'uk', 'Двадцять три тисячі гривень 00 копійок'];
        yield 'dollar masculine' => [100, 'USD', 'uk', 'Один долар США 00 центів'];
        yield 'euro neuter' => [2001, 'EUR', 'uk', 'Двадцять євро 01 євроцент'];
        yield 'english' => [123456, 'UAH', 'en', 'One thousand two hundred thirty-four hryvnias 56 kopiykas'];
        yield 'english one' => [101, 'USD', 'en', 'One US dollar 01 cent'];
        yield 'no minor unit' => [5000, 'JPY', 'uk', "П'ять тисяч JPY"];
    }

    /** @dataProvider amounts */
    public function testAmountInWords(int $minor, string $currency, string $locale, string $expected): void
    {
        self::assertSame($expected, AmountInWords::format(new Money($minor, $currency), $locale));
    }

    public function testPaymentReference(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $code = PaymentReference::generate();
            self::assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{8}$/', $code);
            self::assertTrue(PaymentReference::isValid($code));
            $seen[$code] = true;
        }
        self::assertGreaterThan(495, count($seen));
        $code = PaymentReference::generate();
        $typo = substr($code, 0, 3) . ($code[3] === '2' ? '3' : '2') . substr($code, 4);
        self::assertFalse(PaymentReference::isValid($typo), 'one wrong character is caught');
        self::assertFalse(PaymentReference::isValid('ABC'));
    }

    public function testInvoiceNumber(): void
    {
        self::assertSame('2027-00042', InvoiceNumber::format(2027, 42));
    }

    public function testRuleChoice(): void
    {
        $text = new LocalizedText([]);
        $default = new PaymentRule(1, 1, null, PaymentMode::BankTransfer, 5, null, $text);
        $students = new PaymentRule(2, 1, 7, PaymentMode::Free, null, null, $text);
        self::assertSame($students, PaymentRule::pick([$default, $students], 7));
        self::assertSame($default, PaymentRule::pick([$default, $students], 8));
        self::assertSame($default, PaymentRule::pick([$default, $students], null));
        self::assertNull(PaymentRule::pick([$students], 8));
        self::assertNull(PaymentRule::pick([], 7));
    }

    public function testDocumentEscapesAndValidates(): void
    {
        $html = '<h1>{invoiceNumber}</h1><p>{payerName}</p><p>{total} {currency}</p>{itemsTable}{accountFieldsRows}<p>{iban}</p><p>{instructions}</p>';
        $out = InvoiceDocument::render($html, [
            'number' => '2027-00001',
            'payer' => ['name' => '<b>Олена</b>'],
            'total' => ['decimal' => '150.50', 'currency' => 'UAH'],
            'items' => [['description' => 'Участь & внесок', 'quantity' => 1, 'unit' => ['decimal' => '150.50'], 'amount' => ['decimal' => '150.50']]],
            'recipient' => ['account' => ['iban' => 'UA213223130000026007233566001'], 'accountFields' => [['label' => 'Код', 'value' => '<x>']]],
            'instructions' => "Рядок 1\nРядок 2",
        ], ['no' => '№', 'description' => 'Опис', 'quantity' => 'К-сть', 'price' => 'Ціна', 'amount' => 'Сума', 'total' => 'Разом'], ',');
        self::assertStringContainsString('&lt;b&gt;Олена&lt;/b&gt;', $out);
        self::assertStringContainsString('150,50 UAH', $out);
        self::assertStringContainsString('Участь &amp; внесок', $out);
        self::assertStringContainsString('<td>&lt;x&gt;</td>', $out);
        self::assertStringContainsString('UA21 3223 1300 0002 6007 2335 6600 1', $out);
        self::assertStringContainsString("Рядок 1<br />\nРядок 2", $out);

        $rows = InvoiceDocument::render('<table><tr><td class="l">Банк</td><td>{bankName}</td></tr><tr><td class="l">IBAN</td><td>{iban}</td></tr></table>', [
            'recipient' => ['account' => ['iban' => 'UA11']],
        ], ['no' => '', 'description' => '', 'quantity' => '', 'price' => '', 'amount' => '', 'total' => '']);
        self::assertSame('<table><tr><td class="l">IBAN</td><td>UA11</td></tr></table>', $rows, 'empty requisites are not printed');

        self::assertTrue(InvoiceDocument::validate('<p>{secret}</p>')->has('html'));
        self::assertTrue(InvoiceDocument::validate(str_repeat('x', InvoiceDocument::MAX_LENGTH + 1))->has('html'));
        self::assertTrue(InvoiceDocument::validate('<style>td { border: 0 }</style><p>{iban}</p>')->isEmpty());
        foreach (InvoiceDocument::KINDS as $kind) {
            foreach (['uk', 'en'] as $lang) {
                $file = dirname(__DIR__, 4) . "/templates/invoice/$kind.$lang.html";
                self::assertTrue(InvoiceDocument::validate((string) file_get_contents($file))->isEmpty(), "$kind.$lang");
            }
        }
        self::assertSame(InvoiceDocument::KIND_TREASURY, InvoiceDocument::kindFor(PaymentMode::TreasuryTransfer));
        self::assertNull(InvoiceDocument::kindFor(PaymentMode::ExternalLink));
    }
}
