{**
 * templates/institutionSettings.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Institution profile of the current context (INS-01…07).
 *}
<script>
	$(function() {ldelim}
		$('#confManagerInstitutionForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="confManagerInstitutionForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="institution" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="confManagerInstitutionFormNotification"}

	{if $isError}
		<div id="confManagerInstitutionErrors" role="alert">
			<span class="pkp_form_error">{translate key="form.errorsOccurred"}:</span>
			<ul class="pkp_form_error_list">
				{foreach key=field item=message from=$errors}
					<li>{$message|escape}</li>
				{/foreach}
			</ul>
		</div>
	{/if}

	<p>{translate key="plugins.generic.conferenceManager.institution.intro"}</p>

	{fbvFormArea id="confManagerInstitutionMain" title="plugins.generic.conferenceManager.institution.section.main"}
		{fbvFormSection label="plugins.generic.conferenceManager.field.jurisdiction" description="plugins.generic.conferenceManager.institution.jurisdiction.description"}
			{fbvElement type="select" id="jurisdiction" from=$jurisdictionOptions selected=$jurisdiction translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="select" id="institutionType" label="plugins.generic.conferenceManager.field.institutionType" from=$institutionTypeOptions selected=$institutionType translate=false inline=true size=$fbvStyles.size.MEDIUM}
			{fbvElement type="select" id="ownership" label="plugins.generic.conferenceManager.field.ownership" from=$ownershipOptions selected=$ownership translate=false inline=true size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.conferenceManager.field.name" required=true}
			{fbvElement type="text" id="name" value=$name multilingual=true maxlength="500" required=true}
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.conferenceManager.field.shortName"}
			{fbvElement type="text" id="shortName" value=$shortName multilingual=true maxlength="500"}
		{/fbvFormSection}
		{fbvFormSection label=$legalIdLabel}
			{fbvElement type="text" id="legalId" value=$legalId maxlength="32" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
		{foreach from=$extraFields item=field}
			{fbvFormSection label=$field->labelKey description=$field->descriptionKey}
				<input type="text" name="extra[{$field->name|escape}]" id="extra-{$field->name|escape}" value="{$extra[$field->name]|default:''|escape}" maxlength="{$field->maxLength}" class="field text" />
			{/fbvFormSection}
		{/foreach}
		{fbvFormSection label="plugins.generic.conferenceManager.field.address"}
			{fbvElement type="textarea" id="address" value=$address multilingual=true rows=2}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="email" id="contactEmail" label="plugins.generic.conferenceManager.field.contactEmail" value=$contactEmail inline=true size=$fbvStyles.size.MEDIUM}
			{fbvElement type="url" id="website" label="plugins.generic.conferenceManager.field.website" value=$website inline=true size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="confManagerInstitutionPrivacy" title="plugins.generic.conferenceManager.institution.section.privacy"}
		{fbvFormSection label="plugins.generic.conferenceManager.field.privacyNotice" description="plugins.generic.conferenceManager.institution.privacyNotice.description"}
			{fbvElement type="textarea" id="privacyNotice" value=$privacyNotice multilingual=true rich=true}
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.conferenceManager.field.privacyContact"}
			{fbvElement type="text" id="privacyContact" value=$privacyContact multilingual=true maxlength="500"}
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.conferenceManager.field.retentionNotice"}
			{fbvElement type="textarea" id="retentionNotice" value=$retentionNotice multilingual=true rows=3}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="confManagerInstitutionSigners" title="plugins.generic.conferenceManager.signers"}
		<p class="description">{translate key="plugins.generic.conferenceManager.signers.description"}</p>
		{foreach from=$signerRows item=row key=i}
			<fieldset class="confManager_row">
				<legend>{if $row.id}{translate key="plugins.generic.conferenceManager.signers"} #{$i+1}{else}{translate key="plugins.generic.conferenceManager.signers.add"}{/if}</legend>
				<input type="hidden" name="signers[{$i}][id]" value="{$row.id|escape}" />
				{foreach from=$formLocales key=locale item=localeName}
					<div class="section">
						<label>{translate key="plugins.generic.conferenceManager.field.fullName"} ({$localeName|escape}){if $locale == $primaryLocale} *{/if}
							<input type="text" class="field text" maxlength="255" name="signers[{$i}][fullName][{$locale|escape}]" value="{$row.fullName[$locale]|default:''|escape}" />
						</label>
						<label>{translate key="plugins.generic.conferenceManager.field.position"} ({$localeName|escape}){if $locale == $primaryLocale} *{/if}
							<input type="text" class="field text" maxlength="255" name="signers[{$i}][position][{$locale|escape}]" value="{$row.position[$locale]|default:''|escape}" />
						</label>
					</div>
				{/foreach}
				<div class="section">
					<span class="label">{translate key="plugins.generic.conferenceManager.field.documentTypes"}</span>
					{foreach from=$documentTypeOptions key=value item=label}
						<label class="confManager_inline"><input type="checkbox" name="signers[{$i}][documentTypes][]" value="{$value|escape}"{if isset($row.documentTypesMap[$value])} checked{/if} /> {$label|escape}</label>
					{/foreach}
				</div>
				{if $row.id}
					<label class="confManager_inline"><input type="checkbox" name="signers[{$i}][remove]" value="1" /> {translate key="plugins.generic.conferenceManager.remove"}</label>
				{/if}
			</fieldset>
		{/foreach}
	{/fbvFormArea}

	{fbvFormArea id="confManagerInstitutionAccounts" title="plugins.generic.conferenceManager.paymentAccounts"}
		<p class="description">{translate key="plugins.generic.conferenceManager.paymentAccounts.description" variables=$purposeVariables}</p>
		{foreach from=$accountRows item=row key=i}
			<fieldset class="confManager_row">
				<legend>{if $row.id}{translate key="plugins.generic.conferenceManager.paymentAccounts"} #{$i+1}{else}{translate key="plugins.generic.conferenceManager.paymentAccounts.add"}{/if}</legend>
				<input type="hidden" name="paymentAccounts[{$i}][id]" value="{$row.id|escape}" />
				<div class="section">
					<label>{translate key="plugins.generic.conferenceManager.field.kind"}
						<select name="paymentAccounts[{$i}][kind]" class="field select">
							{foreach from=$accountKindOptions key=value item=label}
								<option value="{$value|escape}"{if $row.kind == $value} selected{/if}>{$label|escape}</option>
							{/foreach}
						</select>
					</label>
					<label>{translate key="plugins.generic.conferenceManager.field.label"}
						<input type="text" class="field text" maxlength="255" name="paymentAccounts[{$i}][label]" value="{$row.label|default:''|escape}" />
					</label>
				</div>
				<div class="section">
					<label>{translate key="plugins.generic.conferenceManager.field.recipientName"} *
						<input type="text" class="field text" maxlength="255" name="paymentAccounts[{$i}][recipientName]" value="{$row.recipientName|default:''|escape}" />
					</label>
					<label>IBAN *
						<input type="text" class="field text" maxlength="42" name="paymentAccounts[{$i}][iban]" value="{$row.iban|default:''|escape}" />
					</label>
				</div>
				<div class="section">
					<label>{translate key="plugins.generic.conferenceManager.field.bankName"}
						<input type="text" class="field text" maxlength="255" name="paymentAccounts[{$i}][bankName]" value="{$row.bankName|default:''|escape}" />
					</label>
					<label>{translate key=$legalIdLabel}
						<input type="text" class="field text" maxlength="32" name="paymentAccounts[{$i}][legalId]" value="{$row.legalId|default:''|escape}" />
					</label>
				</div>
				<div class="section">
					<label>{translate key="plugins.generic.conferenceManager.field.purposeTemplate"}
						<input type="text" class="field text" maxlength="420" name="paymentAccounts[{$i}][purposeTemplate]" value="{$row.purposeTemplate|default:''|escape}" />
					</label>
				</div>
				<label class="confManager_inline"><input type="checkbox" name="paymentAccounts[{$i}][isActive]" value="1"{if $row.isActive} checked{/if} /> {translate key="plugins.generic.conferenceManager.field.isActive"}</label>
				{if $row.id}
					<label class="confManager_inline"><input type="checkbox" name="paymentAccounts[{$i}][remove]" value="1" /> {translate key="plugins.generic.conferenceManager.remove"}</label>
				{/if}
			</fieldset>
		{/foreach}
	{/fbvFormArea}

	{fbvFormArea id="confManagerInstitutionImport" title="plugins.generic.conferenceManager.institution.section.importExport"}
		<p><a href="{$exportUrl|escape}" download>{translate key="plugins.generic.conferenceManager.institution.export"}</a></p>
		{fbvFormSection label="plugins.generic.conferenceManager.institution.import" description="plugins.generic.conferenceManager.institution.import.description"}
			{fbvElement type="textarea" id="importJson" value="" rows=4}
		{/fbvFormSection}
	{/fbvFormArea}

	<style>
		#confManagerInstitutionForm .confManager_row {ldelim} border: 1px solid #ddd; padding: 0.5rem 1rem; margin: 0 0 1rem; {rdelim}
		#confManagerInstitutionForm .confManager_row label {ldelim} display: inline-block; min-width: 45%; margin: 0 1rem 0.5rem 0; vertical-align: top; {rdelim}
		#confManagerInstitutionForm .confManager_row label input.text,
		#confManagerInstitutionForm .confManager_row select {ldelim} display: block; width: 100%; {rdelim}
		#confManagerInstitutionForm .confManager_inline {ldelim} display: inline-block; min-width: 0 !important; margin-right: 1rem; {rdelim}
	</style>

	{fbvFormButtons}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
