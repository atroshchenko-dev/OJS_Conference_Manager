<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ArticleDirectory;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\LegacyUrlService;
use APP\plugins\generic\conferenceManager\classes\Domain\Legacy\LegacyUrlTemplate;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbLegacyUrlRepository;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/** LEG-02, LEG-03: OCS papers matched with articles, old addresses resolved. */
final class LegacyUrlServiceTest extends TestCase
{
    private Connection $db;
    private LegacyUrlService $service;
    private ContextInfo $ctx;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        foreach (range(1, 6) as $id) {
            $this->db->table('submissions')->insert(['submission_id' => $id, 'note' => 's' . $id]);
        }
        $articles = new class () implements ArticleDirectory {
            public function published(int $contextId): array
            {
                return $contextId !== 1 ? [['submissionId' => 6, 'titles' => ['Чужа стаття'], 'year' => 2025]] : [
                    ['submissionId' => 1, 'titles' => ['Освіта <i>і</i> наука', 'Education and science'], 'year' => 2024],
                    ['submissionId' => 2, 'titles' => ['Тези доповіді'], 'year' => 2024],
                    ['submissionId' => 3, 'titles' => ['Тези доповіді'], 'year' => 2025],
                    ['submissionId' => 4, 'titles' => ['Права людини'], 'year' => 2025],
                    ['submissionId' => 5, 'titles' => ['Права людини'], 'year' => 2025],
                ];
            }
        };
        $this->service = new LegacyUrlService(new DbLegacyUrlRepository($this->db), $articles, new FakeSettings(), new AuditLogger($this->db));
        $this->ctx = new ContextInfo(1, 'uk', ['uk']);
    }

    public function testImportAndResolve(): void
    {
        $csv = "\xEF\xBB\xBFpaper_id;conference;sched_conf;title;submission_id\r\n"
            . "101;SRE;SRE-2024;\"Освіта і наука!\";\n"
            . "102;SRE;SRE-2024;Тези доповіді;\n"
            . "103;SRE;SRE-2025;ТЕЗИ ДОПОВІДІ;\n"
            . "104;SRE;SRE-2025;Права людини;\n"
            . "105;SRE;SRE-2025;Права людини;5\n"
            . "106;SRE;SRE-2025;Немає такої;\n"
            . "abc;SRE;SRE-2025;x;\n"
            . "107;SRE;SRE 2025;x;\n"
            . "101;SRE;SRE-2024;x;\n"
            . "108;SRE;SRE-2025;x;6\n";
        $r = $this->service->import($this->ctx, $csv, 1);
        self::assertSame([6, 4], [$r['rows'], $r['matched']]);
        self::assertSame([104], array_column($r['ambiguous'], 'ocsPaperId'));
        self::assertSame([4, 5], $r['ambiguous'][0]['submissionIds']);
        self::assertSame([106], array_column($r['unmatched'], 'ocsPaperId'));
        $k = 'plugins.generic.conferenceManager.legacy.error.';
        self::assertSame([[8, $k . 'id'], [9, $k . 'path'], [10, $k . 'duplicate'], [11, $k . 'submission']], array_map(static fn (array $e): array => [$e['line'], $e['key']], $r['errors']));

        $overview = $this->service->overview($this->ctx);
        self::assertSame([6, 4], [$overview['total'], $overview['matched']]);
        self::assertSame(LegacyUrlTemplate::DEFAULTS, $overview['templates']);

        self::assertSame(['ocsPaperId' => 102, 'submissionId' => 2], $this->service->resolve($this->ctx, '/SRE/SRE-2024/paper/view/102'), 'the year decides');
        self::assertSame(['ocsPaperId' => 103, 'submissionId' => 3], $this->service->resolve($this->ctx, 'https://conf.example.edu/index.php/sre/SRE-2025/paper/download/103/9'));
        self::assertSame(['ocsPaperId' => 104, 'submissionId' => null], $this->service->resolve($this->ctx, '/SRE/SRE-2025/paper/view/104'));
        self::assertSame(['ocsPaperId' => 101, 'submissionId' => null], $this->service->resolve($this->ctx, '/OTHER/SRE-2024/paper/view/101'), 'another conference');
        self::assertNull($this->service->resolve($this->ctx, '/SRE/article/view/1'));
        self::assertSame(['ocsPaperId' => 101, 'submissionId' => null], $this->service->resolve(new ContextInfo(2, 'uk', ['uk']), '/SRE/SRE-2024/paper/view/101'), 'another journal has no papers');

        // A second upload fixes the ambiguous paper; another journal cannot take the same address.
        $r = $this->service->import($this->ctx, "ocs_paper_id,conference,sched_conf,submission_id\n104,SRE,SRE-2025,4\n", 1);
        self::assertSame([1, 1], [$r['rows'], $r['matched']]);
        self::assertSame(5, $this->service->overview($this->ctx)['matched']);
        $r = $this->service->import(new ContextInfo(2, 'uk', ['uk']), "ocs_paper_id,conference,sched_conf,submission_id\n104,SRE,SRE-2025,6\n", 1);
        self::assertSame('plugins.generic.conferenceManager.legacy.error.taken', $r['errors'][0]['key']);
        self::assertSame(4, $this->service->resolve($this->ctx, '/SRE/SRE-2025/paper/view/104')['submissionId'] ?? null);
        self::assertCount(5, $this->service->mapPapers($this->ctx));

        self::assertSame('plugins.generic.conferenceManager.legacy.error.header', $this->service->import($this->ctx, "id,title\n1,x\n")['errors'][0]['key']);
        self::assertSame(6, $this->service->clear($this->ctx, 1));
        self::assertSame(0, $this->service->overview($this->ctx)['total']);
        self::assertSame(4, $this->db->table('conf_audit_log')->where('entity', 'legacy_urls')->count());
    }

    public function testTemplates(): void
    {
        self::assertSame([2 => 'plugins.generic.conferenceManager.legacy.error.template.id'], $this->service->savePatterns($this->ctx, ['archive/{id}', 'archive/x']));
        self::assertSame([], $this->service->savePatterns($this->ctx, ['/archive/{schedConf}/{id}/', ' ']));
        self::assertSame(['archive/{schedConf}/{id}'], $this->service->patterns($this->ctx));
        $this->service->import($this->ctx, "ocs_paper_id,conference,sched_conf,title\n7,SRE,SRE-2024,Тези доповіді\n");
        self::assertSame(['ocsPaperId' => 7, 'submissionId' => 2], $this->service->resolve($this->ctx, '/archive/SRE-2024/7'));
        self::assertNull($this->service->resolve($this->ctx, '/SRE/SRE-2024/paper/view/7'), 'the defaults are replaced');
        self::assertSame([], $this->service->savePatterns($this->ctx, []));
        self::assertSame(LegacyUrlTemplate::DEFAULTS, $this->service->patterns($this->ctx));
    }
}
