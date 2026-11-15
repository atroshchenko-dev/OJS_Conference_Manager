{**
 * Public register of professional development documents (CRT-08, JUR-02).
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.documents.title"}

<div class="page page_conference_documents cmEdition">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.documents.title"}</h1>
	<p>{translate key="plugins.generic.conferenceManager.documents.intro"}</p>

	{if $documents}
		<div class="cmDocuments__scroll">
			<table class="cmDocuments">
				<caption class="pkp_screen_reader">{translate key="plugins.generic.conferenceManager.documents.title"}</caption>
				<thead>
					<tr>
						<th scope="col">{translate key="plugins.generic.conferenceManager.documents.number"}</th>
						<th scope="col">{translate key="plugins.generic.conferenceManager.documents.recipient"}</th>
						<th scope="col">{translate key="plugins.generic.conferenceManager.documents.topic"}</th>
						<th scope="col">{translate key="plugins.generic.conferenceManager.documents.form"}</th>
						<th scope="col">{translate key="plugins.generic.conferenceManager.documents.dates"}</th>
						<th scope="col">{translate key="plugins.generic.conferenceManager.documents.volume"}</th>
						<th scope="col">{translate key="plugins.generic.conferenceManager.documents.issued"}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$documents item=doc}
						<tr>
							<td><a href="{$doc.verifyUrl|escape}">{$doc.number|escape}</a></td>
							<td>{$doc.recipientName|escape}</td>
							<td>{$doc.topic|escape}</td>
							<td>{$doc.eventType|escape}{if $doc.eventFormat}, {$doc.eventFormat|escape}{/if}</td>
							<td>{$doc.eventDates|escape}</td>
							<td>{if $doc.ects}{translate key="plugins.generic.conferenceManager.documents.hours" hours=$doc.hours|escape ects=$doc.ects|escape}{else}{translate key="plugins.generic.conferenceManager.documents.hoursOnly" hours=$doc.hours|escape}{/if}</td>
							<td>{$doc.issueDate|escape}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		</div>
		{if $documentsPages > 1}
			<nav class="cmDocuments__pages" aria-label="{translate key="plugins.generic.conferenceManager.documents.pages"}">
				{if $documentsPage > 1}<a href="{$documentsUrl|escape}?page={$documentsPage-1}">{translate key="plugins.generic.conferenceManager.documents.prev"}</a>{/if}
				<span>{translate key="plugins.generic.conferenceManager.documents.pageOf" page=$documentsPage pages=$documentsPages}</span>
				{if $documentsPage < $documentsPages}<a href="{$documentsUrl|escape}?page={$documentsPage+1}">{translate key="plugins.generic.conferenceManager.documents.next"}</a>{/if}
			</nav>
		{/if}
	{else}
		<p>{translate key="plugins.generic.conferenceManager.documents.empty"}</p>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
