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
     * Changes the state only if it is still $from, so that of two reports
     * handled at the same time only one goes on. False when it was not.
     *
     * @param array<string, mixed> $details
     */
    public function update(int $id, GatewayStatus $from, GatewayStatus $status, ?string $transactionId, array $details, DateTimeImmutable $now): bool;

    /** Marks an attempt as just asked about, so that the next check starts with others. */
    public function touch(int $id, DateTimeImmutable $now): void;

    /**
     * Attempts of all journals still waiting for a result, created in the
     * window, the longest unasked first.
     *
     * @return list<GatewayPayment>
     */
    public function open(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array;
}
