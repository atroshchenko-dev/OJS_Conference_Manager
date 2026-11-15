{**
 * DEC-10: a reviewer marks which submissions of the edition they would like to review.
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.bids.title"}

<div class="container page page_conference_bids cmEdition">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.bids.title"}</h1>
	<p class="cmEdition__meta"><a href="{$bidsEditionUrl|escape}">{$bidsEditionName|escape}</a></p>

	{if $bidsMessage}
		<p class="cmConfirm__result" role="{$bidsMessage.type|escape}">{$bidsMessage.text|escape}</p>
	{/if}

	{if $bidsPage.open}
		<p>{translate key="plugins.generic.conferenceManager.bids.help" until=$bidsUntil|escape}</p>
	{else}
		<p class="cmRegister__full" role="status">{translate key="plugins.generic.conferenceManager.bids.closed" until=$bidsUntil|escape}</p>
	{/if}

	{if !$bidsPage.items}
		<p>{translate key="plugins.generic.conferenceManager.bids.empty"}</p>
	{else}
		<form method="post" action="{$bidsUrl|escape}" class="cmBids">
			{csrf}
			<ol class="cmBids__list">
				{foreach from=$bidsPage.items item=s}
					<li class="cmBids__item">
						<fieldset {if !$bidsPage.open}disabled{/if}>
							<legend class="cmBids__title">{$s.title|escape}</legend>
							{if $s.section}<p class="cmBids__meta">{$s.section|escape}</p>{/if}
							{if $s.keywords}<p class="cmBids__meta">{translate key="plugins.generic.conferenceManager.bids.keywords"}: {foreach from=$s.keywords item=k name=kw}{if !$smarty.foreach.kw.first}, {/if}{$k|escape}{/foreach}</p>{/if}
							{if $s.abstract}
								<details class="cmBids__abstract">
									<summary>{translate key="plugins.generic.conferenceManager.bids.abstract"}</summary>
									<p>{$s.abstract|escape|nl2br}</p>
								</details>
							{/if}
							<div class="cmBids__choices">
								{foreach from=$bidsChoices item=c}
									<label class="cmBids__choice cmBids__choice--{$c.value|escape}"><input type="radio" name="bid[{$s.id|escape}]" value="{$c.value|escape}" {if $s.bid === $c.value}checked{/if}> {$c.label|escape}</label>
								{/foreach}
								<label class="cmBids__choice"><input type="radio" name="bid[{$s.id|escape}]" value="" {if !$s.bid}checked{/if}> {translate key="plugins.generic.conferenceManager.bids.bid.none"}</label>
							</div>
						</fieldset>
					</li>
				{/foreach}
			</ol>
			{if $bidsPage.open}
				<button type="submit" class="cmEdition__button">{translate key="plugins.generic.conferenceManager.bids.save"}</button>
			{/if}
		</form>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
