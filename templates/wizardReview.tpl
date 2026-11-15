{**
 * The "Conference participation" step on the Review step of the
 * submission wizard (REG-02). Rendered inside the wizard's Vue app, so
 * it reads the live form values from "steps" and the errors from "errors".
 *}
<div class="submissionWizard__reviewPanel">
	<div class="submissionWizard__reviewPanel__header">
		<h3 id="review{$step.id|escape}">{$step.reviewName|escape}</h3>
		<pkp-button
			aria-describedby="review{$step.id|escape}"
			class="submissionWizard__reviewPanel__edit"
			@click="openStep('{$step.id|escape}')"
		>
			{translate key="common.edit"}
		</pkp-button>
	</div>
	<div class="submissionWizard__reviewPanel__body submissionWizard__reviewPanel__body--{$step.id|escape}">
		<conference-registration-review
			:steps="steps"
			:errors="errors"
			step-id="{$step.id|escape}"
			none-label="{$conferenceReviewLabels.none|escape}"
			consent-given="{$conferenceReviewLabels.consentGiven|escape}"
			consent-missing="{$conferenceReviewLabels.consentMissing|escape}"
		></conference-registration-review>
	</div>
</div>
