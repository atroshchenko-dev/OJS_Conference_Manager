<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Letters, mailings and reminders (COM-01…03) and the texts of the
 * consent (DAT-01):
 *  - conf_campaigns, conf_campaign_recipients: mass mailings, one row per
 *    recipient with its delivery state;
 *  - conf_reminders_sent: which reminder went to what, so none is sent twice;
 *  - conf_consent_versions: every version of the privacy notice that
 *    people agreed to, with its text;
 *  - the default letters to participants as OJS email templates; letters a
 *    journal has already edited are not touched.
 */
final class Step0008Communication implements SchemaStep
{
    public function id(): string
    {
        return '0008_communication';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasTable('conf_campaigns')) {
            $schema->create('conf_campaigns', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->bigInteger('edition_id')->unsigned();
                $t->bigInteger('created_by')->nullable();
                $t->string('locale', 28);
                $t->text('subject');
                $t->mediumText('body');
                $t->text('filter');
                $t->integer('total')->default(0);
                $t->dateTime('created_at');
                $t->index(['context_id', 'edition_id'], 'conf_campaigns_edition');
                $t->foreign('edition_id', 'conf_campaigns_edition_fk')->references('id')->on('conf_editions')->cascadeOnDelete();
            });
        }
        if (!$schema->hasTable('conf_campaign_recipients')) {
            $schema->create('conf_campaign_recipients', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('campaign_id')->unsigned();
                $t->bigInteger('participant_id')->nullable();
                $t->string('email', 255);
                $t->string('name', 255)->nullable();
                $t->string('status', 16)->default('pending');
                $t->string('error', 255)->nullable();
                $t->dateTime('sent_at')->nullable();
                $t->index(['campaign_id', 'status'], 'conf_campaign_recipients_state');
                $t->foreign('campaign_id', 'conf_campaign_recipients_fk')->references('id')->on('conf_campaigns')->cascadeOnDelete();
            });
        }
        if (!$schema->hasTable('conf_reminders_sent')) {
            $schema->create('conf_reminders_sent', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->bigInteger('edition_id')->unsigned();
                $t->string('kind', 16);
                $t->bigInteger('target_id');
                $t->string('email', 255);
                $t->dateTime('sent_at');
                $t->unique(['kind', 'target_id'], 'conf_reminders_sent_once');
                $t->foreign('edition_id', 'conf_reminders_sent_edition_fk')->references('id')->on('conf_editions')->cascadeOnDelete();
            });
        }
        if (!$schema->hasTable('conf_consent_versions')) {
            $schema->create('conf_consent_versions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->string('version', 16);
                $t->mediumText('texts');
                $t->dateTime('created_at');
                $t->unique(['context_id', 'version'], 'conf_consent_versions_once');
            });
        }
        if ($schema->hasTable('email_templates_default_data')) {
            foreach (ParticipantMailTexts::all() as $key => $locales) {
                foreach ($locales as $locale => $text) {
                    $exists = $db->table('email_templates_default_data')->where('email_key', $key)->where('locale', $locale)->exists();
                    if (!$exists) {
                        $db->table('email_templates_default_data')->insert(['email_key' => $key, 'locale' => $locale] + $text);
                    }
                }
            }
        }
    }
}
