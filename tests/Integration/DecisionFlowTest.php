<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\BulkDecisionService;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\DecisionQueue;
use APP\plugins\generic\conferenceManager\classes\Application\DecisionRecorder;
use APP\plugins\generic\conferenceManager\classes\Application\DecisionService;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\EditorialDirectory;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceService;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionInfo;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionRegistrationService;
use APP\plugins\generic\conferenceManager\classes\Application\TypeChangeNotifier;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AcceptanceDecision;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\BulkDecision;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\DecisionMailTexts;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbDecisionBatchRepository;
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
    private function edition(): array
    {
        $repo = new DbEditionRepository($this->db);
        $service = new EditionService($repo, JurisdictionRegistry::withBuiltIns());
        [$e, $errors] = $service->create($this->ctx, EditionTest::validInput(['status' => 'cfp_open']));
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
        self::assertSame(10, $db->table('email_templates_default_data')->count(), '5 letters × 2 languages, once');
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

    public function summaries(array $submissionIds, string $locale): array
    {
        $out = [];
        foreach ($submissionIds as $id) {
            if (isset($this->stage[$id])) {
                $out[$id] = ['contextId' => 1, 'stageId' => $this->stage[$id], 'status' => self::STATUS_QUEUED, 'title' => 'Тези ' . $id, 'section' => 'Секція 1', 'authors' => 'Іваненко П.'];
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
