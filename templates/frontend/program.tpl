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

	{if $program.sections}
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
	{else}
		<p>{translate key="plugins.generic.conferenceManager.program.empty"}</p>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
