{**
 * Public check of a certificate by the code from its QR link (CRT-07).
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.verify.title"}

<div class="container page page_conference_verify cmEdition">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.verify.title"}</h1>

	{if $verifyLimited}
		<p class="cmConfirm__result" role="alert">{translate key="plugins.generic.conferenceManager.verify.limited"}</p>
	{elseif $verifyResult}
		<div class="cmVerify {if $verifyResult.valid}cmVerify--valid{else}cmVerify--revoked{/if}">
			<p class="cmVerify__status" role="status">
				{if $verifyResult.valid}
					{translate key="plugins.generic.conferenceManager.verify.valid"}
				{else}
					{translate key="plugins.generic.conferenceManager.verify.revoked"}
				{/if}
			</p>
			<dl class="cmEdition__dates">
				<dt>{translate key="plugins.generic.conferenceManager.verify.number"}</dt><dd><strong>{$verifyResult.number|escape}</strong></dd>
				<dt>{translate key="plugins.generic.conferenceManager.verify.type"}</dt><dd>{$verifyResult.type|escape}</dd>
				<dt>{translate key="plugins.generic.conferenceManager.verify.recipient"}</dt><dd>{$verifyResult.recipientName|escape}</dd>
				<dt>{translate key="plugins.generic.conferenceManager.certificate.conference"}</dt><dd>{$verifyResult.conferenceName|escape}{if $verifyResult.eventDates}, {$verifyResult.eventDates|escape}{/if}</dd>
				{if $verifyResult.institutionName}<dt>{translate key="plugins.generic.conferenceManager.verify.institution"}</dt><dd>{$verifyResult.institutionName|escape}</dd>{/if}
				{if $verifyResult.paperTitle}<dt>{translate key="plugins.generic.conferenceManager.verify.paper"}</dt><dd>{$verifyResult.paperTitle|escape}</dd>{/if}
				{if $verifyResult.hours}<dt>{translate key="plugins.generic.conferenceManager.verify.hours"}</dt><dd>{$verifyResult.hours|escape}</dd>{/if}
				<dt>{translate key="plugins.generic.conferenceManager.certificate.issuedAt"}</dt><dd>{$verifyResult.issueDate|escape}</dd>
				{if !$verifyResult.valid && $verifyResult.revokeReason}<dt>{translate key="plugins.generic.conferenceManager.verify.revokeReason"}</dt><dd>{$verifyResult.revokeReason|escape}</dd>{/if}
				{if $verifyResult.replacedBy}<dt>{translate key="plugins.generic.conferenceManager.verify.replacedBy"}</dt><dd>{$verifyResult.replacedBy|escape}</dd>{/if}
			</dl>
		</div>
	{elseif $verifyCode}
		<p class="cmConfirm__result" role="alert">{translate key="plugins.generic.conferenceManager.verify.notFound"}</p>
	{else}
		<p>{translate key="plugins.generic.conferenceManager.verify.intro"}</p>
	{/if}

	<form class="cmVerify__form" method="get" action="{$verifyUrl|escape}">
		<label for="cmVerifyCode">{translate key="plugins.generic.conferenceManager.verify.code"}</label>
		<input type="text" id="cmVerifyCode" name="code" value="{$verifyCode|escape}" maxlength="36" autocomplete="off" spellcheck="false">
		<button type="submit" class="cmEdition__button">{translate key="plugins.generic.conferenceManager.verify.check"}</button>
	</form>
</div>

{include file="frontend/components/footer.tpl"}
