<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\GatewayRegistry;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceService;
use APP\plugins\generic\conferenceManager\classes\Application\OnlinePaymentService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Core\SodiumSecretCipher;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayHttp;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\LiqPayGateway;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbGatewayPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbRegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/** PAY-09 on SQLite: card payment of an invoice through LiqPay with signed reports. */
final class OnlinePaymentFlowTest extends TestCase
{
    private const PUBLIC = 'sandbox_i000000';
    private const PRIVATE = 'sandbox_secret';

    private Connection $db;
    private ContextInfo $ctx;
    private FakeSettings $settings;
    private FeeService $fees;
    private ParticipantService $participants;
    private InvoiceService $invoices;
    private OnlinePaymentService $service;
    private FakeGatewayHttp $http;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->db->table('users')->insert([['note' => 'u1'], ['note' => 'u2']]);
        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Журнал']);
        $registry = JurisdictionRegistry::withBuiltIns();
        $profiles = new InstitutionProfileService(new DbInstitutionRepository($this->db), $registry);
        $this->settings = new FakeSettings();
        $audit = new AuditLogger($this->db);
        $this->fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), $audit);
        $this->participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            new FakeGroups(),
            $audit,
        );
        $this->invoices = new InvoiceService(
            new DbPaymentRepository($this->db),
            new DbFeeRepository($this->db),
            new DbEditionRepository($this->db),
            new DbParticipantRepository($this->db),
            new DbRegistrationRepository($this->db),
            static fn (ContextInfo $c) => $profiles->getOrDraft($c),
            new FakeInvoiceNotifier(),
            $audit,
            static fn (string $key, array $params, string $locale): string => $key,
            $registry,
            $this->settings,
        );
        $this->http = new FakeGatewayHttp();
        $this->service = new OnlinePaymentService(
            $this->settings,
            new GatewayRegistry([new LiqPayGateway(), new \stdClass()]),
            new DbGatewayPaymentRepository($this->db),
            $this->invoices,
            $this->http,
            $audit,
            new SodiumSecretCipher('test-app-key'),
        );
        $this->now = new DateTimeImmutable('2027-02-01T10:00:00Z');
    }

    /** An invoice of 150.00 UAH to pay by card, for user 1. */
    private function invoice(): Invoice
    {
        $editions = new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns());
        [$e, $errors] = $editions->create($this->ctx, EditionTest::validInput(['path' => 'conf', 'status' => 'cfp_open', 'registrationClosesAt' => '2027-05-10']));
        self::assertSame([], $errors->toArray());
        $id = (int) $e?->id;
        $cats = [];
        foreach ($this->fees->applyPreset($this->ctx, $id, InstitutionType::University, static fn (string $k, string $l): string => $k) ?? [] as $c) {
            $cats[$c->code] = (int) $c->id;
        }
        [, $te] = $this->fees->saveTariff($this->ctx, $id, null, ['categoryId' => $cats['other_faculty'], 'material' => 'listener', 'pricing' => 'flat', 'amount' => '150', 'currency' => 'UAH']) ?? [null, null];
        self::assertSame([], $te?->toArray());

        [, $re] = $this->invoices->saveRule($this->ctx, $id, null, ['mode' => 'acquiring']) ?? [null, null];
        self::assertTrue($re?->has('mode'), 'card payment needs a gateway first');
        $this->configure();
        [, $re] = $this->invoices->saveRule($this->ctx, $id, null, ['mode' => 'acquiring']) ?? [null, null];
        self::assertSame([], $re?->toArray());

        [$r, $pe] = $this->participants->registerListener($this->ctx, $id, [
            'userId' => 1, 'email' => 'olena@example.org',
            'givenName' => ['uk' => 'Олена'], 'familyName' => ['uk' => 'Коваль'],
            'affiliation' => 'Ліцей № 1', 'country' => 'UA', 'orcid' => null, 'position' => 'вчителька',
        ], ['categoryId' => $cats['other_faculty'], 'participationForm' => 'online', 'consent' => true], (new ConsentText('Згода'))->version, $this->now);
        self::assertSame([], $pe->toArray());
        $issued = $this->invoices->issueFor($this->ctx, (int) $r['participationId'], null, 1, $this->now);
        self::assertSame(InvoiceService::ISSUED, $issued['result']);
        $invoice = $issued['invoice'];
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(15000, $invoice->total->minor);
        return $invoice;
    }

    private function configure(): void
    {
        $e = $this->service->saveSettings($this->ctx, ['gateway' => 'liqpay', 'sandbox' => true, 'values' => ['publicKey' => self::PUBLIC, 'privateKey' => self::PRIVATE]], 1);
        self::assertSame([], $e->toArray());
    }

    /** @return array{data: string, signature: string} */
    private static function report(array $fields, string $key = self::PRIVATE): array
    {
        return LiqPayGateway::sign($key, $fields + ['public_key' => self::PUBLIC, 'currency' => 'UAH', 'version' => 3]);
    }

    /** @return array{0: string, 1: array<string, mixed>} order id and decoded checkout data */
    private function start(Invoice $invoice, int $userId = 1, string $email = 'olena@example.org'): array
    {
        [$form, $error] = $this->service->start($this->ctx, (int) $invoice->id, $userId, $email, 'Оргвнесок', static fn (string $o): string => 'https://j.example/return/' . $o, 'https://j.example/cb', 'uk', $this->now);
        self::assertNull($error);
        self::assertNotNull($form);
        self::assertSame(LiqPayGateway::CHECKOUT_URL, $form->action);
        $data = json_decode((string) base64_decode($form->fields['data'], true), true);
        self::assertIsArray($data);
        return [(string) $data['order_id'], $data];
    }

    public function testSettingsKeepSecrets(): void
    {
        self::assertFalse($this->service->isOn($this->ctx));
        $e = $this->service->saveSettings($this->ctx, ['gateway' => 'nope'], 1);
        self::assertTrue($e->has('gateway'));
        $e = $this->service->saveSettings($this->ctx, ['gateway' => 'liqpay', 'values' => ['publicKey' => 'p']], 1);
        self::assertTrue($e->has('values.privateKey'));
        $this->configure();
        self::assertTrue($this->service->isOn($this->ctx));
        $shown = $this->service->settings($this->ctx);
        self::assertSame(['publicKey' => self::PUBLIC], $shown['values']);
        self::assertSame(['privateKey' => true], $shown['secretsSet']);
        self::assertStringNotContainsString(self::PRIVATE, (string) json_encode($shown));
        self::assertSame(['liqpay'], array_column($shown['gateways'], 'id'), 'anything but a gateway is skipped');

        // An empty secret keeps the stored one.
        $e = $this->service->saveSettings($this->ctx, ['gateway' => 'liqpay', 'sandbox' => false, 'values' => ['publicKey' => 'other', 'privateKey' => '']], 1);
        self::assertSame([], $e->toArray());
        $stored = $this->settings->get(1, OnlinePaymentService::SETTING)['values']['privateKey'];
        self::assertStringStartsWith('cmenc1:', $stored, 'the private key is stored sealed');
        self::assertStringNotContainsString(self::PRIVATE, (string) json_encode($this->settings->get(1, OnlinePaymentService::SETTING)));
        self::assertSame(self::PRIVATE, (new SodiumSecretCipher('test-app-key'))->open($stored));
        self::assertFalse($this->service->isOn(new ContextInfo(2, 'uk', ['uk'])), 'per journal');

        $this->service->saveSettings($this->ctx, ['gateway' => ''], 1);
        self::assertFalse($this->service->isOn($this->ctx));
    }

    public function testPlainKeyFromOlderVersionIsSealedAndForeignKeyIsNotSet(): void
    {
        $this->settings->set(1, OnlinePaymentService::SETTING, ['gateway' => 'liqpay', 'sandbox' => true, 'values' => ['publicKey' => self::PUBLIC, 'privateKey' => self::PRIVATE]]);
        self::assertTrue($this->service->isOn($this->ctx));
        $stored = $this->settings->get(1, OnlinePaymentService::SETTING)['values'];
        self::assertSame(self::PUBLIC, $stored['publicKey']);
        self::assertStringStartsWith('cmenc1:', $stored['privateKey']);

        // Sealed under another installation's app_key: counts as not set.
        $other = (new SodiumSecretCipher('other-app-key'))->seal(self::PRIVATE);
        $this->settings->set(1, OnlinePaymentService::SETTING, ['gateway' => 'liqpay', 'sandbox' => true, 'values' => ['publicKey' => self::PUBLIC, 'privateKey' => $other]]);
        self::assertSame(['privateKey' => false], $this->service->settings($this->ctx)['secretsSet']);
        self::assertNull((new SodiumSecretCipher('test-app-key'))->open('cmenc1:' . base64_encode(str_repeat('x', 40))));
    }

    public function testSignedReportPaysOnce(): void
    {
        $invoice = $this->invoice();
        self::assertTrue($this->service->canPay($this->ctx, $invoice));
        [, $error] = $this->service->start($this->ctx, (int) $invoice->id, 2, 'other@example.org', 'x', static fn (string $o): string => '', '', 'uk', $this->now);
        self::assertSame('plugins.generic.conferenceManager.gateway.error.notFound', $error, 'only the payer');

        [$orderId, $data] = $this->start($invoice);
        self::assertSame('1-' . $invoice->number . '-1', $orderId);
        self::assertSame(150.0, $data['amount']);
        self::assertSame('1', $data['sandbox']);
        self::assertSame('https://j.example/return/' . $orderId, $data['result_url']);
        [$second] = $this->start($invoice);
        self::assertSame('1-' . $invoice->number . '-2', $second, 'each attempt has its own order id');

        // Not signed with the journal's key, or for another journal: ignored.
        self::assertFalse($this->service->callback($this->ctx, 'liqpay', self::report(['order_id' => $orderId, 'status' => 'success', 'amount' => '150.00'], 'forged'), '', $this->now));
        self::assertFalse($this->service->callback(new ContextInfo(2, 'uk', ['uk']), 'liqpay', self::report(['order_id' => $orderId, 'status' => 'success', 'amount' => '150.00']), '', $this->now));
        self::assertFalse($this->service->callback($this->ctx, 'liqpay', self::report(['order_id' => '1-unknown-1', 'status' => 'success', 'amount' => '150.00']), '', $this->now));
        self::assertSame(InvoiceStatus::Issued, $this->invoices->find($this->ctx, (int) $invoice->id)?->status);

        $paid = self::report(['order_id' => $orderId, 'status' => 'success', 'amount' => '150.00', 'payment_id' => 555, 'sender_card_mask2' => '4242*42']);
        self::assertTrue($this->service->callback($this->ctx, 'liqpay', $paid, '', $this->now));
        $after = $this->invoices->find($this->ctx, (int) $invoice->id);
        self::assertSame(InvoiceStatus::Paid, $after?->status);
        self::assertSame(15000, $after->paidAmount()->minor);
        self::assertFalse($this->service->canPay($this->ctx, $after));

        // A repeated report and a late failure change nothing.
        self::assertTrue($this->service->callback($this->ctx, 'liqpay', $paid, '', $this->now));
        self::assertTrue($this->service->callback($this->ctx, 'liqpay', self::report(['order_id' => $orderId, 'status' => 'failure', 'amount' => '150.00']), '', $this->now));
        self::assertSame(15000, $this->invoices->find($this->ctx, (int) $invoice->id)?->paidAmount()->minor);
        $attempts = $this->service->attempts($this->ctx, (int) $invoice->id);
        self::assertSame(['created', 'success'], array_column($attempts, 'status'), 'newest first');
        self::assertSame('555', $attempts[1]['transactionId']);
        $details = (string) $this->db->table('conf_gateway_payments')->where('order_id', $orderId)->value('details');
        self::assertStringNotContainsString('4242', $details, 'no card data kept');
        self::assertSame(1, $this->db->table('conf_audit_log')->where('action', 'gateway_paid')->count());

        // Paying the second attempt too: recorded for the manager, the invoice is not paid twice.
        self::assertTrue($this->service->callback($this->ctx, 'liqpay', self::report(['order_id' => $second, 'status' => 'success', 'amount' => '150.00']), '', $this->now));
        self::assertSame(15000, $this->invoices->find($this->ctx, (int) $invoice->id)?->paidAmount()->minor);
        self::assertSame(1, $this->db->table('conf_audit_log')->where('action', 'gateway_paid_unmatched')->count());
    }

    public function testStatusRequestFindsLostReport(): void
    {
        $invoice = $this->invoice();
        [$orderId] = $this->start($invoice);
        $this->http->answer = (string) json_encode(['result' => 'ok', 'status' => 'processing', 'order_id' => $orderId, 'amount' => 150, 'currency' => 'UAH']);
        $this->service->refreshInvoice($this->ctx, (int) $invoice->id, $this->now);
        self::assertSame(InvoiceStatus::Issued, $this->invoices->find($this->ctx, (int) $invoice->id)?->status);
        self::assertSame(['pending'], array_column($this->service->attempts($this->ctx, (int) $invoice->id), 'status'));

        $contextOf = fn (int $id): ?ContextInfo => $id === 1 ? $this->ctx : null;
        self::assertSame(0, $this->service->refreshOpen($contextOf, $this->now), 'too fresh to ask');
        $this->http->answer = (string) json_encode(['result' => 'ok', 'status' => 'success', 'order_id' => $orderId, 'amount' => 150, 'currency' => 'UAH', 'payment_id' => 777]);
        self::assertSame(1, $this->service->refreshOpen($contextOf, $this->now->modify('+1 hour')));
        self::assertSame(InvoiceStatus::Paid, $this->invoices->find($this->ctx, (int) $invoice->id)?->status);
        self::assertSame(0, $this->service->refreshOpen($contextOf, $this->now->modify('+2 hours')), 'nothing open');
        self::assertSame(0, $this->service->refreshOpen($contextOf, $this->now->modify('+10 days')));

        $request = json_decode((string) base64_decode($this->http->forms[0]['data'], true), true);
        self::assertSame(['action' => 'status', 'version' => 3, 'public_key' => self::PUBLIC, 'order_id' => $orderId], $request);
        self::assertSame(LiqPayGateway::API_URL, $this->http->urls[0]);
    }

    public function testConcurrentReportsPayOnce(): void
    {
        $invoice = $this->invoice();
        [$orderId] = $this->start($invoice);
        $repo = new DbGatewayPaymentRepository($this->db);
        $attempt = $repo->findByOrder(1, 'liqpay', $orderId);
        self::assertNotNull($attempt);
        // Another report changed the attempt after this one read it: the second change loses.
        self::assertTrue($repo->update((int) $attempt->id, GatewayStatus::Created, GatewayStatus::Pending, null, [], $this->now));
        self::assertFalse($repo->update((int) $attempt->id, GatewayStatus::Created, GatewayStatus::Success, null, [], $this->now));
        self::assertSame(GatewayStatus::Pending, $repo->findByOrder(1, 'liqpay', $orderId)?->status);
    }

    public function testAttemptLimitAndFreshRefresh(): void
    {
        $invoice = $this->invoice();
        for ($i = 0; $i < OnlinePaymentService::MAX_ATTEMPTS; $i++) {
            $this->start($invoice);
        }
        [$form, $error] = $this->service->start($this->ctx, (int) $invoice->id, 1, 'olena@example.org', 'x', static fn (string $o): string => '', '', 'uk', $this->now);
        self::assertNull($form);
        self::assertSame('plugins.generic.conferenceManager.gateway.error.tooManyAttempts', $error);

        // The return page asks the gateway only about a fresh attempt.
        $this->http->answer = (string) json_encode(['result' => 'ok', 'status' => 'success', 'order_id' => '1-' . $invoice->number . '-20', 'amount' => 150, 'currency' => 'UAH']);
        $this->service->refreshInvoice($this->ctx, (int) $invoice->id, $this->now->modify('+2 days'));
        self::assertSame([], $this->http->urls);
        $this->service->refreshInvoice($this->ctx, (int) $invoice->id, $this->now->modify('+1 hour'));
        self::assertCount(1, $this->http->urls);
        self::assertSame(InvoiceStatus::Paid, $this->invoices->find($this->ctx, (int) $invoice->id)?->status);
    }

    public function testOpenAttemptsTakeTurns(): void
    {
        $invoice = $this->invoice();
        [$first] = $this->start($invoice);
        [$second] = $this->start($invoice);
        $this->http->answer = (string) json_encode(['result' => 'error', 'err_code' => 'payment_not_found']);
        $contextOf = fn (int $id): ?ContextInfo => $this->ctx;
        $asked = function (): string {
            $last = $this->http->forms[count($this->http->forms) - 1];
            return (string) json_decode((string) base64_decode($last['data'], true), true)['order_id'];
        };
        self::assertSame(0, $this->service->refreshOpen($contextOf, $this->now->modify('+1 hour'), 1));
        self::assertSame($first, $asked());
        self::assertSame(0, $this->service->refreshOpen($contextOf, $this->now->modify('+2 hours'), 1));
        self::assertSame($second, $asked(), 'an abandoned attempt does not block the next one');
        $this->service->refreshOpen($contextOf, $this->now->modify('+3 hours'), 1);
        self::assertSame($first, $asked());
    }

    public function testSandboxPaymentCountsOnlyInSandbox(): void
    {
        $invoice = $this->invoice();
        [$orderId] = $this->start($invoice);
        $this->service->saveSettings($this->ctx, ['gateway' => 'liqpay', 'sandbox' => false, 'values' => ['publicKey' => self::PUBLIC]], 1);
        self::assertTrue($this->service->callback($this->ctx, 'liqpay', self::report(['order_id' => $orderId, 'status' => 'sandbox', 'amount' => '150.00']), '', $this->now));
        self::assertSame(InvoiceStatus::Issued, $this->invoices->find($this->ctx, (int) $invoice->id)?->status);
        self::assertSame(['failure'], array_column($this->service->attempts($this->ctx, (int) $invoice->id), 'status'));
    }
}

final class FakeGatewayHttp implements GatewayHttp
{
    public ?string $answer = null;
    /** @var list<string> */
    public array $urls = [];
    /** @var list<array<string, string>> */
    public array $forms = [];

    public function postForm(string $url, array $form): ?string
    {
        $this->urls[] = $url;
        $this->forms[] = $form;
        return $this->answer;
    }
}
