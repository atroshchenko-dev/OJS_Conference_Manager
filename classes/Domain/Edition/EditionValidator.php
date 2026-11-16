<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\Isbn;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

/**
 * Business rules of an edition (EDN-01, 02, 04, 05; EVT-02, 03).
 */
final class EditionValidator
{
    public const PATH_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** Paths taken by pages of the plugin under /{context}/conference/. */
    public const RESERVED_PATHS = ['index', 'upcoming', 'confirm', 'my', 'invoice', 'receipt', 'certificate', 'verify', 'documents', 'invitationLetter', 'sessionAttendance', 'checkin', 'payment'];

    private const K = 'plugins.generic.conferenceManager.validation.';

    /**
     * @param ?Edition $current the stored edition when updating
     * @param ?ValidationErrors $parseErrors errors of Edition::fromArray(); an
     *        unreadable date is not reported again as missing
     */
    public function validate(Edition $edition, string $primaryLocale, ?Edition $current = null, ?ValidationErrors $parseErrors = null): ValidationErrors
    {
        $errors = new ValidationErrors();

        if ($edition->text('name')->get($primaryLocale) === null) {
            $errors->add('name', self::K . 'requiredInPrimaryLocale');
        }
        foreach (Edition::LOCALIZED_FIELDS as $field) {
            $limit = in_array($field, ['name', 'venue'], true) ? 500 : 65000;
            foreach ($edition->text($field)->toArray() as $text) {
                if (mb_strlen($text) > $limit) {
                    $errors->add($field, self::K . 'tooLong');
                    break;
                }
            }
        }

        if ($edition->path === '') {
            $errors->add('path', self::K . 'required');
        } elseif (!preg_match(self::PATH_PATTERN, $edition->path)) {
            $errors->add('path', self::K . 'path');
        } elseif (in_array($edition->path, self::RESERVED_PATHS, true)) {
            $errors->add('path', self::K . 'pathReserved');
        }

        $this->validateDates($edition, $errors, $parseErrors ?? new ValidationErrors());

        if ($edition->publicationMode === PublicationMode::External) {
            $url = $edition->externalProceedingsUrl;
            if ($url === null) {
                $errors->add('externalProceedingsUrl', self::K . 'required');
            } elseif (!preg_match('#^https?://\S+$#i', $url) || strlen($url) > 2047) {
                $errors->add('externalProceedingsUrl', self::K . 'url');
            }
        }

        if ($edition->isbn !== null && !Isbn::isValid13($edition->isbn)) {
            $errors->add('isbn', self::K . 'isbn');
        }
        if ($edition->udc !== null && mb_strlen($edition->udc) > 32) {
            $errors->add('udc', self::K . 'tooLong');
        }
        if ($edition->vectorProgramId !== null && mb_strlen($edition->vectorProgramId) > 64) {
            $errors->add('vectorProgramId', self::K . 'tooLong');
        }
        foreach (['hours' => $edition->hours, 'ectsX100' => $edition->ectsX100] as $field => $value) {
            if ($value !== null && ($value < 0 || $value > 100000)) {
                $errors->add($field, self::K . 'range');
            }
        }

        if ($current !== null && !$current->status->canMoveTo($edition->status)) {
            $errors->add('status', self::K . 'statusTransition');
        }
        if ($current === null && $edition->status !== EditionStatus::Draft && $edition->status !== EditionStatus::CfpOpen) {
            $errors->add('status', self::K . 'statusTransition');
        }

        return $errors;
    }

    private function validateDates(Edition $edition, ValidationErrors $errors, ValidationErrors $parseErrors): void
    {
        $starts = $edition->date('startsAt');
        $ends = $edition->date('endsAt');
        $opens = $edition->date('cfpOpensAt');
        $abstract = $edition->date('abstractDeadline');
        $paper = $edition->date('paperDeadline');

        if ($starts === null && !$parseErrors->has('startsAt')) {
            $errors->add('startsAt', self::K . 'required');
        }
        if ($ends === null && !$parseErrors->has('endsAt')) {
            $errors->add('endsAt', self::K . 'required');
        }
        if ($starts !== null && $ends !== null && $ends < $starts) {
            $errors->add('endsAt', self::K . 'dateOrder');
        }

        if ($edition->submissionMode !== SubmissionMode::None) {
            // EDN-02: opening and closing of the call are the only required windows.
            if ($opens === null && !$parseErrors->has('cfpOpensAt')) {
                $errors->add('cfpOpensAt', self::K . 'required');
            }
            if ($abstract === null && !$parseErrors->has('abstractDeadline')) {
                $errors->add('abstractDeadline', self::K . 'required');
            }
        }
        if ($opens !== null && $abstract !== null && !($opens < $abstract)) {
            $errors->add('abstractDeadline', self::K . 'dateOrder');
        }
        if ($paper !== null) {
            if ($edition->submissionMode !== SubmissionMode::AbstractsAndPapers) {
                $errors->add('paperDeadline', self::K . 'paperDeadlineMode');
            } elseif ($abstract !== null && $paper < $abstract) {
                $errors->add('paperDeadline', self::K . 'dateOrder');
            }
        }
    }
}
