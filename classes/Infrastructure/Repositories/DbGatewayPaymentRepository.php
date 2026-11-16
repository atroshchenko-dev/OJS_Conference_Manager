<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayPayment;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final class DbGatewayPaymentRepository implements GatewayPaymentRepository
{
    private const T = 'conf_gateway_payments';

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function add(GatewayPayment $payment, DateTimeImmutable $now): GatewayPayment
    {
        $id = (int) $this->db->table(self::T)->insertGetId([
            'context_id' => $payment->contextId,
            'invoice_id' => $payment->invoiceId,
            'gateway' => $payment->gateway,
            'order_id' => $payment->orderId,
            'amount_minor' => $payment->amount->minor,
            'currency' => $payment->amount->currency,
            'status' => $payment->status->value,
            'transaction_id' => $payment->transactionId,
            'created_at' => DateInput::toStorage($payment->createdAt),
            'updated_at' => DateInput::toStorage($now),
        ]);
        return new GatewayPayment($id, $payment->contextId, $payment->invoiceId, $payment->gateway, $payment->orderId, $payment->amount, $payment->status, $payment->transactionId, $payment->createdAt);
    }

    public function findByOrder(int $contextId, string $gateway, string $orderId): ?GatewayPayment
    {
        $row = $this->db->table(self::T)->where('context_id', $contextId)->where('gateway', $gateway)->where('order_id', $orderId)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    public function ofInvoice(int $invoiceId): array
    {
        return array_values(array_map($this->hydrate(...), $this->db->table(self::T)->where('invoice_id', $invoiceId)->orderByDesc('id')->get()->all()));
    }

    public function update(int $id, GatewayStatus $from, GatewayStatus $status, ?string $transactionId, array $details, DateTimeImmutable $now): bool
    {
        return $this->db->table(self::T)->where('id', $id)->where('status', $from->value)->update([
            'status' => $status->value,
            'transaction_id' => $transactionId === null ? null : mb_substr($transactionId, 0, 64),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'updated_at' => DateInput::toStorage($now),
        ]) === 1;
    }

    public function touch(int $id, DateTimeImmutable $now): void
    {
        $this->db->table(self::T)->where('id', $id)->update(['updated_at' => DateInput::toStorage($now)]);
    }

    public function open(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array
    {
        return array_values(array_map($this->hydrate(...), $this->db->table(self::T)
            ->whereIn('status', [GatewayStatus::Created->value, GatewayStatus::Pending->value])
            ->where('created_at', '>=', DateInput::toStorage($from))
            ->where('created_at', '<=', DateInput::toStorage($to))
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->get()->all()));
    }

    private function hydrate(object $row): GatewayPayment
    {
        return new GatewayPayment(
            (int) $row->id,
            (int) $row->context_id,
            (int) $row->invoice_id,
            (string) $row->gateway,
            (string) $row->order_id,
            new Money((int) $row->amount_minor, trim((string) $row->currency)),
            GatewayStatus::tryFrom((string) $row->status) ?? GatewayStatus::Pending,
            $row->transaction_id === null ? null : (string) $row->transaction_id,
            DateInput::fromStorage((string) $row->created_at) ?? new DateTimeImmutable('@0'),
        );
    }
}
