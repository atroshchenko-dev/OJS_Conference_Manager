<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\InvitationLetterService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Application\ReportService;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionStatistics;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
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
 * RPT-02 reports by dimension and CSV, RPT-03 scheduler status, COM-04
 * the invitation letter: who can get it, whose it is, the language.
 */
final class ReportsAndLettersTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private ReportService $reports;
    private InvitationLetterService $letters;
    private FakeEditorial $editorial;
    private CountingPdf $pdf;
    /** @var array<mixed> */
    private array $runs = [];
    private int $edition;
    /** @var array<string, int> e-mail => participation id */
    private array $pids = [];
    /** @var array<string, int> e-mail => participant id */
    private array $people = [];

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->db->table('users')->insert([['note' => 'u1'], ['note' => 'u2'], ['note' => 'u3'], ['note' => 'u4']]);
        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Вісник']);
        $profiles = new InstitutionProfileService(new DbInstitutionRepository($this->db), JurisdictionRegistry::withBuiltIns());
        [, $errors] = $profiles->save($this->ctx, [
            'jurisdiction' => 'UA', 'institutionType' => 'university', 'ownership' => 'state', 'legalId' => '02070944',
            'name' => ['uk' => 'Університет освіти', 'en' => 'University of Education'],
            'address' => ['uk' => 'м. Київ, вул. Освіти, 1', 'en' => '1 Osvity St, Kyiv'],
            'signers' => [
                ['fullName' => ['uk' => 'Коваль Олена'], 'position' => ['uk' => 'Ректор'], 'documentTypes' => ['invoice']],
                ['fullName' => ['uk' => 'Петренко Іван', 'en' => 'Ivan Petrenko'], 'position' => ['uk' => 'Голова оргкомітету', 'en' => 'Chair'], 'documentTypes' => ['invitation_letter']],
            ],
        ]);
        self::assertSame([], $errors->toArray());

        [$e] = (new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns()))
            ->create($this->ctx, EditionTest::validInput(['status' => 'cfp_open', 'venue' => ['uk' => 'Київ, корпус 2', 'en' => 'Kyiv, building 2']]));
        $this->edition = (int) $e?->id;
        $fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        $cats = [];
        foreach ($fees->applyPreset($this->ctx, $this->edition, InstitutionType::University, static fn (string $k, string $l): string => 'Категорія ' . $k) ?? [] as $c) {
            $cats[$c->code] = (int) $c->id;
        }
        $participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            new FakeGroups(),
            new AuditLogger($this->db),
        );
        $consent = (new ConsentText('Згода'))->version;
        $people = [
            'a@example.org' => ['Андрієнко', 'Ліцей № 1', 'UA', 'online', 'students'],
            'b@example.org' => ['Бондар', ' ліцей №1 ', 'ua', 'in_person', 'students'],
            'c@example.org' => ['Василенко', 'Університет', 'PL', 'online', 'own_staff'],
            'd@example.org' => ['Гнатюк', '', '', 'online', 'own_staff'],
        ];
        foreach ($people as $email => [$family, $affiliation, $country, $form, $cat]) {
            [$r, $errors] = $participants->registerListener($this->ctx, $this->edition, [
                'userId' => count($this->pids) + 1, 'email' => $email,
                'givenName' => ['uk' => 'Олена', 'en' => 'Olena'], 'familyName' => ['uk' => $family, 'en' => $family],
                'affiliation' => $affiliation, 'country' => $country, 'orcid' => null, 'position' => null,
            ], ['categoryId' => $cats[$cat], 'participationForm' => $form, 'consent' => true], $consent, new DateTimeImmutable('2027-02-01T10:00:00Z'));
            self::assertSame([], $errors->toArray());
            $this->pids[$email] = (int) $r['participationId'];
            $this->people[$email] = (int) $r['participantId'];
        }
        // b is an author of submission 7; d has cancelled; a was present.
        $this->db->table('submissions')->insert(['submission_id' => 7, 'note' => 's7']);
        $this->db->table('conf_participations')->where('id', $this->pids['b@example.org'])->update(['role' => 'presenter', 'submission_id' => 7]);
        $this->db->table('conf_participations')->where('id', $this->pids['d@example.org'])->update(['status' => 'cancelled']);
        $this->db->table('conf_participations')->where('id', $this->pids['a@example.org'])->update(['attended' => 1]);

        $translate = static fn (string $key, array $params, string $locale): string => $locale . ':' . substr($key, strlen('plugins.generic.conferenceManager.'))
            . ($params === [] ? '' : ' ' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->editorial = new FakeEditorial();
        $this->reports = new ReportService(
            new DbEditionRepository($this->db),
            new DbRegistrationRepository($this->db),
            $this->editorial,
            new DbEditionStatistics($this->db),
            new DbFeeRepository($this->db),
            $translate,
            fn (): array => $this->runs,
        );
        $this->letters = new InvitationLetterService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            $this->editorial,
            $profiles,
            $this->pdf = new CountingPdf(),
            $translate,
        );
    }

    public function testParticipantReports(): void
    {
        $r = $this->reports->report($this->ctx, $this->edition, 'affiliations', 'uk');
        self::assertNotNull($r);
        self::assertSame(['active', 'attended', 'cancelled'], array_column($r['columns'], 'value'));
        $rows = array_column($r['rows'], 'counts', 'label');
        // Two spellings of one lyceum are one row.
        self::assertSame(['active' => 2, 'attended' => 1, 'cancelled' => 0], $rows['Ліцей № 1']);
        self::assertSame(['active' => 0, 'attended' => 0, 'cancelled' => 1], $rows['uk:report.none']);
        self::assertSame(['active' => 3, 'attended' => 1, 'cancelled' => 1], $r['totals']);

        $countries = array_column($this->reports->report($this->ctx, $this->edition, 'countries', 'uk')['rows'] ?? [], 'counts', 'label');
        $ua = array_values(array_filter(array_keys($countries), static fn (string $l): bool => str_contains($l, 'UA')));
        self::assertCount(1, $ua);
        self::assertSame(2, $countries[$ua[0]]['active']);

        $roles = array_column($this->reports->report($this->ctx, $this->edition, 'roles', 'uk')['rows'] ?? [], 'counts', 'label');
        self::assertSame(1, $roles['uk:registration.participantRole.presenter']['active'] ?? null, json_encode(array_keys($roles)));
        $forms = array_column($this->reports->report($this->ctx, $this->edition, 'forms', 'uk')['rows'] ?? [], 'counts', 'label');
        self::assertCount(2, $forms);
        $categories = array_column($this->reports->report($this->ctx, $this->edition, 'categories', 'uk')['rows'] ?? [], 'counts', 'label');
        self::assertCount(2, $categories, json_encode(array_keys($categories), JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(3, array_sum(array_column($categories, 'active')));

        self::assertNull($this->reports->report($this->ctx, $this->edition, 'bogus', 'uk'));
        self::assertNull($this->reports->report($this->ctx, 999, 'roles', 'uk'));
        self::assertNull($this->reports->report(new ContextInfo(2, 'uk', ['uk'], []), $this->edition, 'roles', 'uk'));

        $csv = (string) $this->reports->csv($this->ctx, $this->edition, 'affiliations', 'uk');
        self::assertStringStartsWith("\u{FEFF}\"uk:report.dimension.affiliations\";\"uk:report.column.active\"", $csv);
        self::assertStringEndsWith("\"uk:report.total\";\"3\";\"1\";\"1\"\r\n", $csv);
        self::assertCount(7, $this->reports->dimensions('uk'));
    }

    public function testSchedulerStatus(): void
    {
        $now = new DateTimeImmutable('2027-03-01T12:00:00Z');
        self::assertSame(['status' => 'never', 'minutes' => null, 'lastRunAt' => null], $this->reports->scheduler($now));
        $this->runs = ['ReminderTask' => ['at' => '2027-03-01T08:00:00Z', 'ok' => true]];
        self::assertSame('stale', $this->reports->scheduler($now)['status']);
        $this->runs['EditionWindowsTask'] = ['at' => '2027-03-01T11:50:00Z', 'ok' => true];
        self::assertSame(['status' => 'ok', 'minutes' => 10, 'lastRunAt' => '2027-03-01T11:50:00Z'], $this->reports->scheduler($now));
    }

    public function testInvitationLetter(): void
    {
        $now = new DateTimeImmutable('2027-03-01T12:00:00Z');
        $a = $this->pids['a@example.org'];
        // A listener gets the letter; only their own or a manager.
        $own = $this->letters->pdf($this->ctx, $a, [$this->people['a@example.org']], null, $now);
        self::assertNotNull($own);
        self::assertSame('invitation-conf-2027-' . $a . '.pdf', $own['name']);
        $html = $this->pdf->lastHtml;
        self::assertStringContainsString('Університет освіти', $html);
        self::assertStringContainsString('uk:invitationLetter.number {&quot;number&quot;:&quot;conf-2027/' . $a . '&quot;,&quot;date&quot;:&quot;01.03.2027&quot;}', $html);
        self::assertStringContainsString('&quot;dates&quot;:&quot;20.05.2027 – 21.05.2027&quot;', $html);
        self::assertStringContainsString('&quot;venue&quot;:&quot;Київ, корпус 2&quot;', $html);
        self::assertStringContainsString('uk:invitationLetter.text ', $html);
        self::assertStringContainsString('Голова оргкомітету', $html, 'the signer of invitation letters');
        self::assertStringNotContainsString('invitationLetter.paper', $html);
        self::assertStringContainsString('Ліцей № 1', $html);

        self::assertNull($this->letters->pdf($this->ctx, $a, [$this->people['c@example.org']], null, $now), 'another person');
        self::assertNotNull($this->letters->pdf($this->ctx, $a, null, 'en', $now));
        self::assertStringContainsString('University of Education', $this->pdf->lastHtml);
        self::assertStringContainsString('Ivan Petrenko', $this->pdf->lastHtml);
        self::assertStringContainsString('en:invitationLetter.title', $this->pdf->lastHtml);
        self::assertNull($this->letters->pdf(new ContextInfo(2, 'uk', ['uk'], []), $a, null, null, $now), 'another journal');

        // A cancelled participation gets none.
        self::assertNull($this->letters->pdf($this->ctx, $this->pids['d@example.org'], null, null, $now));

        // An author only once the paper is accepted, and not when declined.
        $b = $this->pids['b@example.org'];
        self::assertNull($this->letters->pdf($this->ctx, $b, null, null, $now));
        $this->editorial->stage[7] = 3;
        self::assertNull($this->letters->pdf($this->ctx, $b, null, null, $now), 'still in review');
        $this->editorial->stage[7] = 4;
        self::assertNotNull($this->letters->pdf($this->ctx, $b, null, null, $now));
        self::assertStringContainsString('uk:invitationLetter.paper {', $this->pdf->lastHtml);
        self::assertStringContainsString('Тези 7', $this->pdf->lastHtml);

        $rows = [
            ['participationId' => $a, 'status' => 'active', 'role' => 'listener', 'submissionId' => null],
            ['participationId' => $b, 'status' => 'active', 'role' => 'presenter', 'submissionId' => 7],
            ['participationId' => 99, 'status' => 'active', 'role' => 'coauthor', 'submissionId' => 8],
            ['participationId' => 98, 'status' => 'cancelled', 'role' => 'listener', 'submissionId' => null],
        ];
        self::assertSame([$a, $b], $this->letters->available($this->ctx, $rows));
        $this->editorial->declined = [7];
        self::assertSame([$a], $this->letters->available($this->ctx, $rows));
        self::assertNull($this->letters->pdf($this->ctx, $b, null, null, $now));
    }
}
