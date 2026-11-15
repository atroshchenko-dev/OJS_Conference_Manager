{**
 * ATT-04: the page of a QR entry ticket. Staff mark presence; the owner
 * sees their own ticket.
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}

<div class="container page page_conference_checkin cmEdition">
	<h1 class="page_title">{$ciCard.name|escape}</h1>
	{if $ciMessage}
		<p class="cmConfirm__result cmCheckin__result cmCheckin__result--{$ciMessage.type|escape}" role="{$ciMessage.type|escape}">{$ciMessage.text|escape}</p>
	{/if}
	<dl class="cmEdition__dates">
		<dt>{translate key="plugins.generic.conferenceManager.sessionAttendance.edition"}</dt><dd>{$ciCard.editionName|escape}</dd>
		{if $ciCard.affiliation}<dt>{translate key="plugins.generic.conferenceManager.checkin.affiliation"}</dt><dd>{$ciCard.affiliation|escape}</dd>{/if}
		<dt>{translate key="plugins.generic.conferenceManager.participants.role"}</dt><dd>{$ciRole|escape}</dd>
		<dt>{translate key="plugins.generic.conferenceManager.participants.form"}</dt><dd>{$ciForm|escape}</dd>
		{if $ciStaff}
			<dt>{translate key="plugins.generic.conferenceManager.checkin.atEdition"}</dt><dd>{if $ciCard.attended}{translate key="plugins.generic.conferenceManager.checkin.present"}{else}{translate key="plugins.generic.conferenceManager.checkin.notYet"}{/if}</dd>
		{/if}
	</dl>

	{if !$ciCard.active}
		<p class="cmCheckin__result cmCheckin__result--alert" role="alert">{translate key="plugins.generic.conferenceManager.checkin.cancelled"}</p>
	{elseif $ciStaff}
		<form method="post" action="{$ciUrl|escape}" class="cmCheckin">
			{csrf}
			{if !$ciManager}<p>{translate key="plugins.generic.conferenceManager.checkin.chairOnly"}</p>{/if}
			{if $ciSessions}
				<p><label>{translate key="plugins.generic.conferenceManager.checkin.session"}
					<select name="sessionId">
						{if $ciManager}<option value="0">{translate key="plugins.generic.conferenceManager.checkin.noSession"}</option>{/if}
						{foreach from=$ciSessions item=s}
							<option value="{$s.id|escape}"{if $s.id == $ciChosen} selected{/if}>{$s.label|escape}{if $s.present} ✓{/if}</option>
						{/foreach}
					</select>
				</label></p>
			{/if}
			<p><button type="submit" class="cmEdition__button cmCheckin__button">{translate key="plugins.generic.conferenceManager.checkin.mark"}</button></p>
		</form>
	{else}
		<p>{translate key="plugins.generic.conferenceManager.checkin.ownerHelp"}</p>
		{if $ciTicket}<p class="cmTicket">{$ciTicket}</p>{/if}
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
