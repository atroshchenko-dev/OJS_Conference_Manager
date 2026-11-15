<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind;
use APP\plugins\generic\conferenceManager\classes\Domain\Partner\Partner;
use APP\plugins\generic\conferenceManager\classes\Domain\Partner\PartnerLogo;
use APP\plugins\generic\conferenceManager\classes\Domain\Partner\PartnerRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Partner\PartnerRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;

/**
 * INS-04: co-organisers and partners of an edition with logos and roles;
 * a co-organiser may receive the fees through a payment account of the
 * journal's profile ("partner" kind). The public page shows them in order.
 */
final class PartnerService
{
    public const MAX = 30;
    private const K = 'plugins.generic.conferenceManager.';

    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly EditionRepository $editions,
        private readonly InstitutionProfileService $institutions,
        /** Public logo files: keys like "{context}/{edition}-{partner}-{hash}.png". */
        private readonly ?ProofStore $logos = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /** @return list<Partner>|null null when there is no such edition */
    public function ofEdition(ContextInfo $context, int $editionId): ?array
    {
        return $this->editions->find($context->id, $editionId) === null ? null : $this->partners->ofEdition($editionId);
    }

    /**
     * @param array<string, mixed> $input role, name{locale}, url, paymentAccountId
     * @return array{0: ?Partner, 1: ValidationErrors}|null null when the edition or partner is not found
     */
    public function save(ContextInfo $context, int $editionId, ?int $id, array $input, ?int $actorId = null): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $current = $id === null ? null : $this->partners->find($editionId, $id);
        if ($id !== null && $current === null) {
            return null;
        }
        $others = $this->partners->ofEdition($editionId);
        $errors = new ValidationErrors();
        $role = PartnerRole::tryFrom((string) ($input['role'] ?? $current?->role->value ?? PartnerRole::Partner->value));
        if ($role === null) {
            $errors->add('role', self::K . 'validation.invalidChoice');
        }
        $names = [];
        foreach ((array) ($input['name'] ?? $current?->name->toArray() ?? []) as $locale => $text) {
            $text = trim((string) $text);
            if ($text !== '' && in_array((string) $locale, $context->formLocales, true)) {
                $names[(string) $locale] = $text;
            }
        }
        if (($names[$context->primaryLocale] ?? '') === '') {
            $errors->add('name', self::K . 'validation.requiredInPrimaryLocale');
        }
        foreach ($names as $text) {
            if (mb_strlen($text) > 255) {
                $errors->add('name', self::K . 'validation.tooLong');
                break;
            }
        }
        $url = trim((string) ($input['url'] ?? $current->url ?? ''));
        if ($url !== '' && (mb_strlen($url) > 2048 || !preg_match('~^https?://[^\s/]+\.[^\s]+$~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false)) {
            $errors->add('url', self::K . 'validation.url');
        }
        $accountId = array_key_exists('paymentAccountId', $input)
            ? (($input['paymentAccountId'] === null || $input['paymentAccountId'] === '') ? null : (int) $input['paymentAccountId'])
            : $current?->paymentAccountId;
        if ($accountId !== null) {
            $ok = false;
            foreach ($this->institutions->getOrDraft($context)->activePaymentAccounts() as $a) {
                if ((int) $a->id === $accountId && $a->kind === PaymentAccountKind::Partner) {
                    $ok = true;
                }
            }
            if (!$ok) {
                $errors->add('paymentAccountId', self::K . 'partner.error.account');
            }
            if ($role !== null && $role !== PartnerRole::CoOrganizer) {
                $errors->add('paymentAccountId', self::K . 'partner.error.accountRole');
            }
        }
        if ($id === null && count($others) >= self::MAX) {
            $errors->add('name', self::K . 'partner.error.tooMany');
        }
        if (!$errors->isEmpty() || $role === null) {
            return [null, $errors];
        }
        $partner = new Partner($id, $editionId, $role, new LocalizedText($names), $url, $current?->logoPath, $accountId, $current->seq ?? count($others));
        $saved = $this->partners->save($partner);
        $this->audit?->log($context->id, $actorId, 'partner', $saved->id, $id === null ? 'create' : 'update', $current?->toArray(), $saved->toArray());
        return [$saved, $errors];
    }

    public function delete(ContextInfo $context, int $editionId, int $id, ?int $actorId = null): bool
    {
        $current = $this->editions->find($context->id, $editionId) === null ? null : $this->partners->find($editionId, $id);
        if ($current === null) {
            return false;
        }
        $this->partners->delete($editionId, $id);
        if ($current->logoPath !== null) {
            $this->logos?->remove($current->logoPath);
        }
        $this->renumber($editionId);
        $this->audit?->log($context->id, $actorId, 'partner', $id, 'delete', $current->toArray(), null);
        return true;
    }

    /**
     * Moves a partner one place up (-1) or down (+1).
     *
     * @return list<Partner>|null
     */
    public function move(ContextInfo $context, int $editionId, int $id, int $direction): ?array
    {
        $list = $this->ofEdition($context, $editionId);
        if ($list === null) {
            return null;
        }
        $index = null;
        foreach ($list as $i => $p) {
            if ($p->id === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            return null;
        }
        $to = $index + ($direction < 0 ? -1 : 1);
        if ($to >= 0 && $to < count($list)) {
            [$list[$index], $list[$to]] = [$list[$to], $list[$index]];
        }
        foreach ($list as $seq => $p) {
            if ($p->seq !== $seq) {
                $this->partners->save($p->withSeq($seq));
            }
        }
        return $this->partners->ofEdition($editionId);
    }

    /**
     * Stores the logo from an uploaded file; the old one is removed.
     *
     * @return array{0: ?Partner, 1: ValidationErrors}|null
     */
    public function setLogo(ContextInfo $context, int $editionId, int $id, string $tmpPath, ?int $actorId = null): ?array
    {
        $current = $this->editions->find($context->id, $editionId) === null ? null : $this->partners->find($editionId, $id);
        if ($current === null || $this->logos === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $size = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
        $head = $size > 0 ? (string) file_get_contents($tmpPath, false, null, 0, 16) : '';
        $ext = PartnerLogo::extension($head);
        if ($size === 0) {
            $errors->add('logo', self::K . 'partner.error.logoEmpty');
        } elseif ($size > PartnerLogo::MAX_BYTES) {
            $errors->add('logo', self::K . 'partner.error.logoTooLarge');
        } elseif ($ext === null) {
            $errors->add('logo', self::K . 'partner.error.logoType');
        }
        if (!$errors->isEmpty() || $ext === null) {
            return [null, $errors];
        }
        $key = $context->id . '/' . $editionId . '-' . $id . '-' . substr((string) hash_file('sha256', $tmpPath), 0, 12) . '.' . $ext;
        if (!$this->logos->put($tmpPath, $key)) {
            $errors->add('logo', self::K . 'partner.error.logoStore');
            return [null, $errors];
        }
        if ($current->logoPath !== null && $current->logoPath !== $key) {
            $this->logos->remove($current->logoPath);
        }
        $saved = $this->partners->save($current->withLogo($key));
        $this->audit?->log($context->id, $actorId, 'partner', $id, 'logo', ['logoPath' => $current->logoPath], ['logoPath' => $key]);
        return [$saved, $errors];
    }

    public function removeLogo(ContextInfo $context, int $editionId, int $id, ?int $actorId = null): ?Partner
    {
        $current = $this->editions->find($context->id, $editionId) === null ? null : $this->partners->find($editionId, $id);
        if ($current === null) {
            return null;
        }
        if ($current->logoPath !== null) {
            $this->logos?->remove($current->logoPath);
            $this->audit?->log($context->id, $actorId, 'partner', $id, 'logo', ['logoPath' => $current->logoPath], ['logoPath' => null]);
        }
        return $this->partners->save($current->withLogo(null));
    }

    /**
     * For the manager: the partner with the name of its payment account.
     *
     * @param \Closure(string): string $logoUrl
     * @return array<string, mixed>
     */
    public function present(ContextInfo $context, Partner $p, \Closure $logoUrl, string $locale): array
    {
        $account = null;
        if ($p->paymentAccountId !== null) {
            foreach ($this->institutions->getOrDraft($context)->paymentAccounts as $a) {
                if ((int) $a->id === $p->paymentAccountId) {
                    $account = $a->label !== '' ? $a->label : $a->recipientName;
                }
            }
        }
        return $p->toArray() + [
            'localizedName' => (string) $p->name->resolve($locale, $context->primaryLocale),
            'logoUrl' => $p->logoPath === null ? null : $logoUrl($p->logoPath),
            'paymentAccountName' => $account,
        ];
    }

    /**
     * The public list grouped by role, co-organisers first.
     *
     * @param \Closure(string): string $logoUrl
     * @return list<array{role: string, partners: list<array{name: string, url: string, logoUrl: ?string, receivesFees: bool}>}>
     */
    public function publicGroups(ContextInfo $context, int $editionId, string $locale, \Closure $logoUrl): array
    {
        $groups = [];
        foreach ($this->partners->ofEdition($editionId) as $p) {
            $groups[$p->role->value][] = [
                'name' => (string) $p->name->resolve($locale, $context->primaryLocale),
                'url' => $p->url,
                'logoUrl' => $p->logoPath === null ? null : $logoUrl($p->logoPath),
                'receivesFees' => $p->paymentAccountId !== null,
            ];
        }
        $out = [];
        foreach (PartnerRole::cases() as $role) {
            if (isset($groups[$role->value])) {
                $out[] = ['role' => $role->value, 'partners' => $groups[$role->value]];
            }
        }
        return $out;
    }

    /**
     * EDN-09: the partners of $sourceId with copies of their logos go to
     * $targetId, unless it already has partners. Returns how many were copied.
     */
    public function copyAll(ContextInfo $context, int $sourceId, int $targetId, ?int $actorId = null): int
    {
        if ($this->editions->find($context->id, $sourceId) === null || $this->editions->find($context->id, $targetId) === null
            || $this->partners->ofEdition($targetId) !== []) {
            return 0;
        }
        $n = 0;
        foreach ($this->partners->ofEdition($sourceId) as $p) {
            $saved = $this->partners->save(new Partner(null, $targetId, $p->role, $p->name, $p->url, null, $p->paymentAccountId, $p->seq));
            $content = $p->logoPath === null ? null : $this->logos?->read($p->logoPath);
            if ($content !== null && preg_match('~\.([a-z]+)$~', (string) $p->logoPath, $m) === 1) {
                $tmp = tempnam(sys_get_temp_dir(), 'cmlogo');
                if ($tmp !== false && file_put_contents($tmp, $content) !== false) {
                    $key = $context->id . '/' . $targetId . '-' . $saved->id . '-' . substr(hash('sha256', $content), 0, 12) . '.' . $m[1];
                    if ($this->logos->put($tmp, $key)) {
                        $saved = $this->partners->save($saved->withLogo($key));
                    }
                }
                if ($tmp !== false) {
                    @unlink($tmp);
                }
            }
            $this->audit?->log($context->id, $actorId, 'partner', $saved->id, 'copy', null, $saved->toArray() + ['from' => $p->id]);
            $n++;
        }
        return $n;
    }

    private function renumber(int $editionId): void
    {
        foreach ($this->partners->ofEdition($editionId) as $seq => $p) {
            if ($p->seq !== $seq) {
                $this->partners->save($p->withSeq($seq));
            }
        }
    }
}
