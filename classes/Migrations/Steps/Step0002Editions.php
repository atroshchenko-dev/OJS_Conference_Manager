<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Editions (plan 8.2 and 8.6): one event of a context. The OJS issue is
 * optional and exists only in the "ojs_issue" publication mode (EVT-03).
 * All datetimes are stored in UTC; "timezone" is the IANA zone of the event.
 */
final class Step0002Editions implements SchemaStep
{
    public function id(): string
    {
        return '0002_editions';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasTable('conf_editions')) {
            $schema->create('conf_editions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->foreign('context_id', 'conf_editions_context_fk')
                    ->references('journal_id')->on('journals')->cascadeOnDelete();
                $t->bigInteger('issue_id')->nullable();
                $t->foreign('issue_id', 'conf_editions_issue_fk')
                    ->references('issue_id')->on('issues')->restrictOnDelete();
                $t->string('path', 64);
                $t->string('status', 32)->default('draft');
                $t->string('event_type', 32)->default('conference');
                $t->string('submission_mode', 32)->default('abstracts');
                $t->string('publication_mode', 16)->default('none');
                $t->string('external_proceedings_url', 2047)->nullable();
                $t->string('format', 16)->default('in_person');
                $t->string('timezone', 64)->default('UTC');
                $t->dateTime('starts_at')->nullable();
                $t->dateTime('ends_at')->nullable();
                $t->dateTime('cfp_opens_at')->nullable();
                $t->dateTime('abstract_deadline')->nullable();
                $t->dateTime('paper_deadline')->nullable();
                $t->dateTime('registration_closes_at')->nullable();
                $t->dateTime('payment_deadline')->nullable();
                $t->string('review_mode', 16)->default('light');
                $t->string('isbn', 32)->nullable();
                $t->string('udc', 32)->nullable();
                $t->string('vector_program_id', 64)->nullable();
                $t->integer('hours')->nullable();
                $t->integer('ects_x100')->nullable();
                $t->timestamps();
                $t->unique(['context_id', 'path'], 'conf_editions_context_path_unique');
                $t->unique('issue_id', 'conf_editions_issue_unique');
                $t->index(['context_id', 'status'], 'conf_editions_context_status');
                $t->index(['context_id', 'starts_at'], 'conf_editions_context_starts');
            });
        }

        if (!$schema->hasTable('conf_edition_settings')) {
            $schema->create('conf_edition_settings', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_edition_settings_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->string('locale', 28)->default('');
                $t->string('setting_name', 255);
                $t->mediumText('setting_value')->nullable();
                $t->unique(['edition_id', 'locale', 'setting_name'], 'conf_edition_settings_unique');
            });
        }
    }
}
