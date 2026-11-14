<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real migration and repository on SQLite in memory.
 * MySQL/MariaDB and PostgreSQL are covered by ci/integration/run.sh
 * inside a real OJS installation.
 */
final class SchemaAndRepositoryTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
    }

    private function migrate(): void
    {
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
    }

    public function testMigrationIsIdempotentAndRecordsSteps(): void
    {
        $this->migrate();
        $this->db->table('conf_institutions')->insert(['context_id' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->migrate();
        $this->migrate();

        self::assertSame(1, $this->db->table('conf_institutions')->count());
        $steps = $this->db->table(ConferenceSchemaMigration::STEPS_TABLE)->pluck('step')->all();
        self::assertSame(array_map(static fn ($s) => $s->id(), ConferenceSchemaMigration::discoverSteps()), $steps);
        foreach (['conf_institutions', 'conf_institution_settings', 'conf_signers', 'conf_signer_settings', 'conf_payment_accounts', 'conf_audit_log'] as $table) {
            self::assertTrue($this->db->getSchemaBuilder()->hasTable($table), $table);
        }
    }

    public function testStepIdsAreUniqueAndOrdered(): void
    {
        $ids = array_map(static fn ($s) => $s->id(), ConferenceSchemaMigration::discoverSteps());
        self::assertSame(array_values(array_unique($ids)), $ids);
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids);
    }

    public function testRepositoryRoundTripIsolationAndAudit(): void
    {
        $this->migrate();
        $service = new InstitutionProfileService(
            new DbInstitutionRepository($this->db),
            JurisdictionRegistry::withBuiltIns(),
            new AuditLogger($this->db),
        );
        $a = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'А']);
        $b = new ContextInfo(2, 'en', ['en'], ['en' => 'B']);

        [$saved, $errors] = $service->save($a, [
            'jurisdiction' => 'UA',
            'institutionType' => 'college',
            'ownership' => 'communal',
            'legalId' => '14360570',
            'name' => ['uk' => 'Фаховий коледж', 'en' => 'College'],
            'privacyNotice' => ['uk' => 'Текст про персональні дані'],
            'signers' => [
                ['fullName' => ['uk' => 'Перший'], 'position' => ['uk' => 'Директор'], 'documentTypes' => ['invoice']],
                ['fullName' => ['uk' => 'Другий'], 'position' => ['uk' => 'Заступник'], 'documentTypes' => ['certificate_participation']],
            ],
            'paymentAccounts' => [
                ['kind' => 'bank', 'recipientName' => 'Коледж', 'iban' => 'UA743052990000026007233566001', 'extra' => ['note' => 'x']],
            ],
        ], 7, '127.0.0.1');
        self::assertTrue($errors->isEmpty(), json_encode($errors->toArray()) ?: '');
        self::assertNotNull($saved);

        $reloaded = (new DbInstitutionRepository($this->db))->findByContextId(1);
        self::assertNotNull($reloaded);
        self::assertSame($saved->toArray(), $reloaded->toArray());
        self::assertSame('Фаховий коледж', $reloaded->text('name')->get('uk'));
        self::assertSame(['note' => 'x'], $reloaded->paymentAccounts[0]->extra);
        self::assertNull((new DbInstitutionRepository($this->db))->findByContextId(2), 'context B sees nothing of A');

        // Update: keep first signer (by id), drop second, add a third; replace account.
        $data = $reloaded->toArray();
        $data['signers'] = [$data['signers'][0], ['fullName' => ['uk' => 'Третій'], 'position' => ['uk' => 'Секретар']]];
        $data['paymentAccounts'] = [];
        [$updated] = $service->save($a, $data, 7);
        self::assertNotNull($updated);
        self::assertSame($reloaded->signers[0]->id, $updated->signers[0]->id);
        self::assertSame('Третій', $updated->signers[1]->fullName->get('uk'));
        self::assertSame([], $updated->paymentAccounts);
        self::assertSame(2, $this->db->table('conf_signers')->count());
        self::assertSame(2, $this->db->table('conf_signer_settings')->where('setting_name', 'fullName')->count());

        [$savedB] = $service->save($b, ['jurisdiction' => 'generic', 'name' => ['en' => 'B institute']]);
        self::assertSame(2, $savedB?->contextId);
        self::assertSame('Фаховий коледж', (new DbInstitutionRepository($this->db))->findByContextId(1)?->text('name')->get('uk'));

        $audit = $this->db->table('conf_audit_log')->orderBy('id')->get();
        self::assertSame(['create', 'update', 'create'], $audit->pluck('action')->all());
        self::assertSame(7, (int) $audit[0]->actor_user_id);
        self::assertStringContainsString('Фаховий коледж', (string) $audit[0]->after_data);
    }

    public function testDeletingContextCascadesToProfile(): void
    {
        $this->migrate();
        $service = new InstitutionProfileService(new DbInstitutionRepository($this->db), JurisdictionRegistry::withBuiltIns());
        $service->save(new ContextInfo(2, 'en', ['en']), [
            'jurisdiction' => 'generic',
            'name' => ['en' => 'B'],
            'signers' => [['fullName' => ['en' => 'S'], 'position' => ['en' => 'P']]],
        ]);
        $this->db->table('journals')->where('journal_id', 2)->delete();
        self::assertSame(0, $this->db->table('conf_institutions')->count());
        self::assertSame(0, $this->db->table('conf_signers')->count());
        self::assertSame(0, $this->db->table('conf_signer_settings')->count());
    }
}
