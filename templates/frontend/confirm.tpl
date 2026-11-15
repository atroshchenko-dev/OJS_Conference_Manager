{**
 * A co-author confirms participation from the link in the letter (REG-04).
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.confirm.title"}

<div class="page page_conference_confirm cmEdition">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.confirm.title"}</h1>

	{if $confirmEditionName}
		<p class="cmEdition__meta">
			{if $confirmEditionUrl}<a href="{$confirmEditionUrl|escape}">{$confirmEditionName|escape}</a>{else}{$confirmEditionName|escape}{/if}
		</p>
	{/if}

	{if $confirmState === 'pending'}
		{if $confirmRetry}
			<p class="cmConfirm__result" role="alert">{translate key="plugins.generic.conferenceManager.confirm.retry"}</p>
		{/if}
		<p>{translate key="plugins.generic.conferenceManager.confirm.question" email=$confirmEmail|escape}</p>
		<form method="post" action="{url page="conference" op="confirm" path=$confirmToken}" class="cmConfirm">
			{csrf}
			<button type="submit" name="answer" value="accept" class="cmEdition__button cmConfirm__accept">{translate key="plugins.generic.conferenceManager.confirm.accept"}</button>
			<button type="submit" name="answer" value="decline" class="cmConfirm__decline">{translate key="plugins.generic.conferenceManager.confirm.decline"}</button>
		</form>
	{elseif $confirmState === 'confirmed'}
		<p class="cmConfirm__result" role="status">{translate key="plugins.generic.conferenceManager.confirm.done.confirmed"}</p>
	{elseif $confirmState === 'declined'}
		<p class="cmConfirm__result" role="status">{translate key="plugins.generic.conferenceManager.confirm.done.declined"}</p>
	{elseif $confirmState === 'expired'}
		<p class="cmConfirm__result" role="alert">{translate key="plugins.generic.conferenceManager.confirm.expired"}</p>
	{else}
		<p class="cmConfirm__result" role="alert">{translate key="plugins.generic.conferenceManager.confirm.invalid"}</p>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
