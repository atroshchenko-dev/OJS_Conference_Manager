{**
 * Public page of an edition (EDN-07). All values are prepared in
 * ConferencePageHandler::viewModel(); *Html values are already escaped.
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$edition.name}

<div class="page page_conference_edition cmEdition">
	{if $edition.isDraft}
		<p class="cmEdition__draft">{translate key="plugins.generic.conferenceManager.public.draftNotice"}</p>
	{/if}

	<h1 class="page_title">{$edition.name|escape}</h1>
	<p class="cmEdition__meta">
		<span>{$edition.eventTypeLabel|escape}</span>
		<span>{$edition.formatLabel|escape}</span>
		<span>{$edition.statusLabel|escape}</span>
	</p>

	<dl class="cmEdition__facts">
		<dt>{translate key="plugins.generic.conferenceManager.public.when"}</dt>
		<dd>{$edition.dates.startsAt|escape}{if $edition.dates.endsAt} – {$edition.dates.endsAt|escape}{/if}
			<span class="cmEdition__tz">({$edition.timezone|escape})</span></dd>
		{if $edition.venue}
			<dt>{translate key="plugins.generic.conferenceManager.public.where"}</dt>
			<dd>{$edition.venue|escape}</dd>
		{/if}
		{if $edition.hasSubmissions}
			{if $edition.dates.cfpOpensAt}
				<dt>{translate key="plugins.generic.conferenceManager.editions.field.cfpOpensAt"}</dt>
				<dd>{$edition.dates.cfpOpensAt|escape}</dd>
			{/if}
			{if $edition.dates.abstractDeadline}
				<dt>{translate key="plugins.generic.conferenceManager.editions.field.abstractDeadline"}</dt>
				<dd>{$edition.dates.abstractDeadline|escape}</dd>
			{/if}
			{if $edition.hasPapers && $edition.dates.paperDeadline}
				<dt>{translate key="plugins.generic.conferenceManager.editions.field.paperDeadline"}</dt>
				<dd>{$edition.dates.paperDeadline|escape}</dd>
			{/if}
		{/if}
		{if $edition.dates.registrationClosesAt}
			<dt>{translate key="plugins.generic.conferenceManager.editions.field.registrationClosesAt"}</dt>
			<dd>{$edition.dates.registrationClosesAt|escape}</dd>
		{/if}
		{if $edition.dates.paymentDeadline}
			<dt>{translate key="plugins.generic.conferenceManager.editions.field.paymentDeadline"}</dt>
			<dd>{$edition.dates.paymentDeadline|escape}</dd>
		{/if}
		{if $edition.hours || $edition.ects}
			<dt>{translate key="plugins.generic.conferenceManager.public.workload"}</dt>
			<dd>{if $edition.hours}{translate key="plugins.generic.conferenceManager.public.hours" hours=$edition.hours}{/if}{if $edition.hours && $edition.ects}, {/if}{if $edition.ects}{translate key="plugins.generic.conferenceManager.public.ects" ects=$edition.ects}{/if}</dd>
		{/if}
		{if $edition.isbn}
			<dt>ISBN</dt>
			<dd>{$edition.isbn|escape}</dd>
		{/if}
	</dl>

	{if $edition.hasSubmissions}
		<div class="cmEdition__call {if $edition.submissionsOpen}cmEdition__call--open{/if}">
			{if $edition.submissionsOpen}
				<p>{translate key="plugins.generic.conferenceManager.public.submissionsOpen"}</p>
				<a class="cmEdition__button" href="{$edition.submitUrl|escape}">{translate key="plugins.generic.conferenceManager.public.submit"}</a>
			{else}
				<p>{translate key="plugins.generic.conferenceManager.public.submissionsClosed"}</p>
			{/if}
		</div>
	{/if}

	{if $edition.registrationOpen}
		<div class="cmEdition__call cmEdition__call--open">
			<p>{translate key="plugins.generic.conferenceManager.public.registrationOpen"}</p>
			<a class="cmEdition__button" href="{$edition.registerUrl|escape}">{translate key="plugins.generic.conferenceManager.public.registerListener"}</a>
		</div>
	{/if}

	{if $edition.descriptionHtml}
		<section class="cmEdition__section">{$edition.descriptionHtml}</section>
	{/if}
	{if $edition.hasSubmissions && $edition.cfpTextHtml}
		<section class="cmEdition__section">
			<h2>{translate key="plugins.generic.conferenceManager.editions.field.cfpText"}</h2>
			{$edition.cfpTextHtml}
		</section>
	{/if}
	{if $edition.learningOutcomesHtml}
		<section class="cmEdition__section">
			<h2>{translate key="plugins.generic.conferenceManager.editions.field.learningOutcomes"}</h2>
			{$edition.learningOutcomesHtml}
		</section>
	{/if}
	{if $edition.proceedingsUrl}
		<section class="cmEdition__section">
			<h2>{translate key="plugins.generic.conferenceManager.public.proceedings"}</h2>
			<p><a href="{$edition.proceedingsUrl|escape}">{translate key="plugins.generic.conferenceManager.public.proceedingsLink"}</a></p>
		</section>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
