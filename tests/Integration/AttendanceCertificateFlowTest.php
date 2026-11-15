<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\AttendanceService;
use APP\plugins\generic\conferenceManager\classes\Application\CertificateService;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Application\PdfRenderer;
use APP\plugins\generic\conferenceManager\classes\Application\ProofStore;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantFilter;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbAttendanceRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbCertificateRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
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
 * ATT-01, ATT-02, CRT-01…07 on SQLite: marks and a meeting report,
 * eligibility, issue with numbers and snapshots, the PDF and the check.
 */
final class AttendanceCertificateFlowTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private AttendanceService $attendance;
    private CertificateService $certificates;
    private ParticipantService $participants;
    private FakeSettings $settings;
    private MemoryFiles $files;
    private CountingPdf $pdf;
    private DateTimeImmutable $now;
    private int $edition;
    /** @var array<string, int> e-mail => participation id */
    private array $pids = [];

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->db->table('users')->insert([['note' => 'u1'], ['note' => 'u2'], ['note' => 'u3'], ['note' => 'u4']]);
        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Вісник']);
        $this->now = new DateTimeImmutable('2027-05-22T10:00:00Z');
        $this->settings = new FakeSettings();
        $this->files = new MemoryFiles();
        $this->pdf = new CountingPdf();

        [$e] = (new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns()))
            ->create($this->ctx, EditionTest::validInput(['status' => 'cfp_open', 'registrationClosesAt' => '2027-05-10']));
        $this->edition = (int) $e?->id;
        $fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        $cats = [];
        foreach ($fees->applyPreset($this->ctx, $this->edition, InstitutionType::University, static fn (string $k, string $l): string => $k) ?? [] as $c) {
            $cats[$c->code] = (int) $c->id;
        }
        $this->participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            new FakeGroups(),
            new AuditLogger($this->db),
        );
        $consent = (new ConsentText('Згода'))->version;
        foreach (['a@example.org' => 'Андрієнко', 'b@example.org' => 'Бондар', 'c@example.org' => 'Василенко', 'd@example.org' => 'Гнатюк'] as $email => $family) {
            [$r, $errors] = $this->participants->registerListener($this->ctx, $this->edition, [
                'userId' => count($this->pids) + 1, 'email' => $email,
                'givenName' => ['uk' => 'Олена'], 'familyName' => ['uk' => $family],
                'affiliation' => 'Університет', 'country' => 'UA', 'orcid' => null, 'position' => null,
            ], ['categoryId' => $cats['students'], 'participationForm' => 'online', 'consent' => true], $consent, new DateTimeImmutable('2027-02-01T10:00:00Z'));
            self::assertSame([], $errors->toArray());
            $this->pids[$email] = (int) $r['participationId'];
        }

        $this->attendance = new AttendanceService(new DbAttendanceRepository($this->db), new DbEditionRepository($this->db), $this->settings, new AuditLogger($this->db));
        $this->certificates = new CertificateService(
            new DbCertificateRepository($this->db),
            new DbAttendanceRepository($this->db),
            new DbEditionRepository($this->db),
            new DbRegistrationRepository($this->db),
            new FakeEditorial(),
            new InstitutionProfileService(new DbInstitutionRepository($this->db), JurisdictionRegistry::withBuiltIns()),
            $this->pdf,
            $this->files,
            dirname(__DIR__, 2) . '/templates/certificate',
            static fn (ContextInfo $c, string $uuid): string => 'https://j.example/conf/conference/verify/' . $uuid,
            $this->settings,
            static fn (string $key, array $params, string $locale): string => $locale . ':' . $key,
            new AuditLogger($this->db),
        );
    }

    public function testImportPreviewAndApply(): void
    {
        $this->attendance->mark($this->ctx, $this->edition, [$this->pids['b@example.org']], true, 1, $this->now);
        // A Teams report: UTF-16 with a summary above the table, two joins of one person.
        $csv = "1. Summary\nMeeting title\tКонференція\n\n2. Participants\n"
            . "Name\tFirst Join\tLast Leave\tIn-Meeting Duration\tEmail\tRole\n"
            . "Олена Андрієнко\t5/21/27\t5/21/27\t25m 10s\tA@Example.org\tAttendee\n"
            . "Олена Андрієнко\t5/21/27\t5/21/27\t10m\ta@example.org\tAttendee\n"
            . "Олена Бондар\t5/21/27\t5/21/27\t2m\tb@example.org\tAttendee\n"
            . "Гість\t5/21/27\t5/21/27\t1h 2m\tguest@example.com\tAttendee\n"
            . "Без пошти\t5/21/27\t5/21/27\t40m\t\tAttendee\n";
        $utf16 = "\xFF\xFE" . mb_convert_encoding($csv, 'UTF-16LE', 'UTF-8');

        [$preview, $errors] = $this->attendance->import($this->ctx, $this->edition, $utf16, '30', false, 'uk', 1, $this->now) ?? [null, null];
        self::assertSame([], $errors?->toArray());
        self::assertFalse($preview['applied']);
        self::assertSame(1, $preview['present']);
        self::assertSame(1, $preview['skipped']);
        self::assertSame([['email' => 'guest@example.com', 'minutes' => 62]], $preview['unmatched']);
        $byEmail = array_column($preview['matched'], null, 'email');
        self::assertSame(35, $byEmail['a@example.org']['minutes']);
        self::assertTrue($byEmail['a@example.org']['attended']);
        self::assertTrue($byEmail['b@example.org']['keptManual']);
        // Nothing saved yet.
        self::assertSame(1, $this->attendedCount());

        [$applied] = $this->attendance->import($this->ctx, $this->edition, $utf16, 30, true, 'uk', 1, $this->now) ?? [null];
        self::assertTrue($applied['applied']);
        self::assertSame(2, $this->attendedCount(), 'a by import, b kept by hand');
        self::assertSame(30, $this->attendance->threshold($this->ctx));
        $row = $this->db->table('conf_participations')->where('id', $this->pids['a@example.org'])->first();
        self::assertSame(35, (int) $row->attended_minutes);

        $filtered = $this->participants->register($this->ctx, $this->edition, new ParticipantFilter(attended: true), 'uk');
        self::assertSame(2, $filtered['itemsMax'] ?? null);
        self::assertTrue($filtered['items'][0]['attended']);

        [, $bad] = $this->attendance->import($this->ctx, $this->edition, "a,b\n1,2", 0, false, 'uk', 1, $this->now) ?? [null, null];
        self::assertTrue($bad?->has('threshold'));
        self::assertTrue($bad?->has('file') === false);
        [, $bad] = $this->attendance->import($this->ctx, $this->edition, "a,b\n1,2", 30, false, 'uk', 1, $this->now) ?? [null, null];
        self::assertTrue($bad?->has('file'));
        self::assertNull($this->attendance->import(new ContextInfo(2, 'uk', ['uk']), $this->edition, 'x', 30, false, 'uk', 1, $this->now));
    }

    public function testIssueNumbersSnapshotsPdfAndCheck(): void
    {
        $this->attendance->mark($this->ctx, $this->edition, [$this->pids['a@example.org'], $this->pids['c@example.org']], true, 1, $this->now);
        $this->participants->cancel($this->ctx, $this->pids['d@example.org'], 4, 'd@example.org', true, $this->now, null);

        $candidates = array_column($this->certificates->candidates($this->ctx, $this->edition, 'uk') ?? [], null, 'email');
        self::assertSame('listener', $candidates['a@example.org']['type']);
        self::assertSame('notAttended', $candidates['b@example.org']['reason']);
        self::assertSame('cancelled', $candidates['d@example.org']['reason']);

        $out = $this->certificates->issue($this->ctx, $this->edition, array_values($this->pids), 1, $this->now, 'uk') ?? [];
        $byPid = array_column($out, null, 'participationId');
        self::assertSame('CERT-2027-00001', $byPid[$this->pids['a@example.org']]['number']);
        self::assertSame('CERT-2027-00002', $byPid[$this->pids['c@example.org']]['number']);
        self::assertSame('notAttended', $byPid[$this->pids['b@example.org']]['reason']);
        self::assertSame(2, $this->pdf->calls);
        self::assertTrue($this->pdf->landscape);
        self::assertStringContainsString('Олена Василенко', $this->pdf->lastHtml);
        self::assertStringContainsString('data:image/svg+xml;base64,', $this->pdf->lastHtml);
        self::assertCount(2, $this->files->items);

        // Issued once only.
        $again = $this->certificates->issue($this->ctx, $this->edition, [$this->pids['a@example.org']], 1, $this->now, 'uk') ?? [];
        self::assertSame('alreadyIssued', $again[0]['reason']);

        // Another series continues the yearly counter.
        self::assertFalse($this->certificates->saveSeries($this->ctx, 'bad series!', 1)->isEmpty());
        self::assertTrue($this->certificates->saveSeries($this->ctx, 'nau', 1)->isEmpty());
        $this->attendance->mark($this->ctx, $this->edition, [$this->pids['b@example.org']], true, 1, $this->now);
        $third = $this->certificates->issue($this->ctx, $this->edition, [$this->pids['b@example.org']], 1, $this->now, 'uk') ?? [];
        self::assertSame('NAU-2027-00003', $third[0]['number']);

        // The snapshot keeps the document as issued, after the template changes.
        $id = (int) $byPid[$this->pids['a@example.org']]['id'];
        $errors = $this->certificates->saveTemplate($this->ctx, 'listener', '<p>{recipientName} {number} {qrCode}</p>', 1, $this->now);
        self::assertSame([], $errors?->toArray());
        $this->files->items = [];
        $pdf = $this->certificates->pdf($this->ctx, $id);
        self::assertNotNull($pdf);
        self::assertStringContainsString('uk:plugins.generic.conferenceManager.certificate.title.listener', $this->pdf->lastHtml, 'old template re-rendered');

        // Only the holder gets the file.
        $holder = (int) $this->db->table('conf_participations')->where('id', $this->pids['a@example.org'])->value('participant_id');
        self::assertNotNull($this->certificates->pdf($this->ctx, $id, [$holder]));
        self::assertNull($this->certificates->pdf($this->ctx, $id, [$holder + 1]));
        self::assertNull($this->certificates->pdf(new ContextInfo(2, 'uk', ['uk']), $id));
        self::assertCount(1, $this->certificates->mine($this->ctx, [$holder], 'uk'));

        $uuid = (string) $this->db->table('conf_certificates')->where('id', $id)->value('uuid');
        $check = $this->certificates->verify(strtoupper($uuid), 'uk');
        self::assertTrue($check['valid']);
        self::assertSame('CERT-2027-00001', $check['number']);
        self::assertSame('Олена Андрієнко', $check['recipientName']);
        self::assertNull($this->certificates->verify('00000000-0000-4000-8000-000000000000', 'uk'));
        self::assertNull($this->certificates->verify("' or 1=1 --", 'uk'));
    }

    public function testTemplateRulesAndPreview(): void
    {
        $errors = $this->certificates->saveTemplate($this->ctx, 'speaker', '<script>alert(1)</script>{recipientName}', 1, $this->now);
        self::assertTrue($errors?->has('html'));
        $errors = $this->certificates->saveTemplate($this->ctx, 'speaker', '<p>{recipientName} {unknownThing}</p>', 1, $this->now);
        self::assertTrue($errors?->has('html'));
        self::assertNull($this->certificates->saveTemplate($this->ctx, 'nonsense', '<p>x</p>', 1, $this->now));

        [$html] = $this->certificates->preview($this->ctx, 'speaker', '', 'uk') ?? [null];
        self::assertStringContainsString('CERT-2027-00001', (string) $html);
        self::assertStringNotContainsString('{recipientName}', (string) $html);

        self::assertTrue($this->certificates->saveTemplate($this->ctx, 'speaker', '<p>{recipientName}</p>', 1, $this->now)?->isEmpty());
        self::assertTrue($this->certificates->saveTemplate($this->ctx, 'speaker', '<p>{recipientName}!</p>', 1, $this->now)?->isEmpty());
        $speaker = array_column($this->certificates->templates($this->ctx, 'uk'), null, 'type')['speaker'];
        self::assertSame('<p>{recipientName}!</p>', $speaker['custom']);
        self::assertSame(2, $speaker['version']);
        self::assertTrue($this->certificates->saveTemplate($this->ctx, 'speaker', '', 1, $this->now)?->isEmpty());
        self::assertNull(array_column($this->certificates->templates($this->ctx, 'uk'), null, 'type')['speaker']['custom']);
    }

    private function attendedCount(): int
    {
        return $this->db->table('conf_participations')->whereIn('id', array_values($this->pids))->where('attended', true)->count();
    }
}

final class CountingPdf implements PdfRenderer
{
    public int $calls = 0;
    public bool $landscape = false;
    public string $lastHtml = '';

    public function render(string $html, string $title, bool $landscape = false): string
    {
        $this->calls++;
        $this->landscape = $landscape;
        $this->lastHtml = $html;
        return '%PDF-1.4 ' . $title . ' ' . hash('sha256', $html);
    }
}

final class MemoryFiles implements ProofStore
{
    /** @var array<string, string> */
    public array $items = [];

    public function put(string $sourcePath, string $key): bool
    {
        $this->items[$key] = (string) file_get_contents($sourcePath);
        return true;
    }

    public function read(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    public function remove(string $key): void
    {
        unset($this->items[$key]);
    }
}
