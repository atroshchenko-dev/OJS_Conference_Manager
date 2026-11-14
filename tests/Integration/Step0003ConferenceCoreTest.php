<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

/**
 * Step 0003 (conference core) on SQLite in memory with foreign keys on.
 */
final class Step0003ConferenceCoreTest extends TestCase
{
    private const TABLES = [
        'conf_participants', 'conf_participant_settings', 'conf_participations',
        'conf_fee_categories', 'conf_fee_category_settings', 'conf_tariffs',
        'conf_invoices', 'conf_invoice_items', 'conf_payment_proofs', 'conf_payment_events',
        'conf_rooms', 'conf_sessions', 'conf_session_settings', 'conf_session_chairs', 'conf_session_items',
        'conf_certificate_templates', 'conf_certificates',
        'conf_counters', 'conf_mail_campaigns', 'conf_mail_log', 'conf_legacy_urls',
        'conf_edition_partners', 'conf_edition_partner_settings',
        'conf_payment_rules', 'conf_payment_rule_settings',
    ];

    private const NOW = '2026-01-01 00:00:00';

    private Connection $db;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
    }

    /**
     * Minimal stand-ins for OJS core tables, and for conf_editions while
     * step 0002 may not exist yet (that step skips it via hasTable).
     */
    private function migrate(): void
    {
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
    }

    /**
     * @return array{edition: int, participant: int}
     */
    private function seedParticipant(): array
    {
        $edition = (int) $this->db->table('conf_editions')->insertGetId(['context_id' => 1, 'path' => 'e' . uniqid()]);
        $participant = (int) $this->db->table('conf_participants')->insertGetId([
            'context_id' => 1, 'edition_id' => $edition, 'email' => 'a@example.org',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        return ['edition' => $edition, 'participant' => $participant];
    }

    public function testMigrationRunsTwiceAndCreatesAllTables(): void
    {
        $this->migrate();
        $this->migrate();

        $schema = $this->db->getSchemaBuilder();
        foreach (self::TABLES as $table) {
            self::assertTrue($schema->hasTable($table), $table);
        }
        self::assertTrue($this->db->table(ConferenceSchemaMigration::STEPS_TABLE)->where('step', '0003_conference_core')->exists());
        self::assertSame(1, $this->db->table(ConferenceSchemaMigration::STEPS_TABLE)->where('step', '0003_conference_core')->count());
        foreach (['context_id', 'is_minor', 'guardian_consent_at', 'extra'] as $column) {
            self::assertTrue($schema->hasColumn('conf_participants', $column), $column);
        }
        foreach (['payment_account_id', 'mode', 'template_id', 'recipient_snapshot', 'payer_snapshot'] as $column) {
            self::assertTrue($schema->hasColumn('conf_invoices', $column), $column);
        }
        foreach (['jurisdiction', 'is_builtin', 'variables'] as $column) {
            self::assertTrue($schema->hasColumn('conf_certificate_templates', $column), $column);
        }
    }

    public function testPaymentEventsAreAppendOnly(): void
    {
        $this->migrate();
        $schema = $this->db->getSchemaBuilder();
        self::assertTrue($schema->hasColumn('conf_payment_events', 'created_at'));
        self::assertFalse($schema->hasColumn('conf_payment_events', 'updated_at'));
    }

    public function testEditionWithInvoiceCannotBeDeleted(): void
    {
        $this->migrate();
        $ids = $this->seedParticipant();
        $this->db->table('conf_invoices')->insert([
            'context_id' => 1, 'edition_id' => $ids['edition'], 'payer_participant_id' => $ids['participant'],
            'number_year' => 2026, 'number' => 'CONF-2026-0001', 'payment_reference' => 'R1',
            'total_minor' => 50000, 'currency' => 'UAH', 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);

        try {
            $this->db->table('conf_editions')->where('id', $ids['edition'])->delete();
            self::fail('Deleting an edition with an invoice must be restricted');
        } catch (QueryException) {
            // expected
        }
        self::assertSame(1, $this->db->table('conf_editions')->count());
        self::assertSame(1, $this->db->table('conf_invoices')->count());
    }

    public function testDeletingSubmissionNullsParticipation(): void
    {
        $this->migrate();
        $ids = $this->seedParticipant();
        $submission = (int) $this->db->table('submissions')->insertGetId(['note' => 's']);
        $participation = (int) $this->db->table('conf_participations')->insertGetId([
            'participant_id' => $ids['participant'], 'submission_id' => $submission, 'role' => 'presenter',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);

        $this->db->table('submissions')->where('submission_id', $submission)->delete();

        $row = $this->db->table('conf_participations')->where('id', $participation)->first();
        self::assertNotNull($row);
        self::assertNull($row->submission_id);
    }

    public function testCounterIsUniquePerContextKindYear(): void
    {
        $this->migrate();
        $row = ['context_id' => 1, 'kind' => 'invoice', 'year' => 2026, 'last_value' => 1];
        $this->db->table('conf_counters')->insert($row);
        $this->db->table('conf_counters')->insert(['year' => 2027] + $row);

        $this->expectException(QueryException::class);
        $this->db->table('conf_counters')->insert($row);
    }
}
