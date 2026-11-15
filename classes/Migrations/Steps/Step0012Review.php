<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Domain\Decision\DecisionMailTexts;
use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * DEC-07, DEC-09: committee votes on submissions, and the letter of the
 * decision "abstract accepted, send the full text".
 */
final class Step0012Review implements SchemaStep
{
    public function id(): string
    {
        return '0012_review';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasTable('conf_review_votes')) {
            $schema->create('conf_review_votes', function (Blueprint $t) {
                $t->bigInteger('submission_id');
                $t->bigInteger('user_id');
                $t->string('vote', 16);
                $t->text('comment')->nullable();
                $t->dateTime('updated_at');
                $t->primary(['submission_id', 'user_id']);
            });
        }
        if ($schema->hasTable('email_templates_default_data')) {
            foreach (DecisionMailTexts::all() as $key => $locales) {
                foreach ($locales as $locale => $text) {
                    if (!$db->table('email_templates_default_data')->where('email_key', $key)->where('locale', $locale)->exists()) {
                        $db->table('email_templates_default_data')->insert(['email_key' => $key, 'locale' => $locale] + $text);
                    }
                }
            }
        }
    }
}
