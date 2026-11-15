<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Domain\Decision\DecisionMailTexts;
use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Bulk decisions (DEC-03) and the default letters of the decisions
 * 901–904 and of a presentation type change (DEC-02, DEC-05). Letters a
 * journal has already edited are not touched.
 */
final class Step0007Decisions implements SchemaStep
{
    public function id(): string
    {
        return '0007_decisions';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasTable('conf_decision_batches')) {
            $schema->create('conf_decision_batches', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('context_id');
                $t->bigInteger('edition_id')->unsigned();
                $t->integer('decision');
                $t->bigInteger('editor_id');
                $t->dateTime('created_at');
                $t->index(['context_id', 'edition_id'], 'conf_decision_batches_edition');
                $t->foreign('edition_id', 'conf_decision_batches_edition_fk')->references('id')->on('conf_editions')->cascadeOnDelete();
            });
        }
        if (!$schema->hasTable('conf_decision_batch_items')) {
            $schema->create('conf_decision_batch_items', function (Blueprint $t) {
                $t->bigInteger('batch_id')->unsigned();
                $t->bigInteger('submission_id');
                $t->string('status', 16)->default('pending');
                $t->string('error', 255)->nullable();
                $t->dateTime('started_at')->nullable();
                $t->dateTime('finished_at')->nullable();
                $t->primary(['batch_id', 'submission_id']);
                $t->foreign('batch_id', 'conf_decision_items_batch_fk')->references('id')->on('conf_decision_batches')->cascadeOnDelete();
            });
        }
        if ($schema->hasTable('email_templates_default_data')) {
            foreach (DecisionMailTexts::all() as $key => $locales) {
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
