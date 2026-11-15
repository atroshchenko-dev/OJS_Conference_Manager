<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * REG-10: invited participants; FEE-15: what was refunded and why; the
 * letter of the invitation.
 */
final class Step0013GroupsInvitationsRefunds implements SchemaStep
{
    public function id(): string
    {
        return '0013_groups_invitations_refunds';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasColumn('conf_invoices', 'refunded_minor')) {
            $schema->table('conf_invoices', function (Blueprint $t) {
                $t->bigInteger('refunded_minor')->default(0);
                $t->dateTime('refunded_at')->nullable();
                $t->text('refund_note')->nullable();
            });
        }
        if (!$schema->hasColumn('conf_participants', 'invited_at')) {
            $schema->table('conf_participants', function (Blueprint $t) {
                $t->dateTime('invited_at')->nullable();
                // A plain id like conf_audit_log.actor_user_id.
                $t->bigInteger('invited_by')->nullable();
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
