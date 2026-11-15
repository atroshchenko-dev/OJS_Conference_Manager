<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Application\EditionStatistics;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

final class DbEditionStatistics implements EditionStatistics
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function participations(int $editionId): array
    {
        $out = [];
        foreach ($this->db->table('conf_participations as pn')->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('p.edition_id', $editionId)->groupBy('pn.role', 'pn.status')
            ->selectRaw('pn.role, pn.status, COUNT(*) as n')->get() as $r) {
            $out[(string) $r->role][(string) $r->status] = (int) $r->n;
        }
        return $out;
    }

    public function invoices(int $editionId): array
    {
        return array_map(static fn (object $r): array => [
            'status' => (string) $r->status,
            'method' => (string) $r->method,
            'currency' => (string) $r->currency,
            'count' => (int) $r->n,
            'totalMinor' => (int) $r->total,
            'paidMinor' => (int) $r->paid,
            'withPayment' => (int) $r->partial,
        ], $this->db->table('conf_invoices')->where('edition_id', $editionId)->groupBy('status', 'method', 'currency')
            ->selectRaw('status, method, currency, COUNT(*) as n, SUM(total_minor) as total, SUM(paid_minor) as paid, SUM(CASE WHEN paid_minor > 0 THEN 1 ELSE 0 END) as partial')->get()->all());
    }

    public function pendingProofs(int $editionId): int
    {
        return $this->db->table('conf_payment_proofs as pr')->join('conf_invoices as i', 'i.id', '=', 'pr.invoice_id')
            ->where('i.edition_id', $editionId)->where('pr.review_status', ProofStatus::Pending->value)->count();
    }

    public function rememberConsent(int $contextId, string $version, string $texts, DateTimeImmutable $now): void
    {
        if ($this->db->table('conf_consent_versions')->where('context_id', $contextId)->where('version', $version)->exists()) {
            return;
        }
        try {
            $this->db->table('conf_consent_versions')->insert([
                'context_id' => $contextId, 'version' => $version, 'texts' => $texts, 'created_at' => DateInput::toStorage($now),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request stored it first.
        }
    }

    public function consentVersions(int $contextId): array
    {
        $agreements = [];
        foreach ($this->db->table('conf_participants')->where('context_id', $contextId)->whereNotNull('consent_version')
            ->groupBy('consent_version')->selectRaw('consent_version, COUNT(*) as n')->get() as $r) {
            $agreements[(string) $r->consent_version] = (int) $r->n;
        }
        foreach ($this->db->table('conf_submission_registrations as sr')->join('conf_editions as e', 'e.id', '=', 'sr.edition_id')
            ->where('e.context_id', $contextId)->whereNotNull('sr.consent_version')
            ->groupBy('sr.consent_version')->selectRaw('sr.consent_version, COUNT(*) as n')->get() as $r) {
            $agreements[(string) $r->consent_version] = ($agreements[(string) $r->consent_version] ?? 0) + (int) $r->n;
        }
        return array_map(static fn (object $r): array => [
            'version' => (string) $r->version,
            'createdAt' => DateInput::toIsoUtc(DateInput::fromStorage((string) $r->created_at)),
            'texts' => (string) $r->texts,
            'agreements' => $agreements[(string) $r->version] ?? 0,
        ], $this->db->table('conf_consent_versions')->where('context_id', $contextId)->orderByDesc('id')->get()->all());
    }
}
