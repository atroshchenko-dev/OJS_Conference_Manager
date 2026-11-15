<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\IssueProvisioner;
use APP\plugins\generic\conferenceManager\classes\Application\TibIssueFields;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

final class EditionServiceTest extends TestCase
{
    private Connection $db;
    private EditionService $service;
    /** @var IssueProvisioner&object{created: list<array{int, array<string,string>, ?int}>} */
    private IssueProvisioner $issues;
    private ContextInfo $a;
    private ContextInfo $b;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $db = $this->db;
        $this->issues = new class ($db) implements IssueProvisioner {
            /** @var list<array{int, array<string,string>, ?int}> */
            public array $created = [];

            public function __construct(private Connection $db)
            {
            }

            public function createUnpublishedIssue(int $contextId, array $title, ?int $year): int
            {
                $this->created[] = [$contextId, $title, $year];
                return (int) $this->db->table('issues')->insertGetId(['note' => 'c' . $contextId]);
            }

            public function exists(int $contextId, int $issueId): bool
            {
                return $this->db->table('issues')->where('issue_id', $issueId)->exists();
            }
        };
        $this->service = new EditionService(
            new DbEditionRepository($this->db),
            JurisdictionRegistry::withBuiltIns(),
            new DbInstitutionRepository($this->db),
            $this->issues,
            new AuditLogger($this->db),
        );
        $this->a = new ContextInfo(1, 'uk', ['uk', 'en']);
        $this->b = new ContextInfo(2, 'en', ['en'], [], '', 'America/New_York');
    }

    public function testCreateReadUpdateAndIsolation(): void
    {
        [$saved, $errors] = $this->service->create($this->a, EditionTest::validInput(['name' => ['uk' => 'К', 'de' => 'drop']]), 7, '127.0.0.1');
        self::assertSame([], $errors->toArray());
        self::assertNotNull($saved?->id);
        $id = (int) $saved->id;

        $read = $this->service->get($this->a, $id);
        self::assertNotNull($read);
        self::assertSame(['uk' => 'К'], $read->text('name')->toArray(), 'locales the context does not use are dropped');
        self::assertSame('2027-05-20T06:00:00Z', $read->toArray()['startsAt']);
        self::assertSame($saved->toArray(), $read->toArray());

        self::assertNull($this->service->get($this->b, $id), 'another context cannot see the edition');
        self::assertNull($this->service->update($this->b, $id, EditionTest::validInput()));
        self::assertNull($this->service->delete($this->a, $id + 100));

        $result = $this->service->update($this->a, $id, EditionTest::validInput(['status' => 'cfp_open', 'format' => 'online']));
        self::assertNotNull($result);
        [$updated, $errors] = $result;
        self::assertSame([], $errors->toArray());
        self::assertSame(EditionStatus::CfpOpen, $updated?->status);
        self::assertSame(2, $this->db->table('conf_audit_log')->where('entity', 'edition')->count());

        $list = $this->service->list($this->a, [EditionStatus::CfpOpen]);
        self::assertSame(1, $list['itemsMax']);
        self::assertSame(0, $this->service->list($this->b)['itemsMax']);
    }

    public function testPathIsUniquePerContext(): void
    {
        $this->service->create($this->a, EditionTest::validInput());
        [, $errors] = $this->service->create($this->a, EditionTest::validInput());
        self::assertSame(['plugins.generic.conferenceManager.validation.pathTaken'], $errors->toArray()['path'] ?? null);
        [$other, $errors] = $this->service->create($this->b, EditionTest::validInput(['name' => ['en' => 'C']]));
        self::assertSame([], $errors->toArray(), 'the same path is fine in another context');
        self::assertSame('America/New_York', $this->service->defaultTimezone($this->b));
        self::assertSame('Europe/Kyiv', $this->service->defaultTimezone($this->a), 'UA jurisdiction default for uk');
        self::assertNotNull($other);
    }

    public function testOjsIssueModeCreatesOneIssue(): void
    {
        [$saved] = $this->service->create($this->a, EditionTest::validInput(['publicationMode' => 'ojs_issue']));
        self::assertNotNull($saved?->issueId);
        self::assertCount(1, $this->issues->created);
        self::assertSame(2027, $this->issues->created[0][2]);
        $id = (int) $saved->id;

        // Leaving the mode keeps the link; coming back does not create another issue.
        $this->service->update($this->a, $id, EditionTest::validInput(['publicationMode' => 'none', 'issueId' => 999]));
        [$back] = $this->service->update($this->a, $id, EditionTest::validInput(['publicationMode' => 'ojs_issue'])) ?? [null];
        self::assertSame($saved->issueId, $back?->issueId);
        self::assertCount(1, $this->issues->created);

        // RESTRICT: the OJS issue cannot be deleted while the edition exists.
        $this->expectException(\Throwable::class);
        $this->db->table('issues')->where('issue_id', $saved->issueId)->delete();
    }

    public function testDeleteIsRefusedWhenInUse(): void
    {
        [$e1] = $this->service->create($this->a, EditionTest::validInput());
        [$e2] = $this->service->create($this->a, EditionTest::validInput(['path' => 'conf-2028']));
        $this->db->table('conf_participants')->insert([
            'context_id' => 1, 'edition_id' => $e1?->id, 'email' => 'a@example.org',
            'created_at' => '2027-01-01 00:00:00', 'updated_at' => '2027-01-01 00:00:00',
        ]);
        $this->db->table('publication_settings')->insert([
            'publication_id' => 1, 'setting_name' => 'conferenceEditionId', 'setting_value' => (string) $e2?->id,
        ]);
        self::assertSame(['plugins.generic.conferenceManager.validation.editionInUse'], $this->service->delete($this->a, (int) $e1?->id)?->toArray()['id']);
        self::assertTrue($this->service->delete($this->a, (int) $e2?->id)?->has('id'));

        [$e3] = $this->service->create($this->a, EditionTest::validInput(['path' => 'conf-2029']));
        self::assertSame([], $this->service->delete($this->a, (int) $e3?->id)?->toArray());
        self::assertNull($this->service->get($this->a, (int) $e3?->id));
        self::assertSame(0, $this->db->table('conf_edition_settings')->where('edition_id', $e3?->id)->count(), 'settings cascade');
    }

    public function testCopy(): void
    {
        [$source] = $this->service->create($this->a, EditionTest::validInput(['publicationMode' => 'ojs_issue']));
        $result = $this->service->copy($this->a, (int) $source?->id, [
            'path' => 'conf-2028', 'startsAt' => '2028-05-18 09:00', 'endsAt' => '2028-05-19 18:00',
            'cfpOpensAt' => '2028-01-15', 'abstractDeadline' => '2028-03-01', 'paperDeadline' => '2028-04-01',
        ]);
        self::assertNotNull($result);
        [$copy, $errors] = $result;
        self::assertSame([], $errors->toArray());
        self::assertSame('conf-2028', $copy?->path);
        self::assertSame($source?->text('name')->toArray(), $copy?->text('name')->toArray());
        self::assertNotSame($source?->issueId, $copy?->issueId, 'a copy gets its own issue');
        self::assertNull($copy?->isbn, 'ISBN belongs to one book');
    }

    public function testContextDeletionIsBlockedOnlyByFinancialRecords(): void
    {
        [$e] = $this->service->create($this->b, EditionTest::validInput(['name' => ['en' => 'C']]));
        $this->db->table('conf_participants')->insert([
            'context_id' => 2, 'edition_id' => $e?->id, 'email' => 'a@example.org',
            'created_at' => '2027-01-01 00:00:00', 'updated_at' => '2027-01-01 00:00:00',
        ]);
        $this->db->table('journals')->where('journal_id', 2)->delete();
        self::assertSame(0, $this->db->table('conf_editions')->count());
        self::assertSame(0, $this->db->table('conf_participants')->count());
    }

    /** EDN-10: with TIB Conference on, the issue fields follow the edition and the other way round. */
    public function testTibConferenceFields(): void
    {
        $tib = new class () implements TibIssueFields {
            public bool $on = true;
            /** @var array<int, array<string, string>> */
            public array $issues = [];
            public int $writes = 0;

            public function enabled(int $contextId): bool
            {
                return $this->on;
            }

            public function write(int $contextId, int $issueId, array $fields): void
            {
                $this->writes++;
                $this->issues[$issueId] = array_merge($this->issues[$issueId] ?? [], $fields);
            }
        };
        $service = new EditionService(
            new DbEditionRepository($this->db),
            JurisdictionRegistry::withBuiltIns(),
            new DbInstitutionRepository($this->db),
            $this->issues,
            new AuditLogger($this->db),
            $tib,
        );
        self::assertTrue($service->tibEnabled($this->a));

        // An edition with its own issue writes the fields at once.
        [$saved] = $service->create($this->a, EditionTest::validInput(['publicationMode' => 'ojs_issue', 'venue' => ['uk' => 'Київ, вул. Хрещатик, 1', 'en' => 'Kyiv']]));
        $id = (int) $saved?->id;
        $issueId = (int) $saved?->issueId;
        self::assertSame([
            'conferenceDateBegin' => '2027-05-20',
            'conferenceDateEnd' => '2027-05-21',
            'conferencePlaceStreet' => 'Київ, вул. Хрещатик, 1',
            'conferenceOnline' => '',
        ], $tib->issues[$issueId]);

        // An edition without an issue writes nothing.
        $service->create($this->a, EditionTest::validInput(['path' => 'no-issue']));
        self::assertCount(1, $tib->issues);

        // Edited in the issue form: new dates keep the times of day, the venue and the format follow.
        $writes = $tib->writes;
        $errors = $service->syncFromIssue($this->a, $issueId, [
            'conferenceDateBegin' => '2027-06-02',
            'conferenceDateEnd' => '2027-06-03',
            'conferencePlaceStreet' => 'Львів',
            'conferenceOnline' => '1',
        ]);
        self::assertNotNull($errors);
        self::assertSame([], $errors->toArray());
        $edition = $service->get($this->a, $id);
        self::assertSame('2027-06-02T09:00', $edition?->toArray()['local']['startsAt']);
        self::assertSame('2027-06-03T18:00', $edition?->toArray()['local']['endsAt']);
        self::assertEquals(['uk' => 'Львів', 'en' => 'Kyiv'], $edition?->text('venue')->toArray());
        self::assertSame('online', $edition?->format->value);
        self::assertSame($writes, $tib->writes, 'a change from the issue is not written back');
        self::assertSame(1, $this->db->table('conf_audit_log')->where('entity_id', $id)->where('action', 'tib_sync')->count());

        // Nothing differs, empty or bad dates: nothing changes.
        self::assertNull($service->syncFromIssue($this->a, $issueId, ['conferenceDateBegin' => '2027-06-02', 'conferenceDateEnd' => '', 'conferencePlaceStreet' => 'Львів', 'conferenceOnline' => '1']));
        self::assertNull($service->syncFromIssue($this->a, $issueId, ['conferenceDateBegin' => '02.06.2027']));
        self::assertNull($service->syncFromIssue($this->a, $issueId + 50, ['conferenceDateBegin' => '2027-07-01']));
        self::assertNull($service->syncFromIssue($this->b, $issueId, ['conferenceDateBegin' => '2027-07-01']), 'another context');

        // An end before the start is refused and the edition stays.
        $errors = $service->syncFromIssue($this->a, $issueId, ['conferenceDateEnd' => '2027-05-01']);
        self::assertArrayHasKey('endsAt', $errors?->toArray() ?? []);
        self::assertSame('2027-06-03T18:00', $service->get($this->a, $id)?->toArray()['local']['endsAt']);

        // Edited in the plugin: the issue follows; off in the context: nothing is written or read.
        $service->update($this->a, $id, array_merge($edition?->toArray() ?? [], ['endsAt' => '2027-06-04 17:00', 'format' => 'hybrid']));
        self::assertSame('2027-06-04', $tib->issues[$issueId]['conferenceDateEnd']);
        self::assertSame('', $tib->issues[$issueId]['conferenceOnline']);
        $tib->on = false;
        $writes = $tib->writes;
        $service->update($this->a, $id, array_merge($edition?->toArray() ?? [], ['endsAt' => '2027-06-05 17:00']));
        self::assertSame($writes, $tib->writes);
        self::assertNull($service->syncFromIssue($this->a, $issueId, ['conferenceDateBegin' => '2027-07-01']));
    }
}
