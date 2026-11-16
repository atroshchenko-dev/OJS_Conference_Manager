<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;

/**
 * PAY-09: LiqPay (PrivatBank), API version 3. The checkout form and the
 * callback carry `data` (base64 of JSON) and `signature`
 * = base64(sha1(private_key + data + private_key)), as in LiqPay's PHP SDK.
 */
final class LiqPayGateway implements PaymentGateway
{
    public const ID = 'liqpay';
    public const CHECKOUT_URL = 'https://www.liqpay.ua/api/3/checkout';
    public const API_URL = 'https://www.liqpay.ua/api/request';

    /** LiqPay statuses that end an attempt; the rest are still in progress. */
    private const FINAL = [
        'success' => GatewayStatus::Success,
        'failure' => GatewayStatus::Failure,
        'error' => GatewayStatus::Failure,
        'try_again' => GatewayStatus::Failure,
        'reversed' => GatewayStatus::Reversed,
    ];

    /** Fields of a report kept in the payment journal; no card data. */
    private const KEPT = ['status', 'payment_id', 'paytype', 'err_code', 'err_description', 'liqpay_order_id', 'end_date', 'is_3ds'];

    public function id(): string
    {
        return self::ID;
    }

    public function name(): string
    {
        return 'LiqPay';
    }

    public function fields(): array
    {
        return [
            ['name' => 'publicKey', 'label' => 'plugins.generic.conferenceManager.gateway.liqpay.publicKey', 'secret' => false],
            ['name' => 'privateKey', 'label' => 'plugins.generic.conferenceManager.gateway.liqpay.privateKey', 'secret' => true],
        ];
    }

    public function currencies(): array
    {
        return ['UAH', 'USD', 'EUR'];
    }

    public static function signature(string $privateKey, string $data): string
    {
        return base64_encode(sha1($privateKey . $data . $privateKey, true));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: string, signature: string}
     */
    public static function sign(string $privateKey, array $params): array
    {
        $json = (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // The amount goes as a JSON number written from the decimal string, never through a float.
        if (isset($params['amount']) && is_string($params['amount']) && preg_match('/^\d+(\.\d+)?$/', $params['amount'])) {
            $json = str_replace('"amount":"' . $params['amount'] . '"', '"amount":' . $params['amount'], $json);
        }
        $data = base64_encode($json);
        return ['data' => $data, 'signature' => self::signature($privateKey, $data)];
    }

    public function checkout(GatewayConfig $config, CheckoutOrder $order): CheckoutForm
    {
        $params = [
            'version' => 3,
            'public_key' => $config->value('publicKey'),
            'action' => 'pay',
            'amount' => $order->amount->decimal(),
            'currency' => $order->amount->currency,
            'description' => mb_substr($order->description, 0, 500),
            'order_id' => $order->orderId,
            'language' => $order->language === 'en' ? 'en' : 'uk',
            'result_url' => $order->returnUrl,
            'server_url' => $order->callbackUrl,
        ];
        if ($config->sandbox) {
            $params['sandbox'] = '1';
        }
        return new CheckoutForm(self::CHECKOUT_URL, self::sign($config->value('privateKey'), $params));
    }

    public function callback(GatewayConfig $config, array $post, string $body): ?GatewayResult
    {
        $data = is_string($post['data'] ?? null) ? $post['data'] : '';
        $signature = is_string($post['signature'] ?? null) ? $post['signature'] : '';
        $key = $config->value('privateKey');
        if ($data === '' || $key === '' || !hash_equals(self::signature($key, $data), $signature)) {
            return null;
        }
        $json = (string) base64_decode($data, true);
        $report = json_decode($json, true);
        if (!is_array($report) || ($report['public_key'] ?? null) !== $config->value('publicKey')) {
            return null;
        }
        return $this->result($config, $report);
    }

    public function status(GatewayConfig $config, string $orderId, GatewayHttp $http): ?GatewayResult
    {
        $form = self::sign($config->value('privateKey'), [
            'action' => 'status',
            'version' => 3,
            'public_key' => $config->value('publicKey'),
            'order_id' => $orderId,
        ]);
        $json = (string) $http->postForm(self::API_URL, $form);
        $answer = json_decode($json, true);
        if (!is_array($answer) || ($answer['result'] ?? '') === 'error' || !isset($answer['status'])) {
            return null;
        }
        $answer['order_id'] ??= $orderId;
        return $this->result($config, $answer);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function result(GatewayConfig $config, array $report): ?GatewayResult
    {
        $orderId = (string) ($report['order_id'] ?? '');
        $currency = strtoupper((string) ($report['currency'] ?? ''));
        if ($orderId === '' || !Money::isCurrency($currency)) {
            return null;
        }
        $raw = (string) ($report['status'] ?? '');
        // A test payment counts only for a journal in sandbox mode.
        $status = $raw === 'sandbox'
            ? ($config->sandbox ? GatewayStatus::Success : GatewayStatus::Failure)
            : (self::FINAL[$raw] ?? GatewayStatus::Pending);
        // A JSON number is only formatted (six places, then trailing zeros cut), never computed with.
        $value = $report['amount'] ?? null;
        $text = match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_numeric($value) => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.'),
            default => '',
        };
        $amount = preg_match('/^\d+(\.\d+)?$/', $text) ? Money::parse($text, $currency) : null;
        $details = array_intersect_key($report, array_flip(self::KEPT));
        return new GatewayResult(
            $orderId,
            $status,
            $amount === null ? 0 : $amount->minor,
            $currency,
            isset($report['payment_id']) ? (string) $report['payment_id'] : null,
            $details,
        );
    }
}
