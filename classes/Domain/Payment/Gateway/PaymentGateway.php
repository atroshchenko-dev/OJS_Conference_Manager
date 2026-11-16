<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

/**
 * PAY-09: one card payment service. Built-in: LiqPay. Others come from
 * plugins through the ConferenceManager::paymentGateways hook.
 */
interface PaymentGateway
{
    /** Short id: 'liqpay', 'monobank'… */
    public function id(): string;

    /** Name shown to managers and participants. */
    public function name(): string;

    /**
     * Settings of the merchant account.
     *
     * @return list<array{name: string, label: string, secret: bool}>
     */
    public function fields(): array;

    /**
     * Currencies the gateway accepts (ISO 4217).
     *
     * @return list<string>
     */
    public function currencies(): array;

    public function checkout(GatewayConfig $config, CheckoutOrder $order): CheckoutForm;

    /**
     * A report posted by the gateway to the callback address; null when it
     * is not signed with the journal's key.
     *
     * @param array<string, mixed> $post
     */
    public function callback(GatewayConfig $config, array $post, string $body): ?GatewayResult;

    /** The state of an attempt asked from the gateway; null when it cannot be told. */
    public function status(GatewayConfig $config, string $orderId, GatewayHttp $http): ?GatewayResult;
}
