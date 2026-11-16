<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

use DateTimeImmutable;

interface GatewayPaymentRepository
{
    public function add(GatewayPayment $payment, DateTimeImmutable $now): GatewayPayment;

    /** The attempt of the journal with this order id at this gateway. */
    public function findByOrder(int $contextId, string $gateway, string $orderId): ?GatewayPayment;

    /** @return list<GatewayPayment> newest first */
    public function ofInvoice(int $invoiceId): array;

    /**
     * @param array<string, mixed> $details
     */
    public function update(int $id, GatewayStatus $status, ?string $transactionId, array $details, DateTimeImmutable $now): void;

    /**
     * Attempts of all journals still waiting for a result, created in the window.
     *
     * @return list<GatewayPayment>
     */
    public function open(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array;
}
