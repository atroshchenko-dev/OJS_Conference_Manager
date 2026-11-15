{**
 * PRG-06: the program by days as a PDF (mPDF, text only).
 *}
<style>
	body { font-family: dejavusans; font-size: 10pt; color: #111; }
	h1 { font-size: 16pt; margin: 0 0 2pt; }
	.event { color: #444; margin: 0 0 4pt; }
	.note { color: #555; font-size: 9pt; margin: 0 0 10pt; }
	h2 { font-size: 12.5pt; border-bottom: 0.5pt solid #999; padding-bottom: 2pt; margin: 14pt 0 6pt; }
	table.session { width: 100%; border-collapse: collapse; margin: 0 0 8pt; page-break-inside: avoid; }
	td.time { width: 26mm; padding-right: 3mm; vertical-align: top; font-weight: bold; }
	.title { font-weight: bold; font-size: 11pt; }
	.meta { color: #444; font-size: 9pt; }
	.item { margin: 2pt 0 0; }
	.item .t { color: #333; }
	.authors { color: #555; font-size: 9pt; }
</style>
<h1>{translate key="plugins.generic.conferenceManager.program.title"}</h1>
<p class="event">{$edition.name|escape}{if $edition.venue} · {$edition.venue|escape}{/if}</p>
<p class="note">{translate key="plugins.generic.conferenceManager.program.timezone.note" tz=$programTimezone}</p>
{foreach from=$programDays item=day}
	<h2>{$day.label|escape}</h2>
	{foreach from=$day.sessions item=s}
		<table class="session"><tr>
			<td class="time">{$s.start|escape}–{$s.end|escape}</td>
			<td>
				<div class="title">{$s.title|escape}</div>
				<div class="meta">{$s.typeLabel|escape}{if $s.room} · {$s.room|escape}{/if}{if $s.chairsText} · {translate key="plugins.generic.conferenceManager.program.chairs"}: {$s.chairsText|escape}{/if}{if $s.hours} · {translate key="plugins.generic.conferenceManager.program.hours" n=$s.hours}{/if}{if $s.ects} · {translate key="plugins.generic.conferenceManager.program.ects" ects=$s.ects}{/if}</div>
				{foreach from=$s.items item=item}
					<div class="item"><span class="t">{$item.start|escape}</span> {$item.title|escape}{if $item.authors} <span class="authors">— {$item.authors|escape}</span>{/if}</div>
				{/foreach}
			</td>
		</tr></table>
	{/foreach}
{/foreach}
