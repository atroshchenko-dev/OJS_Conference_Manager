<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\AccountingExportService;
use APP\plugins\generic\conferenceManager\classes\Application\BulkDecisionService;
use APP\plugins\generic\conferenceManager\classes\Application\CampaignQueue;
use APP\plugins\generic\conferenceManager\classes\Application\CampaignService;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\DashboardService;
use APP\plugins\generic\conferenceManager\classes\Application\DecisionQueue;
use APP\plugins\generic\conferenceManager\classes\Application\DecisionRecorder;
use APP\plugins\generic\conferenceManager\classes\Application\DecisionService;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\EditorialDirectory;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantMailer;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Application\ProgramService;
use APP\plugins\generic\conferenceManager\classes\Application\ReminderService;
use APP\plugins\generic\conferenceManager\classes\Application\SettingsStore;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionInfo;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionRegistrationService;
use APP\plugins\generic\conferenceManager\classes\Application\TypeChangeNotifier;
use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AcceptanceDecision;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\BulkDecision;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\DecisionMailTexts;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\AccountingColumn;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Export\CsvTableWriter;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Export\XlsxTableWriter;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbCampaignRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbDecisionBatchRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionStatistics;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbRegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbReminderRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\classes\Migrations\Steps\Step0008Communication;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * DEC-01…06 on SQLite: what follows an acceptance with a presentation
 * type, the type change with a new invoice, the panel of the submission
 * card and bulk decisions through the queue.
 */
final class DecisionFlowTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private FeeService $fees;
    private InvoiceService $invoices;
    private FakeEditorial $editorial;
    private FakeTypeNotifier $typeNotifier;
    private DecisionService $decisions;
    private DateTimeImmutable $now;
    private int $bankId;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->db->table('users')->insert([['note' => 'u1'], ['note' => 'u2'], ['note' => 'u3']]);
        $this->db->table('submissions')->insert([['note' => 's1'], ['note' => 's2'], ['note' => 's3'], ['note' => 's4']]);
        $this->db->table('authors')->insert([['note' => 'a1'], ['note' => 'a2'], ['note' => 'a3']]);
        $this->db->table('issues')->insert(['issue_id' => 77, 'note' => 'i77']);
        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Журнал']);
        $registry = JurisdictionRegistry::withBuiltIns();
        $profiles = new InstitutionProfileService(new DbInstitutionRepository($this->db), $registry);
        [$profile, $errors] = $profiles->save($this->ctx, [
            'jurisdiction' => 'UA',
            'institutionType' => 'university',
            'ownership' => 'state',
            'legalId' => '02070944',
            'name' => ['uk' => 'Університет'],
            'address' => ['uk' => 'м. Київ, вул. Освіти, 1'],
            'paymentAccounts' => [
                ['kind' => 'bank', 'recipientName' => 'Університет', 'iban' => 'UA213223130000026007233566001', 'bankName' => 'Банк', 'purposeTemplate' => 'Оргвнесок {invoiceNumber}'],
            ],
        ]);
        self::assertSame([], $errors->toArray());
        $this->bankId = (int) ($profile?->activePaymentAccounts()[0]->id ?? 0);
        $this->fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        $translate = static fn (string $key, array $params, string $locale): string => $key;
        $this->invoices = new InvoiceService(
            new DbPaymentRepository($this->db),
            new DbFeeRepository($this->db),
            new DbEditionRepository($this->db),
            new DbParticipantRepository($this->db),
            new DbRegistrationRepository($this->db),
            static fn (ContextInfo $c) => $profiles->getOrDraft($c),
            null,
            new AuditLogger($this->db),
            $translate,
            $registry,
        );
        $this->editorial = new FakeEditorial();
        $this->typeNotifier = new FakeTypeNotifier();
        $this->decisions = new DecisionService(
            new DbRegistrationRepository($this->db),
            new DbEditionRepository($this->db),
            $this->editorial,
            $this->invoices,
            null,
            $this->typeNotifier,
            new AuditLogger($this->db),
            $translate,
        );
        $this->now = new DateTimeImmutable('2027-02-01T10:00:00Z');
    }

    /**
     * An edition with an issue, bank transfer and abstract tariffs that
     * depend on the type: oral 300, poster 200, other types 150.
     *
     * @return array{0: int, 1: int} edition id, category id
     */
    private function edition(array $overrides = []): array
    {
        $repo = new DbEditionRepository($this->db);
        $service = new EditionService($repo, JurisdictionRegistry::withBuiltIns());
        [$e, $errors] = $service->create($this->ctx, EditionTest::validInput($overrides + ['status' => 'cfp_open']));
        self::assertSame([], $errors->toArray());
        $repo->save($e->withIssueId(77));
        $id = (int) $e->id;
        $cats = [];
        foreach ($this->fees->applyPreset($this->ctx, $id, InstitutionType::University, static fn (string $k, string $l): string => $k) ?? [] as $c) {
            $cats[$c->code] = (int) $c->id;
        }
        [, $errors] = $this->invoices->saveRule($this->ctx, $id, null, ['mode' => 'bank_transfer', 'paymentAccountId' => $this->bankId]) ?? [null, null];
        self::assertSame([], $errors?->toArray());
        foreach (['oral' => '300', 'poster' => '200', '' => '150'] as $type => $amount) {
            [, $errors] = $this->fees->saveTariff($this->ctx, $id, null, [
                'categoryId' => $cats['other_faculty'], 'material' => 'abstract', 'pricing' => 'flat', 'amount' => $amount,
                'currency' => 'UAH', 'presentationType' => $type,
            ]) ?? [null, null];
            self::assertSame([], $errors?->toArray(), 'tariff ' . $type);
        }
        return [$id, $cats['other_faculty']];
    }

    /** Submits the abstract with the speaker questionnaire: presenter, co-author. */
    private function submit(int $submissionId, int $editionId, int $categoryId, string $type = 'oral'): void
    {
        $service = new SubmissionRegistrationService(
            new DbRegistrationRepository($this->db),
            new DbEditionRepository($this->db),
            new FakeSubmissions(),
            null,
            null,
            new DbFeeRepository($this->db),
        );
        $consent = (new ConsentText('Згода'))->version;
        $service->save($this->ctx, new SubmissionInfo($submissionId, 1, false), [
            'editionId' => $editionId, 'presentationType' => $type, 'participationForm' => 'in_person',
            'presenterEmail' => 'petro@example.org', 'consent' => true, 'categoryId' => (string) $categoryId,
        ], $consent, 1, $this->now);
        self::assertSame([], $service->validateSubmit($this->ctx, $submissionId, $consent, $this->now)->toArray());
        $service->onSubmitted($this->ctx, $submissionId, 1, $this->now);
        $this->editorial->stage[$submissionId] = EditorialDirectory::STAGE_SUBMISSION;
    }

    public function testAcceptanceSetsTypeIssueAndInvoice(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(1, $edition, $cat, 'oral');

        $r = $this->decisions->onDecision($this->ctx, 1, AcceptanceDecision::Poster->value, 1, $this->now);
        self::assertTrue($r['handled']);
        self::assertTrue($r['issueAssigned']);
        self::assertSame(InvoiceService::ISSUED, $r['invoice']);
        self::assertSame(PresentationType::Poster, (new DbRegistrationRepository($this->db))->findBySubmission(1)?->presentationType, 'the decision sets the type');
        self::assertSame('poster', $this->editorial->types[1]);
        self::assertSame([1 => 77], $this->editorial->issues);
        $invoice = $this->invoices->currentForSubmission(1);
        self::assertSame(20000, $invoice?->total->minor, 'the poster tariff');

        // A second acceptance does not issue a second invoice.
        self::assertSame(InvoiceService::EXISTS, $this->decisions->onDecision($this->ctx, 1, 17, 1, $this->now)['invoice']);
        // Other decisions and submissions outside conferences are left alone.
        self::assertFalse($this->decisions->onDecision($this->ctx, 1, BulkDecision::DECLINE, 1, $this->now)['handled']);
        self::assertFalse($this->decisions->onDecision($this->ctx, 4, 901, 1, $this->now)['handled']);
    }

    public function testCoreAcceptanceKeepsTheRequestedType(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(2, $edition, $cat, 'oral');
        $this->decisions->onDecision($this->ctx, 2, 17, 1, $this->now);
        self::assertSame(PresentationType::Oral, (new DbRegistrationRepository($this->db))->findBySubmission(2)?->presentationType);
        self::assertSame([], $this->editorial->types);
        self::assertSame(30000, $this->invoices->currentForSubmission(2)?->total->minor);
    }

    public function testTypeChangeReissuesAnUnpaidInvoice(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(1, $edition, $cat, 'oral');

        // Only after acceptance.
        $early = $this->decisions->changeType($this->ctx, 1, 'poster', 1, $this->now);
        self::assertInstanceOf(ValidationErrors::class, $early);
        self::assertTrue($early->has('presentationType'));

        $this->decisions->onDecision($this->ctx, 1, AcceptanceDecision::Poster->value, 1, $this->now);
        $this->editorial->stage[1] = EditorialDirectory::STAGE_EDITING;
        $first = $this->invoices->currentForSubmission(1);

        $bad = $this->decisions->changeType($this->ctx, 1, 'keynote', 1, $this->now);
        self::assertInstanceOf(ValidationErrors::class, $bad);

        self::assertSame(['changed' => false, 'invoice' => null], $this->decisions->changeType($this->ctx, 1, 'poster', 1, $this->now));
        self::assertSame([], $this->typeNotifier->sent, 'no letter without a change');

        $r = $this->decisions->changeType($this->ctx, 1, 'oral', 1, $this->now);
        self::assertSame(['changed' => true, 'invoice' => InvoiceService::ISSUED], $r);
        self::assertSame('oral', $this->editorial->types[1]);
        self::assertSame([[1, PresentationType::Poster->labelKey(), PresentationType::Oral->labelKey()]], $this->typeNotifier->sent);
        $second = $this->invoices->currentForSubmission(1);
        self::assertNotSame($first?->id, $second?->id);
        self::assertSame(30000, $second?->total->minor);
        self::assertSame(InvoiceStatus::Cancelled, $this->invoices->find($this->ctx, (int) $first?->id)?->status);

        // Online is priced as "other types": a new invoice again.
        self::assertSame(InvoiceService::ISSUED, $this->decisions->changeType($this->ctx, 1, 'online', 1, $this->now)['invoice'] ?? null);
        $online = $this->invoices->currentForSubmission(1);
        self::assertSame(15000, $online?->total->minor);
        // Absentee costs the same as online: the invoice stays.
        self::assertSame(InvoiceService::KEPT, $this->decisions->changeType($this->ctx, 1, 'absentee', 1, $this->now)['invoice'] ?? null);
        self::assertSame($online?->id, $this->invoices->currentForSubmission(1)?->id);
    }

    public function testPaidInvoiceIsKeptOnTypeChange(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(1, $edition, $cat, 'oral');
        $this->decisions->onDecision($this->ctx, 1, AcceptanceDecision::Oral->value, 1, $this->now);
        $this->editorial->stage[1] = EditorialDirectory::STAGE_PRODUCTION;
        $invoice = $this->invoices->currentForSubmission(1);
        self::assertTrue($this->invoices->markPaid($this->ctx, (int) $invoice?->id, null, null, 1, $this->now)?->isEmpty());

        $r = $this->decisions->changeType($this->ctx, 1, 'poster', 1, $this->now);
        self::assertSame(['changed' => true, 'invoice' => InvoiceService::KEPT], $r);
        self::assertSame($invoice?->id, $this->invoices->currentForSubmission(1)?->id);
        self::assertSame(InvoiceStatus::Paid, $this->invoices->currentForSubmission(1)?->status);
    }

    /** FEE-08: what was paid moves to the new invoice; only the difference is due. */
    public function testReissueCarriesThePaidAmount(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(1, $edition, $cat, 'oral');
        $this->decisions->onDecision($this->ctx, 1, AcceptanceDecision::Oral->value, 1, $this->now);
        $this->editorial->stage[1] = EditorialDirectory::STAGE_EDITING;
        $first = $this->invoices->currentForSubmission(1);
        self::assertSame(30000, $first?->total->minor);
        self::assertTrue($this->invoices->markPaid($this->ctx, (int) $first?->id, '100', null, 1, $this->now)?->isEmpty());
        self::assertNotNull($this->invoices->unpaidForPublication(1), 'FEE-11: part paid still blocks publishing');

        // Part paid, cheaper type: a new invoice for 200 with 100 already paid.
        self::assertSame(['changed' => true, 'invoice' => InvoiceService::ISSUED], $this->decisions->changeType($this->ctx, 1, 'poster', 1, $this->now));
        $second = $this->invoices->currentForSubmission(1);
        self::assertSame(20000, $second?->total->minor);
        self::assertSame(10000, $second?->paidAmount()->minor);
        self::assertSame(10000, $second?->due()->minor);
        self::assertSame($first?->id, $second?->supersedesId);
        self::assertSame(InvoiceStatus::Superseded, $this->invoices->find($this->ctx, (int) $first?->id)?->status);
        $history = $this->invoices->history($this->ctx, (int) $second?->id, 'uk');
        self::assertSame([$second?->id, $first?->id], array_column($history, 'id'), 'the chain, newest first');

        // Nothing changed: no new invoice.
        $same = $this->invoices->reissue($this->ctx, (int) $second?->id, null, 1, $this->now);
        self::assertNull($same[0] ?? null);
        self::assertTrue(($same[1] ?? new ValidationErrors())->has('pages'));

        // Paid in full, then a dearer type: the manager reissues, 100 is due.
        self::assertTrue($this->invoices->markPaid($this->ctx, (int) $second?->id, null, null, 1, $this->now)?->isEmpty());
        self::assertNull($this->invoices->unpaidForPublication(1), 'FEE-11: paid passes');
        self::assertSame(['changed' => true, 'invoice' => InvoiceService::ISSUED], $this->decisions->changeType($this->ctx, 1, 'oral', 1, $this->now));
        $third = $this->invoices->currentForSubmission(1);
        self::assertSame(30000, $third?->total->minor);
        self::assertSame(20000, $third?->paidAmount()->minor);
        self::assertSame(InvoiceStatus::Issued, $third?->status);
        self::assertSame(InvoiceStatus::Superseded, $this->invoices->find($this->ctx, (int) $second?->id)?->status);
        $events = $this->invoices->events($this->ctx, (int) $third?->id);
        self::assertSame(20000, $events[0]['payload']['carried'] ?? null, 'the journal shows the carried payment');

        // Overpaid after a cheaper type: the invoice stays, no refund here.
        self::assertTrue($this->invoices->markPaid($this->ctx, (int) $third?->id, null, null, 1, $this->now)?->isEmpty());
        self::assertSame(['changed' => true, 'invoice' => InvoiceService::KEPT], $this->decisions->changeType($this->ctx, 1, 'poster', 1, $this->now));
        $over = $this->invoices->reissue($this->ctx, (int) $third?->id, null, 1, $this->now);
        self::assertTrue(($over[1] ?? new ValidationErrors())->has('status'));
    }

    /** FEE-11: unpaid blocks, waived and free pass. */
    public function testPublicationWaitsForPayment(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(1, $edition, $cat, 'oral');
        self::assertNull($this->invoices->unpaidForPublication(1), 'no invoice yet');
        $this->decisions->onDecision($this->ctx, 1, AcceptanceDecision::Oral->value, 1, $this->now);
        $invoice = $this->invoices->unpaidForPublication(1);
        self::assertSame(30000, $invoice?->due()->minor);
        [$waiver] = $this->invoices->waive($this->ctx, (int) $invoice?->id, 'Запрошений доповідач', 1, $this->now) ?? [null];
        self::assertNotNull($waiver);
        self::assertNull($this->invoices->unpaidForPublication(1), 'waived passes');
        self::assertNull($this->invoices->unpaidForPublication(4), 'not a conference submission');
    }

    private function exporter(FakeSettings $settings): AccountingExportService
    {
        return new AccountingExportService(
            new DbPaymentRepository($this->db),
            new DbFeeRepository($this->db),
            new DbEditionRepository($this->db),
            $this->invoices,
            $settings,
            new AuditLogger($this->db),
            static fn (string $key, array $params, string $locale): string => $key,
        );
    }

    /** FEE-12: columns of the journal, period, CSV with decimal comma, XLSX. */
    public function testAccountingExport(): void
    {
        [$edition, $cat] = $this->edition();
        foreach ([1 => 'oral', 2 => 'poster', 3 => 'oral'] as $sid => $type) {
            $this->submit($sid, $edition, $cat, $type);
            $this->decisions->onDecision($this->ctx, $sid, 17, 1, $this->now);
        }
        $paid = $this->invoices->currentForSubmission(2);
        $this->invoices->markPaid($this->ctx, (int) $paid?->id, null, 'п/д 15', 1, $this->now->modify('+3 days'));
        $settings = new FakeSettings();
        $export = $this->exporter($settings);

        self::assertCount(count(AccountingColumn::defaults()), $export->columns($this->ctx, 'uk')['selected']);
        self::assertTrue($export->saveColumns($this->ctx, ['nonsense'], 1)->has('columns'));
        self::assertTrue($export->saveColumns($this->ctx, ['number', 'payerName', 'total', 'paid', 'status', 'number', 'x'], 1)->isEmpty());
        self::assertSame(['number', 'payerName', 'total', 'paid', 'status'], $export->columns($this->ctx, 'uk')['selected'], 'known columns, in order, once');

        [$file, $errors] = $export->export($this->ctx, ['editionId' => $edition], new CsvTableWriter(), 'uk', 1);
        self::assertTrue($errors->isEmpty());
        self::assertSame(3, $file['rows'] ?? null);
        $csv = (string) file_get_contents((string) $file['path']);
        unlink((string) $file['path']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        self::assertSame('plugins.generic.conferenceManager.export.column.number;plugins.generic.conferenceManager.export.column.payerName;plugins.generic.conferenceManager.export.column.total;plugins.generic.conferenceManager.export.column.paid;plugins.generic.conferenceManager.export.column.status', $lines[0]);
        self::assertStringContainsString(';300,00;0,00;', $lines[1], 'decimal comma for spreadsheets');
        self::assertStringContainsString(';200,00;200,00;', $lines[2]);

        // Paid in the period: only the paid one.
        [$file] = $export->export($this->ctx, ['dateField' => 'paid', 'from' => '2027-02-04', 'to' => '2027-02-04'], new CsvTableWriter(), 'uk', 1);
        self::assertSame(1, $file['rows'] ?? null);
        unlink((string) $file['path']);
        [$file] = $export->export($this->ctx, ['from' => '2027-03-01'], new CsvTableWriter(), 'uk', 1);
        self::assertSame(0, $file['rows'] ?? null);
        unlink((string) $file['path']);
        [$none, $errors] = $export->export($this->ctx, ['from' => '2027-02-30', 'to' => '1.2.2027'], new CsvTableWriter(), 'uk', 1);
        self::assertNull($none);
        self::assertTrue($errors->has('from') && $errors->has('to'));
        [$none, $errors] = $export->export($this->ctx, ['editionId' => 999], new CsvTableWriter(), 'uk', 1);
        self::assertTrue($errors->has('editionId'));

        if (XlsxTableWriter::isAvailable()) {
            [$file] = $export->export($this->ctx, [], new XlsxTableWriter(), 'uk', 1);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open((string) $file['path']));
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            self::assertNotFalse($zip->getFromName('xl/workbook.xml'));
            $zip->close();
            unlink((string) $file['path']);
            self::assertNotFalse(simplexml_load_string($sheet), 'well-formed sheet');
            self::assertStringContainsString('<c r="C2" s="2"><v>300.00</v></c>', $sheet, 'amounts are numbers');
            self::assertSame(4, substr_count($sheet, '<row '));
        }
        self::assertSame(4, $this->db->table('conf_audit_log')->where('entity', 'export')->count(), 'DAT-05: every export is in the audit log');
    }

    /** FEE-12: 2000 rows well within 30 s. */
    public function testExportOfTwoThousandRows(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(1, $edition, $cat, 'oral');
        $this->decisions->onDecision($this->ctx, 1, 17, 1, $this->now);
        $template = $this->invoices->currentForSubmission(1);
        $repo = new DbPaymentRepository($this->db);
        for ($i = 1; $i < 2000; $i++) {
            $repo->createInvoice($template?->with(['id' => null, 'number' => null, 'numberYear' => null, 'paymentReference' => null]) ?? throw new \LogicException(), 1, $this->now);
        }
        $export = $this->exporter(new FakeSettings());
        foreach ([new CsvTableWriter(), new XlsxTableWriter()] as $writer) {
            if ($writer instanceof XlsxTableWriter && !XlsxTableWriter::isAvailable()) {
                continue;
            }
            $start = microtime(true);
            [$file] = $export->export($this->ctx, ['editionId' => $edition], $writer, 'uk', 1);
            $took = microtime(true) - $start;
            self::assertSame(2000, $file['rows'] ?? null);
            unlink((string) $file['path']);
            self::assertLessThan(30, $took, $writer->extension());
        }
    }

    /** A listener of the edition, with an invoice when the edition has a listener tariff. */
    private function listener(int $editionId, int $categoryId, string $email, string $given): int
    {
        $participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            new FakeGroups(),
            new AuditLogger($this->db),
        );
        [$r, $e] = $participants->registerListener($this->ctx, $editionId, [
            'userId' => $given === 'Іван' ? 3 : 2, 'email' => $email, 'givenName' => ['uk' => $given], 'familyName' => ['uk' => 'Коваль'],
            'affiliation' => 'Ліцей', 'country' => 'UA', 'orcid' => null, 'position' => null,
        ], ['categoryId' => $categoryId, 'participationForm' => 'online', 'consent' => true], (new ConsentText('Згода'))->version, $this->now);
        self::assertSame([], $e->toArray());
        return (int) $r['participationId'];
    }

    private function reminders(FakeMailer $mailer, FakeSettings $settings): ReminderService
    {
        return new ReminderService(new DbEditionRepository($this->db), new DbReminderRepository($this->db), $this->editorial, $mailer, $settings, new AuditLogger($this->db));
    }

    /** COM-03: once, in the window, only to those who have not done it. */
    public function testReminders(): void
    {
        [$edition, $cat] = $this->edition(['paymentDeadline' => '2027-02-10', 'startsAt' => '2027-02-20 10:00', 'endsAt' => '2027-02-21 18:00', 'paperDeadline' => '2027-02-12', 'abstractDeadline' => '2027-02-05']);
        foreach ([1, 2, 3, 4] as $sid) {
            $this->submit($sid, $edition, $cat, 'oral');
        }
        foreach ([1, 2, 3] as $sid) {
            $this->decisions->onDecision($this->ctx, $sid, 17, 1, $this->now);
            $this->editorial->stage[$sid] = EditorialDirectory::STAGE_EDITING;
        }
        $this->invoices->markPaid($this->ctx, (int) $this->invoices->currentForSubmission(2)?->id, null, null, 1, $this->now);
        $this->editorial->fullText[3] = true;
        $this->listener($edition, $cat, 'olena@example.org', 'Олена');

        $mailer = new FakeMailer();
        $settings = new FakeSettings();
        $service = $this->reminders($mailer, $settings);
        $deadline = (new DbEditionRepository($this->db))->find(1, $edition)?->date('paymentDeadline');
        self::assertNotNull($deadline);
        $inWindow = $deadline->modify('-1 day');

        self::assertSame(['payment' => 0, 'paper' => 0, 'event' => 0, 'failed' => 0], $service->run($this->ctx, $inWindow), 'off by default');
        self::assertTrue($service->saveSettings($this->ctx, ['payment' => ['enabled' => true, 'days' => 0]], 1)->has('payment'));
        self::assertTrue($service->saveSettings($this->ctx, [
            'payment' => ['enabled' => true, 'days' => 3], 'paper' => ['enabled' => true, 'days' => 7], 'event' => ['enabled' => true, 'days' => 2],
        ], 1)->isEmpty());

        $early = $service->run($this->ctx, $deadline->modify('-5 days'));
        self::assertSame(0, $early['payment'], 'too early for the payment');
        self::assertSame(2, $early['paper'], 'but within 7 days of the full text deadline: accepted without full text, submissions 1 and 2, not 3 (sent) or 4 (not accepted)');
        $mailer->failFor = ['petro@example.org'];
        $first = $service->run($this->ctx, $inWindow);
        self::assertSame(0, $first['payment']);
        self::assertSame(2, $first['failed'], 'two unpaid invoices of petro, the letter failed');
        $mailer->failFor = [];
        $mailer->sent = [];
        $done = $service->run($this->ctx, $inWindow);
        self::assertSame(2, $done['payment'], 'the failed ones are tried again; the paid one is skipped');
        self::assertSame(0, $done['paper'], 'already reminded');
        self::assertSame(0, $done['event'], 'not yet');
        self::assertSame(ParticipantMailTexts::REMIND_PAYMENT, $mailer->sent[0]['template']);
        self::assertSame('300.00 UAH', $mailer->sent[0]['data']['amountDue']);
        self::assertSame(['payment' => 0, 'paper' => 0, 'event' => 0, 'failed' => 0], $service->run($this->ctx, $inWindow), 'never twice');

        $starts = (new DbEditionRepository($this->db))->find(1, $edition)?->date('startsAt');
        $mailer->sent = [];
        $event = $service->run($this->ctx, $starts?->modify('-1 day') ?? $this->now);
        self::assertSame(2, $event['event'], 'the listener and the presenter of the accepted abstracts, once per person');
        self::assertSame(['petro@example.org', 'olena@example.org'], array_column($mailer->sent, 'email'));
        self::assertSame(['payment' => 2, 'paper' => 2, 'event' => 2], $service->sentCounts($edition));
    }

    private function campaigns(FakeMailer $mailer, FakeCampaignQueue $queue): CampaignService
    {
        return new CampaignService(new DbCampaignRepository($this->db), new DbParticipantRepository($this->db), new DbEditionRepository($this->db), $this->editorial, $mailer, $queue, new AuditLogger($this->db));
    }

    /** COM-02: filters, one letter per person, portions, delivery state. */
    public function testCampaign(): void
    {
        [$edition, $cat] = $this->edition();
        foreach ([1, 2] as $sid) {
            $this->submit($sid, $edition, $cat, 'oral');
        }
        $this->editorial->stage[1] = EditorialDirectory::STAGE_EDITING;
        $this->listener($edition, $cat, 'olena@example.org', 'Олена');
        $this->listener($edition, $cat, 'ivan@example.org', 'Іван');
        $mailer = new FakeMailer();
        $queue = new FakeCampaignQueue();
        $service = $this->campaigns($mailer, $queue);

        self::assertSame(3, $service->preview($this->ctx, $edition, [], 'uk')['count'] ?? null, 'petro is presenter of two abstracts and co-author: one letter');
        self::assertSame(2, $service->preview($this->ctx, $edition, ['role' => 'listener'], 'uk')['count'] ?? null);
        self::assertSame(['petro@example.org', 'olena@example.org'], array_column($service->preview($this->ctx, $edition, ['accepted' => '1'], 'uk')['sample'] ?? [], 'email'), 'presenter and co-author of the accepted abstract');
        self::assertSame(0, $service->preview($this->ctx, $edition, ['section' => 'Інша'], 'uk')['count'] ?? null);
        self::assertSame(2, $service->preview($this->ctx, $edition, ['fullText' => 'no'], 'uk')['count'] ?? null);
        $this->editorial->fullText[1] = true;
        self::assertSame(0, $service->preview($this->ctx, $edition, ['fullText' => 'no'], 'uk')['count'] ?? null);
        self::assertSame(2, $service->preview($this->ctx, $edition, ['fullText' => 'yes'], 'uk')['count'] ?? null);
        self::assertNull($service->preview($this->ctx, 999, [], 'uk'));

        self::assertTrue($service->validate(['subject' => 'Програма', 'body' => 'Привіт, {$recipientName}! {$password}'])->has('body'), 'unknown variable');
        [$none, $errors] = $service->start($this->ctx, $edition, ['subject' => '', 'body' => ''], 1, 'uk', $this->now) ?? [null, null];
        self::assertNull($none);
        self::assertTrue($errors?->has('subject'));

        $test = $service->sendTest($this->ctx, $edition, ['subject' => 'Програма', 'body' => 'Шановна/шановний {$recipientName}'], 'manager@example.org', 'Менеджер', 'uk');
        self::assertTrue($test?->isEmpty());
        self::assertSame('[TEST] Програма', $mailer->sent[0]['template']);
        $mailer->sent = [];

        $mailer->failFor = ['ivan@example.org'];
        [$id, $errors] = $service->start($this->ctx, $edition, ['subject' => 'Програма', 'body' => 'Шановна/шановний {$recipientName}, програма «{$conferenceName}».', 'role' => 'listener'], 1, 'uk', $this->now) ?? [null, null];
        self::assertIsInt($id);
        self::assertSame([[1, $id]], $queue->pushed, 'one portion of up to 50');
        self::assertFalse($service->status($this->ctx, $id)['finished'] ?? true);
        self::assertSame(2, $service->runBatch($this->ctx, $id, 'https://j.example/my', $this->now));
        self::assertSame(0, $service->runBatch($this->ctx, $id, 'https://j.example/my', $this->now), 'nothing is sent twice');
        $status = $service->status($this->ctx, $id);
        self::assertTrue($status['finished'] ?? false);
        self::assertSame(1, $status['counts']['sent'] ?? null);
        self::assertSame(1, $status['counts']['failed'] ?? null);
        self::assertSame('mailbox full', $status['failed'][0]['error'] ?? null);
        self::assertSame('Олена Коваль', $mailer->sent[0]['data']['recipientName']);
        self::assertSame([$id], array_column($service->ofEdition($this->ctx, $edition) ?? [], 'id'));
        self::assertNull($service->status(new ContextInfo(2, 'uk', ['uk']), $id), 'another journal');
    }

    /** RPT-01 and DAT-01. */
    public function testDashboardAndConsentVersions(): void
    {
        [$edition, $cat] = $this->edition();
        foreach ([1, 2, 3, 4] as $sid) {
            $this->submit($sid, $edition, $cat, $sid === 2 ? 'poster' : 'oral');
        }
        $this->decisions->onDecision($this->ctx, 1, AcceptanceDecision::Oral->value, 1, $this->now);
        $this->decisions->onDecision($this->ctx, 2, AcceptanceDecision::Poster->value, 1, $this->now);
        $this->editorial->stage[1] = $this->editorial->stage[2] = EditorialDirectory::STAGE_EDITING;
        $this->editorial->declined = [3];
        $this->invoices->markPaid($this->ctx, (int) $this->invoices->currentForSubmission(2)?->id, null, null, 1, $this->now);
        $this->invoices->markPaid($this->ctx, (int) $this->invoices->currentForSubmission(1)?->id, '50', null, 1, $this->now);
        $this->listener($edition, $cat, 'olena@example.org', 'Олена');

        $stats = new DbEditionStatistics($this->db);
        $dashboard = new DashboardService(new DbEditionRepository($this->db), new DbRegistrationRepository($this->db), $this->editorial, $stats, new DbReminderRepository($this->db), static fn (string $k, array $p, string $l): string => $k);
        $d = $dashboard->edition($this->ctx, $edition, 'uk');
        self::assertNotNull($d);
        self::assertSame(['total' => 4, 'waiting' => 1, 'review' => 0, 'accepted' => 2, 'declined' => 1, 'published' => 0, 'fullText' => 0], array_diff_key($d['submissions'], ['byType' => 1]));
        self::assertSame(['oral' => 1, 'poster' => 1], array_column($d['submissions']['byType'], 'count', 'value'));
        $roles = array_column($d['participants'], 'active', 'role');
        self::assertSame(1, $roles['listener']);
        self::assertSame(4, $roles['presenter']);
        $uah = $d['payments'][0];
        self::assertSame(1, $uah['paid']['count']);
        self::assertSame('200.00', $uah['paid']['amount']['decimal']);
        self::assertSame(1, $uah['open']['count']);
        self::assertSame(1, $uah['open']['partPaid']);
        self::assertSame('250.00', $uah['open']['due']['decimal']);
        self::assertNull($dashboard->edition(new ContextInfo(2, 'uk', ['uk']), $edition, 'uk'));

        $stats->rememberConsent(1, 'aaaa', '{"uk":"Згода"}', $this->now);
        $stats->rememberConsent(1, 'aaaa', '{"uk":"Інша"}', $this->now);
        $stats->rememberConsent(1, (new ConsentText('Згода'))->version, '{"uk":"Згода"}', $this->now);
        $versions = $stats->consentVersions(1);
        self::assertCount(2, $versions, 'a version is kept once');
        self::assertSame(6, $versions[0]['agreements'], 'four submissions, the presenter and the listener');
        self::assertSame('{"uk":"Згода"}', $versions[1]['texts']);
    }

    /** PRG-01: accepted papers by section and presentation type; publishing the program. */
    public function testProgram(): void
    {
        [$edition, $cat] = $this->edition();
        foreach ([1 => 'oral', 2 => 'poster', 3 => 'oral', 4 => 'online'] as $sid => $type) {
            $this->submit($sid, $edition, $cat, $type);
        }
        $this->decisions->onDecision($this->ctx, 1, AcceptanceDecision::Oral->value, 1, $this->now);
        $this->decisions->onDecision($this->ctx, 2, AcceptanceDecision::Poster->value, 1, $this->now);
        $this->decisions->onDecision($this->ctx, 4, AcceptanceDecision::Online->value, 1, $this->now);
        $this->editorial->stage = [1 => EditorialDirectory::STAGE_EDITING, 2 => EditorialDirectory::STAGE_PRODUCTION, 3 => EditorialDirectory::STAGE_SUBMISSION, 4 => EditorialDirectory::STAGE_EDITING];
        $this->editorial->declined = [4];
        $service = new ProgramService(new DbEditionRepository($this->db), new DbRegistrationRepository($this->db), $this->editorial);

        $p = $service->program($this->ctx, $edition, 'uk');
        self::assertSame(2, $p['count'] ?? null, 'waiting and declined papers are not in the program');
        self::assertSame(['Секція 1'], array_column($p['sections'], 'title'));
        self::assertSame(['oral', 'poster'], array_column($p['sections'][0]['groups'], 'type'), 'oral before posters');
        self::assertSame('Тези 1', $p['sections'][0]['groups'][0]['items'][0]['title']);
        self::assertSame('plugins.generic.conferenceManager.program.group.poster', $p['sections'][0]['groups'][1]['labelKey']);
        self::assertNull($service->program(new ContextInfo(2, 'uk', ['uk']), $edition, 'uk'), 'another journal');

        $repo = new DbEditionRepository($this->db);
        $e = $repo->find(1, $edition);
        self::assertFalse($e?->programPublished);
        self::assertFalse(ProgramService::isPublic($e));
        $published = Edition::fromArray(1, array_merge($e->toArray(), $e->toArray()['local'], ['programPublished' => true, 'programText' => ['uk' => 'Пленарне засідання о 10:00']]), $edition, 'Europe/Kyiv', new ValidationErrors());
        $repo->save($published);
        $e = $repo->find(1, $edition);
        self::assertTrue($e?->programPublished);
        self::assertSame('Пленарне засідання о 10:00', $e->text('programText')->resolve('uk', 'uk'));
        self::assertSame(ProgramService::isPublic($e), $e->status !== EditionStatus::Draft);
        self::assertArrayNotHasKey('programPublished', $e->toCopyInput(), 'a copy starts unpublished');
    }

    public function testCommunicationStepInstallsLetters(): void
    {
        $db = $this->db;
        $db->getSchemaBuilder()->create('email_templates_default_data', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->string('email_key');
            $t->string('locale');
            $t->string('name');
            $t->text('subject');
            $t->text('body');
        });
        $db->table('email_templates_default_data')->insert(['email_key' => ParticipantMailTexts::INVOICE, 'locale' => 'uk', 'name' => 'x', 'subject' => 'Наш текст', 'body' => 'b']);
        (new Step0008Communication())->up($db->getSchemaBuilder(), $db);
        (new Step0008Communication())->up($db->getSchemaBuilder(), $db);
        self::assertSame(14, $db->table('email_templates_default_data')->count());
        self::assertSame('Наш текст', $db->table('email_templates_default_data')->where('email_key', ParticipantMailTexts::INVOICE)->where('locale', 'uk')->value('subject'));
        foreach (ParticipantMailTexts::all() as $key => $locales) {
            foreach ($locales as $text) {
                preg_match_all('/\{\$([A-Za-z]+)\}/', $text['subject'] . $text['body'], $m);
                self::assertSame([], array_diff($m[1], [...ParticipantMailTexts::COMMON, ...ParticipantMailTexts::VARIABLES[$key]]), $key . ': only declared variables');
            }
        }
    }

    public function testPanel(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(1, $edition, $cat, 'oral');
        self::assertNull($this->decisions->panel($this->ctx, 4, 'uk'), 'not a conference submission');

        $panel = $this->decisions->panel($this->ctx, 1, 'uk');
        self::assertSame($edition, $panel['edition']['id']);
        self::assertSame('oral', $panel['presentationType']);
        self::assertFalse($panel['canChangeType'], 'before the decision');
        self::assertNull($panel['invoice']);
        self::assertSame(['petro@example.org', 'olena@example.org'], array_column($panel['participants'], 'email'));

        $this->decisions->onDecision($this->ctx, 1, AcceptanceDecision::Oral->value, 1, $this->now);
        $this->editorial->stage[1] = EditorialDirectory::STAGE_EDITING;
        $panel = $this->decisions->panel($this->ctx, 1, 'uk');
        self::assertTrue($panel['canChangeType']);
        self::assertSame('30000', (string) $panel['invoice']['due']['minor']);
        self::assertCount(4, $panel['presentationTypes']);
    }

    /** @return array{0: BulkDecisionService, 1: FakeRecorder, 2: FakeQueue} */
    private function bulk(): array
    {
        $recorder = new FakeRecorder($this->editorial);
        $queue = new FakeQueue();
        $service = new BulkDecisionService(
            new DbDecisionBatchRepository($this->db),
            new DbRegistrationRepository($this->db),
            new DbEditionRepository($this->db),
            $this->editorial,
            $recorder,
            $queue,
            new AuditLogger($this->db),
            static fn (string $key, array $params, string $locale): string => $key,
        );
        return [$service, $recorder, $queue];
    }

    public function testBulkDecisionCandidatesAndValidation(): void
    {
        [$edition, $cat] = $this->edition();
        $this->submit(1, $edition, $cat, 'oral');
        $this->submit(2, $edition, $cat, 'poster');
        $this->submit(3, $edition, $cat, 'oral');
        $this->editorial->stage[3] = EditorialDirectory::STAGE_EDITING;
        [$bulk] = $this->bulk();

        self::assertNull($bulk->candidates($this->ctx, 999, null, [], 'uk'));
        self::assertSame([1, 2], array_column($bulk->candidates($this->ctx, $edition, null, [], 'uk') ?? [], 'submissionId'), 'only abstracts waiting for the first decision');
        self::assertSame([2], array_column($bulk->candidates($this->ctx, $edition, null, ['presentationType' => 'poster'], 'uk') ?? [], 'submissionId'));
        self::assertSame([1], array_column($bulk->candidates($this->ctx, $edition, null, ['search' => 'тези 1'], 'uk') ?? [], 'submissionId'));
        $this->editorial->editorOf = [2];
        self::assertSame([2], array_column($bulk->candidates($this->ctx, $edition, 5, [], 'uk') ?? [], 'submissionId'), 'a section editor sees their submissions');

        $errors = $bulk->start($this->ctx, $edition, 2, [1], 1, true, $this->now);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('decision'), 'core accept is not offered in bulk mode');
        self::assertTrue($bulk->start($this->ctx, $edition, 901, [], 1, true, $this->now)->has('submissionIds'));
        self::assertTrue($bulk->start($this->ctx, $edition, 901, [3], 1, true, $this->now)->has('submissionIds'), 'already decided');
        self::assertTrue($bulk->start($this->ctx, $edition, 901, [1], 5, false, $this->now)->has('submissionIds'), 'not assigned to the editor');
        self::assertTrue($bulk->start($this->ctx, $edition, 901, range(1, BulkDecision::MAX_ITEMS + 1), 1, true, $this->now)->has('submissionIds'));
        self::assertCount(5, $bulk->decisionOptions('uk'));
    }

    public function testBulkDecisionRunsOneJobPerSubmission(): void
    {
        [$edition, $cat] = $this->edition();
        foreach ([1, 2, 3] as $id) {
            $this->submit($id, $edition, $cat, 'oral');
        }
        [$bulk, $recorder, $queue] = $this->bulk();
        $recorder->failFor = [3];

        $r = $bulk->start($this->ctx, $edition, BulkDecision::DECLINE, [1, 2, 3, 2], 1, true, $this->now);
        self::assertIsArray($r);
        $batch = $r['batchId'];
        self::assertSame([[1, $batch, 1], [1, $batch, 2], [1, $batch, 3]], $queue->pushed, 'one job per submission, duplicates dropped');

        $status = $bulk->status($this->ctx, $batch, 'uk');
        self::assertFalse($status['finished']);
        self::assertSame(3, $status['counts'][BulkDecisionService::PENDING]);

        foreach ($queue->pushed as [$context, $b, $submission]) {
            $bulk->runItem($context, $b, $submission, $this->now);
        }
        $bulk->runItem(1, $batch, 1, $this->now);
        self::assertSame([[1, BulkDecision::DECLINE, 1], [2, BulkDecision::DECLINE, 1], [3, BulkDecision::DECLINE, 1]], $recorder->recorded, 'a job run twice records once');

        $status = $bulk->status($this->ctx, $batch, 'uk');
        self::assertTrue($status['finished']);
        self::assertSame(2, $status['counts'][BulkDecisionService::DONE]);
        self::assertSame(1, $status['counts'][BulkDecisionService::FAILED]);
        self::assertSame('wrong stage', $status['items'][2]['error']);
        self::assertSame('Тези 3', $status['items'][2]['title']);
        self::assertNull($bulk->status(new ContextInfo(2, 'uk', ['uk']), $batch, 'uk'), 'another journal');
        self::assertSame([$batch], array_column($bulk->runs($this->ctx, $edition, 'uk') ?? [], 'id'));
    }

    public function testBulkHundredDecisionsAreQueuedAtOnce(): void
    {
        [$edition, $cat] = $this->edition();
        $ids = range(10, 109);
        foreach ($ids as $id) {
            $this->db->table('submissions')->insert(['submission_id' => $id, 'note' => 's' . $id]);
            $this->submit($id, $edition, $cat, 'poster');
        }
        [$bulk, $recorder, $queue] = $this->bulk();
        $started = microtime(true);
        $r = $bulk->start($this->ctx, $edition, AcceptanceDecision::Poster->value, $ids, 1, true, $this->now);
        self::assertIsArray($r);
        self::assertCount(100, $queue->pushed);
        foreach ($queue->pushed as [$context, $b, $submission]) {
            $bulk->runItem($context, $b, $submission, $this->now);
        }
        self::assertCount(100, $recorder->recorded);
        self::assertTrue($bulk->status($this->ctx, $r['batchId'], 'uk')['finished']);
        self::assertLessThan(60, microtime(true) - $started, 'the plugin side of 100 decisions is fast');
    }

    public function testMigrationInstallsLettersWithoutTouchingEditedOnes(): void
    {
        $db = OjsStandIns::connection();
        $db->getSchemaBuilder()->create('email_templates_default_data', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->string('email_key', 255);
            $t->string('locale', 14);
            $t->string('name', 255);
            $t->string('subject', 255);
            $t->text('body');
            $t->unique(['email_key', 'locale']);
        });
        $db->table('email_templates_default_data')->insert(['email_key' => 'CONF_DECISION_ORAL', 'locale' => 'uk', 'name' => 'Свій', 'subject' => 'Свій', 'body' => 'Свій']);
        (new ConferenceSchemaMigration())->usingConnection($db)->up();
        (new ConferenceSchemaMigration())->usingConnection($db)->up();
        self::assertSame(24, $db->table('email_templates_default_data')->count(), '(5 + 7) letters × 2 languages, once');
        self::assertSame('Свій', $db->table('email_templates_default_data')->where('email_key', 'CONF_DECISION_ORAL')->where('locale', 'uk')->value('body'));
        self::assertTrue($db->getSchemaBuilder()->hasTable('conf_decision_batch_items'));
    }

    public function testDefaultLettersHaveBothLanguagesAndVariables(): void
    {
        $all = DecisionMailTexts::all();
        self::assertSame(['CONF_DECISION_ORAL', 'CONF_DECISION_POSTER', 'CONF_DECISION_ONLINE', 'CONF_DECISION_ABSENTEE', 'CONF_TYPE_CHANGED'], array_keys($all));
        foreach ($all as $key => $locales) {
            self::assertSame(['uk', 'en'], array_keys($locales), $key);
            foreach ($locales as $text) {
                self::assertStringContainsString('{$recipientName}', $text['body']);
                self::assertStringContainsString('{$conferenceName}', $text['subject'] . $text['body']);
                self::assertStringContainsString('{$cabinetUrl}', $text['body']);
            }
        }
        self::assertStringContainsString('{$oldPresentationType}', $all['CONF_TYPE_CHANGED']['uk']['body']);
    }
}

final class FakeEditorial implements EditorialDirectory
{
    /** @var array<int, int> */
    public array $stage = [];
    /** @var array<int, string> */
    public array $types = [];
    /** @var array<int, int> */
    public array $issues = [];
    /** @var list<int> */
    public array $editorOf = [];
    /** @var array<int, bool> */
    public array $fullText = [];
    /** @var list<int> */
    public array $declined = [];

    public function summaries(array $submissionIds, string $locale): array
    {
        $out = [];
        foreach ($submissionIds as $id) {
            if (isset($this->stage[$id])) {
                $out[$id] = ['contextId' => 1, 'stageId' => $this->stage[$id], 'status' => in_array($id, $this->declined, true) ? self::STATUS_DECLINED : self::STATUS_QUEUED, 'title' => 'Тези ' . $id, 'section' => 'Секція 1', 'authors' => 'Іваненко П.'];
            }
        }
        return $out;
    }

    public function decidableBy(int $userId, array $submissionIds): array
    {
        return array_values(array_intersect($submissionIds, $this->editorOf));
    }

    public function assignIssue(int $submissionId, int $issueId): bool
    {
        if (isset($this->issues[$submissionId])) {
            return false;
        }
        $this->issues[$submissionId] = $issueId;
        return true;
    }

    public function setPresentationType(int $submissionId, string $presentationType): void
    {
        $this->types[$submissionId] = $presentationType;
    }

    public function hasFullText(array $submissionIds): array
    {
        $out = [];
        foreach ($submissionIds as $id) {
            $out[$id] = $this->fullText[$id] ?? false;
        }
        return $out;
    }

    /** @var list<array{userId: int, email: string, givenName: string, familyName: string, affiliation: string, reviews: int}> */
    public array $reviewers = [];

    public function completedReviewers(array $submissionIds, string $locale): array
    {
        return $this->reviewers;
    }
}

final class FakeMailer implements ParticipantMailer
{
    /** @var list<array{template: string, email: string, data: array<string, string>}> */
    public array $sent = [];
    /** @var list<string> */
    public array $failFor = [];

    public function sendTemplate(ContextInfo $context, string $templateKey, string $email, ?string $name, array $data, string $locale): void
    {
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('mailbox full');
        }
        $this->sent[] = ['template' => $templateKey, 'email' => $email, 'data' => $data];
    }

    public function sendText(ContextInfo $context, string $subject, string $text, string $email, ?string $name, array $data): void
    {
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('mailbox full');
        }
        $this->sent[] = ['template' => $subject, 'email' => $email, 'data' => $data];
    }

    public function cabinetUrl(ContextInfo $context): string
    {
        return 'https://j.example/my';
    }

    public function editionUrl(ContextInfo $context, string $path): string
    {
        return 'https://j.example/' . $path;
    }
}

final class FakeCampaignQueue implements CampaignQueue
{
    /** @var list<array{0: int, 1: int}> */
    public array $pushed = [];

    public function push(int $contextId, int $campaignId, string $cabinetUrl): void
    {
        $this->pushed[] = [$contextId, $campaignId];
    }
}

final class FakeRecorder implements DecisionRecorder
{
    /** @var list<array{0: int, 1: int, 2: int}> */
    public array $recorded = [];
    /** @var list<int> */
    public array $failFor = [];

    public function __construct(private readonly FakeEditorial $editorial)
    {
    }

    public function record(int $contextId, int $submissionId, int $decision, int $editorId): ?string
    {
        $this->recorded[] = [$submissionId, $decision, $editorId];
        if (in_array($submissionId, $this->failFor, true)) {
            return 'wrong stage';
        }
        $this->editorial->stage[$submissionId] = $decision === BulkDecision::DECLINE ? EditorialDirectory::STAGE_SUBMISSION : EditorialDirectory::STAGE_EDITING;
        return null;
    }
}

final class FakeQueue implements DecisionQueue
{
    /** @var list<array{0: int, 1: int, 2: int}> */
    public array $pushed = [];

    public function push(int $contextId, int $batchId, int $submissionId): void
    {
        $this->pushed[] = [$contextId, $batchId, $submissionId];
    }
}

final class FakeTypeNotifier implements TypeChangeNotifier
{
    /** @var list<array{0: int, 1: string, 2: string}> */
    public array $sent = [];

    public function typeChanged(ContextInfo $context, int $submissionId, string $oldLabel, string $newLabel, ?int $editorId): void
    {
        $this->sent[] = [$submissionId, $oldLabel, $newLabel];
    }
}

final class FakeSettings implements SettingsStore
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function get(int $contextId, string $name): mixed
    {
        return $this->values[$contextId . '/' . $name] ?? null;
    }

    public function set(int $contextId, string $name, mixed $value): void
    {
        $this->values[$contextId . '/' . $name] = $value;
    }
}
