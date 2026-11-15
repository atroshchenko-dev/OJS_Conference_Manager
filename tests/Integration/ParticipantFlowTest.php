<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantGroups;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionInfo;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionRegistrationService;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\MaterialType;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\TariffQuery;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationValidator;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbRegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * FEE-01…02 and REG-05…08 on SQLite: categories and tariffs, listener
 * registration, the participant's own records, cancellation with
 * invoices, the register with filters and export.
 */
final class ParticipantFlowTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private FeeService $fees;
    private ParticipantService $participants;
    private FakeGroups $groups;
    private string $consent;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->db->table('submissions')->insert([['note' => 's1']]);
        $this->db->table('authors')->insert([['note' => 'a1'], ['note' => 'a2']]);
        $this->db->table('users')->insert([['note' => 'u1'], ['note' => 'u2'], ['note' => 'u3']]);

        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en']);
        $this->fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        $this->groups = new FakeGroups();
        $this->participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            $this->groups,
            new AuditLogger($this->db),
        );
        $this->consent = (new ConsentText('Згода'))->version;
        $this->now = new DateTimeImmutable('2027-02-01T10:00:00Z');
    }

    /** @param array<string, mixed> $overrides */
    private function edition(array $overrides = [], int $contextId = 1): int
    {
        $service = new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns());
        [$e, $errors] = $service->create(new ContextInfo($contextId, 'uk', ['uk']), EditionTest::validInput($overrides + [
            'status' => 'cfp_open',
            'registrationClosesAt' => '2027-05-10',
        ]));
        self::assertSame([], $errors->toArray());
        return (int) $e?->id;
    }

    /** @return array<string, int> code => category id */
    private function presets(int $editionId): array
    {
        $out = [];
        $list = $this->fees->applyPreset($this->ctx, $editionId, InstitutionType::University, static fn (string $k, string $l): string => $l . ':' . $k);
        foreach ($list ?? [] as $c) {
            $out[$c->code] = (int) $c->id;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function person(int $userId, string $email, string $family = 'Шевченко'): array
    {
        return [
            'userId' => $userId, 'email' => $email,
            'givenName' => ['uk' => 'Тарас'], 'familyName' => ['uk' => $family],
            'affiliation' => 'Інститут', 'country' => 'UA', 'orcid' => null, 'position' => 'доцент',
        ];
    }

    public function testPresetsAndTariffsThroughTheService(): void
    {
        $id = $this->edition();
        $cats = $this->presets($id);
        self::assertArrayHasKey('own_staff', $cats);
        self::assertArrayHasKey('foreign', $cats);
        // Applying again adds nothing.
        self::assertCount(count($cats), $this->presets($id));

        [$tariff, $errors] = $this->fees->saveTariff($this->ctx, $id, null, [
            'categoryId' => $cats['other_faculty'], 'material' => 'abstract', 'pricing' => 'flat', 'amount' => '150', 'currency' => 'uah',
        ]) ?? [null, null];
        self::assertSame([], $errors?->toArray());
        self::assertSame(15000, $tariff?->amount->minor);

        [, $errors] = $this->fees->saveTariff($this->ctx, $id, null, [
            'categoryId' => $cats['other_faculty'], 'material' => 'abstract', 'pricing' => 'flat', 'amount' => '150,005', 'currency' => 'UAH',
        ]) ?? [null, null];
        self::assertTrue($errors?->has('amount'));

        $quote = $this->fees->quote($this->ctx, $id, new TariffQuery($cats['other_faculty'], MaterialType::Abstract, $this->now));
        self::assertSame(['minor' => 15000, 'currency' => 'UAH'], array_intersect_key($quote?->toArray()['total'] ?? [], ['minor' => 1, 'currency' => 1]));

        // Another context does not see the edition.
        self::assertNull($this->fees->categories(new ContextInfo(2, 'uk', ['uk']), $id));
    }

    public function testListenerRegistrationChecks(): void
    {
        $id = $this->edition();
        $cats = $this->presets($id);
        $person = self::person(1, 'Taras@Example.org');

        [$r, $e] = $this->participants->registerListener($this->ctx, $id, $person, ['participationForm' => 'online', 'consent' => true], $this->consent, $this->now);
        self::assertNull($r);
        self::assertTrue($e->has('categoryId'));

        [$r, $e] = $this->participants->registerListener($this->ctx, $id, $person, ['categoryId' => $cats['students'], 'participationForm' => 'absentee', 'consent' => false], $this->consent, $this->now);
        self::assertNull($r);
        self::assertTrue($e->has('participationForm'));
        self::assertTrue($e->has('consent'));

        [$r, $e] = $this->participants->registerListener($this->ctx, $id, $person, ['categoryId' => $cats['students'], 'participationForm' => 'online', 'consent' => '1'], $this->consent, $this->now);
        self::assertSame([], $e->toArray());
        self::assertNotNull($r);
        self::assertSame([[1, 1]], $this->groups->assigned);

        // One active listener registration per person and edition.
        [$r2, $e] = $this->participants->registerListener($this->ctx, $id, $person, ['categoryId' => $cats['students'], 'participationForm' => 'online', 'consent' => true], $this->consent, $this->now);
        self::assertNull($r2);
        self::assertTrue($e->has('editionId'));

        // Closed registration; an edition of another context.
        [$r3, $e] = $this->participants->registerListener($this->ctx, $id, self::person(2, 'b@example.org'), ['categoryId' => $cats['students'], 'participationForm' => 'online', 'consent' => true], $this->consent, new DateTimeImmutable('2027-05-10T00:00:00Z'));
        self::assertNull($r3);
        self::assertTrue($e->has('editionId'));
        $other = $this->edition(['path' => 'other'], 2);
        [$r4] = $this->participants->registerListener($this->ctx, $other, self::person(2, 'b@example.org'), ['participationForm' => 'online', 'consent' => true], $this->consent, $this->now);
        self::assertNull($r4);

        $row = $this->db->table('conf_participants')->where('id', $r['participantId'])->first();
        self::assertSame('taras@example.org', $row->email);
        self::assertSame('confirmed', $row->confirmation_status);
        self::assertSame($this->consent, $row->consent_version);
        self::assertSame($cats['students'], (int) $row->category_id);
    }

    public function testOwnRecordsCancellationAndInvoices(): void
    {
        $id = $this->edition(['format' => 'in_person']);
        $taras = self::person(1, 'taras@example.org');
        [$mine] = $this->participants->registerListener($this->ctx, $id, $taras, ['consent' => true], $this->consent, $this->now);
        [$theirs] = $this->participants->registerListener($this->ctx, $id, self::person(2, 'olena@example.org', 'Коваль'), ['consent' => true], $this->consent, $this->now);
        self::assertNotNull($mine);
        self::assertNotNull($theirs);

        // An author added before having an account is found by the email.
        $this->db->table('conf_participants')->insert(['context_id' => 1, 'edition_id' => $id, 'email' => 'taras2@example.org', 'confirmation_status' => 'pending', 'created_at' => '2027-01-01 00:00:00', 'updated_at' => '2027-01-01 00:00:00']);
        $pid = (int) $this->db->table('conf_participants')->max('id');
        $this->db->table('conf_participations')->insert(['participant_id' => $pid, 'submission_id' => 1, 'role' => 'coauthor', 'created_at' => '2027-01-01 00:00:00', 'updated_at' => '2027-01-01 00:00:00']);

        $list = $this->participants->myParticipations($this->ctx, 1, 'taras@example.org', 'uk', $this->now);
        self::assertCount(1, $list);
        self::assertTrue($list[0]['canCancel']);
        self::assertSame('in_person', $list[0]['participationForm']);
        self::assertCount(1, $this->participants->myParticipations($this->ctx, 3, 'Taras2@example.org', 'uk', $this->now));
        self::assertSame('Тези 1', $this->participants->myParticipations($this->ctx, 3, 'taras2@example.org', 'uk', $this->now)[0]['submissionTitle']);
        self::assertSame([], $this->participants->myParticipations(new ContextInfo(2, 'uk', ['uk']), 1, 'taras@example.org', 'uk', $this->now));

        // REG-06: another person's participation is refused.
        self::assertSame(ParticipantService::FORBIDDEN, $this->participants->cancel($this->ctx, $theirs['participationId'], 1, 'taras@example.org', false, $this->now)['result']);
        self::assertSame(ParticipantService::NOT_FOUND, $this->participants->cancel(new ContextInfo(2, 'uk', ['uk']), $mine['participationId'], 1, 'taras@example.org', true, $this->now)['result']);

        // Invoices of the participant: one paid, one issued.
        $invoice = static fn (string $status, int $paid): array => ['context_id' => 1, 'edition_id' => $id, 'payer_participant_id' => $mine['participantId'], 'status' => $status, 'paid_minor' => $paid, 'total_minor' => 20000, 'currency' => 'UAH', 'created_at' => '2027-01-20 00:00:00', 'updated_at' => '2027-01-20 00:00:00'];
        $this->db->table('conf_invoices')->insert([$invoice('paid', 20000), $invoice('issued', 0)]);

        $out = $this->participants->cancel($this->ctx, $mine['participationId'], 1, 'taras@example.org', false, $this->now);
        self::assertSame(ParticipantService::CANCELLED, $out['result']);
        self::assertSame(['cancelled' => 1, 'refundRequested' => 1], $out['invoices']);
        self::assertSame(['refund_requested', 'cancelled'], $this->db->table('conf_invoices')->orderBy('id')->pluck('status')->all());
        self::assertSame(2, $this->db->table('conf_payment_events')->count());
        self::assertSame(ParticipantService::ALREADY, $this->participants->cancel($this->ctx, $mine['participationId'], 1, 'taras@example.org', false, $this->now)['result']);

        // After the deadline only a manager can cancel.
        $late = new DateTimeImmutable('2027-05-15T00:00:00Z');
        self::assertFalse($this->participants->myParticipations($this->ctx, 2, 'olena@example.org', 'uk', $late)[0]['canCancel']);
        self::assertSame(ParticipantService::DEADLINE, $this->participants->cancel($this->ctx, $theirs['participationId'], 2, 'olena@example.org', false, $late)['result']);
        self::assertSame(ParticipantService::CANCELLED, $this->participants->cancel($this->ctx, $theirs['participationId'], 99, '', true, $late)['result']);

        // Registering again after cancelling is allowed.
        [$again] = $this->participants->registerListener($this->ctx, $id, $taras, ['consent' => true], $this->consent, $this->now);
        self::assertNotNull($again);
    }

    public function testRegisterFiltersPagingAndExport(): void
    {
        $id = $this->edition();
        $cats = $this->presets($id);
        $repo = new DbParticipantRepository($this->db);
        foreach (range(1, 120) as $i) {
            $repo->addListener(1, $id, self::person($i % 3 + 1, "p$i@example.org", $i % 2 ? 'Шевченко' : 'Franko'), $i % 3 ? $cats['students'] : null, \APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm::Online, $this->consent, $this->now);
        }
        $this->db->table('conf_participants')->where('email', 'p7@example.org')->update(['affiliation' => '=HYPERLINK("x")']);

        $page = $this->participants->register($this->ctx, $id, new ParticipantFilter(count: 500), 'uk');
        self::assertSame(120, $page['itemsMax']);
        self::assertCount(ParticipantFilter::MAX_PAGE, $page['items']);
        self::assertSame('Тарас Шевченко', $page['items'][0]['name']);

        self::assertSame(40, $this->participants->register($this->ctx, $id, new ParticipantFilter(categoryId: 0), 'uk')['itemsMax']);
        self::assertSame(80, $this->participants->register($this->ctx, $id, new ParticipantFilter(categoryId: $cats['students']), 'uk')['itemsMax']);
        self::assertSame(60, $this->participants->register($this->ctx, $id, new ParticipantFilter(search: 'frank'), 'uk')['itemsMax']);
        self::assertSame(1, $this->participants->register($this->ctx, $id, new ParticipantFilter(search: 'p77@'), 'uk')['itemsMax']);
        self::assertSame(0, $this->participants->register($this->ctx, $id, new ParticipantFilter(role: ParticipantRole::Presenter), 'uk')['itemsMax']);
        self::assertSame(120, $this->participants->register($this->ctx, $id, new ParticipantFilter(status: ParticipationStatus::Active), 'uk')['itemsMax']);
        self::assertNull($this->participants->register(new ContextInfo(2, 'uk', ['uk']), $id, new ParticipantFilter(), 'uk'));

        $csv = (string) $this->participants->exportCsv($this->ctx, $id, new ParticipantFilter(), 'uk');
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertCount(121, array_filter(explode("\n", $csv)));
        self::assertStringContainsString("'=HYPERLINK", $csv);

        // The manager sets a category; a category of another edition is refused.
        $first = $page['items'][0]['participationId'];
        self::assertSame([], $this->participants->setCategory($this->ctx, $first, $cats['foreign'])?->toArray());
        $other = $this->edition(['path' => 'other']);
        $otherCats = $this->presets($other);
        self::assertTrue($this->participants->setCategory($this->ctx, $first, $otherCats['foreign'])?->has('categoryId'));
    }

    public function testRegisterOfAThousandParticipantsIsFast(): void
    {
        $id = $this->edition();
        $rows = [];
        foreach (range(1, 1000) as $i) {
            $rows[] = ['context_id' => 1, 'edition_id' => $id, 'email' => "u$i@example.org", 'confirmation_status' => 'confirmed', 'created_at' => '2027-01-01 00:00:00', 'updated_at' => '2027-01-01 00:00:00'];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $this->db->table('conf_participants')->insert($chunk);
        }
        $parts = [];
        foreach ($this->db->table('conf_participants')->pluck('id') as $pid) {
            $parts[] = ['participant_id' => $pid, 'role' => 'listener', 'created_at' => '2027-01-01 00:00:00', 'updated_at' => '2027-01-01 00:00:00'];
        }
        foreach (array_chunk($parts, 200) as $chunk) {
            $this->db->table('conf_participations')->insert($chunk);
        }
        $start = microtime(true);
        $page = $this->participants->register($this->ctx, $id, new ParticipantFilter(search: 'u99', offset: 0), 'uk');
        $page2 = $this->participants->register($this->ctx, $id, new ParticipantFilter(count: 50, offset: 950), 'uk');
        self::assertLessThan(1.0, microtime(true) - $start);
        self::assertSame(11, $page['itemsMax']);
        self::assertSame(1000, $page2['itemsMax']);
        self::assertCount(50, $page2['items']);
    }

    public function testPresenterCategoryInTheQuestionnaire(): void
    {
        $id = $this->edition();
        $cats = $this->presets($id);
        $service = new SubmissionRegistrationService(
            new DbRegistrationRepository($this->db),
            new DbEditionRepository($this->db),
            new FakeSubmissions(),
            null,
            null,
            new DbFeeRepository($this->db),
        );
        $submission = new SubmissionInfo(1, 1, false);
        $service->save($this->ctx, $submission, [
            'editionId' => $id, 'presentationType' => 'oral', 'participationForm' => 'in_person',
            'presenterEmail' => 'petro@example.org', 'consent' => true,
        ], $this->consent, 1, $this->now);

        $errors = $service->validateSubmit($this->ctx, 1, $this->consent, $this->now);
        self::assertTrue($errors->has(RegistrationValidator::FIELD_CATEGORY));

        $service->save($this->ctx, $submission, ['categoryId' => (string) $cats['other_faculty']], $this->consent, 1, $this->now);
        self::assertSame($cats['other_faculty'], $service->get(1)->categoryId);
        self::assertSame([], $service->validateSubmit($this->ctx, 1, $this->consent, $this->now)->toArray());

        $service->onSubmitted($this->ctx, 1, 1, $this->now);
        $presenter = $this->db->table('conf_participants')->where('email', 'petro@example.org')->first();
        $coauthor = $this->db->table('conf_participants')->where('email', 'olena@example.org')->first();
        self::assertSame($cats['other_faculty'], (int) $presenter->category_id);
        self::assertNull($coauthor->category_id);

        // A category in use cannot be deleted.
        self::assertTrue($this->fees->deleteCategory($this->ctx, $id, $cats['other_faculty'])?->has('id'));
    }

    public function testMinorListenerNeedsGuardianConsent(): void
    {
        $id = $this->edition();
        $cats = $this->presets($id);
        $pupil = self::person(3, 'pupil@example.org', 'Іваненко');
        $input = ['categoryId' => $cats['students'], 'participationForm' => 'online', 'consent' => true, 'minor' => '1'];

        [$r, $e] = $this->participants->registerListener($this->ctx, $id, $pupil, $input, $this->consent, $this->now);
        self::assertNull($r);
        self::assertSame(['registration.error.guardianRequired'], array_map(static fn (string $k): string => substr($k, -strlen('registration.error.guardianRequired')), $e->toArray()['guardianConsent'] ?? []));
        self::assertSame(0, $this->db->table('conf_participations')->count());

        [$r] = $this->participants->registerListener($this->ctx, $id, $pupil, $input + ['guardianConsent' => true], $this->consent, $this->now);
        self::assertNotNull($r);
        $row = $this->db->table('conf_participants')->where('email', 'pupil@example.org')->first();
        self::assertTrue((bool) $row->is_minor);
        self::assertNotNull($row->guardian_consent_at);
        // Minimal data: the job position is not kept for a minor.
        self::assertNull($row->position);

        $list = $this->participants->register($this->ctx, $id, new ParticipantFilter(), 'uk')['items'] ?? [];
        self::assertTrue($list[0]['minor'] ?? false);
        self::assertNotNull($list[0]['guardianConsentAt'] ?? null);
    }

    public function testMinorPresenterNeedsGuardianConsent(): void
    {
        $id = $this->edition();
        $cats = $this->presets($id);
        $service = new SubmissionRegistrationService(
            new DbRegistrationRepository($this->db),
            new DbEditionRepository($this->db),
            new FakeSubmissions(),
            null,
            null,
            new DbFeeRepository($this->db),
        );
        $submission = new SubmissionInfo(1, 1, false);
        $service->save($this->ctx, $submission, [
            'editionId' => $id, 'presentationType' => 'oral', 'participationForm' => 'in_person',
            'presenterEmail' => 'petro@example.org', 'consent' => true, 'categoryId' => (string) $cats['students'],
            'presenterMinor' => true,
        ], $this->consent, 1, $this->now);
        self::assertTrue($service->validateSubmit($this->ctx, 1, $this->consent, $this->now)->has(RegistrationValidator::FIELD_GUARDIAN));

        $service->save($this->ctx, $submission, ['guardianConsent' => '1'], $this->consent, 1, $this->now);
        self::assertNotNull($service->get(1)->guardianConsentAt);
        self::assertSame([], $service->validateSubmit($this->ctx, 1, $this->consent, $this->now)->toArray());

        // Unticking "minor" drops the guardian consent time; ticking it
        // again asks for the consent anew.
        $service->save($this->ctx, $submission, ['presenterMinor' => false], $this->consent, 1, $this->now);
        self::assertNull($service->get(1)->guardianConsentAt);
        $service->save($this->ctx, $submission, ['presenterMinor' => true, 'guardianConsent' => true], $this->consent, 1, $this->now);

        $service->onSubmitted($this->ctx, 1, 1, $this->now);
        $presenter = $this->db->table('conf_participants')->where('email', 'petro@example.org')->first();
        $coauthor = $this->db->table('conf_participants')->where('email', 'olena@example.org')->first();
        self::assertTrue((bool) $presenter->is_minor);
        self::assertNotNull($presenter->guardian_consent_at);
        self::assertFalse((bool) $coauthor->is_minor);
    }
}

final class FakeGroups implements ParticipantGroups
{
    /** @var list<array{int, int}> */
    public array $assigned = [];

    public function assignParticipant(int $contextId, int $userId): void
    {
        $this->assigned[] = [$contextId, $userId];
    }
}
