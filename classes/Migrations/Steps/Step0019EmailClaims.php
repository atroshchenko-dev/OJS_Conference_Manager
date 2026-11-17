<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Security audit 0.33: an account sees records made for its email address
 * (by a manager, an import, a co-author) only after the owner of the
 * mailbox has confirmed it with a link. One row per account and address.
 */
final class Step0019EmailClaims implements SchemaStep
{
    public function id(): string
    {
        return '0019_email_claims';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if ($schema->hasTable('conf_email_claims')) {
            return;
        }
        $schema->create('conf_email_claims', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->bigInteger('user_id');
            $t->string('email', 255);
            $t->char('token_hash', 64)->nullable();
            $t->dateTime('requested_at')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->unique(['user_id', 'email'], 'conf_email_claims_user_email');
            $t->index(['email'], 'conf_email_claims_email');
        });
    }
}
