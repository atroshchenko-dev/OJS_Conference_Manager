<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Queries\UpcomingEditionsQuery;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

final class UpcomingAndWindowsTest extends TestCase
{
    private Connection $db;
    private EditionService $service;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        $this->db->table('journals')->insert(['path' => 'c', 'enabled' => 0]);
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->service = new EditionService(new DbEditionRepository($this->db), JurisdictionRegistry::withBuiltIns(), null, null, new AuditLogger($this->db));
        // The plugin is enabled in journals 1 and 3, not in 2; journal 3 is disabled.
        foreach ([1, 3] as $id) {
            $this->db->table('plugin_settings')->insert(['plugin_name' => 'conferencemanagerplugin', 'context_id' => $id, 'setting_name' => 'enabled', 'setting_value' => '1', 'setting_type' => 'bool']);
        }
    }

    private function create(int $contextId, array $overrides): int
    {
        [$e, $errors] = $this->service->create(new ContextInfo($contextId, 'uk', ['uk']), EditionTest::validInput($overrides));
        self::assertSame([], $errors->toArray());
        return (int) $e?->id;
    }

    public function testUpcomingListsOpenAndPublishedOfEnabledJournals(): void
    {
        $open = $this->create(1, ['status' => 'cfp_open', 'venue' => ['uk' => 'м. львів, вул. Наукова 5']]);
        $this->create(1, ['path' => 'draft']);
        $webinar = $this->create(1, ['path' => 'web', 'status' => 'cfp_open', 'eventType' => 'webinar', 'format' => 'online', 'submissionMode' => 'none', 'paperDeadline' => '', 'startsAt' => '2027-02-01 10:00', 'endsAt' => '2027-02-01 11:00']);
        $this->create(2, ['status' => 'cfp_open']);
        $this->create(3, ['status' => 'cfp_open']);

        $query = new UpcomingEditionsQuery($this->db, 'conferencemanagerplugin');
        $all = $query->find([]);
        self::assertSame(2, $all['itemsMax']);
        self::assertSame([$webinar, $open], array_map(static fn ($e) => $e->id, $all['items']), 'sorted by start date');
        self::assertSame([1], $query->contextIds());
        self::assertSame([$webinar], array_map(static fn ($e) => $e->id, $query->find(['format' => 'online'])['items']));
        // SQLite lowers only ASCII; MariaDB and PostgreSQL are checked in ci/integration/api-smoke.sh.
        self::assertSame([$open], array_map(static fn ($e) => $e->id, $query->find(['q' => 'Львів'])['items']), 'search by city in the venue');
        self::assertSame(0, $query->find(['contextId' => 2])['itemsMax']);
    }

    public function testExpiredCallsAreClosed(): void
    {
        $past = $this->create(1, ['status' => 'cfp_open', 'cfpOpensAt' => '2026-01-01', 'abstractDeadline' => '2026-02-01', 'paperDeadline' => '']);
        $future = $this->create(2, ['path' => 'future', 'status' => 'cfp_open']);
        $now = new DateTimeImmutable('2026-11-15T10:00:00Z');

        self::assertSame(1, $this->service->closeExpiredCalls($now));
        self::assertSame(0, $this->service->closeExpiredCalls($now), 'idempotent');
        $ctx = static fn (int $id) => new ContextInfo($id, 'uk', ['uk']);
        self::assertSame(EditionStatus::CfpClosed, $this->service->get($ctx(1), $past)?->status);
        self::assertSame(EditionStatus::CfpOpen, $this->service->get($ctx(2), $future)?->status);
        self::assertSame(1, $this->db->table('conf_audit_log')->where('action', 'auto_close')->count());
    }
}
