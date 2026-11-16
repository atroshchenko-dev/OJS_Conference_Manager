{**
 * PUB-05: the coming events on the home page of a journal in the conference site mode.
 *}
<section class="cmHomeEditions">
	<h2>{translate key="plugins.generic.conferenceManager.editionsPage.coming"}</h2>
	<ul class="cmUpcoming__list">
		{foreach from=$homeEditions item=item}
			<li class="cmUpcoming__item">
				<h3><a href="{$item.url|escape}">{$item.name|escape}</a></h3>
				<p class="cmEdition__meta">
					<span>{$item.eventTypeLabel|escape}</span>
					<span>{$item.formatLabel|escape}</span>
					{if $item.submissionsOpen}<span class="cmUpcoming__open">{translate key="plugins.generic.conferenceManager.public.submissionsOpen"}</span>{/if}
				</p>
				<p>{$item.dates.startsAt|escape}{if $item.dates.endsAt && $item.dates.endsAt != $item.dates.startsAt} – {$item.dates.endsAt|escape}{/if}{if $item.venue} · {$item.venue|escape}{/if}</p>
			</li>
		{/foreach}
	</ul>
	<p><a href="{$homeEditionsUrl|escape}">{translate key="plugins.generic.conferenceManager.editionsPage.all"}</a></p>
</section>
