<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * REG-11: promo codes of an edition and their use by participations.
 * REG-12: the number of in-person listener seats of an edition; when it
 * is reached, new in-person listeners join a waiting list (participation
 * status "waitlisted").
 */
final class Step0016PromoCodesWaitlist implements SchemaStep
{
    public function id(): string
    {
        return '0016_promo_codes_waitlist';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasTable('conf_promo_codes')) {
            $schema->create('conf_promo_codes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('edition_id')->unsigned();
                $t->foreign('edition_id', 'conf_promo_codes_edition_fk')->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->string('code', 32);
                // Either a percentage or a fixed amount in the currency of the tariff.
                $t->smallInteger('percent')->nullable();
                $t->bigInteger('amount_minor')->nullable();
                $t->string('currency', 3)->nullable();
                $t->integer('max_uses')->nullable();
                $t->date('valid_from')->nullable();
                $t->date('valid_until')->nullable();
                $t->boolean('active')->default(true);
                $t->string('note', 255)->nullable();
                $t->dateTime('created_at');
                $t->unique(['edition_id', 'code'], 'conf_promo_codes_unique');
            });
        }
        if (!$schema->hasTable('conf_promo_redemptions')) {
            $schema->create('conf_promo_redemptions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('promo_code_id')->unsigned();
                $t->foreign('promo_code_id', 'conf_promo_redemptions_code_fk')->references('id')->on('conf_promo_codes')->cascadeOnDelete();
                $t->bigInteger('participation_id')->unsigned();
                $t->foreign('participation_id', 'conf_promo_redemptions_participation_fk')->references('id')->on('conf_participations')->cascadeOnDelete();
                $t->dateTime('created_at');
                $t->unique('participation_id', 'conf_promo_redemptions_participation');
                $t->index('promo_code_id', 'conf_promo_redemptions_code');
            });
        }
        if (!$schema->hasColumn('conf_editions', 'listener_seats')) {
            $schema->table('conf_editions', function (Blueprint $t) {
                $t->integer('listener_seats')->nullable();
            });
        }
        if (!$schema->hasColumn('conf_participations', 'waitlisted_at')) {
            $schema->table('conf_participations', function (Blueprint $t) {
                // The place in the waiting list; kept after the seat is given, for the record.
                $t->dateTime('waitlisted_at')->nullable();
            });
        }
        if ($schema->hasTable('email_templates_default_data')) {
            foreach (ParticipantMailTexts::all() as $key => $locales) {
                foreach ($locales as $locale => $text) {
                    if (!$db->table('email_templates_default_data')->where('email_key', $key)->where('locale', $locale)->exists()) {
                        $db->table('email_templates_default_data')->insert(['email_key' => $key, 'locale' => $locale] + $text);
                    }
                }
            }
        }
    }
}
