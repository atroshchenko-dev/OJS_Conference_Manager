{**
 * PUB-05: the events of the journal, the coming ones first.
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.editionsPage.title"}

<div class="container page page_conference_editions cmUpcoming">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.editionsPage.title"}</h1>

	{if !$comingEditions && !$pastEditions}
		<p class="cmUpcoming__empty">{translate key="plugins.generic.conferenceManager.editionsPage.empty"}</p>
	{/if}

	{foreach from=[['key' => 'coming', 'items' => $comingEditions], ['key' => 'past', 'items' => $pastEditions]] item=group}
		{if $group.items}
			<h2 class="cmEditionsPage__heading">{translate key="plugins.generic.conferenceManager.editionsPage."|cat:$group.key}</h2>
			<ul class="cmUpcoming__list">
				{foreach from=$group.items item=item}
					<li class="cmUpcoming__item">
						<h3><a href="{$item.url|escape}">{$item.name|escape}</a></h3>
						<p class="cmEdition__meta">
							<span>{$item.eventTypeLabel|escape}</span>
							<span>{$item.formatLabel|escape}</span>
							{if $item.submissionsOpen}<span class="cmUpcoming__open">{translate key="plugins.generic.conferenceManager.public.submissionsOpen"}</span>{/if}
						</p>
						<p>{$item.dates.startsAt|escape}{if $item.dates.endsAt && $item.dates.endsAt != $item.dates.startsAt} – {$item.dates.endsAt|escape}{/if}{if $item.venue} · {$item.venue|escape}{/if}</p>
						{if $item.proceedingsUrl}<p><a href="{$item.proceedingsUrl|escape}">{translate key="plugins.generic.conferenceManager.editionsPage.proceedings"}</a></p>{/if}
					</li>
				{/foreach}
			</ul>
		{/if}
	{/foreach}
</div>

{include file="frontend/components/footer.tpl"}
