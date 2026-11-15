{**
 * Backend page of editions. The content is the Vue component
 * "ConferenceEditionsManager" registered by js/editionsManager.js.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">{translate key="plugins.generic.conferenceManager.editions.title"}</h1>
	<conference-editions-manager v-bind="components.conferenceEditions"></conference-editions-manager>
{/block}
