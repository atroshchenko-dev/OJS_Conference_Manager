{**
 * Presence at one session of the program (ATT-01, ATT-02): for the chair
 * (moderator) of the session and for registration managers.
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}

<div class="container page page_conference_session_attendance cmEdition">
	<p class="cmSessionAttendance__back"><a href="{$saBackUrl|escape}">{if $saManager}{translate key="plugins.generic.conferenceManager.sessionAttendance.backManager"}{else}{translate key="plugins.generic.conferenceManager.sessionAttendance.backMy"}{/if}</a></p>
	<h1 class="page_title">{$saTitle|escape}</h1>
	<dl class="cmEdition__dates">
		<dt>{translate key="plugins.generic.conferenceManager.sessionAttendance.edition"}</dt><dd><a href="{$saEditionUrl|escape}">{$saEditionName|escape}</a></dd>
		<dt>{translate key="plugins.generic.conferenceManager.sessionAttendance.when"}</dt><dd>{$saWhen|escape} ({$saTypeLabel|escape})</dd>
		{if $saHours}<dt>{translate key="plugins.generic.conferenceManager.program.session.hours"}</dt><dd>{$saHours|escape}</dd>{/if}
		<dt>{translate key="plugins.generic.conferenceManager.sessionAttendance.presentCount"}</dt><dd>{$saPresent|escape} / {$saTotal|escape}</dd>
	</dl>
	{if $saHours}<p class="cmEditions__note">{translate key="plugins.generic.conferenceManager.sessionAttendance.seminarNote" n=$saHours}</p>{/if}

	{if $saMessage && $saMessage.text}
		<p class="cmConfirm__result" role="{$saMessage.type|escape}">{$saMessage.text|escape}</p>
	{/if}

	<h2 class="cmMy__section">{translate key="plugins.generic.conferenceManager.sessionAttendance.marks"}</h2>
	{if !$saPeople}
		<p>{translate key="plugins.generic.conferenceManager.sessionAttendance.nobody"}</p>
	{else}
		<form method="post" action="{$saPageUrl|escape}" class="cmSessionAttendance">
			{csrf}
			<p class="cmSessionAttendance__tools">
				<label>{translate key="plugins.generic.conferenceManager.sessionAttendance.filter"} <input type="search" class="cmSessionAttendance__filter" data-cm-filter></label>
				<button type="button" class="cmEdition__button" data-cm-check="1">{translate key="plugins.generic.conferenceManager.sessionAttendance.checkShown"}</button>
				<button type="button" data-cm-check="0">{translate key="plugins.generic.conferenceManager.sessionAttendance.uncheckShown"}</button>
			</p>
			<div class="cmDocuments__scroll">
				<table class="cmDocuments">
					<caption class="pkp_screen_reader">{translate key="plugins.generic.conferenceManager.sessionAttendance.marks"}</caption>
					<thead>
						<tr>
							<th scope="col">{translate key="plugins.generic.conferenceManager.attendance.title"}</th>
							<th scope="col">{translate key="plugins.generic.conferenceManager.participants.name"}</th>
							<th scope="col">{translate key="plugins.generic.conferenceManager.participants.role"}</th>
							<th scope="col">{translate key="plugins.generic.conferenceManager.sessionAttendance.source"}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$saPeople item=p}
							<tr data-cm-person="{$p.name|escape|lower} {$p.email|escape|lower} {$p.affiliation|escape|lower}">
								<td>
									<input type="hidden" name="shown[]" value="{$p.participationId|escape}">
									<input type="checkbox" name="present[]" value="{$p.participationId|escape}" id="cm-sa-{$p.participationId|escape}"{if $p.present} checked{/if}>
								</td>
								<td><label for="cm-sa-{$p.participationId|escape}">{$p.name|escape}</label>{if $p.ofSession} <span class="cmBadge">{translate key="plugins.generic.conferenceManager.sessionAttendance.ofSession"}</span>{/if}<div class="cmSessionAttendance__meta">{$p.email|escape}{if $p.affiliation} · {$p.affiliation|escape}{/if}</div></td>
								<td>{$p.roleLabel|escape}</td>
								<td>{if $p.source === 'import'}{translate key="plugins.generic.conferenceManager.sessionAttendance.fromReport" minutes=$p.minutes|default:'—'}{elseif $p.source === 'manual'}{translate key="plugins.generic.conferenceManager.sessionAttendance.byHand"}{/if}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			</div>
			<p><button type="submit" class="cmEdition__button">{translate key="plugins.generic.conferenceManager.sessionAttendance.save"}</button></p>
		</form>
	{/if}

	<h2 class="cmMy__section">{translate key="plugins.generic.conferenceManager.attendance.import"}</h2>
	<p>{translate key="plugins.generic.conferenceManager.sessionAttendance.importHelp" minutes=$saMinutes}</p>
	<form method="post" action="{$saPageUrl|escape}" enctype="multipart/form-data" class="cmSessionAttendance__import">
		{csrf}
		<input type="hidden" name="importReport" value="1">
		{if $saReportData}<input type="hidden" name="reportData" value="{$saReportData|escape}">{/if}
		<p>
			<label>{translate key="plugins.generic.conferenceManager.attendance.file"} <input type="file" name="report" accept=".csv,.tsv,.txt,text/csv"></label>
			<label>{translate key="plugins.generic.conferenceManager.attendance.threshold"} <input type="number" name="threshold" min="1" max="1440" value="{$saThreshold|escape}"></label>
		</p>
		<p>
			<button type="submit" name="preview" value="1">{translate key="plugins.generic.conferenceManager.attendance.preview"}</button>
			{if $saReportData}<button type="submit" name="apply" value="1" class="cmEdition__button">{translate key="plugins.generic.conferenceManager.attendance.apply"}</button>{/if}
		</p>
	</form>
	{if $saImport}
		<div class="cmDocuments__scroll">
			<table class="cmDocuments">
				<caption>{translate key="plugins.generic.conferenceManager.sessionAttendance.reportTable" threshold=$saImport.threshold}</caption>
				<thead><tr><th scope="col">{translate key="plugins.generic.conferenceManager.participants.name"}</th><th scope="col">{translate key="plugins.generic.conferenceManager.attendance.minutes"}</th><th scope="col">{translate key="plugins.generic.conferenceManager.attendance.title"}</th></tr></thead>
				<tbody>
					{foreach from=$saImport.matched item=m}
						<tr><td>{$m.name|escape}<div class="cmSessionAttendance__meta">{$m.email|escape}</div></td><td>{$m.minutes|escape}</td><td>{if $m.attended}{translate key="plugins.generic.conferenceManager.attendance.present"}{elseif $m.keptManual}{translate key="plugins.generic.conferenceManager.attendance.keptManual"}{else}{translate key="plugins.generic.conferenceManager.attendance.absent"}{/if}</td></tr>
					{/foreach}
				</tbody>
			</table>
		</div>
		{if $saImport.unmatched}
			<details><summary>{translate key="plugins.generic.conferenceManager.attendance.unmatched" count=$saUnmatched}</summary>
				<ul>{foreach from=$saImport.unmatched item=u}<li>{$u.email|escape} — {$u.minutes|escape}</li>{/foreach}</ul>
			</details>
		{/if}
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
