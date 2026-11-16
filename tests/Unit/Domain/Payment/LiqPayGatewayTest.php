<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\CheckoutOrder;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayConfig;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayHttp;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\LiqPayGateway;
use PHPUnit\Framework\TestCase;

/** PAY-09: the LiqPay adapter without the network. */
final class LiqPayGatewayTest extends TestCase
{
    private const PUBLIC = 'sandbox_i00000000';
    private const PRIVATE = 'sandbox_secret';

    private function config(bool $sandbox = true): GatewayConfig
    {
        return new GatewayConfig('liqpay', $sandbox, ['publicKey' => self::PUBLIC, 'privateKey' => self::PRIVATE]);
    }

    /** @param array<string, mixed> $report */
    private static function post(array $report, string $key = self::PRIVATE): array
    {
        $data = base64_encode((string) json_encode($report));
        return ['data' => $data, 'signature' => base64_encode(sha1($key . $data . $key, true))];
    }

    public function testCheckoutForm(): void
    {
        $g = new LiqPayGateway();
        $form = $g->checkout($this->config(), new CheckoutOrder('1-2027-00001-1', new Money(15075, 'UAH'), 'Оргвнесок, рахунок № 2027-00001', 'https://j.example/r', 'https://j.example/cb', 'uk'));
        self::assertSame('https://www.liqpay.ua/api/3/checkout', $form->action);
        $json = (string) base64_decode($form->fields['data'], true);
        self::assertStringContainsString('"amount":150.75,', $json, 'a JSON number written from the decimal string');
        self::assertSame([
            'version' => 3, 'public_key' => self::PUBLIC, 'action' => 'pay', 'amount' => 150.75, 'currency' => 'UAH',
            'description' => 'Оргвнесок, рахунок № 2027-00001', 'order_id' => '1-2027-00001-1', 'language' => 'uk',
            'result_url' => 'https://j.example/r', 'server_url' => 'https://j.example/cb', 'sandbox' => '1',
        ], json_decode($json, true));
        self::assertSame(base64_encode(sha1(self::PRIVATE . $form->fields['data'] . self::PRIVATE, true)), $form->fields['signature']);
        $live = json_decode((string) base64_decode($g->checkout($this->config(false), new CheckoutOrder('x', new Money(100, 'EUR'), 'd', 'r', 'c', 'en'))->fields['data'], true), true);
        self::assertArrayNotHasKey('sandbox', $live);
        self::assertSame(['en', 1.0, 'EUR'], [$live['language'], $live['amount'], $live['currency']]);
    }

    public function testCallback(): void
    {
        $g = new LiqPayGateway();
        $report = ['public_key' => self::PUBLIC, 'order_id' => '1-2027-00001-1', 'status' => 'success', 'amount' => 150.75, 'currency' => 'UAH', 'payment_id' => 98765, 'paytype' => 'card', 'sender_card_mask2' => '4242*42'];
        $r = $g->callback($this->config(), self::post($report), '');
        self::assertNotNull($r);
        self::assertSame(['1-2027-00001-1', GatewayStatus::Success, 15075, 'UAH', '98765'], [$r->orderId, $r->status, $r->amountMinor, $r->currency, $r->transactionId]);
        self::assertSame(['status' => 'success', 'payment_id' => 98765, 'paytype' => 'card'], $r->details, 'no card data kept');

        // Not signed with the journal's key, tampered, another merchant: refused.
        self::assertNull($g->callback($this->config(), self::post($report, 'other'), ''));
        $tampered = self::post($report);
        $tampered['data'] = base64_encode((string) json_encode(['amount' => 1] + $report));
        self::assertNull($g->callback($this->config(), $tampered, ''));
        self::assertNull($g->callback($this->config(), self::post(['public_key' => 'someone'] + $report), ''));
        self::assertNull($g->callback($this->config(), [], ''));
        self::assertNull($g->callback(new GatewayConfig('liqpay', true, ['publicKey' => self::PUBLIC, 'privateKey' => '']), self::post($report, ''), ''));

        // Statuses.
        $status = fn (string $s, bool $sandbox = true): ?GatewayStatus => $g->callback($this->config($sandbox), self::post(['status' => $s] + $report), '')?->status;
        self::assertSame(GatewayStatus::Success, $status('sandbox'));
        self::assertSame(GatewayStatus::Failure, $status('sandbox', false), 'a test payment does not count in live mode');
        self::assertSame(GatewayStatus::Failure, $status('failure'));
        self::assertSame(GatewayStatus::Failure, $status('error'));
        self::assertSame(GatewayStatus::Reversed, $status('reversed'));
        self::assertSame(GatewayStatus::Pending, $status('processing'));
        self::assertSame(GatewayStatus::Pending, $status('wait_accept'));
    }

    public function testStatusRequest(): void
    {
        $http = new class () implements GatewayHttp {
            public ?string $answer = null;
            /** @var list<array{0: string, 1: array<string, string>}> */
            public array $calls = [];

            public function postForm(string $url, array $form): ?string
            {
                $this->calls[] = [$url, $form];
                return $this->answer;
            }
        };
        $g = new LiqPayGateway();
        $http->answer = '{"result":"ok","status":"success","order_id":"o-1","amount":200,"currency":"UAH","payment_id":5}';
        $r = $g->status($this->config(), 'o-1', $http);
        self::assertSame([GatewayStatus::Success, 20000, '5'], [$r?->status, $r?->amountMinor, $r?->transactionId]);
        [$url, $form] = $http->calls[0];
        self::assertSame('https://www.liqpay.ua/api/request', $url);
        self::assertSame(['action' => 'status', 'version' => 3, 'public_key' => self::PUBLIC, 'order_id' => 'o-1'], json_decode((string) base64_decode($form['data'], true), true));
        self::assertSame(base64_encode(sha1(self::PRIVATE . $form['data'] . self::PRIVATE, true)), $form['signature']);

        $http->answer = '{"result":"error","err_code":"payment_not_found"}';
        self::assertNull($g->status($this->config(), 'o-2', $http));
        $http->answer = null;
        self::assertNull($g->status($this->config(), 'o-3', $http));
    }

    public function testStatusTransitions(): void
    {
        self::assertTrue(GatewayStatus::Created->canBecome(GatewayStatus::Success));
        self::assertTrue(GatewayStatus::Pending->canBecome(GatewayStatus::Failure));
        self::assertTrue(GatewayStatus::Failure->canBecome(GatewayStatus::Success));
        self::assertFalse(GatewayStatus::Success->canBecome(GatewayStatus::Failure), 'a stale failure after success');
        self::assertFalse(GatewayStatus::Success->canBecome(GatewayStatus::Success), 'a repeated report');
        self::assertTrue(GatewayStatus::Success->canBecome(GatewayStatus::Reversed));
        self::assertFalse(GatewayStatus::Reversed->canBecome(GatewayStatus::Success));
    }
}
