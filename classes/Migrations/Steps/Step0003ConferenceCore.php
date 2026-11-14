<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Conference core (plan 8.2, 8.4, 8.6): participants, fees, invoices and
 * payments, programme, certificates and service tables.
 *
 * conf_editions and conf_edition_settings belong to step 0002; this step
 * only references conf_editions.id.
 *
 * Deletion rules: financial records (invoices, proofs, payment events) and
 * certificates use RESTRICT towards editions and participants (ADR-5);
 * links to OJS submissions, users, authors and galleys become NULL.
 * FKs to OJS core tables are signed BIGINT, FKs to plugin tables unsigned.
 */
final class Step0003ConferenceCore implements SchemaStep
{
    public function id(): string
    {
        return '0003_conference_core';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        $this->fees($schema);
        $this->participants($schema);
        $this->programme($schema);
        $this->participations($schema);
        $this->invoices($schema);
        $this->certificates($schema);
        $this->service($schema);
        $this->partnersAndRules($schema);
    }

    private function fees(Builder $schema): void
    {
        if (!$schema->hasTable('conf_fee_categories')) {
            $schema->create('conf_fee_categories', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_fee_categories_edition_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->string('code', 32);
                $t->boolean('requires_proof')->default(false);
                $t->integer('sort_order')->default(0);
                $t->timestamps();
                $t->unique(['edition_id', 'code'], 'conf_fee_categories_code_unique');
            });
        }

        $this->settingsTable($schema, 'conf_fee_category_settings', 'category_id', 'conf_fee_categories');

        if (!$schema->hasTable('conf_tariffs')) {
            $schema->create('conf_tariffs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('category_id')->unsigned();
                $t->foreign('category_id', 'conf_tariffs_category_fk')
                    ->references('id')->on('conf_fee_categories')->cascadeOnDelete();
                $t->string('material_type', 16)->default('any');
                $t->string('presentation_type', 16)->nullable();
                $t->string('pricing_mode', 16)->default('flat');
                $t->bigInteger('amount_minor')->default(0);
                $t->char('currency', 3);
                $t->integer('min_pages')->nullable();
                $t->integer('max_pages')->nullable();
                $t->dateTime('valid_from')->nullable();
                $t->dateTime('valid_to')->nullable();
                $t->timestamps();
                $t->index(['category_id', 'material_type'], 'conf_tariffs_category_material');
            });
        }
    }

    private function participants(Builder $schema): void
    {
        if (!$schema->hasTable('conf_participants')) {
            $schema->create('conf_participants', function (Blueprint $t) {
                $t->bigIncrements('id');
                // Denormalised copy of conf_editions.context_id for isolation (8.6).
                // Participants go with their edition; invoices and certificates
                // keep RESTRICT, so financial records block the deletion (8.4).
                $t->bigInteger('context_id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_participants_edition_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->bigInteger('user_id')->nullable();
                $t->foreign('user_id', 'conf_participants_user_fk')
                    ->references('user_id')->on('users')->nullOnDelete();
                $t->bigInteger('author_id')->nullable();
                $t->foreign('author_id', 'conf_participants_author_fk')
                    ->references('author_id')->on('authors')->nullOnDelete();
                $t->string('email', 255);
                $t->string('affiliation', 255)->nullable();
                $t->string('position', 255)->nullable();
                $t->string('country', 2)->nullable();
                $t->string('orcid', 255)->nullable();
                $t->bigInteger('category_id')->unsigned()->nullable();
                $t->foreign('category_id', 'conf_participants_category_fk')
                    ->references('id')->on('conf_fee_categories')->nullOnDelete();
                $t->string('confirmation_status', 16)->default('pending');
                $t->char('confirmation_token_hash', 64)->nullable();
                $t->string('consent_version', 16)->nullable();
                $t->dateTime('consent_at')->nullable();
                $t->boolean('is_minor')->default(false);
                $t->dateTime('guardian_consent_at')->nullable();
                $t->text('extra')->nullable();
                $t->dateTime('anonymized_at')->nullable();
                $t->timestamps();
                $t->unique(['edition_id', 'email'], 'conf_participants_edition_email_unique');
                $t->index(['context_id', 'edition_id'], 'conf_participants_context');
                $t->index('user_id', 'conf_participants_user');
                $t->index('confirmation_token_hash', 'conf_participants_token');
            });
        }

        $this->settingsTable($schema, 'conf_participant_settings', 'participant_id', 'conf_participants');
    }

    private function programme(Builder $schema): void
    {
        if (!$schema->hasTable('conf_rooms')) {
            $schema->create('conf_rooms', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_rooms_edition_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->string('name', 255);
                $t->integer('capacity')->nullable();
                $t->string('online_url', 2048)->nullable();
                $t->timestamps();
                $t->index('edition_id', 'conf_rooms_edition');
            });
        }

        if (!$schema->hasTable('conf_sessions')) {
            $schema->create('conf_sessions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_sessions_edition_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->bigInteger('room_id')->unsigned()->nullable();
                $t->foreign('room_id', 'conf_sessions_room_fk')
                    ->references('id')->on('conf_rooms')->nullOnDelete();
                $t->string('type', 16)->default('section');
                // OJS sections.section_id; no FK (journal sections are edited outside the plugin).
                $t->bigInteger('section_id')->nullable();
                $t->dateTime('starts_at');
                $t->dateTime('ends_at');
                $t->string('stream_url', 2048)->nullable();
                $t->dateTime('stream_visible_from')->nullable();
                $t->integer('hours')->nullable();
                $t->integer('ects_x100')->nullable();
                $t->timestamps();
                $t->index(['edition_id', 'starts_at'], 'conf_sessions_edition_time');
            });
        }

        $this->settingsTable($schema, 'conf_session_settings', 'session_id', 'conf_sessions');

        if (!$schema->hasTable('conf_session_chairs')) {
            $schema->create('conf_session_chairs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('session_id')->unsigned();
                $t->foreign('session_id', 'conf_session_chairs_session_fk')
                    ->references('id')->on('conf_sessions')->cascadeOnDelete();
                $t->bigInteger('participant_id')->unsigned();
                $t->foreign('participant_id', 'conf_session_chairs_participant_fk')
                    ->references('id')->on('conf_participants')->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['session_id', 'participant_id'], 'conf_session_chairs_unique');
            });
        }

        if (!$schema->hasTable('conf_session_items')) {
            $schema->create('conf_session_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('session_id')->unsigned();
                $t->foreign('session_id', 'conf_session_items_session_fk')
                    ->references('id')->on('conf_sessions')->cascadeOnDelete();
                $t->bigInteger('submission_id')->nullable();
                $t->foreign('submission_id', 'conf_session_items_submission_fk')
                    ->references('submission_id')->on('submissions')->nullOnDelete();
                $t->integer('seq')->default(0);
                $t->integer('duration_min')->nullable();
                $t->timestamps();
                $t->index(['session_id', 'seq'], 'conf_session_items_session_seq');
            });
        }
    }

    private function participations(Builder $schema): void
    {
        if (!$schema->hasTable('conf_participations')) {
            $schema->create('conf_participations', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('participant_id')->unsigned();
                $t->foreign('participant_id', 'conf_participations_participant_fk')
                    ->references('id')->on('conf_participants')->cascadeOnDelete();
                $t->bigInteger('submission_id')->nullable();
                $t->foreign('submission_id', 'conf_participations_submission_fk')
                    ->references('submission_id')->on('submissions')->nullOnDelete();
                $t->bigInteger('session_id')->unsigned()->nullable();
                $t->foreign('session_id', 'conf_participations_session_fk')
                    ->references('id')->on('conf_sessions')->nullOnDelete();
                $t->string('role', 16);
                $t->string('participation_form', 16)->default('in_person');
                $t->string('status', 16)->default('active');
                $t->boolean('attended')->default(false);
                $t->bigInteger('attended_by')->nullable();
                $t->foreign('attended_by', 'conf_participations_attended_by_fk')
                    ->references('user_id')->on('users')->nullOnDelete();
                $t->dateTime('attended_at')->nullable();
                $t->string('attendance_source', 16)->nullable();
                $t->timestamps();
                $t->index(['participant_id', 'role'], 'conf_participations_participant');
                $t->index('submission_id', 'conf_participations_submission');
            });
        }
    }

    private function invoices(Builder $schema): void
    {
        if (!$schema->hasTable('conf_invoices')) {
            $schema->create('conf_invoices', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_invoices_edition_fk')
                    ->references('id')->on('conf_editions')->restrictOnDelete();
                $t->bigInteger('payer_participant_id')->unsigned();
                $t->foreign('payer_participant_id', 'conf_invoices_payer_fk')
                    ->references('id')->on('conf_participants')->restrictOnDelete();
                $t->bigInteger('payment_account_id')->unsigned()->nullable();
                $t->foreign('payment_account_id', 'conf_invoices_account_fk')
                    ->references('id')->on('conf_payment_accounts')->restrictOnDelete();
                // Number is assigned on issue; drafts have none.
                $t->integer('number_year')->nullable();
                $t->string('number', 32)->nullable();
                $t->string('payment_reference', 32)->nullable();
                $t->string('status', 16)->default('draft');
                $t->string('method', 16)->default('bank_transfer');
                $t->string('mode', 32)->nullable();
                $t->bigInteger('template_id')->nullable();
                $t->bigInteger('total_minor')->default(0);
                $t->bigInteger('paid_minor')->default(0);
                $t->char('currency', 3);
                $t->text('waiver_reason')->nullable();
                $t->bigInteger('supersedes_invoice_id')->unsigned()->nullable();
                $t->foreign('supersedes_invoice_id', 'conf_invoices_supersedes_fk')
                    ->references('id')->on('conf_invoices')->restrictOnDelete();
                $t->dateTime('due_at')->nullable();
                $t->dateTime('issued_at')->nullable();
                $t->dateTime('paid_at')->nullable();
                $t->string('pdf_path', 255)->nullable();
                $t->char('pdf_sha256', 64)->nullable();
                $t->string('provider', 32)->nullable();
                $t->string('provider_ref', 128)->nullable();
                $t->string('idempotency_key', 128)->nullable();
                $t->mediumText('payer_snapshot')->nullable();
                $t->mediumText('recipient_snapshot')->nullable();
                $t->timestamps();
                $t->unique(['context_id', 'number_year', 'number'], 'conf_invoices_number_unique');
                $t->unique('payment_reference', 'conf_invoices_reference_unique');
                $t->unique('idempotency_key', 'conf_invoices_idempotency_unique');
                $t->index(['context_id', 'edition_id', 'status'], 'conf_invoices_context');
                $t->index('payer_participant_id', 'conf_invoices_payer');
            });
        }

        if (!$schema->hasTable('conf_invoice_items')) {
            $schema->create('conf_invoice_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('invoice_id')->unsigned();
                $t->foreign('invoice_id', 'conf_invoice_items_invoice_fk')
                    ->references('id')->on('conf_invoices')->cascadeOnDelete();
                $t->bigInteger('participation_id')->unsigned()->nullable();
                $t->foreign('participation_id', 'conf_invoice_items_participation_fk')
                    ->references('id')->on('conf_participations')->nullOnDelete();
                $t->bigInteger('submission_id')->nullable();
                $t->foreign('submission_id', 'conf_invoice_items_submission_fk')
                    ->references('submission_id')->on('submissions')->nullOnDelete();
                $t->text('description_snapshot');
                $t->bigInteger('tariff_id')->unsigned()->nullable();
                $t->foreign('tariff_id', 'conf_invoice_items_tariff_fk')
                    ->references('id')->on('conf_tariffs')->nullOnDelete();
                $t->integer('quantity')->default(1);
                $t->bigInteger('unit_minor')->default(0);
                $t->bigInteger('amount_minor')->default(0);
                $t->timestamps();
                $t->index('invoice_id', 'conf_invoice_items_invoice');
            });
        }

        if (!$schema->hasTable('conf_payment_proofs')) {
            $schema->create('conf_payment_proofs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('invoice_id')->unsigned();
                $t->foreign('invoice_id', 'conf_payment_proofs_invoice_fk')
                    ->references('id')->on('conf_invoices')->restrictOnDelete();
                $t->bigInteger('uploaded_by')->nullable();
                $t->foreign('uploaded_by', 'conf_payment_proofs_uploader_fk')
                    ->references('user_id')->on('users')->nullOnDelete();
                $t->string('file_path', 255)->nullable();
                $t->string('original_name', 255)->default('');
                $t->string('mime', 128)->default('');
                $t->bigInteger('size')->default(0);
                $t->char('sha256', 64);
                $t->string('review_status', 16)->default('pending');
                $t->bigInteger('reviewed_by')->nullable();
                $t->foreign('reviewed_by', 'conf_payment_proofs_reviewer_fk')
                    ->references('user_id')->on('users')->nullOnDelete();
                $t->dateTime('reviewed_at')->nullable();
                $t->text('note')->nullable();
                $t->dateTime('purge_after')->nullable();
                $t->dateTime('purged_at')->nullable();
                $t->timestamps();
                $t->index(['invoice_id', 'review_status'], 'conf_payment_proofs_invoice');
                $t->index('purge_after', 'conf_payment_proofs_purge');
            });
        }

        if (!$schema->hasTable('conf_payment_events')) {
            // Append-only journal: created_at only, never updated or deleted.
            $schema->create('conf_payment_events', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('invoice_id')->unsigned();
                $t->foreign('invoice_id', 'conf_payment_events_invoice_fk')
                    ->references('id')->on('conf_invoices')->restrictOnDelete();
                $t->string('type', 32);
                // Plain id like conf_audit_log.actor_user_id: an FK would rewrite the journal.
                $t->bigInteger('actor_user_id')->nullable();
                $t->mediumText('payload')->nullable();
                $t->dateTime('created_at');
                $t->index(['invoice_id', 'created_at'], 'conf_payment_events_invoice');
            });
        }
    }

    private function certificates(Builder $schema): void
    {
        if (!$schema->hasTable('conf_certificate_templates')) {
            $schema->create('conf_certificate_templates', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->foreign('context_id', 'conf_certificate_templates_context_fk')
                    ->references('journal_id')->on('journals')->cascadeOnDelete();
                $t->string('type', 16);
                $t->string('locale', 28)->default('');
                $t->string('jurisdiction', 16)->default('generic');
                $t->boolean('is_builtin')->default(false);
                $t->mediumText('body_html')->nullable();
                $t->string('background_path', 255)->nullable();
                $t->text('variables')->nullable();
                $t->integer('version')->default(1);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->index(['context_id', 'type', 'is_active'], 'conf_certificate_templates_context');
            });
        }

        if (!$schema->hasTable('conf_certificates')) {
            $schema->create('conf_certificates', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->char('uuid', 36);
                $t->string('number', 32);
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_certificates_edition_fk')
                    ->references('id')->on('conf_editions')->restrictOnDelete();
                $t->bigInteger('session_id')->unsigned()->nullable();
                $t->foreign('session_id', 'conf_certificates_session_fk')
                    ->references('id')->on('conf_sessions')->nullOnDelete();
                $t->bigInteger('participant_id')->unsigned();
                $t->foreign('participant_id', 'conf_certificates_participant_fk')
                    ->references('id')->on('conf_participants')->restrictOnDelete();
                $t->bigInteger('template_id')->unsigned();
                $t->foreign('template_id', 'conf_certificates_template_fk')
                    ->references('id')->on('conf_certificate_templates')->restrictOnDelete();
                $t->integer('template_version')->default(1);
                $t->string('type', 16);
                $t->integer('hours')->nullable();
                $t->integer('ects_x100')->nullable();
                $t->string('form', 16)->nullable();
                $t->text('partial_qualifications')->nullable();
                $t->dateTime('issued_at');
                $t->dateTime('revoked_at')->nullable();
                $t->text('revoke_reason')->nullable();
                $t->bigInteger('replaced_by_id')->unsigned()->nullable();
                $t->foreign('replaced_by_id', 'conf_certificates_replaced_by_fk')
                    ->references('id')->on('conf_certificates')->restrictOnDelete();
                $t->string('pdf_path', 255)->nullable();
                $t->char('pdf_sha256', 64)->nullable();
                $t->mediumText('snapshot')->nullable();
                $t->dateTime('vector_exported_at')->nullable();
                $t->timestamps();
                $t->unique('uuid', 'conf_certificates_uuid_unique');
                $t->unique(['context_id', 'number'], 'conf_certificates_number_unique');
                $t->index(['context_id', 'edition_id', 'type'], 'conf_certificates_context');
                $t->index('participant_id', 'conf_certificates_participant');
            });
        }
    }

    private function service(Builder $schema): void
    {
        if (!$schema->hasTable('conf_counters')) {
            $schema->create('conf_counters', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->string('kind', 16);
                $t->integer('year');
                $t->bigInteger('last_value')->default(0);
                $t->timestamps();
                $t->unique(['context_id', 'kind', 'year'], 'conf_counters_unique');
            });
        }

        if (!$schema->hasTable('conf_mail_campaigns')) {
            $schema->create('conf_mail_campaigns', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_mail_campaigns_edition_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->mediumText('filter')->nullable();
                $t->string('template_key', 64);
                $t->string('status', 16)->default('draft');
                $t->integer('total')->default(0);
                $t->integer('sent')->default(0);
                $t->integer('failed')->default(0);
                $t->bigInteger('created_by')->nullable();
                $t->foreign('created_by', 'conf_mail_campaigns_creator_fk')
                    ->references('user_id')->on('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['edition_id', 'status'], 'conf_mail_campaigns_edition');
            });
        }

        if (!$schema->hasTable('conf_mail_log')) {
            $schema->create('conf_mail_log', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('campaign_id')->unsigned()->nullable();
                $t->foreign('campaign_id', 'conf_mail_log_campaign_fk')
                    ->references('id')->on('conf_mail_campaigns')->nullOnDelete();
                $t->bigInteger('participant_id')->unsigned();
                $t->foreign('participant_id', 'conf_mail_log_participant_fk')
                    ->references('id')->on('conf_participants')->cascadeOnDelete();
                $t->string('template_key', 64);
                $t->dateTime('sent_at')->nullable();
                $t->text('error')->nullable();
                $t->timestamps();
                $t->index(['participant_id', 'sent_at'], 'conf_mail_log_participant');
                $t->index('campaign_id', 'conf_mail_log_campaign');
            });
        }

        if (!$schema->hasTable('conf_legacy_urls')) {
            $schema->create('conf_legacy_urls', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->foreign('context_id', 'conf_legacy_urls_context_fk')
                    ->references('journal_id')->on('journals')->cascadeOnDelete();
                $t->bigInteger('ocs_paper_id')->nullable();
                $t->string('old_path', 255);
                $t->bigInteger('submission_id')->nullable();
                $t->foreign('submission_id', 'conf_legacy_urls_submission_fk')
                    ->references('submission_id')->on('submissions')->nullOnDelete();
                $t->bigInteger('galley_id')->nullable();
                $t->foreign('galley_id', 'conf_legacy_urls_galley_fk')
                    ->references('galley_id')->on('publication_galleys')->nullOnDelete();
                $t->timestamps();
                $t->unique('old_path', 'conf_legacy_urls_path_unique');
                $t->index(['context_id', 'ocs_paper_id'], 'conf_legacy_urls_context');
            });
        }
    }

    private function partnersAndRules(Builder $schema): void
    {
        if (!$schema->hasTable('conf_edition_partners')) {
            $schema->create('conf_edition_partners', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_edition_partners_edition_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->integer('seq')->default(0);
                $t->string('role', 16)->default('partner');
                $t->string('logo_path', 255)->nullable();
                $t->bigInteger('payment_account_id')->unsigned()->nullable();
                $t->foreign('payment_account_id', 'conf_edition_partners_account_fk')
                    ->references('id')->on('conf_payment_accounts')->nullOnDelete();
                $t->timestamps();
                $t->index(['edition_id', 'seq'], 'conf_edition_partners_edition_seq');
            });
        }

        $this->settingsTable($schema, 'conf_edition_partner_settings', 'partner_id', 'conf_edition_partners');

        if (!$schema->hasTable('conf_payment_rules')) {
            $schema->create('conf_payment_rules', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_payment_rules_edition_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->bigInteger('category_id')->unsigned()->nullable();
                $t->foreign('category_id', 'conf_payment_rules_category_fk')
                    ->references('id')->on('conf_fee_categories')->cascadeOnDelete();
                $t->string('mode', 32);
                $t->bigInteger('payment_account_id')->unsigned()->nullable();
                $t->foreign('payment_account_id', 'conf_payment_rules_account_fk')
                    ->references('id')->on('conf_payment_accounts')->nullOnDelete();
                $t->string('external_url', 2048)->nullable();
                $t->timestamps();
                $t->index(['edition_id', 'category_id'], 'conf_payment_rules_edition');
            });
        }

        $this->settingsTable($schema, 'conf_payment_rule_settings', 'rule_id', 'conf_payment_rules');
    }

    /**
     * PKP-style localized settings: (parent, locale, setting_name, setting_value).
     */
    private function settingsTable(Builder $schema, string $table, string $parentColumn, string $parentTable): void
    {
        if ($schema->hasTable($table)) {
            return;
        }
        $schema->create($table, function (Blueprint $t) use ($table, $parentColumn, $parentTable) {
            $t->bigIncrements('id');
            $t->bigInteger($parentColumn)->unsigned();
            $t->foreign($parentColumn, $table . '_fk')
                ->references('id')->on($parentTable)->cascadeOnDelete();
            $t->string('locale', 28)->default('');
            $t->string('setting_name', 255);
            $t->mediumText('setting_value')->nullable();
            $t->unique([$parentColumn, 'locale', 'setting_name'], $table . '_unique');
        });
    }
}
