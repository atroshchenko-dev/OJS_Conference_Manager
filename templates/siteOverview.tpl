{**
 * templates/siteOverview.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * DST-04: all journals of the installation and the load profile (plan, 15.2).
 *}
<form class="pkp_form cmSite" id="confManagerSiteForm" method="post" action="{url page="conferenceSite"}">
	{csrf}
	{if $isError}
		<ul class="pkp_form_error_list" role="alert">
			{foreach key=field item=message from=$errors}<li>{$message|escape}</li>{/foreach}
		</ul>
	{/if}

	<p class="cmSite__meta">
		{translate key="plugins.generic.conferenceManager.site.version"}: <strong>{$overview.version|escape}</strong>
		· {translate key="plugins.generic.conferenceManager.site.schema"}: <strong>{$overview.schemaSteps|escape}</strong> ({$overview.schemaLast|escape})
		· {translate key="plugins.generic.conferenceManager.site.queue"}: <strong>{$overview.queuedJobs|escape}</strong> / {$overview.allQueuedJobs|escape}
		· {translate key="plugins.generic.conferenceManager.site.failed"}: <strong>{$overview.failedJobs|escape}</strong>
	</p>

	<table class="cmSite__table">
		<thead>
			<tr>
				<th>{translate key="plugins.generic.conferenceManager.site.journal"}</th>
				<th>{translate key="plugins.generic.conferenceManager.site.enabled"}</th>
				<th>{translate key="plugins.generic.conferenceManager.site.editions"}</th>
				<th>{translate key="plugins.generic.conferenceManager.site.participants"}</th>
				<th>{translate key="plugins.generic.conferenceManager.site.letters"}</th>
				<th>{translate key="plugins.generic.conferenceManager.site.jobs"}</th>
			</tr>
		</thead>
		<tbody>
			{foreach from=$overview.contexts item=c}
				<tr class="{if !$c.enabled}cmSite__off{/if}">
					<td><strong>{$c.name|escape}</strong><br><code>{$c.path|escape}</code></td>
					<td>{if $c.enabled}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</td>
					<td>{$c.activeEditions|escape} / {$c.editions|escape}</td>
					<td>{$c.participants|escape}</td>
					<td>{$c.pendingLetters|escape}</td>
					<td>{$c.queuedJobs|escape}</td>
				</tr>
			{/foreach}
		</tbody>
	</table>
	<p class="cmSite__hint">{translate key="plugins.generic.conferenceManager.site.editionsHint"}</p>

	<h3>{translate key="plugins.generic.conferenceManager.site.tasks"}</h3>
	<ul class="cmSite__tasks">
		{foreach from=$taskRuns item=t}
			<li>{$t.name|escape}: {if $t.at}{$t.at|escape}{if !$t.ok} — {translate key="plugins.generic.conferenceManager.site.taskFailed"}{/if}{else}{translate key="plugins.generic.conferenceManager.site.taskNever"}{/if}</li>
		{/foreach}
	</ul>

	<h3>{translate key="plugins.generic.conferenceManager.site.load"}</h3>
	<p class="cmSite__hint">{translate key="plugins.generic.conferenceManager.site.loadHelp"}</p>
	{fbvFormArea id="confManagerSiteLoad"}
		{fbvFormSection title="plugins.generic.conferenceManager.site.profile"}
			{fbvElement type="select" id="loadProfile" from=$profileOptions selected=$loadProfile translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.conferenceManager.site.mailsPerHour" description="plugins.generic.conferenceManager.site.mailsPerHourHelp"}
			{fbvElement type="text" id="mailsPerHour" value=$mailsPerHour size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
	{/fbvFormArea}
	<p class="cmSite__current">
		{translate key="plugins.generic.conferenceManager.site.current" batch=$siteSettings.batchSize mails=$siteSettings.mailsPerHour|default:"∞"}
		{if $siteSettings.nightOnly} {translate key="plugins.generic.conferenceManager.site.nightOnly"}{/if}
	</p>
	<p class="cmSite__hint">
		{translate key="plugins.generic.conferenceManager.site.runner"}:
		{if $overview.jobRunner}{translate key="plugins.generic.conferenceManager.site.runnerOn" n=$overview.jobRunnerMaxJobs}{else}{translate key="plugins.generic.conferenceManager.site.runnerOffNow"}{/if}
		· {translate key="plugins.generic.conferenceManager.site.timezone"}: {$overview.serverTimezone|escape}
	</p>
	{if $advice}
		<ul class="cmSite__advice">
			{foreach from=$advice item=a}<li>{$a|escape}</li>{/foreach}
		</ul>
	{/if}

	<h3>{translate key="plugins.generic.conferenceManager.site.presets"}</h3>
	<p class="cmSite__hint">
		{translate key="plugins.generic.conferenceManager.site.presets.count" n=$presetCount}
		{translate key="plugins.generic.conferenceManager.site.presets.folder" folder=$presetFolder|escape}
	</p>
	{if $presetProblems}
		<div class="cmSite__advice">
			<p>{translate key="plugins.generic.conferenceManager.site.presets.problems"}</p>
			<ul>
				{foreach from=$presetProblems item=p}<li><code>{$p.file|escape}</code>: {$p.text|escape}</li>{/foreach}
			</ul>
		</div>
	{/if}

	<style>
		#confManagerSiteForm .cmSite__table {ldelim} width: 100%; border-collapse: collapse; margin: 1rem 0 0.25rem; {rdelim}
		#confManagerSiteForm .cmSite__table th, #confManagerSiteForm .cmSite__table td {ldelim} border-bottom: 1px solid #ddd; padding: 0.4rem 0.5rem; text-align: left; vertical-align: top; {rdelim}
		#confManagerSiteForm .cmSite__table td:nth-child(n+2), #confManagerSiteForm .cmSite__table th:nth-child(n+2) {ldelim} text-align: right; {rdelim}
		#confManagerSiteForm .cmSite__off {ldelim} color: #777; {rdelim}
		#confManagerSiteForm .cmSite__hint {ldelim} color: #555; font-size: 0.875rem; {rdelim}
		#confManagerSiteForm .cmSite__advice {ldelim} border-left: 4px solid #d00a6c; padding: 0.5rem 1rem 0.5rem 2rem; background: #fff5f9; {rdelim}
		#confManagerSiteForm .cmSite__tasks {ldelim} margin: 0.5rem 0 1rem; {rdelim}
		.cmSite__saved {ldelim} border-left: 4px solid #00b28d; padding: 0.5rem 1rem; background: #f0fbf8; {rdelim}
		@media (max-width: 700px) {ldelim} #confManagerSiteForm .cmSite__table {ldelim} display: block; overflow-x: auto; {rdelim} {rdelim}
	</style>

	{fbvFormButtons hideCancel=true submitText="common.save"}
</form>
