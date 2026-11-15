<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\CertificateService;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Application\PresetLibrary;
use APP\plugins\generic\conferenceManager\classes\Application\SetupService;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeCategory;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRule;
use APP\plugins\generic\conferenceManager\classes\Domain\Preset\Preset;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Files\JsonPresetSource;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbAttendanceRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbCertificateRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbRegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 17.5: the configuration matrix. Each fixture in
 * tests/fixtures/segments/ is a typical institution: its profile, a
 * preset applied through the first setup wizard (INS-08) and what a
 * listener of each category gets. S6 puts the others on 20 contexts of
 * one installation and checks that they do not see each other.
 */
final class SegmentMatrixTest extends TestCase
{
    private Connection $db;
    private FakeSettings $settings;
    private InstitutionProfileService $profiles;
    private EditionService $editions;
    private FeeService $fees;
    private InvoiceService $invoices;
    private ParticipantService $participants;
    private SetupService $setup;
    private int $users = 0;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $registry = JurisdictionRegistry::withBuiltIns();
        $this->settings = new FakeSettings();
        $this->profiles = new InstitutionProfileService(new DbInstitutionRepository($this->db), $registry);
        $profiles = $this->profiles;
        $this->editions = new EditionService(new DbEditionRepository($this->db), $registry);
        $this->fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        $this->invoices = new InvoiceService(
            new DbPaymentRepository($this->db),
            new DbFeeRepository($this->db),
            new DbEditionRepository($this->db),
            new DbParticipantRepository($this->db),
            new DbRegistrationRepository($this->db),
            static fn (ContextInfo $c) => $profiles->getOrDraft($c),
            new FakeInvoiceNotifier(),
            new AuditLogger($this->db),
            static fn (string $key, array $params, string $locale): string => $key,
            $registry,
        );
        $this->participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            new FakeGroups(),
            new AuditLogger($this->db),
        );
        $certificates = new CertificateService(
            new DbCertificateRepository($this->db),
            new DbAttendanceRepository($this->db),
            new DbEditionRepository($this->db),
            new DbRegistrationRepository($this->db),
            new FakeEditorial(),
            $profiles,
            new CountingPdf(),
            new MemoryFiles(),
            dirname(__DIR__, 2) . '/templates/certificate',
            static fn (ContextInfo $c, string $uuid): string => 'https://j.example/verify/' . $uuid,
            $this->settings,
            static fn (string $key, array $params, string $locale): string => $key,
            new AuditLogger($this->db),
        );
        $this->setup = new SetupService(
            new PresetLibrary(new JsonPresetSource(['package' => dirname(__DIR__, 2) . '/presets'])),
            $this->editions,
            $this->fees,
            $this->invoices,
            $certificates,
            static fn (ContextInfo $c) => $profiles->getOrDraft($c),
            $this->settings,
            new AuditLogger($this->db),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function segments(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/fixtures/segments/S[1-5].json') ?: [] as $file) {
            $out[basename($file, '.json')] = [$file];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function read(string $file): array
    {
        return (array) json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @dataProvider segments */
    #[DataProvider('segments')]
    public function testSegment(string $file): void
    {
        $this->runSegment(new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Журнал']), self::read($file));
    }

    public function testProviderWithTwentyContexts(): void
    {
        $s6 = self::read(dirname(__DIR__) . '/fixtures/segments/S6.json');
        $fixtures = [];
        foreach ($s6['segments'] as $code) {
            $fixtures[] = $code === 'generic' ? $s6['generic'] : self::read(dirname(__DIR__) . "/fixtures/segments/$code.json");
        }
        $editionOf = [];
        for ($i = 3; $i <= (int) $s6['contexts']; $i++) {
            $this->db->table('journals')->insert(['path' => "j$i"]);
        }
        for ($i = 1; $i <= (int) $s6['contexts']; $i++) {
            $fixture = $fixtures[($i - 1) % count($fixtures)];
            $generic = $fixture['institution']['jurisdiction'] === 'generic';
            $ctx = $generic ? new ContextInfo($i, 'en', ['en'], ['en' => "Journal $i"]) : new ContextInfo($i, 'uk', ['uk', 'en'], ['uk' => "Журнал $i"]);
            $editionOf[$i] = $this->runSegment($ctx, $fixture);
        }
        // Isolation: each context sees only its own edition, categories and rules.
        foreach ($editionOf as $ctxId => $editionId) {
            $ctx = new ContextInfo($ctxId, 'uk', ['uk']);
            self::assertSame([$editionId], array_map(static fn ($e): ?int => $e->id, $this->editions->list($ctx)['items']));
            $other = $editionOf[$ctxId === 1 ? 2 : 1];
            self::assertNull($this->fees->categories($ctx, $other), "context $ctxId reads categories of another");
            self::assertNull($this->invoices->rules($ctx, $other), "context $ctxId reads payment rules of another");
        }
        self::assertSame(20, (int) $this->db->table('conf_editions')->count());
    }

    public function testWarningsAndRefusals(): void
    {
        $ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Журнал']);
        $s1 = self::read(dirname(__DIR__) . '/fixtures/segments/S1.json');
        unset($s1['institution']['paymentAccounts']);
        [, $errors] = $this->profiles->save($ctx, $s1['institution']);
        self::assertSame([], $errors->toArray());
        $now = new DateTimeImmutable('2026-11-20T10:00:00Z');
        $translate = static fn (string $k, string $l): string => "$l:$k";

        [$edition, , $warnings] = $this->setup->apply($ctx, ['preset' => 'ua-university-private'] + $s1['edition'], $translate, 1, $now);
        self::assertNotNull($edition);
        self::assertSame(['plugins.generic.conferenceManager.setup.warning.account'], array_column($warnings, 'key'), 'no bank account yet');
        self::assertCount(1, $this->invoices->rules($ctx, (int) $edition->id) ?? [], 'only the free rule of the staff');
        // A category name from the locale key in every form language.
        $names = ($this->fees->categories($ctx, (int) $edition->id) ?? [])[0]->name->toArray();
        ksort($names);
        self::assertSame(['en' => 'en:plugins.generic.conferenceManager.fee.preset.own_staff', 'uk' => 'uk:plugins.generic.conferenceManager.fee.preset.own_staff'], $names);

        // The same path again: the edition errors come back, nothing is created.
        [$again, $errors] = $this->setup->apply($ctx, ['preset' => 'ua-university-private'] + $s1['edition'], $translate, 1, $now);
        self::assertNull($again);
        self::assertTrue($errors->has('path'));
        self::assertSame(1, (int) $this->db->table('conf_editions')->count());

        // A preset of another jurisdiction or an unknown one is refused.
        [$none, $errors] = $this->setup->apply($ctx, ['preset' => 'generic-conference'] + $s1['edition'], $translate, 1, $now);
        self::assertNull($none);
        self::assertTrue($errors->has('preset'));
        self::assertNotContains('generic-conference', array_column($this->setup->state($ctx, 1)['presets'], 'code'));
        [$none, $errors] = $this->setup->apply($ctx, ['preset' => '../etc/passwd'] + $s1['edition'], $translate, 1, $now);
        self::assertTrue($errors->has('preset'));
        self::assertSame('ua-university-private', $this->setup->state($ctx, 1)['last']['preset']);
    }

    /**
     * @param array<string, mixed> $fixture
     * @return int the edition id
     */
    private function runSegment(ContextInfo $ctx, array $fixture): int
    {
        [, $errors] = $this->profiles->save($ctx, $fixture['institution']);
        self::assertSame([], $errors->toArray(), 'institution profile');
        $state = $this->setup->state($ctx, 0);
        self::assertContains($fixture['preset'], array_column($state['presets'], 'code'), 'the wizard offers the preset');

        $now = new DateTimeImmutable('2026-11-20T10:00:00Z');
        [$edition, $errors, $warnings] = $this->setup->apply($ctx, ['preset' => $fixture['preset']] + $fixture['edition'], static fn (string $k, string $l): string => "$l:$k", 1, $now);
        self::assertSame([], $errors->toArray(), 'edition from the wizard');
        self::assertNotNull($edition);
        $expect = $fixture['expect'];
        $id = (int) $edition->id;
        foreach ($expect['edition'] as $field => $value) {
            self::assertSame($value, $edition->toArray()[$field], "edition.$field");
        }
        self::assertSame('draft', $edition->status->value, 'the wizard leaves the edition a draft');
        self::assertCount($expect['warnings'], $warnings);
        self::assertSame($expect['vector'], (bool) $this->settings->get($ctx->id, CertificateService::SETTING_VECTOR));
        self::assertSame($fixture['preset'], $this->settings->get($ctx->id, SetupService::SETTING)['preset']);

        $categories = [];
        foreach ($this->fees->categories($ctx, $id) ?? [] as $c) {
            self::assertInstanceOf(FeeCategory::class, $c);
            self::assertNotSame('', (string) $c->name->get($ctx->primaryLocale), "category {$c->code} has a name");
            $categories[$c->code] = (int) $c->id;
        }
        self::assertCount($expect['categories'], $categories);
        self::assertCount($expect['tariffs'], $this->fees->tariffs($ctx, $id) ?? []);
        $rules = [];
        foreach ($this->invoices->rules($ctx, $id) ?? [] as $r) {
            self::assertInstanceOf(PaymentRule::class, $r);
            $rules[$r->categoryId === null ? '*' : (string) array_search($r->categoryId, $categories, true)] = $r->mode->value;
        }
        ksort($rules);
        $want = $expect['rules'];
        ksort($want);
        self::assertSame($want, $rules);

        // Open registration, as the manager does after checking the draft.
        [, $errors] = $this->editions->update($ctx, $id, array_merge(array_intersect_key($edition->toArray(), array_flip(Preset::EDITION_FIELDS)), $fixture['edition'], ['status' => 'cfp_open']));
        self::assertSame([], $errors?->toArray() ?? [], 'opening the edition');
        foreach ($expect['listeners'] as $l) {
            $this->db->table('users')->insert(['note' => 'u']);
            $userId = ++$this->users;
            [$r, $e] = $this->participants->registerListener($ctx, $id, [
                'userId' => $userId, 'email' => "l$userId@example.org",
                'givenName' => [$ctx->primaryLocale => 'Олена'], 'familyName' => [$ctx->primaryLocale => 'Коваль'],
                'affiliation' => 'Заклад', 'country' => 'UA', 'orcid' => null, 'position' => null,
            ], ['categoryId' => $categories[$l['category']], 'participationForm' => $edition->format->value === 'in_person' ? 'in_person' : 'online', 'consent' => true], (new ConsentText('Згода'))->version, $now);
            self::assertSame([], $e->toArray(), "listener of {$l['category']}");
            $result = $this->invoices->issueFor($ctx, (int) $r['participationId'], null, 1, $now);
            self::assertSame($l['result'], $result['result'], "invoice for {$l['category']}");
            if ($l['result'] === InvoiceService::ISSUED) {
                self::assertSame($l['minor'], $result['invoice']?->total->minor);
                self::assertSame($l['currency'], $result['invoice']?->total->currency);
                self::assertSame($l['method'], $result['invoice']?->method);
            }
        }
        return $id;
    }
}
