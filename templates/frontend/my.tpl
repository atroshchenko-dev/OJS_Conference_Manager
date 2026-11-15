{**
 * The participant's cabinet: own participations and cancellation (REG-06, REG-08).
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.my.title"}

<div class="page page_conference_my cmEdition">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.my.title"}</h1>

	{if $myMessage}
		<p class="cmConfirm__result" role="{$myMessage.type|escape}">{$myMessage.text|escape}</p>
	{/if}

	{if !$myItems}
		<p>{translate key="plugins.generic.conferenceManager.my.empty"}</p>
	{else}
		<ul class="cmMy">
			{foreach from=$myItems item=p}
				<li class="cmMy__item {if $p.status === 'cancelled'}cmMy__item--cancelled{/if}">
					<h2 class="cmMy__title"><a href="{$p.editionUrl|escape}">{$p.editionName|escape}</a></h2>
					<dl class="cmEdition__dates">
						{if $p.startsAtText}<dt>{translate key="plugins.generic.conferenceManager.editions.field.startsAt"}</dt><dd>{$p.startsAtText|escape}</dd>{/if}
						<dt>{translate key="plugins.generic.conferenceManager.participants.role"}</dt><dd>{$p.roleLabel|escape}</dd>
						{if $p.submissionTitle}<dt>{translate key="plugins.generic.conferenceManager.participants.submission"}</dt><dd>{$p.submissionTitle|escape}</dd>{/if}
						<dt>{translate key="plugins.generic.conferenceManager.participants.form"}</dt><dd>{$p.formLabel|escape}</dd>
						{if $p.categoryName}<dt>{translate key="plugins.generic.conferenceManager.participants.category"}</dt><dd>{$p.categoryName|escape}</dd>{/if}
						{if $p.role !== 'listener'}<dt>{translate key="plugins.generic.conferenceManager.participants.confirmation"}</dt><dd>{$p.confirmationLabel|escape}</dd>{/if}
						<dt>{translate key="plugins.generic.conferenceManager.participants.status"}</dt><dd>{$p.statusLabel|escape}</dd>
					</dl>
					{if $p.canCancel}
						<form method="post" action="{$myUrl|escape}" class="cmMy__cancel">
							{csrf}
							<input type="hidden" name="participationId" value="{$p.participationId|escape}">
							<button type="submit" class="cmConfirm__decline">{translate key="plugins.generic.conferenceManager.my.cancel"}</button>
							{if $p.cancelDeadlineText}<span class="cmMy__deadline">{translate key="plugins.generic.conferenceManager.my.cancelUntil" date=$p.cancelDeadlineText|escape}</span>{/if}
						</form>
					{/if}
				</li>
			{/foreach}
		</ul>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
