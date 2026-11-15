<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Wizard;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionRegistrationService;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationValidator as F;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\SpeakerRegistration;
use DateTimeImmutable;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldSelect;
use PKP\components\forms\FieldText;
use PKP\components\forms\FormComponent;

/**
 * The "Conference participation" step of the OJS 3.5 submission wizard
 * (REG-02). The wizard renders each step from page state: a step holds
 * sections, a "form" section holds a PKP form whose values are autosaved
 * to the form's action, here the plugin API.
 */
final class ConferenceWizardStep
{
    public const STEP_ID = 'conference';
    public const FORM_ID = 'conferenceRegistration';
    private const K = 'plugins.generic.conferenceManager.registration.';

    public function __construct(
        private readonly SubmissionRegistrationService $service,
    ) {
    }

    /**
     * Saves the obvious answers once, so that what the form shows is also
     * what is stored even if the author never touches the step: the only
     * open edition, the submitter as presenter, a form of participation
     * that matches the format of the edition.
     *
     * @param list<Edition> $open
     */
    public function prefill(ContextInfo $context, int $submissionId, array $open, ?string $submitterEmail, string $consentVersion, DateTimeImmutable $now, bool $regularAllowed): SpeakerRegistration
    {
        $current = $this->service->get($submissionId);
        if ($current->editionId !== null || $current->presenterEmail !== null || $current->isRegistered()) {
            return $current;
        }
        $input = ['presenterEmail' => $submitterEmail];
        if (count($open) === 1 && !$regularAllowed) {
            $edition = $open[0];
            $input['editionId'] = $edition->id;
            $online = $edition->format === EventFormat::Online;
            $input['participationForm'] = $online ? ParticipationForm::Online->value : ParticipationForm::InPerson->value;
            $input['presentationType'] = $online ? PresentationType::Online->value : PresentationType::Oral->value;
        }
        $submission = new \APP\plugins\generic\conferenceManager\classes\Application\SubmissionInfo($submissionId, $context->id, false);
        [$saved] = $this->service->save($context, $submission, $input, $consentVersion, null, $now);
        return $saved ?? $current;
    }

    /**
     * @param list<Edition> $editions editions to offer (open ones plus the chosen one)
     * @param list<string> $locales
     * @return array<string, mixed> a step of the wizard state
     */
    public function build(
        ContextInfo $context,
        int $submissionId,
        array $editions,
        SpeakerRegistration $registration,
        ConsentText $consent,
        string $locale,
        array $locales,
        bool $regularAllowed,
    ): array {
        $request = Application::get()->getRequest();
        $action = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_API,
            $request->getContext()->getPath(),
            'conference/submissions/' . $submissionId . '/registration',
        );

        $form = new FormComponent(self::FORM_ID, 'PUT', $action, $locales);
        $editionOptions = [[
            'value' => '',
            'label' => __(self::K . ($regularAllowed ? 'edition.none' : 'edition.choose')),
        ]];
        foreach ($editions as $edition) {
            $editionOptions[] = ['value' => $edition->id, 'label' => $this->editionLabel($edition, $locale, $context->primaryLocale)];
        }
        $form->addField(new FieldSelect(F::FIELD_EDITION, [
            'label' => __(self::K . 'edition'),
            'description' => __(self::K . ($regularAllowed ? 'edition.descriptionOptional' : 'edition.description')),
            'options' => $editionOptions,
            'value' => $registration->editionId ?? '',
            'isRequired' => !$regularAllowed,
            'size' => 'large',
        ]));
        // FEE-01: the category of the presenter. One list for all offered
        // editions; the check on submit takes only the chosen edition's.
        $categoryOptions = [];
        $withCategories = 0;
        foreach ($editions as $edition) {
            $categories = $this->service->categories($edition->id ?? 0);
            $withCategories += $categories === [] ? 0 : 1;
            foreach ($categories as $category) {
                $name = (string) $category->name->resolve($locale, $context->primaryLocale);
                $categoryOptions[] = [
                    'value' => $category->id,
                    'label' => count($editions) > 1 ? $this->editionLabel($edition, $locale, $context->primaryLocale, false) . ' — ' . $name : $name,
                ];
            }
        }
        if ($categoryOptions !== []) {
            $form->addField(new FieldSelect(F::FIELD_CATEGORY, [
                'label' => __(self::K . 'category'),
                'description' => __(self::K . 'category.description'),
                'options' => array_merge([['value' => '', 'label' => __(self::K . 'category.choose')]], $categoryOptions),
                'value' => $registration->categoryId ?? '',
                'isRequired' => $withCategories === count($editions),
                'showWhen' => F::FIELD_EDITION,
                'size' => 'large',
            ]));
        }
        $form->addField(new FieldOptions(F::FIELD_PRESENTATION, [
            'label' => __(self::K . 'presentationType'),
            'type' => 'radio',
            'options' => $this->options(PresentationType::cases()),
            'value' => $registration->presentationType?->value,
            'isRequired' => true,
            'showWhen' => F::FIELD_EDITION,
        ]));
        $form->addField(new FieldOptions(F::FIELD_FORM, [
            'label' => __(self::K . 'participationForm'),
            'type' => 'radio',
            'options' => $this->options(ParticipationForm::cases()),
            'value' => $registration->participationForm?->value,
            'isRequired' => true,
            'showWhen' => F::FIELD_EDITION,
        ]));
        $form->addField(new FieldText(F::FIELD_PRESENTER, [
            'label' => __(self::K . 'presenterEmail'),
            'description' => __(self::K . 'presenterEmail.description'),
            'inputType' => 'email',
            'value' => $registration->presenterEmail ?? '',
            'isRequired' => true,
            'showWhen' => F::FIELD_EDITION,
        ]));
        // EVT-04: a presenter under 18 needs the legal representative's consent.
        $form->addField(new FieldOptions(F::FIELD_MINOR, [
            'label' => __(self::K . 'minor'),
            'type' => 'checkbox',
            'options' => [['value' => true, 'label' => __(self::K . 'minor.yes')]],
            'value' => $registration->presenterMinor ? [true] : [],
            'showWhen' => F::FIELD_EDITION,
        ]));
        $form->addField(new FieldOptions(F::FIELD_GUARDIAN, [
            'label' => __(self::K . 'guardian'),
            'description' => __(self::K . 'guardian.description'),
            'type' => 'checkbox',
            'options' => [['value' => true, 'label' => __(self::K . 'guardian.agree')]],
            'value' => $registration->guardianConsentAt !== null ? [true] : [],
            'showWhen' => F::FIELD_EDITION,
        ]));
        $form->addField(new FieldOptions(F::FIELD_CONSENT, [
            'label' => __(self::K . 'consent'),
            'description' => $consent->text,
            'type' => 'checkbox',
            'options' => [['value' => true, 'label' => __(self::K . 'consent.agree')]],
            'value' => $registration->hasConsent() && $registration->consentVersion === $consent->version ? [true] : [],
            'isRequired' => true,
            'showWhen' => F::FIELD_EDITION,
        ]));

        // As PKPSubmissionHandler::removeButtonFromForm(): wizard forms
        // have no save button, they are autosaved.
        $form->addPage(['id' => 'default'])->addGroup(['id' => 'default', 'pageId' => 'default']);
        foreach ($form->fields as $field) {
            $field->groupId = 'default';
        }

        return [
            'id' => self::STEP_ID,
            'name' => __(self::K . 'step'),
            'reviewName' => __(self::K . 'step'),
            'sections' => [[
                'id' => self::FORM_ID,
                'name' => __(self::K . 'step'),
                'type' => 'form',
                'description' => __(self::K . 'step.description'),
                'form' => $form->getConfig(),
            ]],
            'reviewTemplate' => '',
        ];
    }

    /**
     * Inserts the step before "Review" (the last step).
     *
     * @param list<array<string, mixed>> $steps
     * @param array<string, mixed> $step
     * @return list<array<string, mixed>>
     */
    public static function insert(array $steps, array $step): array
    {
        $out = [];
        $done = false;
        foreach ($steps as $existing) {
            if (($existing['id'] ?? null) === self::STEP_ID) {
                continue;
            }
            if (!$done && ($existing['id'] ?? null) === 'review') {
                $out[] = $step;
                $done = true;
            }
            $out[] = $existing;
        }
        if (!$done) {
            $out[] = $step;
        }
        return $out;
    }

    private function editionLabel(Edition $edition, string $locale, string $fallback, bool $withDates = true): string
    {
        $title = $edition->text('name')->resolve($locale, $fallback) ?? $edition->path;
        if (!$withDates) {
            return $title;
        }
        $starts = $edition->date('startsAt')?->setTimezone(new \DateTimeZone($edition->timezone));
        $deadline = $edition->date('abstractDeadline')?->setTimezone(new \DateTimeZone($edition->timezone));
        $parts = [$title];
        if ($starts !== null) {
            $parts[] = $starts->format('d.m.Y');
        }
        if ($deadline !== null) {
            $parts[] = __(self::K . 'edition.deadline', ['date' => $deadline->format('d.m.Y H:i')]);
        }
        return implode(' · ', $parts);
    }

    /**
     * @param list<\BackedEnum> $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(
            static fn (\BackedEnum $c): array => ['value' => (string) $c->value, 'label' => __($c->labelKey())], // @phpstan-ignore method.notFound
            $cases,
        );
    }
}
