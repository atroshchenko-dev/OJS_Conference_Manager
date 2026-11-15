{**
 * PRG-01: static program of an edition, by section and presentation type.
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}

<div class="container page page_conference_program cmEdition cmProgram">
	<nav class="cmProgram__back"><a href="{$editionUrl|escape}">← {$edition.name|escape}</a></nav>
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.program.title"}</h1>
	<p class="cmProgram__event">{$edition.name|escape}{if $edition.dates.startsAt} · {$edition.dates.startsAt|escape}{/if}{if $edition.venue} · {$edition.venue|escape}{/if}</p>

	{if $programPreview}
		<p class="cmProgram__preview" role="status">{translate key="plugins.generic.conferenceManager.program.preview"}</p>
	{/if}

	{if $programIntro}
		<div class="cmProgram__intro">{$programIntro|escape|nl2br}</div>
	{/if}

	{if $programSignedIn}
		<p class="cmProgram__mine">
			{if $programOnlyMine}<a href="{$programUrl|escape}">{translate key="plugins.generic.conferenceManager.myProgram.all"}</a>
			{else}<a href="{$programUrl|escape}?mine=1">{translate key="plugins.generic.conferenceManager.myProgram.only" n=$programMineCount}</a>{/if}
			{if $programMineCount} · <a href="{$programMineIcsUrl|escape}">{translate key="plugins.generic.conferenceManager.myProgram.ics"}</a>{/if}
		</p>
	{/if}

	{if $programDays}
		<div class="cmProgram__tools">
			<a class="cmProgram__tool" href="{$programIcsUrl|escape}">{translate key="plugins.generic.conferenceManager.program.download.ics"}</a>
			<a class="cmProgram__tool" href="{$programPdfUrl|escape}">{translate key="plugins.generic.conferenceManager.program.download.pdf"}</a>
			<p class="cmProgram__tz" data-cm-tz="{$programTimezone|escape}" data-label="{translate key="plugins.generic.conferenceManager.program.timezone.label"}" data-event="{translate key="plugins.generic.conferenceManager.program.timezone.event" tz=$programTimezone}" data-mine="{translate key="plugins.generic.conferenceManager.program.timezone.mine"}">{translate key="plugins.generic.conferenceManager.program.timezone.note" tz=$programTimezone}</p>
		</div>
		{if $programDays|@count > 1}
			<nav class="cmProgram__toc" aria-label="{translate key="plugins.generic.conferenceManager.program.days"}">
				<ol>
					{foreach from=$programDays item=day}
						<li><a href="#{$day.anchor|escape}">{$day.label|escape}</a></li>
					{/foreach}
				</ol>
			</nav>
		{/if}
		{foreach from=$programDays item=day}
			<section class="cmProgram__day" id="{$day.anchor|escape}">
				<h2>{$day.label|escape}</h2>
				{foreach from=$day.sessions item=s}
					<article class="cmProgram__session cmProgram__session--{$s.type|escape}" id="cm-session-{$s.id|escape}">
						<p class="cmProgram__when">
							<span class="cmProgram__time"><time datetime="{$s.startsAt|escape}" data-cm-time>{$s.start|escape}</time>–<time datetime="{$s.endsAt|escape}" data-cm-time>{$s.end|escape}</time></span>
							<span class="cmProgram__type">{$s.typeLabel|escape}</span>{if $s.room}<span class="cmProgram__room">{$s.room|escape}</span>{/if}
						</p>
						<h3 class="cmProgram__sessionTitle">{$s.title|escape}</h3>
						{if $s.mineText}<p class="cmProgram__mineReason">{$s.mineText|escape}</p>{/if}
						{if $s.chairs}<p class="cmProgram__chairs">{translate key="plugins.generic.conferenceManager.program.chairs"}: {$s.chairsText|escape}</p>{/if}
						{if $s.description}<div class="cmProgram__description">{$s.description|escape|nl2br}</div>{/if}
						{if $s.hours || $s.ects}
							<p class="cmProgram__credits">{if $s.hours}{translate key="plugins.generic.conferenceManager.program.hours" n=$s.hours}{/if}{if $s.hours && $s.ects} · {/if}{if $s.ects}{translate key="plugins.generic.conferenceManager.program.ects" ects=$s.ects}{/if}</p>
						{/if}
						{if $s.learningOutcomes}
							<details class="cmProgram__outcomes"><summary>{translate key="plugins.generic.conferenceManager.program.outcomes"}</summary>{$s.learningOutcomes|escape|nl2br}</details>
						{/if}
						{if $s.stream}
							<p class="cmProgram__stream cmProgram__stream--{$s.stream.state|escape}">
								{if $s.stream.state == 'open'}<a href="{$s.stream.url|escape}" rel="noopener" target="_blank">{translate key="plugins.generic.conferenceManager.program.stream.open"}</a>
								{elseif $s.stream.state == 'later'}{translate key="plugins.generic.conferenceManager.program.stream.later" date=$s.stream.fromLabel}
								{else}{translate key="plugins.generic.conferenceManager.program.stream.register"}{/if}
							</p>
						{/if}
						{if $s.items}
							<ol class="cmProgram__items cmProgram__items--timed">
								{foreach from=$s.items item=item}
									<li class="cmProgram__item">
										<time class="cmProgram__itemTime" datetime="{$item.startsAt|escape}" data-cm-time>{$item.start|escape}</time>
										<span class="cmProgram__itemBody">
											<span class="cmProgram__title">{if $item.url}<a href="{$item.url|escape}">{$item.title|escape}</a>{else}{$item.title|escape}{/if}</span>
											{if $item.authors}<span class="cmProgram__authors">{$item.authors|escape}</span>{/if}
										</span>
									</li>
								{/foreach}
							</ol>
						{/if}
						{if $s.type != 'break'}
							<div class="cmProgram__sessionTools">
								<a href="{$s.icsUrl|escape}">{translate key="plugins.generic.conferenceManager.program.session.ics"}</a>
								{if $programSignedIn}
									<form method="post" action="{$programUrl|escape}" class="cmProgram__pick">
										{csrf}
										<input type="hidden" name="sessionId" value="{$s.id|escape}">
										<input type="hidden" name="pick" value="{if $s.picked}0{else}1{/if}">
										{if $programOnlyMine}<input type="hidden" name="mine" value="1">{/if}
										<button type="submit">{if $s.picked}★ {translate key="plugins.generic.conferenceManager.myProgram.remove"}{else}☆ {translate key="plugins.generic.conferenceManager.myProgram.add"}{/if}</button>
									</form>
								{/if}
							</div>
						{/if}
					</article>
				{/foreach}
			</section>
		{/foreach}
	{elseif $program.sections}
		{if $program.sections|@count > 1}
			<nav class="cmProgram__toc" aria-label="{translate key="plugins.generic.conferenceManager.program.sections"}">
				<ol>
					{foreach from=$program.sections item=section name=toc}
						<li><a href="#cm-section-{$smarty.foreach.toc.iteration}">{$section.title|default:"—"|escape}</a></li>
					{/foreach}
				</ol>
			</nav>
		{/if}
		{foreach from=$program.sections item=section name=sections}
			<section class="cmProgram__section" id="cm-section-{$smarty.foreach.sections.iteration}">
				<h2>{$section.title|default:"—"|escape}</h2>
				{foreach from=$section.groups item=group}
					<h3 class="cmProgram__group cmProgram__group--{$group.type|escape}">{$group.label|escape} <span class="cmProgram__count">({$group.items|@count})</span></h3>
					<ol class="cmProgram__items">
						{foreach from=$group.items item=item}
							<li class="cmProgram__item">
								<span class="cmProgram__title">{if $item.url}<a href="{$item.url|escape}">{$item.title|escape}</a>{else}{$item.title|escape}{/if}</span>
								{if $item.authors}<span class="cmProgram__authors">{$item.authors|escape}</span>{/if}
							</li>
						{/foreach}
					</ol>
				{/foreach}
			</section>
		{/foreach}
	{elseif $programOnlyMine}
		<p>{translate key="plugins.generic.conferenceManager.myProgram.empty"}</p>
	{else}
		<p>{translate key="plugins.generic.conferenceManager.program.empty"}</p>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
