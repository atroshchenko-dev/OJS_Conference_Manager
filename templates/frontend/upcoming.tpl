{**
 * Upcoming events of all journals of the site (EDN-08, DST-03).
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.upcoming.title"}

<div class="container page page_conference_upcoming cmUpcoming">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.upcoming.title"}</h1>

	<form class="cmUpcoming__filters" method="get" action="">
		<label>{translate key="plugins.generic.conferenceManager.upcoming.journal"}
			<select name="journal">
				<option value="">{translate key="plugins.generic.conferenceManager.upcoming.all"}</option>
				{foreach from=$journals item=journal}
					<option value="{$journal.id|escape}"{if $filters.contextId == $journal.id} selected{/if}>{$journal.name|escape}</option>
				{/foreach}
			</select>
		</label>
		<label>{translate key="plugins.generic.conferenceManager.editions.column.type"}
			<select name="type">
				<option value="">{translate key="plugins.generic.conferenceManager.upcoming.all"}</option>
				{foreach from=$eventTypes item=option}
					<option value="{$option.value|escape}"{if $filters.eventType == $option.value} selected{/if}>{$option.label|escape}</option>
				{/foreach}
			</select>
		</label>
		<label>{translate key="plugins.generic.conferenceManager.editions.field.format"}
			<select name="format">
				<option value="">{translate key="plugins.generic.conferenceManager.upcoming.all"}</option>
				{foreach from=$formats item=option}
					<option value="{$option.value|escape}"{if $filters.format == $option.value} selected{/if}>{$option.label|escape}</option>
				{/foreach}
			</select>
		</label>
		<label>{translate key="plugins.generic.conferenceManager.upcoming.search"}
			<input type="search" name="q" value="{$filters.q|escape}" maxlength="100">
		</label>
		<button type="submit">{translate key="plugins.generic.conferenceManager.upcoming.apply"}</button>
	</form>

	{if !$items}
		<p class="cmUpcoming__empty">{translate key="plugins.generic.conferenceManager.upcoming.empty"}</p>
	{else}
		<ul class="cmUpcoming__list">
			{foreach from=$items item=item}
				<li class="cmUpcoming__item">
					<h2><a href="{$item.url|escape}">{$item.name|escape}</a></h2>
					<p class="cmEdition__meta">
						<span>{$item.journal|escape}</span>
						<span>{$item.eventTypeLabel|escape}</span>
						<span>{$item.formatLabel|escape}</span>
						{if $item.submissionsOpen}<span class="cmUpcoming__open">{translate key="plugins.generic.conferenceManager.public.submissionsOpen"}</span>{/if}
					</p>
					<p>{$item.dates.startsAt|escape}{if $item.venue} · {$item.venue|escape}{/if}</p>
				</li>
			{/foreach}
		</ul>
		{if $pages > 1}
			<nav class="cmUpcoming__pages">
				{if $page > 1}<a href="?journal={$filters.contextId|escape:url}&amp;type={$filters.eventType|escape:url}&amp;format={$filters.format|escape:url}&amp;q={$filters.q|escape:url}&amp;page={$page-1}">←</a>{/if}
				<span>{$page} / {$pages}</span>
				{if $page < $pages}<a href="?journal={$filters.contextId|escape:url}&amp;type={$filters.eventType|escape:url}&amp;format={$filters.format|escape:url}&amp;q={$filters.q|escape:url}&amp;page={$page+1}">→</a>{/if}
			</nav>
		{/if}
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
