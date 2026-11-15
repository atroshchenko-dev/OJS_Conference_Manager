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
use APP\plugins\generic\conferenceManager\classes\Application\ProofNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\ProofService;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentReference;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofFile;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PaymentFilter;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Files\LocalProofStore;
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

    /** JUR-05: an organisation pays with its ЄДРПОУ; the tax number of a person only when turned on. */
    public function testPayerDetails(): void
    {
        [, $invoice] = $this->openInvoice();
        $id = (int) $invoice->id;
        self::assertNull($this->invoices->savePayerDetails($this->ctx, $id, 2, 'stranger@example.org', ['kind' => 'person'], $this->now), 'only the payer');

        $bad = $this->invoices->savePayerDetails($this->ctx, $id, 1, 'olena@example.org', ['kind' => 'organization', 'organization' => 'Ліцей', 'legalId' => '12345679'], $this->now);
        self::assertSame(['legalId' => ['plugins.generic.conferenceManager.validation.edrpou']], $bad?->toArray());
        $ok = $this->invoices->savePayerDetails($this->ctx, $id, 1, 'olena@example.org', ['kind' => 'organization', 'organization' => '  Київський   ліцей № 1 ', 'legalId' => '0207 0944'], $this->now);
        self::assertSame([], $ok?->toArray());
        $saved = $this->invoices->find($this->ctx, $id);
        self::assertSame('Київський ліцей № 1', $saved?->payer['name']);
        self::assertSame('02070944', $saved?->payer['legalId']);
        self::assertSame('Олена Коваль', $saved?->payer['contactName'] ?? null, 'the person stays as the contact');
        self::assertContains('payer_changed', array_column($this->invoices->events($this->ctx, $id), 'type'));

        $docs = new InvoiceDocumentService($this->invoices, new MemoryTemplateStore(), null, dirname(__DIR__, 2) . '/templates/invoice');
        $html = (string) $docs->html($this->ctx, $saved ?? throw new \LogicException());
        self::assertStringContainsString('Київський ліцей № 1', $html);
        self::assertStringContainsString('plugins.generic.conferenceManager.jurisdiction.ua.edrpou: 02070944', $html);

        // Without the setting a person gives no tax number, even if sent.
        self::assertSame([], $this->invoices->savePayerDetails($this->ctx, $id, 1, 'olena@example.org', ['kind' => 'person', 'taxId' => '1234567890'], $this->now)?->toArray());
        self::assertArrayNotHasKey('taxId', $this->invoices->find($this->ctx, $id)?->payer ?? []);

        // With the setting on, the РНОКПП is required and checked.
        $settings = new FakeSettings();
        $withTax = new InvoiceService(
            new DbPaymentRepository($this->db),
            new DbFeeRepository($this->db),
            new DbEditionRepository($this->db),
            new DbParticipantRepository($this->db),
            new DbRegistrationRepository($this->db),
            fn (ContextInfo $c) => (new InstitutionProfileService(new DbInstitutionRepository($this->db), JurisdictionRegistry::withBuiltIns()))->getOrDraft($c),
            null,
            new AuditLogger($this->db),
            null,
            JurisdictionRegistry::withBuiltIns(),
            $settings,
        );
        $withTax->setPersonalTaxIdEnabled($this->ctx, true, 1);
        self::assertTrue($withTax->payerFields($this->ctx)['taxIdEnabled']);
        self::assertTrue($withTax->savePayerDetails($this->ctx, $id, 1, 'olena@example.org', ['kind' => 'person'], $this->now)?->has('taxId'));
        self::assertTrue($withTax->savePayerDetails($this->ctx, $id, 1, 'olena@example.org', ['kind' => 'person', 'taxId' => '1234567890'], $this->now)?->has('taxId'));
        $valid = '123456789' . \APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA\Rnokpp::checkDigit('123456789');
        self::assertSame([], $withTax->savePayerDetails($this->ctx, $id, 1, 'olena@example.org', ['kind' => 'person', 'taxId' => $valid], $this->now)?->toArray());
        self::assertSame($valid, $withTax->find($this->ctx, $id)?->payer['taxId'] ?? null);

        // A paid invoice keeps its payer.
        $this->invoices->markPaid($this->ctx, $id, null, null, 1, $this->now);
        self::assertTrue($this->invoices->savePayerDetails($this->ctx, $id, 1, 'olena@example.org', ['kind' => 'person'], $this->now)?->has('kind'));
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

    /** @return array{0: ProofService, 1: FakeProofNotifier, 2: string} */
    private function proofService(): array
    {
        $dir = sys_get_temp_dir() . '/cm-proofs-' . bin2hex(random_bytes(4));
        $notifier = new FakeProofNotifier();
        $service = new ProofService(
            new DbPaymentRepository($this->db),
            $this->invoices,
            new LocalProofStore($dir),
            $notifier,
            new AuditLogger($this->db),
            static fn (string $key, array $params, string $locale): string => $key,
        );
        return [$service, $notifier, $dir];
    }

    private static function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cmp');
        file_put_contents($path, $content);
        return $path;
    }

    /** @return array{0: int, 1: Invoice} edition id and an open invoice of 150 UAH for user 1 */
    private function openInvoice(): array
    {
        [$id, $cats] = $this->edition();
        $this->rule($id, ['mode' => 'bank_transfer', 'paymentAccountId' => $this->bankId]);
        $this->tariff($id, $cats['other_faculty'], '150');
        $p = $this->listener($id, $cats['other_faculty'], 1, 'olena@example.org');
        $invoice = $this->invoices->issueFor($this->ctx, $p, null, 1, $this->now)['invoice'];
        self::assertInstanceOf(Invoice::class, $invoice);
        return [$id, $invoice];
    }

    public function testReceiptUploadChecks(): void
    {
        [, $invoice] = $this->openInvoice();
        [$proofs, , $dir] = $this->proofService();
        $iid = (int) $invoice->id;
        $pdf = self::file("%PDF-1.4\n% receipt 1\n");

        // Somebody else's invoice: nothing is said about it.
        self::assertNull($proofs->upload($this->ctx, $iid, 2, 'other@example.org', $pdf, 'r.pdf', $this->now, 'uk'));
        self::assertNull($proofs->upload(new ContextInfo(2, 'uk', ['uk']), $iid, 1, 'olena@example.org', $pdf, 'r.pdf', $this->now, 'uk'));

        // The type comes from the content, not from the name.
        [$r, $e] = $proofs->upload($this->ctx, $iid, 1, 'olena@example.org', self::file('<html><script>alert(1)</script>'), 'receipt.pdf', $this->now, 'uk') ?? [null, null];
        self::assertNull($r);
        self::assertTrue($e?->has('file'));
        [$r, $e] = $proofs->upload($this->ctx, $iid, 1, 'olena@example.org', self::file(''), 'empty.pdf', $this->now, 'uk') ?? [null, null];
        self::assertNull($r);
        $big = self::file('%PDF-' . str_repeat('x', ProofFile::MAX_BYTES));
        [$r, $e] = $proofs->upload($this->ctx, $iid, 1, 'olena@example.org', $big, 'big.pdf', $this->now, 'uk') ?? [null, null];
        self::assertNull($r);
        self::assertSame(['plugins.generic.conferenceManager.proof.error.tooLarge'], $e?->toArray()['file'] ?? null);

        // Found by email too (an author without an account).
        [$r, $e, $warnings] = $proofs->upload($this->ctx, $iid, null, 'Olena@Example.org', $pdf, '../../etc/Квитанція "1".PDF', $this->now, 'uk') ?? [null, null, null];
        self::assertSame([], $e?->toArray());
        self::assertSame([], $warnings);
        self::assertSame('Квитанція 1.pdf', $r['name'] ?? null);
        self::assertSame('application/pdf', $r['mime'] ?? null);
        self::assertSame('pending', $r['status'] ?? null);
        $row = $this->db->table('conf_payment_proofs')->first();
        self::assertSame(hash('sha256', "%PDF-1.4\n% receipt 1\n"), $row->sha256);
        self::assertStringStartsWith('1/' . $iid . '/', (string) $row->file_path);
        self::assertFileExists($dir . '/' . $row->file_path);
        self::assertContains('proof_uploaded', array_column($this->invoices->events($this->ctx, $iid), 'type'));

        // The same file again for the same invoice: refused.
        [$r, $e] = $proofs->upload($this->ctx, $iid, 1, 'olena@example.org', $pdf, 'again.pdf', $this->now, 'uk') ?? [null, null];
        self::assertNull($r);
        self::assertSame(['plugins.generic.conferenceManager.proof.error.sameInvoice'], $e?->toArray()['file'] ?? null);

        // A PNG is fine; the payer sees both receipts.
        [$r] = $proofs->upload($this->ctx, $iid, 1, 'olena@example.org', self::file("\x89PNG\r\n\x1A\n" . 'data'), 'photo.jpeg', $this->now, 'uk') ?? [null];
        self::assertSame('photo.png', $r['name'] ?? null);
        $mine = $proofs->withProofs($this->ctx, $this->invoices->mine($this->ctx, 1, 'olena@example.org', 'uk'), 'uk');
        self::assertCount(2, $mine[0]['proofs']);
        self::assertTrue($mine[0]['canUploadProof']);

        // Only the payer or a manager gets the file.
        $proofId = (int) $row->id;
        self::assertSame("%PDF-1.4\n% receipt 1\n", $proofs->file($this->ctx, $proofId, [1, ''])['content'] ?? null);
        self::assertNull($proofs->file($this->ctx, $proofId, [2, 'other@example.org']));
        self::assertNotNull($proofs->file($this->ctx, $proofId, null));
        self::assertNull($proofs->file(new ContextInfo(2, 'uk', ['uk']), $proofId, null));
    }

    public function testReceiptReviewQueue(): void
    {
        [$editionId, $invoice] = $this->openInvoice();
        [$proofs, $notifier] = $this->proofService();
        $iid = (int) $invoice->id;
        $pdf = self::file("%PDF-1.7\nbank order 77\n");
        [$first] = $proofs->upload($this->ctx, $iid, 1, 'olena@example.org', $pdf, 'order.pdf', $this->now, 'uk') ?? [null];

        $queue = $proofs->queue($this->ctx, $editionId, ProofStatus::Pending, 30, 0, 'uk');
        self::assertSame(1, $queue['itemsMax']);
        self::assertSame('2027-00001', $queue['items'][0]['invoice']['number']);
        self::assertSame(15000, $queue['items'][0]['invoice']['due']['minor']);
        self::assertSame([], $queue['items'][0]['duplicateOf']);
        self::assertSame(0, $proofs->queue(new ContextInfo(2, 'uk', ['uk']), null, ProofStatus::Pending, 30, 0, 'uk')['itemsMax']);

        // Rejecting needs a reason; the payer gets a letter with it.
        self::assertTrue($proofs->reject($this->ctx, (int) $first['id'], '  ', 3, $this->now)?->has('note'));
        self::assertSame([], $proofs->reject($this->ctx, (int) $first['id'], 'Не та сума', 3, $this->now)?->toArray());
        self::assertSame([false, 'Не та сума'], [$notifier->sent[0][1], $notifier->sent[0][2]]);
        self::assertTrue($proofs->reject($this->ctx, (int) $first['id'], 'ще раз', 3, $this->now)?->has('status'), 'reviewed once');
        self::assertTrue($proofs->accept($this->ctx, (int) $first['id'], null, 3, $this->now)?->has('status'));
        self::assertSame(0, $proofs->queue($this->ctx, $editionId, ProofStatus::Pending, 30, 0, 'uk')['itemsMax']);

        // After a rejection the same file may be sent again.
        [$second, , $warnings] = $proofs->upload($this->ctx, $iid, 1, 'olena@example.org', $pdf, 'order.pdf', $this->now, 'uk') ?? [null, null, null];
        self::assertNotNull($second);
        self::assertSame(['duplicate'], $warnings, 'the same content was seen before');

        // A wrong amount is refused before anything changes.
        self::assertTrue($proofs->accept($this->ctx, (int) $second['id'], 'abc', 3, $this->now)?->has('amount'));
        self::assertSame('pending', $proofs->get($this->ctx, (int) $second['id'], 'uk')['status'] ?? null);

        // Accepting marks the invoice paid for what is due and sends a letter.
        self::assertSame([], $proofs->accept($this->ctx, (int) $second['id'], null, 3, $this->now)?->toArray());
        $paid = $this->invoices->find($this->ctx, $iid);
        self::assertSame(InvoiceStatus::Paid, $paid?->status);
        self::assertSame(15000, $paid->paidAmount()->minor);
        self::assertTrue($notifier->sent[1][1]);
        self::assertSame(['proof_uploaded', 'proof_rejected', 'proof_uploaded', 'proof_accepted', 'paid'], array_values(array_filter(
            array_column($this->invoices->events($this->ctx, $iid), 'type'),
            static fn (string $t): bool => $t !== 'issued',
        )));
        // A paid invoice takes no more receipts.
        [$r, $e] = $proofs->upload($this->ctx, $iid, 1, 'olena@example.org', self::file('%PDF-new'), 'n.pdf', $this->now, 'uk') ?? [null, null];
        self::assertNull($r);
        self::assertTrue($e?->has('status'));
        self::assertFalse($proofs->withProofs($this->ctx, $this->invoices->mine($this->ctx, 1, 'olena@example.org', 'uk'), 'uk')[0]['canUploadProof']);

        // The journal of the edition shows all of it, newest first.
        $journal = $proofs->journal($this->ctx, $editionId, 50, 0, 'uk');
        self::assertSame('paid', $journal['items'][0]['type'] ?? null);
        self::assertSame(15000, $journal['items'][0]['amount']['minor'] ?? null);
        self::assertSame('issued', $journal['items'][$journal['itemsMax'] - 1]['type'] ?? null);
        self::assertNull($proofs->journal(new ContextInfo(2, 'uk', ['uk']), $editionId, 50, 0, 'uk'));
    }

    public function testPartPaymentByReceipt(): void
    {
        [, $invoice] = $this->openInvoice();
        [$proofs] = $this->proofService();
        [$p] = $proofs->upload($this->ctx, (int) $invoice->id, 1, 'olena@example.org', self::file("\xFF\xD8\xFF\xE0 jpeg"), 'scan.jpg', $this->now, 'uk') ?? [null];
        self::assertSame([], $proofs->accept($this->ctx, (int) $p['id'], '100', 3, $this->now)?->toArray());
        $after = $this->invoices->find($this->ctx, (int) $invoice->id);
        self::assertSame(InvoiceStatus::Issued, $after?->status);
        self::assertSame(5000, $after->due()->minor);
    }

    public function testRegisterFilterByPayment(): void
    {
        [$id, $invoice] = $this->openInvoice();
        [$proofs] = $this->proofService();
        $cats = array_column(array_map(static fn ($c): array => [$c->code, (int) $c->id], $this->fees->categories($this->ctx, $id) ?? []), 1, 0);
        $paidP = $this->listener($id, $cats['other_faculty'], 2, 'paid@example.org');
        $paid = $this->invoices->issueFor($this->ctx, $paidP, null, 1, $this->now)['invoice'];
        $this->invoices->markPaid($this->ctx, (int) $paid?->id, null, null, 3, $this->now);
        $this->listener($id, $cats['students'], 3, 'free@example.org');

        $emails = function (?PaymentFilter $f) use ($id): array {
            $page = $this->participants->register($this->ctx, $id, new ParticipantFilter(payment: $f), 'uk') ?? ['items' => []];
            $out = array_column($page['items'], 'email');
            sort($out);
            return $out;
        };
        self::assertSame(['paid@example.org'], $emails(PaymentFilter::Paid));
        self::assertSame(['olena@example.org'], $emails(PaymentFilter::Unpaid));
        self::assertSame([], $emails(PaymentFilter::ProofPending));
        self::assertSame(['free@example.org'], $emails(PaymentFilter::None));
        $proofs->upload($this->ctx, (int) $invoice->id, 1, 'olena@example.org', self::file('%PDF-f'), 'f.pdf', $this->now, 'uk');
        self::assertSame(['olena@example.org'], $emails(PaymentFilter::ProofPending));
        self::assertCount(3, $emails(null));
    }

    /**
     * FEE-13: the payment journal is only ever appended to. The whole flow
     * of invoices and receipts runs, then every row written before is
     * still there unchanged, and no code updates or deletes the table.
     */
    public function testPaymentJournalIsAppendOnly(): void
    {
        [$editionId, $cats] = $this->edition('second');
        $this->rule($editionId, ['mode' => 'bank_transfer', 'paymentAccountId' => $this->bankId]);
        $this->tariff($editionId, $cats['other_faculty'], '150');
        [$proofs] = $this->proofService();
        $snapshot = fn (): array => array_map(static fn (object $r): array => (array) $r, $this->db->table('conf_payment_events')->orderBy('id')->get()->all());

        $p1 = $this->listener($editionId, $cats['other_faculty'], 1, 'olena@example.org');
        $i1 = (int) $this->invoices->issueFor($this->ctx, $p1, null, 1, $this->now)['invoice']?->id;
        $before = $snapshot();
        $p2 = $this->listener($editionId, $cats['other_faculty'], 2, 'taras@example.org');
        $i2 = (int) $this->invoices->issueFor($this->ctx, $p2, null, 1, $this->now)['invoice']?->id;
        $this->invoices->markPaid($this->ctx, $i1, '50', null, 3, $this->now);
        [$proof] = $proofs->upload($this->ctx, $i1, 1, 'olena@example.org', self::file('%PDF-1 j'), 'a.pdf', $this->now, 'uk') ?? [null];
        $proofs->reject($this->ctx, (int) $proof['id'], 'нечитабельно', 3, $this->now);
        $this->invoices->waive($this->ctx, $i2, 'пільга', 3, $this->now);
        $this->participants->cancel($this->ctx, $p1, 1, 'olena@example.org', true, $this->now, null);
        $after = $snapshot();

        self::assertGreaterThan(count($before), count($after));
        self::assertSame($before, array_slice($after, 0, count($before)), 'earlier journal rows are unchanged');
        $ids = array_column($after, 'id');
        self::assertSame(range((int) $ids[0], (int) $ids[0] + count($ids) - 1), array_map('intval', $ids), 'no row was deleted');

        $root = dirname(__DIR__, 2) . '/classes';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            if (!$f instanceof \SplFileInfo || $f->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($f->getPathname());
            // Any statement that names the table and then updates, deletes
            // or truncates it, whatever the query builder chain looks like.
            foreach (preg_split('/;\s*\n/', $code) ?: [] as $statement) {
                if (str_contains($statement, "'conf_payment_events")
                    && preg_match('/->\s*(update|delete|truncate|upsert|updateOrInsert|forceDelete)\s*\(|\b(UPDATE|DELETE\s+FROM|TRUNCATE)\b[^;]*conf_payment_events/i', $statement)) {
                    $offenders[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }
        self::assertSame([], $offenders, 'code that changes the payment journal');
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

final class FakeProofNotifier implements ProofNotifier
{
    /** @var list<array{Invoice, bool, ?string}> */
    public array $sent = [];

    public function proofReviewed(ContextInfo $context, Invoice $invoice, array $presented, bool $accepted, ?string $note): void
    {
        $this->sent[] = [$invoice, $accepted, $note];
    }
}
