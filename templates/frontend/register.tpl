{**
 * A listener registers for an edition (REG-05).
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}

<div class="container page page_conference_register cmEdition">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.listener.title"}</h1>
	<p class="cmEdition__meta"><a href="{$edition.url|escape}">{$edition.name|escape}</a></p>

	{if !$registerOpen}
		<p class="cmConfirm__result" role="alert">{translate key="plugins.generic.conferenceManager.listener.error.closed"}</p>
	{else}
		{if $registerErrors.form || $registerErrors.editionId}
			<div class="cmConfirm__result" role="alert">
				{foreach from=$registerErrors.form|default:[] item=e}<p>{$e|escape}</p>{/foreach}
				{foreach from=$registerErrors.editionId|default:[] item=e}<p>{$e|escape}</p>{/foreach}
				<p><a href="{$myUrl|escape}">{translate key="plugins.generic.conferenceManager.my.title"}</a></p>
			</div>
		{/if}

		<form method="post" action="{$registerUrl|escape}" class="cmRegister">
			{csrf}
			<p class="cmRegister__person">
				{translate key="plugins.generic.conferenceManager.listener.as" name=$registerPerson.name|escape email=$registerPerson.email|escape}
			</p>

			{if $registerCategories}
				<div class="cmRegister__field">
					<label for="cmCategory">{translate key="plugins.generic.conferenceManager.registration.category"} <span class="cmRegister__required" aria-hidden="true">*</span></label>
					<select id="cmCategory" name="categoryId" required {if $registerErrors.categoryId}aria-invalid="true" aria-describedby="cmCategoryError"{/if}>
						<option value="">{translate key="plugins.generic.conferenceManager.registration.category.choose"}</option>
						{foreach from=$registerCategories item=c}
							<option value="{$c.value|escape}" {if $registerValues.categoryId == $c.value}selected{/if}>{$c.label|escape}{if $c.requiresProof} — {translate key="plugins.generic.conferenceManager.fee.requiresProof.short"}{/if}</option>
						{/foreach}
					</select>
					{foreach from=$registerErrors.categoryId|default:[] item=e}<p class="cmRegister__error" id="cmCategoryError">{$e|escape}</p>{/foreach}
				</div>
			{/if}

			<fieldset class="cmRegister__field">
				<legend>{translate key="plugins.generic.conferenceManager.registration.participationForm"}</legend>
				{foreach from=$registerForms item=f}
					<label class="cmRegister__choice"><input type="radio" name="participationForm" value="{$f.value|escape}" required {if $registerValues.participationForm === $f.value}checked{/if}> {$f.label|escape}</label>
				{/foreach}
				{foreach from=$registerErrors.participationForm|default:[] item=e}<p class="cmRegister__error">{$e|escape}</p>{/foreach}
			</fieldset>

			<div class="cmRegister__field">
				<label for="cmAffiliation">{translate key="plugins.generic.conferenceManager.participants.affiliation"}</label>
				<input id="cmAffiliation" type="text" name="affiliation" maxlength="255" value="{$registerValues.affiliation|escape}">
			</div>
			<div class="cmRegister__field">
				<label for="cmPosition">{translate key="plugins.generic.conferenceManager.participants.position"}</label>
				<input id="cmPosition" type="text" name="position" maxlength="255" value="{$registerValues.position|escape}">
			</div>

			{* Left empty by people; robots fill every field. *}
			<div class="cmRegister__trap" aria-hidden="true">
				<label for="cmWebsite">Website</label>
				<input id="cmWebsite" type="text" name="website" tabindex="-1" autocomplete="off" value="">
			</div>

			<div class="cmRegister__field">
				<label class="cmRegister__choice"><input type="checkbox" name="minor" value="1" {if $registerValues.minor}checked{/if}> {translate key="plugins.generic.conferenceManager.listener.minor"}</label>
				<div class="cmRegister__guardian">
					<p class="cmRegister__hint">{translate key="plugins.generic.conferenceManager.registration.guardian.description"}</p>
					<label class="cmRegister__choice"><input type="checkbox" name="guardianConsent" value="1" {if $registerValues.guardianConsent}checked{/if}> {translate key="plugins.generic.conferenceManager.registration.guardian.agree"}</label>
					{foreach from=$registerErrors.guardianConsent|default:[] item=e}<p class="cmRegister__error">{$e|escape}</p>{/foreach}
				</div>
			</div>

			<div class="cmRegister__field">
				<p class="cmRegister__consentText">{$consentHtml}</p>
				<label class="cmRegister__choice"><input type="checkbox" name="consent" value="1" required {if $registerValues.consent}checked{/if}> {translate key="plugins.generic.conferenceManager.registration.consent.agree"}</label>
				{foreach from=$registerErrors.consent|default:[] item=e}<p class="cmRegister__error">{$e|escape}</p>{/foreach}
			</div>

			<button type="submit" class="cmEdition__button">{translate key="plugins.generic.conferenceManager.listener.submit"}</button>
		</form>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
