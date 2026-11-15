{**
 * The participant's cabinet: own participations and cancellation (REG-06, REG-08),
 * own invoices with payment instructions (FEE-04, FEE-06), own certificates (CRT-04).
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.conferenceManager.my.title"}

<div class="page page_conference_my cmEdition">
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
						<dt>{translate key="plugins.generic.conferenceManager.participants.status"}</dt><dd>{$p.statusLabel|escape}</dd>
					</dl>
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
						<a href="{$c.verifyUrl|escape}">{translate key="plugins.generic.conferenceManager.certificate.verifyLink"}</a>
					</p>
				</li>
			{/foreach}
		</ul>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
