<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** DEC-10: what reviewers of the journal would like to review. */
final class Step0017ReviewBids implements SchemaStep
{
    public function id(): string
    {
        return '0017_review_bids';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if ($schema->hasTable('email_templates_default_data')) {
            foreach (ParticipantMailTexts::all() as $key => $locales) {
                foreach ($locales as $locale => $text) {
                    if (!$db->table('email_templates_default_data')->where('email_key', $key)->where('locale', $locale)->exists()) {
                        $db->table('email_templates_default_data')->insert(['email_key' => $key, 'locale' => $locale] + $text);
                    }
                }
            }
        }
        if ($schema->hasTable('conf_review_bids')) {
            return;
        }
        $schema->create('conf_review_bids', function (Blueprint $t) {
            $t->bigIncrements('id');
            // Plain ids like conf_review_votes: OJS owns submissions and users.
            $t->bigInteger('submission_id');
            $t->bigInteger('user_id');
            $t->string('bid', 16);
            $t->dateTime('updated_at');
            $t->unique(['submission_id', 'user_id'], 'conf_review_bids_unique');
            $t->index('user_id', 'conf_review_bids_user');
        });
    }
}
