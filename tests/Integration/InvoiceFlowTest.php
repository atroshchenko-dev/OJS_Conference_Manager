<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceDocumentService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceTemplateStore;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentReference;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
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

/**
 * PAY-01…08 and FEE-04…07 on SQLite: payment rules, issuing an invoice
 * after a listener registration, numbers, payments, waivers.
 */
final class InvoiceFlowTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private FeeService $fees;
    private ParticipantService $participants;
    private InvoiceService $invoices;
    private FakeInvoiceNotifier $notifier;
    private DateTimeImmutable $now;
    private int $bankId;
    private int $treasuryId;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->db->table('users')->insert([['note' => 'u1'], ['note' => 'u2'], ['note' => 'u3']]);
        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Журнал']);
        $registry = JurisdictionRegistry::withBuiltIns();
        $profiles = new InstitutionProfileService(new DbInstitutionRepository($this->db), $registry);
        [$profile, $errors] = $profiles->save($this->ctx, [
            'jurisdiction' => 'UA',
            'institutionType' => 'ippo',
            'ownership' => 'communal',
            'legalId' => '02070944',
            'name' => ['uk' => 'Інститут післядипломної освіти'],
            'address' => ['uk' => 'м. Київ, вул. Освіти, 1'],
            'paymentAccounts' => [
                ['kind' => 'bank', 'recipientName' => 'ІППО', 'iban' => 'UA213223130000026007233566001', 'bankName' => 'Банк', 'purposeTemplate' => 'Оргвнесок за {editionTitle}, рахунок {invoiceNumber}, {payerName}'],
                ['kind' => 'treasury', 'recipientName' => 'ІППО', 'iban' => 'UA118201720000031000000000001', 'extra' => ['incomeCode' => '25010100']],
            ],
        ]);
        self::assertSame([], $errors->toArray());
        $accounts = $profile?->activePaymentAccounts() ?? [];
        $this->bankId = (int) $accounts[0]->id;
        $this->treasuryId = (int) $accounts[1]->id;

        $this->fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        $this->participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            new FakeGroups(),
            new AuditLogger($this->db),
        );
        $this->notifier = new FakeInvoiceNotifier();
        $this->invoices = new InvoiceService(
            new DbPaymentRepository($this->db),
            new DbFeeRepository($this->db),
            new DbEditionRepository($this->db),
            new DbParticipantRepository($this->db),
            new DbRegistrationRepository($this->db),
            static fn (ContextInfo $c) => $profiles->getOrDraft($c),
            $this->notifier,
            new AuditLogger($this->db),
            static fn (string $key, array $params, string $locale): string => $key . ($params === [] ? '' : ':' . implode(',', array_map('strval', $params))),
            $registry,
        );
        $this->now = new DateTimeImmutable('2027-02-01T10:00:00Z');
    }

    /** @return array{0: int, 1: array<string, int>} edition id, category code => id */
    private function edition(string $path = 'conf'): array
    {
        $service = new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns());
        [$e, $errors] = $service->create($this->ctx, EditionTest::validInput([
            'path' => $path,
            'status' => 'cfp_open',
            'registrationClosesAt' => '2027-05-10',
        ]));
        self::assertSame([], $errors->toArray());
        $id = (int) $e?->id;
        $cats = [];
        foreach ($this->fees->applyPreset($this->ctx, $id, InstitutionType::University, static fn (string $k, string $l): string => $k) ?? [] as $c) {
            $cats[$c->code] = (int) $c->id;
        }
        return [$id, $cats];
    }

    private function listener(int $editionId, int $categoryId, int $userId, string $email): int
    {
        [$r, $e] = $this->participants->registerListener($this->ctx, $editionId, [
            'userId' => $userId, 'email' => $email,
            'givenName' => ['uk' => 'Олена'], 'familyName' => ['uk' => 'Коваль'],
            'affiliation' => 'Ліцей № 1', 'country' => 'UA', 'orcid' => null, 'position' => 'вчителька',
        ], ['categoryId' => $categoryId, 'participationForm' => 'online', 'consent' => true], (new ConsentText('Згода'))->version, $this->now);
        self::assertSame([], $e->toArray());
        return (int) $r['participationId'];
    }

    /** @param array<string, mixed> $input */
    private function rule(int $editionId, array $input): void
    {
        [, $errors] = $this->invoices->saveRule($this->ctx, $editionId, null, $input) ?? [null, null];
        self::assertSame([], $errors?->toArray());
    }

    private function tariff(int $editionId, int $categoryId, string $amount): void
    {
        [, $errors] = $this->fees->saveTariff($this->ctx, $editionId, null, [
            'categoryId' => $categoryId, 'material' => 'listener', 'pricing' => 'flat', 'amount' => $amount, 'currency' => 'UAH',
        ]) ?? [null, null];
        self::assertSame([], $errors?->toArray());
    }

    public function testRuleValidation(): void
    {
        [$id, $cats] = $this->edition();
        [, $e] = $this->invoices->saveRule($this->ctx, $id, null, ['mode' => 'bank_transfer']) ?? [null, null];
        self::assertTrue($e?->has('paymentAccountId'));
        [, $e] = $this->invoices->saveRule($this->ctx, $id, null, ['mode' => 'bank_transfer', 'paymentAccountId' => $this->treasuryId]) ?? [null, null];
        self::assertTrue($e?->has('paymentAccountId'));
        [, $e] = $this->invoices->saveRule($this->ctx, $id, null, ['mode' => 'external_link', 'externalUrl' => 'javascript:alert(1)']) ?? [null, null];
        self::assertTrue($e?->has('externalUrl'));
        $this->rule($id, ['mode' => 'bank_transfer', 'paymentAccountId' => $this->bankId]);
        [, $e] = $this->invoices->saveRule($this->ctx, $id, null, ['mode' => 'free']) ?? [null, null];
        self::assertTrue($e?->has('categoryId'), 'second default rule');
        $this->rule($id, ['mode' => 'treasury_transfer', 'categoryId' => $cats['own_staff'], 'paymentAccountId' => $this->treasuryId]);
        self::assertCount(2, $this->invoices->rules($this->ctx, $id) ?? []);
        self::assertNull($this->invoices->rules(new ContextInfo(2, 'uk', ['uk']), $id));
    }

    public function testListenerInvoiceIssueAndPayment(): void
    {
        [$id, $cats] = $this->edition();
        $this->rule($id, ['mode' => 'bank_transfer', 'paymentAccountId' => $this->bankId]);
        $this->rule($id, ['mode' => 'free', 'categoryId' => $cats['students']]);
        $this->tariff($id, $cats['other_faculty'], '150');
        $this->tariff($id, $cats['students'], '50');

        $p = $this->listener($id, $cats['other_faculty'], 1, 'olena@example.org');
        $r = $this->invoices->issueFor($this->ctx, $p, null, 1, $this->now);
        self::assertSame(InvoiceService::ISSUED, $r['result']);
        $invoice = $r['invoice'];
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame('2027-00001', $invoice->number);
        self::assertTrue(PaymentReference::isValid((string) $invoice->paymentReference));
        self::assertSame(15000, $invoice->total->minor);
        self::assertSame('bank', $invoice->method);
        self::assertCount(1, $this->notifier->sent);

        $shown = $this->invoices->present($this->ctx, $invoice, 'uk');
        self::assertSame("Сто п'ятдесят гривень 00 копійок", $shown['totalInWords']);
        self::assertStringContainsString('рахунок 2027-00001, Олена Коваль', $shown['purpose']);
        self::assertStringContainsString('invoice.instructions.noCard', $shown['instructions'], 'UA warns about card payments');
        self::assertSame('UA213223130000026007233566001', $shown['recipient']['account']['iban']);
        self::assertTrue($shown['hasDocument']);

        // Runs once.
        self::assertSame(InvoiceService::EXISTS, $this->invoices->issueFor($this->ctx, $p, null, 1, $this->now)['result']);

        // A free category: no invoice.
        $s = $this->listener($id, $cats['students'], 2, 'student@example.org');
        self::assertSame(InvoiceService::FREE, $this->invoices->issueFor($this->ctx, $s, null, 1, $this->now)['result']);

        // Part payment, then the rest.
        self::assertSame([], $this->invoices->markPaid($this->ctx, (int) $invoice->id, '100', 'виписка', 1, $this->now)?->toArray());
        $part = $this->invoices->find($this->ctx, (int) $invoice->id);
        self::assertSame(InvoiceStatus::Issued, $part?->status);
        self::assertSame(5000, $part?->due()->minor);
        self::assertSame([], $this->invoices->markPaid($this->ctx, (int) $invoice->id, null, null, 1, $this->now)?->toArray());
        $paid = $this->invoices->find($this->ctx, (int) $invoice->id);
        self::assertSame(InvoiceStatus::Paid, $paid?->status);
        self::assertNotNull($paid?->paidAt);
        self::assertTrue($this->invoices->markPaid($this->ctx, (int) $invoice->id, null, null, 1, $this->now)?->has('status'));
        self::assertSame(['issued', 'part_paid', 'paid'], array_column($this->invoices->events($this->ctx, (int) $invoice->id), 'type'));

        // Another context sees nothing.
        self::assertNull($this->invoices->find(new ContextInfo(2, 'uk', ['uk']), (int) $invoice->id));
    }

    public function testWaiverAndCancel(): void
    {
        [$id, $cats] = $this->edition();
        $this->rule($id, ['mode' => 'treasury_transfer', 'paymentAccountId' => $this->treasuryId]);
        $this->tariff($id, $cats['other_faculty'], '200');
        $p1 = $this->listener($id, $cats['other_faculty'], 1, 'a@example.org');
        $p2 = $this->listener($id, $cats['other_faculty'], 2, 'b@example.org');
        $i1 = $this->invoices->issueFor($this->ctx, $p1, null, 1, $this->now)['invoice'];
        $i2 = $this->invoices->issueFor($this->ctx, $p2, null, 1, $this->now)['invoice'];
        self::assertSame('2027-00002', $i2?->number);
        $shown = $this->invoices->present($this->ctx, $i1 ?? throw new \LogicException(), 'uk');
        self::assertContains(['label' => 'plugins.generic.conferenceManager.jurisdiction.ua.incomeCode', 'value' => '25010100'], $shown['recipient']['accountFields']);

        [, $e] = $this->invoices->waive($this->ctx, (int) $i1->id, '  ', 1, $this->now) ?? [null, null];
        self::assertTrue($e?->has('reason'));
        [$waiver, $e] = $this->invoices->waive($this->ctx, (int) $i1->id, 'Член оргкомітету', 1, $this->now) ?? [null, null];
        self::assertSame([], $e?->toArray());
        self::assertSame(0, $waiver?->total->minor);
        self::assertSame(InvoiceStatus::Waived, $waiver?->status);
        self::assertTrue($waiver?->isWaiver());
        self::assertSame($i1->id, $waiver?->supersedesId);
        self::assertSame(InvoiceStatus::Superseded, $this->invoices->find($this->ctx, (int) $i1->id)?->status);
        self::assertSame($waiver?->id, $this->invoices->issueFor($this->ctx, $p1, null, 1, $this->now)['invoice']?->id);
        self::assertFalse($this->invoices->present($this->ctx, $waiver ?? throw new \LogicException(), 'uk')['hasDocument']);

        self::assertSame([], $this->invoices->cancel($this->ctx, (int) $i2?->id, 1, $this->now)?->toArray());
        self::assertSame(InvoiceStatus::Cancelled, $this->invoices->find($this->ctx, (int) $i2?->id)?->status);
        // After cancelling, a new invoice can be issued; numbers keep going.
        self::assertSame('2027-00004', $this->invoices->issueFor($this->ctx, $p2, null, 1, $this->now)['invoice']?->number);

        $list = $this->invoices->ofEdition($this->ctx, $id, null, 'b@example', 50, 0, 'uk');
        self::assertSame(2, $list['itemsMax'] ?? null);
        $mine = $this->invoices->ofParticipants($this->ctx, [(int) $this->db->table('conf_participations')->where('id', $p1)->value('participant_id')], 'uk');
        self::assertSame(['waived', 'superseded'], array_column($mine, 'status'));
    }

    public function testListenerRegistrationIssuesTheInvoice(): void
    {
        [$id, $cats] = $this->edition();
        $this->rule($id, ['mode' => 'bank_transfer', 'paymentAccountId' => $this->bankId]);
        $this->tariff($id, $cats['other_faculty'], '100.50');
        $participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            null,
            null,
            null,
            $this->invoices,
        );
        [$r] = $participants->registerListener($this->ctx, $id, [
            'userId' => 3, 'email' => 'c@example.org', 'givenName' => ['uk' => 'Іван'], 'familyName' => ['uk' => 'Петренко'],
            'affiliation' => null, 'country' => null, 'orcid' => null, 'position' => null,
        ], ['categoryId' => $cats['other_faculty'], 'participationForm' => 'online', 'consent' => true], 'v1', $this->now);
        self::assertNotNull($r);
        $mine = $this->invoices->mine($this->ctx, 3, 'c@example.org', 'uk');
        self::assertCount(1, $mine);
        self::assertSame(10050, $mine[0]['total']['minor']);
        self::assertSame('Сто гривень 50 копійок', $mine[0]['totalInWords']);
        self::assertSame([], $this->invoices->mine($this->ctx, 1, 'other@example.org', 'uk'));
    }

    public function testDocumentTemplates(): void
    {
        [$id, $cats] = $this->edition();
        $this->rule($id, ['mode' => 'treasury_transfer', 'paymentAccountId' => $this->treasuryId]);
        $this->tariff($id, $cats['other_faculty'], '150');
        $invoice = $this->invoices->issueFor($this->ctx, $this->listener($id, $cats['other_faculty'], 1, 'a@example.org'), null, 1, $this->now)['invoice'];
        self::assertNotNull($invoice);
        $store = new MemoryTemplateStore();
        $docs = new InvoiceDocumentService($this->invoices, $store, null, dirname(__DIR__, 2) . '/templates/invoice');

        $html = (string) $docs->html($this->ctx, $invoice);
        self::assertStringContainsString('казначейськ', $html);
        self::assertStringContainsString('2027-00001', $html);
        self::assertStringContainsString('25010100', $html);
        self::assertStringContainsString('Сто п&#039;ятдесят гривень 00 копійок', $html);
        self::assertStringContainsString('treasury account', (string) $docs->html($this->ctx, $invoice, 'en'));

        self::assertTrue($docs->saveTemplate($this->ctx, 'treasury', '<p>{nope}</p>')?->has('html'));
        self::assertNull($docs->saveTemplate($this->ctx, 'card', '<p></p>'));
        self::assertTrue($docs->saveTemplate($this->ctx, 'treasury', '<h1>Власний {invoiceNumber}</h1>')?->isEmpty());
        self::assertSame('<h1>Власний 2027-00001</h1>', $docs->html($this->ctx, $invoice));
        self::assertNotNull($docs->templates($this->ctx)['treasury']['custom']);
        $docs->saveTemplate($this->ctx, 'treasury', '  ');
        self::assertNull($docs->templates($this->ctx)['treasury']['custom']);

        [$waiver] = $this->invoices->waive($this->ctx, (int) $invoice->id, 'Доповідач-гість', 1, $this->now) ?? [null];
        self::assertNull($docs->html($this->ctx, $waiver ?? throw new \LogicException()));
    }

    public function testNumbersAreSequentialPerContextAndYear(): void
    {
        $repo = new DbPaymentRepository($this->db);
        $numbers = [];
        for ($i = 0; $i < 100; $i++) {
            $numbers[] = $this->db->transaction(static fn (): int => $repo->nextNumber(1, 2027));
        }
        self::assertSame(range(1, 100), $numbers);
        self::assertSame(1, $repo->nextNumber(2, 2027));
        self::assertSame(1, $repo->nextNumber(1, 2028));
    }
}

final class FakeInvoiceNotifier implements InvoiceNotifier
{
    /** @var list<array{Invoice, array<string, mixed>}> */
    public array $sent = [];

    public function invoiceIssued(ContextInfo $context, Invoice $invoice, array $presented): void
    {
        $this->sent[] = [$invoice, $presented];
    }
}

final class MemoryTemplateStore implements InvoiceTemplateStore
{
    /** @var array<string, string> */
    private array $html = [];

    public function get(int $contextId, string $kind): ?string
    {
        return $this->html[$contextId . $kind] ?? null;
    }

    public function set(int $contextId, string $kind, ?string $html): void
    {
        if ($html === null) {
            unset($this->html[$contextId . $kind]);
        } else {
            $this->html[$contextId . $kind] = $html;
        }
    }
}
