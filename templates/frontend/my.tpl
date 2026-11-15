{**
 * The participant's cabinet: own participations and cancellation (REG-06, REG-08),
 * own invoices with payment instructions (FEE-04, FEE-06), own certificates (CRT-04).
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.my.title"}

<div class="container page page_conference_my cmEdition">
	<h1 class="page_title">{translate key="plugins.generic.conferenceManager.my.title"}</h1>

	{if $myMessage}
		<p class="cmConfirm__result" role="{$myMessage.type|escape}">{$myMessage.text|escape}</p>
	{/if}

	{if !$myItems}
		<p>{translate key="plugins.generic.conferenceManager.my.empty"}</p>
	{else}
		<ul class="cmMy">
			{foreach from=$myItems item=p}
				<li class="cmMy__item {if $p.status === 'cancelled'}cmMy__item--cancelled{/if}">
					<h2 class="cmMy__title"><a href="{$p.editionUrl|escape}">{$p.editionName|escape}</a></h2>
					<dl class="cmEdition__dates">
						{if $p.startsAtText}<dt>{translate key="plugins.generic.conferenceManager.editions.field.startsAt"}</dt><dd>{$p.startsAtText|escape}</dd>{/if}
						<dt>{translate key="plugins.generic.conferenceManager.participants.role"}</dt><dd>{$p.roleLabel|escape}</dd>
						{if $p.submissionTitle}<dt>{translate key="plugins.generic.conferenceManager.participants.submission"}</dt><dd>{$p.submissionTitle|escape}</dd>{/if}
						<dt>{translate key="plugins.generic.conferenceManager.participants.form"}</dt><dd>{$p.formLabel|escape}</dd>
						{if $p.categoryName}<dt>{translate key="plugins.generic.conferenceManager.participants.category"}</dt><dd>{$p.categoryName|escape}</dd>{/if}
						{if $p.role !== 'listener'}<dt>{translate key="plugins.generic.conferenceManager.participants.confirmation"}</dt><dd>{$p.confirmationLabel|escape}</dd>{/if}
						<dt>{translate key="plugins.generic.conferenceManager.participants.status"}</dt><dd>{$p.statusLabel|escape}{if $p.waitlistPosition} ({translate key="plugins.generic.conferenceManager.waitlist.position" n=$p.waitlistPosition}){/if}</dd>
						{if $p.promoCode}<dt>{translate key="plugins.generic.conferenceManager.promo.field"}</dt><dd>{$p.promoCode|escape}</dd>{/if}
					</dl>
					{if $p.waitlistPosition}<p class="cmMy__waitlist">{translate key="plugins.generic.conferenceManager.waitlist.help"}</p>{/if}
					{if $p.ticket}
						<details class="cmTicket__box">
							<summary>{translate key="plugins.generic.conferenceManager.my.ticket"}</summary>
							<p>{translate key="plugins.generic.conferenceManager.my.ticketHelp"}</p>
							<p class="cmTicket">{$p.ticket}</p>
							{if $p.ticketUrl}<p><a href="{$p.ticketUrl|escape}">{translate key="plugins.generic.conferenceManager.my.ticketOpen"}</a></p>{/if}
						</details>
					{/if}
					{if $p.letterUrl}
						<p class="cmMy__letter"><a class="cmEdition__button" href="{$p.letterUrl|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.conferenceManager.invitationLetter.download"}</a> <a href="{$p.letterUrlEn|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.conferenceManager.invitationLetter.downloadEn"}</a></p>
					{/if}
					{if $p.canApplyPromo}
						<form method="post" action="{$myUrl|escape}" class="cmMy__promo">
							{csrf}
							<input type="hidden" name="promoParticipationId" value="{$p.participationId|escape}">
							<label for="cmPromo{$p.participationId|escape}">{translate key="plugins.generic.conferenceManager.promo.field"}</label>
							<input id="cmPromo{$p.participationId|escape}" type="text" name="promoCode" maxlength="32" autocomplete="off" required>
							<button type="submit" class="cmConfirm__decline">{translate key="plugins.generic.conferenceManager.promo.apply"}</button>
						</form>
					{/if}
					{if $p.canCancel}
						<form method="post" action="{$myUrl|escape}" class="cmMy__cancel">
							{csrf}
							<input type="hidden" name="participationId" value="{$p.participationId|escape}">
							<button type="submit" class="cmConfirm__decline">{translate key="plugins.generic.conferenceManager.my.cancel"}</button>
							{if $p.cancelDeadlineText}<span class="cmMy__deadline">{translate key="plugins.generic.conferenceManager.my.cancelUntil" date=$p.cancelDeadlineText|escape}</span>{/if}
						</form>
					{/if}
				</li>
			{/foreach}
		</ul>
	{/if}

	{if $myInvoices}
		<h2 class="cmMy__section">{translate key="plugins.generic.conferenceManager.my.invoices"}</h2>
		<ul class="cmMy">
			{foreach from=$myInvoices item=i}
				<li class="cmMy__item cmInvoice {if !$i.isOpen}cmMy__item--cancelled{/if}">
					<h3 class="cmMy__title">
						{if $i.number}{translate key="plugins.generic.conferenceManager.invoice.numberShort" number=$i.number|escape}{/if}
						· {$i.editionName|escape}
					</h3>
					<dl class="cmEdition__dates">
						<dt>{translate key="plugins.generic.conferenceManager.invoice.total"}</dt><dd><strong>{$i.total.decimal|escape} {$i.total.currency|escape}</strong>{if $i.paid.minor > 0 && $i.isOpen} ({translate key="plugins.generic.conferenceManager.invoice.dueLeft" amount=$i.due.decimal|escape currency=$i.due.currency|escape}){/if}</dd>
						<dt>{translate key="plugins.generic.conferenceManager.participants.status"}</dt><dd>{$i.statusLabel|escape}</dd>
						<dt>{translate key="plugins.generic.conferenceManager.invoice.mode"}</dt><dd>{$i.modeLabel|escape}</dd>
						{if $i.issuedAtText}<dt>{translate key="plugins.generic.conferenceManager.invoice.issuedAt"}</dt><dd>{$i.issuedAtText|escape}</dd>{/if}
						{if $i.dueAtText && $i.isOpen}<dt>{translate key="plugins.generic.conferenceManager.invoice.dueAt"}</dt><dd>{$i.dueAtText|escape}</dd>{/if}
						{if $i.paymentReference && $i.isOpen}<dt>{translate key="plugins.generic.conferenceManager.invoice.reference"}</dt><dd><code>{$i.paymentReference|escape}</code></dd>{/if}
						{if $i.purpose && $i.hasDocument && $i.isOpen}<dt>{translate key="plugins.generic.conferenceManager.invoice.purpose"}</dt><dd>{$i.purpose|escape}</dd>{/if}
						{if $i.waiverReason}<dt>{translate key="plugins.generic.conferenceManager.invoice.waiverReason"}</dt><dd>{$i.waiverReason|escape}</dd>{/if}
					</dl>
					{if $i.isOpen}
						<div class="cmInvoice__instructions">{$i.instructionsHtml}</div>
						<p class="cmInvoice__actions">
							{if $i.pdfUrl}<a class="cmEdition__button" href="{$i.pdfUrl|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.conferenceManager.invoice.download"}</a>{/if}
							{if $i.externalUrl}<a class="cmEdition__button" href="{$i.externalUrl|escape}" target="_blank" rel="noopener noreferrer">{translate key="plugins.generic.conferenceManager.invoice.payExternal"}</a>{/if}
						</p>
					{/if}
					{if $i.proofs}
						<h4 class="cmInvoice__subtitle">{translate key="plugins.generic.conferenceManager.proof.mine"}</h4>
						<ul class="cmInvoice__proofs">
							{foreach from=$i.proofs item=p}
								<li class="cmInvoice__proof cmInvoice__proof--{$p.status|escape}">
									<a href="{$p.url|escape}" target="_blank" rel="noopener">{$p.name|escape}</a>
									{if $p.uploadedAtText}· {$p.uploadedAtText|escape}{/if}
									· <strong>{$p.statusLabel|escape}</strong>
									{if $p.note}<div class="cmInvoice__proofNote">{translate key="plugins.generic.conferenceManager.proof.noteFromManager"}: {$p.note|escape}</div>{/if}
								</li>
							{/foreach}
						</ul>
					{/if}
					{if $i.canEditPayer}
						<details class="cmInvoice__payer"{if $i.payer.kind == 'organization'} open{/if}>
							<summary>{translate key="plugins.generic.conferenceManager.payer.title"}</summary>
							<form method="post" action="{$myUrl|escape}" class="cmInvoice__payerForm">
								{csrf}
								<input type="hidden" name="payerInvoiceId" value="{$i.id|escape}">
								<fieldset>
									<legend>{translate key="plugins.generic.conferenceManager.payer.who"}</legend>
									<label><input type="radio" name="payerKind" value="person"{if $i.payer.kind != 'organization'} checked{/if}> {translate key="plugins.generic.conferenceManager.payer.person"}</label>
									<label><input type="radio" name="payerKind" value="organization"{if $i.payer.kind == 'organization'} checked{/if}> {translate key="plugins.generic.conferenceManager.payer.organization"}</label>
								</fieldset>
								<label for="cmPayerOrg{$i.id|escape}">{translate key="plugins.generic.conferenceManager.payer.organizationName"}</label>
								<input id="cmPayerOrg{$i.id|escape}" type="text" name="payerOrganization" maxlength="255" value="{if $i.payer.kind == 'organization'}{$i.payer.name|escape}{/if}">
								<label for="cmPayerLegal{$i.id|escape}">{translate key=$payerFields.legalId}</label>
								<input id="cmPayerLegal{$i.id|escape}" type="text" name="payerLegalId" maxlength="32" inputmode="numeric" value="{$i.payer.legalId|escape}">
								{if $payerFields.taxIdEnabled}
									<label for="cmPayerTax{$i.id|escape}">{translate key=$payerFields.taxId}</label>
									<input id="cmPayerTax{$i.id|escape}" type="text" name="payerTaxId" maxlength="32" inputmode="numeric" autocomplete="off" value="{$i.payer.taxId|escape}">
									<p class="cmRegister__hint">{translate key="plugins.generic.conferenceManager.payer.taxIdHint"}</p>
								{/if}
								<p class="cmRegister__hint">{translate key="plugins.generic.conferenceManager.payer.hint"}</p>
								<button type="submit" class="cmEdition__button">{translate key="plugins.generic.conferenceManager.payer.save"}</button>
							</form>
						</details>
					{/if}
					{if $i.canUploadProof}
						<form method="post" action="{$myUrl|escape}" enctype="multipart/form-data" class="cmInvoice__upload">
							{csrf}
							<input type="hidden" name="invoiceId" value="{$i.id|escape}">
							<input type="hidden" name="MAX_FILE_SIZE" value="5242880">
							<label for="cmProof{$i.id|escape}">{translate key="plugins.generic.conferenceManager.proof.upload"}</label>
							<p class="cmRegister__hint">{translate key="plugins.generic.conferenceManager.proof.uploadHint"}</p>
							<input id="cmProof{$i.id|escape}" type="file" name="proof" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" required>
							<button type="submit" class="cmEdition__button">{translate key="plugins.generic.conferenceManager.proof.send"}</button>
						</form>
					{/if}
				</li>
			{/foreach}
		</ul>
	{/if}

	{if $myProgram}
		<h2 class="cmMy__section">{translate key="plugins.generic.conferenceManager.myProgram.title"}</h2>
		{foreach from=$myProgram item=e}
			<h3 class="cmMy__title">{$e.editionName|escape}</h3>
			<ul class="cmMy cmMy__program">
				{foreach from=$e.sessions item=s}
					<li><a href="{$s.url|escape}">{if $s.title}{$s.title|escape}{else}{$s.typeLabel|escape}{/if}</a> · {$s.whenText|escape}{if $s.reasonText} · <span class="cmMy__reason">{$s.reasonText|escape}</span>{/if}</li>
				{/foreach}
			</ul>
			<p><a href="{$e.programUrl|escape}?mine=1">{translate key="plugins.generic.conferenceManager.myProgram.open"}</a> · <a href="{$e.icsUrl|escape}">{translate key="plugins.generic.conferenceManager.myProgram.ics"}</a></p>
		{/foreach}
	{/if}

	{if $mySessions}
		<h2 class="cmMy__section">{translate key="plugins.generic.conferenceManager.my.sessions"}</h2>
		<p>{translate key="plugins.generic.conferenceManager.my.sessionsHelp"}</p>
		<ul class="cmMy">
			{foreach from=$mySessions item=s}
				<li class="cmMy__item">
					<h3 class="cmMy__title"><a href="{$s.url|escape}">{if $s.title}{$s.title|escape}{else}{$s.typeLabel|escape}{/if}</a></h3>
					<p>{$s.editionName|escape} · {$s.startsAtText|escape} · {$s.typeLabel|escape}</p>
				</li>
			{/foreach}
		</ul>
	{/if}

	{if $myCertificates}
		<h2 class="cmMy__section">{translate key="plugins.generic.conferenceManager.my.certificates"}</h2>
		<ul class="cmMy">
			{foreach from=$myCertificates item=c}
				<li class="cmMy__item cmCertificate">
					<h3 class="cmMy__title">{$c.typeLabel|escape} № {$c.number|escape}</h3>
					<dl class="cmEdition__dates">
						<dt>{translate key="plugins.generic.conferenceManager.certificate.conference"}</dt><dd>{$c.conferenceName|escape}</dd>
						{if $c.issuedAtText}<dt>{translate key="plugins.generic.conferenceManager.certificate.issuedAt"}</dt><dd>{$c.issuedAtText|escape}</dd>{/if}
					</dl>
					<p class="cmInvoice__actions">
						<a class="cmEdition__button" href="{$c.pdfUrl|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.conferenceManager.certificate.download"}</a>
						{foreach from=$c.altPdf item=alt}<a href="{$alt.url|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.conferenceManager.certificate.downloadIn" language=$alt.language|escape}</a>{/foreach}
						<a href="{$c.verifyUrl|escape}">{translate key="plugins.generic.conferenceManager.certificate.verifyLink"}</a>
					</p>
				</li>
			{/foreach}
		</ul>
	{/if}
	<p class="cmMy__data"><a href="{$myDataUrl|escape}" download>{translate key="plugins.generic.conferenceManager.privacy.myData"}</a></p>
</div>

{include file="frontend/components/footer.tpl"}
