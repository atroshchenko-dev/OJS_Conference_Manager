<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PromoCode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PromoRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;

/**
 * REG-11: the manager's list of promo codes of an edition. A code that
 * was used is kept for the record: it can be switched off, not deleted.
 */
final class PromoService
{
    private const K = 'plugins.generic.conferenceManager.promo.error.';

    public function __construct(
        private readonly PromoRepository $promos,
        private readonly EditionRepository $editions,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /** @return list<PromoCode>|null null when the edition is not of this context */
    public function ofEdition(ContextInfo $context, int $editionId): ?array
    {
        return $this->editions->find($context->id, $editionId) === null ? null : $this->promos->ofEdition($editionId);
    }

    /**
     * @param array<string, mixed> $input see PromoCode::fromInput
     * @return array{0: ?PromoCode, 1: ValidationErrors}|null
     */
    public function save(ContextInfo $context, int $editionId, ?int $id, array $input, ?int $actorId): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $old = $id === null ? null : $this->promos->find($editionId, $id);
        if ($id !== null && $old === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $code = PromoCode::fromInput($id, $editionId, $input, $errors);
        if ($code === null) {
            return [null, $errors];
        }
        $same = $this->promos->findByCode($editionId, $code->code);
        if ($same !== null && $same->id !== $id) {
            $errors->add('code', self::K . 'duplicate');
            return [null, $errors];
        }
        if ($id === null && count($this->promos->ofEdition($editionId)) >= PromoCode::MAX_PER_EDITION) {
            $errors->add('code', self::K . 'tooMany');
            return [null, $errors];
        }
        // The terms of a used code stay as they were: only the limits and the switch change.
        if ($old !== null && $this->promos->everUsed((int) $old->id)
            && ($old->code !== $code->code || $old->percent !== $code->percent || $old->amount?->minor !== $code->amount?->minor || $old->amount?->currency !== $code->amount?->currency)) {
            $errors->add('code', self::K . 'usedTerms');
            return [null, $errors];
        }
        $saved = $this->promos->save($code);
        $this->audit?->log($context->id, $actorId, 'promo_code', $saved->id, $old === null ? 'created' : 'updated', $old?->toArray(), $saved->toArray());
        return [$saved, $errors];
    }

    /** @return bool|null null when not found, false when the code was used */
    public function delete(ContextInfo $context, int $editionId, int $id, ?int $actorId): ?bool
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $code = $this->promos->find($editionId, $id);
        if ($code === null) {
            return null;
        }
        if ($this->promos->everUsed($id)) {
            return false;
        }
        $this->promos->delete($editionId, $id);
        $this->audit?->log($context->id, $actorId, 'promo_code', $id, 'deleted', $code->toArray(), null);
        return true;
    }
}
