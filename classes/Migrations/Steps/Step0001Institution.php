<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Institution profile (plan 8.6): organizer, signers, payment accounts,
 * and the audit log used from the very first release.
 */
final class Step0001Institution implements SchemaStep
{
    public function id(): string
    {
        return '0001_institution';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasTable('conf_institutions')) {
            $schema->create('conf_institutions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->foreign('context_id', 'conf_institutions_context_fk')
                    ->references('journal_id')->on('journals')->cascadeOnDelete();
                $t->string('jurisdiction', 16)->default('generic');
                $t->string('institution_type', 32)->default('other');
                $t->string('ownership', 16)->default('state');
                $t->string('legal_id', 32)->nullable();
                $t->string('contact_email', 255)->default('');
                $t->string('website', 255)->default('');
                $t->string('logo_path', 255)->nullable();
                $t->string('seal_path', 255)->nullable();
                $t->text('extra')->nullable();
                $t->timestamps();
                $t->unique('context_id', 'conf_institutions_context_unique');
            });
        }

        if (!$schema->hasTable('conf_institution_settings')) {
            $schema->create('conf_institution_settings', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('institution_id')->unsigned();
                $t->foreign('institution_id', 'conf_institution_settings_fk')
                    ->references('id')->on('conf_institutions')->cascadeOnDelete();
                $t->string('locale', 28)->default('');
                $t->string('setting_name', 255);
                $t->mediumText('setting_value')->nullable();
                $t->unique(['institution_id', 'locale', 'setting_name'], 'conf_institution_settings_unique');
            });
        }

        if (!$schema->hasTable('conf_signers')) {
            $schema->create('conf_signers', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('institution_id')->unsigned();
                $t->foreign('institution_id', 'conf_signers_institution_fk')
                    ->references('id')->on('conf_institutions')->cascadeOnDelete();
                $t->integer('seq')->default(0);
                $t->string('signature_path', 255)->nullable();
                $t->text('document_types')->nullable();
                $t->timestamps();
                $t->index(['institution_id', 'seq'], 'conf_signers_institution_seq');
            });
        }

        if (!$schema->hasTable('conf_signer_settings')) {
            $schema->create('conf_signer_settings', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('signer_id')->unsigned();
                $t->foreign('signer_id', 'conf_signer_settings_fk')
                    ->references('id')->on('conf_signers')->cascadeOnDelete();
                $t->string('locale', 28)->default('');
                $t->string('setting_name', 255);
                $t->mediumText('setting_value')->nullable();
                $t->unique(['signer_id', 'locale', 'setting_name'], 'conf_signer_settings_unique');
            });
        }

        if (!$schema->hasTable('conf_payment_accounts')) {
            $schema->create('conf_payment_accounts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('institution_id')->unsigned();
                $t->foreign('institution_id', 'conf_payment_accounts_institution_fk')
                    ->references('id')->on('conf_institutions')->cascadeOnDelete();
                $t->integer('seq')->default(0);
                $t->string('kind', 16)->default('bank');
                $t->string('label', 255)->default('');
                $t->string('recipient_name', 255);
                $t->string('iban', 34);
                $t->string('bank_name', 255)->default('');
                $t->string('legal_id', 32)->default('');
                $t->text('purpose_template')->nullable();
                $t->text('extra')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->index(['institution_id', 'is_active'], 'conf_payment_accounts_active');
            });
        }

        if (!$schema->hasTable('conf_audit_log')) {
            $schema->create('conf_audit_log', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id')->nullable();
                $t->bigInteger('actor_user_id')->nullable();
                $t->string('entity', 64);
                $t->bigInteger('entity_id')->nullable();
                $t->string('action', 64);
                $t->mediumText('before_data')->nullable();
                $t->mediumText('after_data')->nullable();
                $t->string('ip', 45)->nullable();
                $t->dateTime('created_at');
                $t->index(['context_id', 'entity', 'entity_id'], 'conf_audit_log_entity');
                $t->index(['context_id', 'created_at'], 'conf_audit_log_time');
            });
        }
    }
}
