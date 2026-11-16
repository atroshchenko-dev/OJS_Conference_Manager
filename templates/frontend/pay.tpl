{**
 * PAY-09: the participant goes on to the payment page of the gateway.
 * The form is sent at once; the button is there when scripts are off.
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.gateway.pay.title"}

<div class="container page page_conference_pay cmEdition">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.gateway.pay.title"}</h1>
	<p>{$payDescription|escape}{if $payAmount}: <strong>{$payAmount|escape}</strong>{/if}</p>
	<p>{translate key="plugins.generic.conferenceManager.gateway.pay.help" gateway=$payGateway|escape}</p>
	<form method="post" action="{$payForm->action|escape}" id="cmPayForm" class="cmPay">
		{foreach from=$payForm->fields key=name item=value}
			<input type="hidden" name="{$name|escape}" value="{$value|escape}">
		{/foreach}
		<button type="submit" class="cmEdition__button">{translate key="plugins.generic.conferenceManager.gateway.pay.go" gateway=$payGateway|escape}</button>
	</form>
	<p><a href="{$payBackUrl|escape}">{translate key="plugins.generic.conferenceManager.gateway.pay.back"}</a></p>
	<script>document.getElementById('cmPayForm').submit();</script>
</div>

{include file="frontend/components/footer.tpl"}
