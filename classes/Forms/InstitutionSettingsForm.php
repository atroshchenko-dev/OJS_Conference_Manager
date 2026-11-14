<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Forms;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\DocumentType;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionProfile;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\Ownership;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccount;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

/**
 * Institution profile settings in the plugin modal (INS-01…07).
 *
 * The form only moves data; all rules live in the domain validator, so
 * the REST API and this form accept exactly the same profiles.
 */
class InstitutionSettingsForm extends Form
{
    private ?InstitutionProfile $saved = null;

    public function __construct(
        private readonly \PKP\plugins\Plugin $plugin,
        private readonly ContextInfo $context,
        private readonly InstitutionProfileService $service,
        private readonly JurisdictionRegistry $jurisdictions,
    ) {
        parent::__construct($plugin->getTemplateResource('institutionSettings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData(): void
    {
        $this->_data = $this->service->getOrDraft($this->context)->toArray();
    }

    public function readInputData(): void
    {
        $this->readUserVars([
            'jurisdiction', 'institutionType', 'ownership', 'legalId', 'contactEmail', 'website', 'extra',
            ...InstitutionProfile::LOCALIZED_FIELDS,
            'signers', 'paymentAccounts', 'importJson',
        ]);
        $this->setData('signers', self::cleanRows((array) $this->getData('signers'), ['fullName', 'position']));
        $this->setData('paymentAccounts', self::cleanRows((array) $this->getData('paymentAccounts'), ['recipientName', 'iban', 'label']));
        foreach ((array) $this->getData('paymentAccounts') as $i => $row) {
            // An unchecked checkbox is not posted at all.
            $this->_data['paymentAccounts'][$i]['isActive'] = !empty($row['isActive']);
        }
    }

    public function validate($callHooks = true): bool
    {
        if (!parent::validate($callHooks)) {
            return false;
        }
        if (trim((string) $this->getData('importJson')) !== '') {
            return true; // validated by import()
        }
        $errors = $this->service->validate($this->context, $this->profileInput());
        $this->addDomainErrors($errors);
        return $errors->isEmpty();
    }

    public function execute(...$functionArgs): mixed
    {
        $request = \APP\core\Application::get()->getRequest();
        $userId = $request->getUser()?->getId();
        $ip = $request->getRemoteAddr();

        $json = trim((string) $this->getData('importJson'));
        [$saved, $errors] = $json !== ''
            ? $this->service->import($this->context, $json, $userId, $ip)
            : $this->service->save($this->context, $this->profileInput(), $userId, $ip);

        $this->addDomainErrors($errors);
        $this->saved = $saved;
        parent::execute(...$functionArgs);
        return $saved;
    }

    public function isSaved(): bool
    {
        return $this->saved !== null;
    }

    public function fetch($request, $template = null, $display = false)
    {
        $jurisdictionCode = (string) ($this->getData('jurisdiction') ?: 'generic');
        $profile = $this->jurisdictions->has($jurisdictionCode)
            ? $this->jurisdictions->get($jurisdictionCode)
            : $this->jurisdictions->get('generic');

        $signers = array_values((array) $this->getData('signers'));
        $signers[] = ['id' => '', 'fullName' => [], 'position' => [], 'documentTypes' => []];
        foreach ($signers as $i => $signer) {
            $signers[$i]['documentTypesMap'] = array_fill_keys(
                array_filter((array) ($signer['documentTypes'] ?? []), 'is_string'),
                true,
            );
        }
        $accounts = array_values((array) $this->getData('paymentAccounts'));
        $accounts[] = ['id' => '', 'kind' => 'bank', 'isActive' => true];

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'jurisdictionOptions' => array_map(fn ($p) => __($p->getNameKey()), $this->jurisdictions->all()),
            'institutionTypeOptions' => self::enumOptions(InstitutionType::cases()),
            'ownershipOptions' => self::enumOptions(Ownership::cases()),
            'documentTypeOptions' => self::enumOptions(DocumentType::cases()),
            'accountKindOptions' => self::enumOptions(array_filter(
                PaymentAccountKind::cases(),
                fn (PaymentAccountKind $k) => in_array($k, $profile->allowedAccountKinds(), true),
            )),
            'legalIdLabel' => $profile->getLegalIdLabelKey(),
            'extraFields' => $profile->getInstitutionExtraFields(),
            'signerRows' => $signers,
            'accountRows' => $accounts,
            'purposeVariables' => '{' . implode('}, {', PaymentAccount::PURPOSE_VARIABLES) . '}',
            'exportUrl' => $request->getDispatcher()->url(
                $request,
                \PKP\core\PKPApplication::ROUTE_API,
                $request->getContext()->getPath(),
                'conference/institution/export',
            ),
            'primaryLocale' => $this->context->primaryLocale,
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileInput(): array
    {
        $input = [];
        foreach ([
            'jurisdiction', 'institutionType', 'ownership', 'legalId', 'contactEmail', 'website', 'extra',
            ...InstitutionProfile::LOCALIZED_FIELDS,
            'signers', 'paymentAccounts',
        ] as $key) {
            $input[$key] = $this->getData($key);
        }
        $current = $this->service->getOrDraft($this->context);
        $input['logoPath'] = $current->logoPath;
        $input['sealPath'] = $current->sealPath;
        return $input;
    }

    private function addDomainErrors(ValidationErrors $errors): void
    {
        foreach ($errors->toArray() as $field => $keys) {
            $label = $field;
            if (preg_match('/^(signers|paymentAccounts)\.(\d+)\.(\w+)$/', $field, $m)) {
                $label = __('plugins.generic.conferenceManager.' . $m[1]) . ' #' . ((int) $m[2] + 1) . ': '
                    . __('plugins.generic.conferenceManager.field.' . $m[3]);
            } elseif (str_starts_with($field, 'extra.')) {
                $label = substr($field, 6);
            } else {
                $label = __('plugins.generic.conferenceManager.field.' . $field);
            }
            foreach ($keys as $key) {
                $this->addError($field, $label . ' — ' . __($key));
            }
        }
    }

    /**
     * Drops removed and completely empty rows.
     *
     * @param array<mixed> $rows
     * @param list<string> $significant fields that make a row non-empty
     * @return list<array<string, mixed>>
     */
    private static function cleanRows(array $rows, array $significant): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            $hasValue = false;
            foreach ($significant as $field) {
                $value = $row[$field] ?? '';
                $values = is_array($value) ? $value : [$value];
                foreach ($values as $v) {
                    if (is_string($v) && trim($v) !== '') {
                        $hasValue = true;
                    }
                }
            }
            if ($hasValue) {
                unset($row['remove']);
                $clean[] = $row;
            }
        }
        return $clean;
    }

    /**
     * @param iterable<InstitutionType|Ownership|DocumentType|PaymentAccountKind> $cases
     * @return array<string, string>
     */
    private static function enumOptions(iterable $cases): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = __($case->labelKey());
        }
        return $options;
    }
}
