<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\CheckoutForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\CheckoutOrder;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayConfig;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayHttp;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayPayment;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayResult;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\PaymentGateway;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateInterval;
use DateTimeImmutable;

/**
 * PAY-09: card payment of invoices through the journal's own merchant
 * account at a payment gateway. Off until a manager sets it up; the
 * invoice is marked paid only from a report signed by the gateway or
 * from the gateway's own answer to a status request.
 */
final class OnlinePaymentService
{
    public const SETTING = 'paymentGateway';
    private const K = 'plugins.generic.conferenceManager.gateway.error.';

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly GatewayRegistry $gateways,
        private readonly GatewayPaymentRepository $payments,
        private readonly InvoiceService $invoices,
        private readonly ?GatewayHttp $http = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * The journal's setup for managers; secrets are never returned, only
     * whether they are set.
     *
     * @return array{gateway: ?string, sandbox: bool, values: array<string, string>, secretsSet: array<string, bool>, gateways: list<array{id: string, name: string, fields: list<array{name: string, label: string, secret: bool}>, currencies: list<string>}>}
     */
    public function settings(ContextInfo $context): array
    {
        $stored = $this->stored($context);
        $gateway = $stored['gateway'] === null ? null : $this->gateways->get($stored['gateway']);
        $values = [];
        $secrets = [];
        foreach ($gateway?->fields() ?? [] as $f) {
            if ($f['secret']) {
                $secrets[$f['name']] = ($stored['values'][$f['name']] ?? '') !== '';
            } else {
                $values[$f['name']] = $stored['values'][$f['name']] ?? '';
            }
        }
        return [
            'gateway' => $gateway?->id(),
            'sandbox' => $stored['sandbox'],
            'values' => $values,
            'secretsSet' => $secrets,
            'gateways' => array_map(static fn (PaymentGateway $g): array => [
                'id' => $g->id(),
                'name' => $g->name(),
                'fields' => $g->fields(),
                'currencies' => $g->currencies(),
            ], $this->gateways->all()),
        ];
    }

    /**
     * Turns card payment on (with a gateway) or off (gateway ''). An empty
     * secret keeps the stored one.
     *
     * @param array<string, mixed> $input gateway, sandbox, values
     */
    public function saveSettings(ContextInfo $context, array $input, ?int $actorId): ValidationErrors
    {
        $errors = new ValidationErrors();
        $id = trim((string) ($input['gateway'] ?? ''));
        if ($id === '') {
            $this->settings->set($context->id, self::SETTING, ['gateway' => null, 'sandbox' => false, 'values' => []]);
            $this->audit?->log($context->id, $actorId, 'payment_gateway', null, 'update', null, ['gateway' => null]);
            return $errors;
        }
        $gateway = $this->gateways->get($id);
        if ($gateway === null) {
            $errors->add('gateway', self::K . 'unknown');
            return $errors;
        }
        $old = $this->stored($context);
        $given = is_array($input['values'] ?? null) ? $input['values'] : [];
        $values = [];
        foreach ($gateway->fields() as $f) {
            $value = trim((string) ($given[$f['name']] ?? ''));
            if ($value === '' && $f['secret'] && $old['gateway'] === $id) {
                $value = $old['values'][$f['name']] ?? '';
            }
            if ($value === '') {
                $errors->add('values.' . $f['name'], self::K . 'required');
            } elseif (mb_strlen($value) > 500) {
                $errors->add('values.' . $f['name'], self::K . 'tooLong');
            }
            $values[$f['name']] = $value;
        }
        if (!$errors->isEmpty()) {
            return $errors;
        }
        $sandbox = filter_var($input['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->settings->set($context->id, self::SETTING, ['gateway' => $id, 'sandbox' => $sandbox, 'values' => $values]);
        $public = array_intersect_key($values, array_flip(array_map(static fn (array $f): string => $f['name'], array_filter($gateway->fields(), static fn (array $f): bool => !$f['secret']))));
        $this->audit?->log($context->id, $actorId, 'payment_gateway', null, 'update', null, ['gateway' => $id, 'sandbox' => $sandbox] + $public);
        return $errors;
    }

    /** Card payment is set up in the journal. */
    public function isOn(ContextInfo $context): bool
    {
        return $this->active($context) !== null;
    }

    /** The participant can pay this invoice by card now. */
    public function canPay(ContextInfo $context, Invoice $invoice): bool
    {
        $active = $this->active($context);
        return $active !== null
            && $invoice->mode === PaymentMode::Acquiring
            && $invoice->status->isOpen()
            && !$invoice->isWaiver()
            && $invoice->due()->minor > 0
            && in_array($invoice->due()->currency, $active[0]->currencies(), true);
    }

    public function canPayInvoice(ContextInfo $context, int $invoiceId): bool
    {
        $invoice = $this->invoices->find($context, $invoiceId);
        return $invoice !== null && $this->canPay($context, $invoice);
    }

    /**
     * A new attempt to pay what is still due: the form for the gateway's
     * payment page, or an error key.
     *
     * @param callable(string): string $returnUrl gets the order id
     * @return array{0: ?CheckoutForm, 1: ?string}
     */
    public function start(ContextInfo $context, int $invoiceId, int $userId, string $email, string $description, callable $returnUrl, string $callbackUrl, string $language, DateTimeImmutable $now): array
    {
        $invoice = $this->invoices->find($context, $invoiceId);
        if ($invoice === null || $invoice->id === null || !$this->invoices->isPayer($invoice, $userId, $email)) {
            return [null, self::K . 'notFound'];
        }
        $active = $this->active($context);
        if ($active === null || !$this->canPay($context, $invoice)) {
            return [null, self::K . 'notPayable'];
        }
        [$gateway, $config] = $active;
        $attempt = count($this->payments->ofInvoice($invoice->id)) + 1;
        $orderId = $context->id . '-' . ($invoice->number ?? 'I' . $invoice->id) . '-' . $attempt;
        $payment = $this->payments->add(new GatewayPayment(null, $context->id, $invoice->id, $gateway->id(), $orderId, $invoice->due(), GatewayStatus::Created, null, $now), $now);
        $order = new CheckoutOrder($orderId, $payment->amount, $description, $returnUrl($orderId), $callbackUrl, $language);
        return [$gateway->checkout($config, $order), null];
    }

    /**
     * A report posted by the gateway. False when it is not signed with the
     * journal's key or names no attempt of the journal.
     *
     * @param array<string, mixed> $post
     */
    public function callback(ContextInfo $context, string $gatewayId, array $post, string $body, DateTimeImmutable $now): bool
    {
        $active = $this->active($context);
        if ($active === null || $active[0]->id() !== $gatewayId) {
            return false;
        }
        $result = $active[0]->callback($active[1], $post, $body);
        return $result !== null && $this->apply($context, $gatewayId, $result, $now);
    }

    /**
     * After the return from the payment page: asks the gateway about the
     * newest open attempt of the invoice when no report has come yet.
     */
    public function refreshInvoice(ContextInfo $context, int $invoiceId, DateTimeImmutable $now): void
    {
        $active = $this->active($context);
        if ($active === null || $this->http === null) {
            return;
        }
        foreach ($this->payments->ofInvoice($invoiceId) as $payment) {
            if ($payment->contextId === $context->id && $payment->gateway === $active[0]->id() && $payment->status->isOpen()) {
                $result = $active[0]->status($active[1], $payment->orderId, $this->http);
                if ($result !== null) {
                    $this->apply($context, $payment->gateway, $result, $now);
                }
                return;
            }
        }
    }

    /**
     * Scheduled: open attempts from 10 minutes to 7 days old are asked
     * about, so that a lost report does not leave a paid invoice open.
     *
     * @param callable(int): ?ContextInfo $contextOf
     * @return int attempts whose state changed
     */
    public function refreshOpen(callable $contextOf, DateTimeImmutable $now, int $limit = 200): int
    {
        if ($this->http === null) {
            return 0;
        }
        $changed = 0;
        foreach ($this->payments->open($now->sub(new DateInterval('P7D')), $now->sub(new DateInterval('PT10M')), $limit) as $payment) {
            $context = $contextOf($payment->contextId);
            $active = $context === null ? null : $this->active($context);
            if ($context === null || $active === null || $active[0]->id() !== $payment->gateway) {
                continue;
            }
            $result = $active[0]->status($active[1], $payment->orderId, $this->http);
            if ($result !== null && $result->status !== $payment->status && $this->apply($context, $payment->gateway, $result, $now)) {
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Attempts of an invoice for managers.
     *
     * @return list<array{orderId: string, gateway: string, amount: array<string, mixed>, status: string, transactionId: ?string, createdAt: string}>
     */
    public function attempts(ContextInfo $context, int $invoiceId): array
    {
        $out = [];
        foreach ($this->payments->ofInvoice($invoiceId) as $p) {
            if ($p->contextId === $context->id) {
                $out[] = [
                    'orderId' => $p->orderId,
                    'gateway' => $p->gateway,
                    'amount' => $p->amount->toArray(),
                    'status' => $p->status->value,
                    'transactionId' => $p->transactionId,
                    'createdAt' => $p->createdAt->format('Y-m-d\TH:i:s\Z'),
                ];
            }
        }
        return $out;
    }

    /**
     * Records a verified result once: a repeated or stale report changes
     * nothing. A success pays the invoice with the amount the gateway took.
     */
    private function apply(ContextInfo $context, string $gatewayId, GatewayResult $result, DateTimeImmutable $now): bool
    {
        $payment = $this->payments->findByOrder($context->id, $gatewayId, $result->orderId);
        if ($payment === null || $payment->id === null) {
            return false;
        }
        if (!$payment->status->canBecome($result->status)) {
            return true;
        }
        $this->payments->update($payment->id, $result->status, $result->transactionId ?? $payment->transactionId, $result->details, $now);
        $entry = ['gateway' => $gatewayId, 'orderId' => $result->orderId, 'transactionId' => $result->transactionId, 'amount' => $result->amountMinor, 'currency' => $result->currency];
        if ($result->status === GatewayStatus::Success) {
            $invoice = $this->invoices->find($context, $payment->invoiceId);
            $wrongCurrency = $result->currency !== $payment->amount->currency;
            $errors = $invoice === null || $wrongCurrency || $result->amountMinor <= 0
                ? null
                : $this->invoices->markPaid($context, $payment->invoiceId, $this->decimal($result->amountMinor, $result->currency), $this->note($gatewayId, $result), null, $now);
            // Paid on a closed invoice or in another currency: the manager sorts it out.
            $action = $errors !== null && $errors->isEmpty() ? 'gateway_paid' : 'gateway_paid_unmatched';
            $this->audit?->log($context->id, null, 'invoice', $payment->invoiceId, $action, null, $entry);
        } elseif ($result->status === GatewayStatus::Reversed) {
            $this->audit?->log($context->id, null, 'invoice', $payment->invoiceId, 'gateway_reversed', null, $entry);
        }
        return true;
    }

    private function decimal(int $minor, string $currency): string
    {
        return (new \APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money($minor, $currency))->decimal();
    }

    private function note(string $gatewayId, GatewayResult $result): string
    {
        $name = $this->gateways->get($gatewayId)?->name() ?? $gatewayId;
        return trim($name . ' ' . $result->orderId . ($result->transactionId === null ? '' : ' / ' . $result->transactionId));
    }

    /** @return array{0: PaymentGateway, 1: GatewayConfig}|null */
    private function active(ContextInfo $context): ?array
    {
        $stored = $this->stored($context);
        $gateway = $stored['gateway'] === null ? null : $this->gateways->get($stored['gateway']);
        if ($gateway === null) {
            return null;
        }
        foreach ($gateway->fields() as $f) {
            if (($stored['values'][$f['name']] ?? '') === '') {
                return null;
            }
        }
        return [$gateway, new GatewayConfig($gateway->id(), $stored['sandbox'], $stored['values'])];
    }

    /** @return array{gateway: ?string, sandbox: bool, values: array<string, string>} */
    private function stored(ContextInfo $context): array
    {
        $raw = $this->settings->get($context->id, self::SETTING);
        $raw = is_array($raw) ? $raw : [];
        $values = [];
        foreach ((array) ($raw['values'] ?? []) as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $values[$k] = (string) $v;
            }
        }
        return [
            'gateway' => is_string($raw['gateway'] ?? null) && $raw['gateway'] !== '' ? $raw['gateway'] : null,
            'sandbox' => filter_var($raw['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'values' => $values,
        ];
    }
}
