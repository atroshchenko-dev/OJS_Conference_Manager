#!/usr/bin/env bash
# Install → migrate twice → enable → upgrade check against a running OJS
# container (default name "ojs"). Used locally and by the CI "upgrade" job.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
ojs="${OJS_CONTAINER:-ojs}"
work="$(mktemp -d)"
run() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }

docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"

# 1. Package of the current release.
pkg="$("$root/ci/package.sh" "$work/v1")"
docker cp "$pkg" "$ojs:/tmp/cm-v1.tar.gz"

run context confa uk
run context confb en
run install /tmp/cm-v1.tar.gz
run assert-tables conf_schema_steps,conf_institutions,conf_institution_settings,conf_signers,conf_signer_settings,conf_payment_accounts,conf_audit_log,conf_editions,conf_edition_settings,conf_participants,conf_participations,conf_fee_categories,conf_tariffs,conf_invoices,conf_invoice_items,conf_payment_proofs,conf_payment_events,conf_rooms,conf_sessions,conf_certificate_templates,conf_certificates,conf_counters,conf_mail_campaigns,conf_mail_log,conf_legacy_urls,conf_edition_partners,conf_payment_rules,conf_submission_registrations
run migrate-twice
run assert-column conf_submission_registrations category_id
run enable confa
run enable confb
run user-groups confa
run user-groups confb
run publication-schema confa

# 2. A synthetic next release: bumped version + one extra schema step.
#    Proves that upgrade.xml runs NEW steps even though the old migration
#    class is already loaded in the upgrading request.
next="$work/next/conferenceManager"
mkdir -p "$next"
tar -xzf "$pkg" -C "$work/next"
sed -i 's:<release>\([0-9]*\)\.\([0-9]*\)\.\([0-9]*\)\.\([0-9]*\)</release>:<release>\1.\2.\3.99</release>:' "$next/version.xml"
cat > "$next/classes/Migrations/Steps/Step9999UpgradeProbe.php" <<'PHP'
<?php
declare(strict_types=1);
namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;
use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
final class Step9999UpgradeProbe implements SchemaStep
{
    public function id(): string { return '9999_upgrade_probe'; }
    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasColumn('conf_institutions', 'upgrade_probe')) {
            $schema->table('conf_institutions', fn (Blueprint $t) => $t->string('upgrade_probe', 8)->nullable());
        }
    }
}
PHP
tar -C "$work/next" -czf "$work/cm-next.tar.gz" conferenceManager
docker cp "$work/cm-next.tar.gz" "$ojs:/tmp/cm-next.tar.gz"
run upgrade /tmp/cm-next.tar.gz
run assert-column conf_institutions upgrade_probe
run migrate-twice
run assert-column conf_submission_registrations category_id
run steps

# 3. Clean up the probe so the container can be reused: reinstall v1 files.
docker exec "$ojs" sh -c 'chown -R www-data:www-data /var/www/html/plugins/generic/conferenceManager /var/www/html/cache /var/www/files'
rm -rf "$work"
echo "LIFECYCLE OK"
