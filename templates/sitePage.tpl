{**
 * DST-04: backend page of the site administrator.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">{translate key="plugins.generic.conferenceManager.site.title"}</h1>
	<div class="app__contentPanel cmSitePage">
		{if $siteSaved}<p class="cmSite__saved" role="status">{translate key="plugins.generic.conferenceManager.site.saved"}</p>{/if}
		{$siteFormHtml}
	</div>
{/block}
