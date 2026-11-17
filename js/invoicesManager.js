/**
 * Payments of an edition (PAY-01…08, FEE-04…07), opened from the editions
 * page. Vue components registered with the OJS 3.5 registry:
 *
 *   ConferencePaymentSettings  rules "category → payment mode", invoice templates
 *   ConferenceInvoicesList     invoices with payment, waiver and cancellation
 *   ConferenceProofQueue       receipts to review: view, accept, reject (FEE-10)
 *   ConferencePaymentJournal   the payment journal of the edition (FEE-13)
 *   ConferenceAccountingExport export for accounting, CSV or XLSX (FEE-12)
 *
 * Amounts go to the server as decimal strings; nothing here computes money.
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	function request(csrfToken, method, url, body) {
		var init = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-Csrf-Token': csrfToken, Accept: 'application/json' },
		};
		if (body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		}
		return fetch(url, init).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (data) {
				return { ok: response.ok, status: response.status, data: data };
			});
		});
	}

	function notify(message, type) {
		if (window.pkp && pkp.eventBus) {
			pkp.eventBus.$emit('notify', message, type || 'success');
		}
	}

	function firstError(data) {
		if (!data) {
			return '';
		}
		if (data.errorMessage) {
			return data.errorMessage;
		}
		var keys = Object.keys(data);
		return keys.length && Array.isArray(data[keys[0]]) ? data[keys[0]].join(' ') : '';
	}

	var common = {
		methods: {
			t: function (key, params) {
				var s = this.i18n[key] || key;
				Object.keys(params || {}).forEach(function (p) { s = s.replace('{$' + p + '}', params[p]); });
				return s;
			},
			api: function (method, url, body) {
				return request(this.csrfToken, method, url, body);
			},
			money: function (m) {
				return m ? m.decimal + ' ' + m.currency : '';
			},
			time: function (iso) {
				return iso ? String(iso).replace('T', ' ').slice(0, 16) + ' UTC' : '';
			},
		},
	};

	pkp.registry.registerComponent('ConferencePaymentSettings', {
		name: 'ConferencePaymentSettings',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			templatesUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			locales: { type: Array, required: true },
			primaryLocale: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return {
				isLoading: true,
				rules: [],
				options: { modes: [], accounts: [], categories: [] },
				rule: null,
				errors: {},
				templates: [],
				personalTaxId: false,
				template: null,
				templateErrors: {},
				/** PAY-09: card payment through the journal's payment gateway */
				gateway: null,
				gatewayForm: { gateway: '', sandbox: false, values: {} },
				gatewayErrors: {},
			};
		},
		computed: {
			gatewayUrl: function () {
				return this.templatesUrl.replace(/invoice-templates$/, 'payment-gateway');
			},
			gatewayChosen: function () {
				var self = this;
				return ((self.gateway && self.gateway.gateways) || []).filter(function (g) { return g.id === self.gatewayForm.gateway; })[0] || null;
			},
			ruleMode: function () {
				var self = this;
				return (self.options.modes || []).filter(function (m) { return self.rule && m.value === self.rule.mode; })[0] || {};
			},
		},
		mounted: function () {
			this.load();
		},
		methods: {
			load: function () {
				var self = this;
				self.api('GET', self.apiUrl).then(function (r) {
					self.isLoading = false;
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
						return;
					}
					self.rules = r.data.rules || [];
					self.options = r.data.options || self.options;
				});
				self.api('GET', self.templatesUrl).then(function (r) {
					if (r.ok) {
						self.templates = r.data.templates || [];
						self.personalTaxId = !!r.data.personalTaxId;
					}
				});
				self.api('GET', self.gatewayUrl).then(function (r) {
					if (r.ok) {
						self.setGateway(r.data);
					}
				});
			},
			setGateway: function (data) {
				this.gateway = data;
				this.gatewayForm = { gateway: data.gateway || '', sandbox: !!data.sandbox, values: Object.assign({}, data.values || {}) };
				this.gatewayErrors = {};
			},
			saveGateway: function () {
				var self = this;
				self.api('PUT', self.gatewayUrl, self.gatewayForm).then(function (r) {
					if (r.ok) {
						self.setGateway(r.data);
						notify(self.t('fee.saved'));
					} else {
						self.gatewayErrors = (r.data && r.data.errors) || r.data || {};
						notify(firstError(r.data), 'warning');
					}
				});
			},
			gatewayError: function (name) {
				var e = this.gatewayErrors['values.' + name];
				return e ? [].concat(e).join(' ') : '';
			},
			/** JUR-05: off by default; only when the accounting needs it. */
			savePersonalTaxId: function () {
				var self = this;
				self.api('PUT', self.templatesUrl.replace(/invoice-templates$/, 'invoice-settings'), { personalTaxId: self.personalTaxId }).then(function (r) {
					r.ok ? (self.personalTaxId = !!r.data.personalTaxId, notify(self.t('fee.saved'))) : notify(firstError(r.data), 'warning');
				});
			},
			categoryName: function (id) {
				if (id === null) {
					return this.t('payment.rule.default');
				}
				var c = (this.options.categories || []).filter(function (x) { return x.id === id; })[0];
				return c ? c.label : '—';
			},
			modeLabel: function (value) {
				var m = (this.options.modes || []).filter(function (x) { return x.value === value; })[0];
				return m ? m.label : value;
			},
			accountLabel: function (id) {
				var a = (this.options.accounts || []).filter(function (x) { return x.id === id; })[0];
				return a ? a.label : '';
			},
			newRule: function () {
				var instructions = {};
				this.locales.forEach(function (l) { instructions[l.key] = ''; });
				this.errors = {};
				this.rule = { id: null, categoryId: '', mode: 'bank_transfer', paymentAccountId: '', externalUrl: '', instructions: instructions };
			},
			editRule: function (x) {
				var instructions = {};
				this.locales.forEach(function (l) { instructions[l.key] = (x.instructions || {})[l.key] || ''; });
				this.errors = {};
				this.rule = {
					id: x.id, categoryId: x.categoryId === null ? '' : x.categoryId, mode: x.mode,
					paymentAccountId: x.paymentAccountId === null ? '' : x.paymentAccountId, externalUrl: x.externalUrl || '', instructions: instructions,
				};
			},
			saveRule: function () {
				var self = this;
				var x = self.rule;
				var body = Object.assign({}, x);
				delete body.id;
				self.api(x.id ? 'PUT' : 'POST', self.apiUrl + (x.id ? '/' + x.id : ''), body).then(function (r) {
					if (r.ok) {
						self.rule = null;
						notify(self.t('fee.saved'));
						self.load();
					} else {
						self.errors = r.data || {};
					}
				});
			},
			deleteRule: function (x) {
				var self = this;
				if (!window.confirm(self.t('payment.rule.deleteConfirm'))) {
					return;
				}
				self.api('DELETE', self.apiUrl + '/' + x.id).then(function (r) {
					r.ok ? self.load() : notify(firstError(r.data), 'warning');
				});
			},
			editTemplate: function (tpl) {
				this.templateErrors = {};
				this.template = { kind: tpl.kind, html: tpl.custom || tpl.builtIn, variables: tpl.variables };
			},
			saveTemplate: function (reset) {
				var self = this;
				self.api('PUT', self.templatesUrl + '/' + self.template.kind, { html: reset ? '' : self.template.html }).then(function (r) {
					if (r.ok) {
						self.template = null;
						notify(self.t('fee.saved'));
						self.load();
					} else {
						self.templateErrors = r.data || {};
					}
				});
			},
			err: function (field) {
				return (this.errors[field] || []).join(' ');
			},
		},
		template: '\
<div class="cmEditions cmFees">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'payment.title\') }}: {{ editionName }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else>\
	<section class="cmEditions__form cmFees__block">\
		<h3>{{ t(\'payment.rules\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'payment.rules.description\') }}</p>\
		<table v-if="rules.length" class="cmEditions__table">\
			<thead><tr><th>{{ t(\'fee.category\') }}</th><th>{{ t(\'payment.mode\') }}</th><th>{{ t(\'payment.account\') }}</th><th></th></tr></thead>\
			<tbody><tr v-for="x in rules" :key="x.id">\
				<td>{{ categoryName(x.categoryId) }}</td><td>{{ modeLabel(x.mode) }}</td>\
				<td>{{ accountLabel(x.paymentAccountId) }}<div class="cmEditions__path" v-if="x.externalUrl">{{ x.externalUrl }}</div></td>\
				<td class="cmEditions__actions"><pkp-button @click="editRule(x)">{{ t(\'edit\') }}</pkp-button><pkp-button :is-warnable="true" @click="deleteRule(x)">{{ t(\'delete\') }}</pkp-button></td>\
			</tr></tbody>\
		</table>\
		<p v-else class="cmEditions__note">{{ t(\'payment.rules.empty\') }}</p>\
		<div class="cmEditions__buttons"><pkp-button @click="newRule">{{ t(\'payment.rule.add\') }}</pkp-button></div>\
		<form v-if="rule" class="cmFees__edit" @submit.prevent="saveRule" novalidate>\
			<div class="cmEditions__grid">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.categoryId }"><span>{{ t(\'fee.category\') }}</span>\
					<select class="pkpFormField__input" v-model="rule.categoryId"><option value="">{{ t(\'payment.rule.default\') }}</option><option v-for="c in options.categories" :key="c.id" :value="c.id">{{ c.label }}</option></select><em v-if="errors.categoryId">{{ err(\'categoryId\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.mode }"><span>{{ t(\'payment.mode\') }}</span>\
					<select class="pkpFormField__input" v-model="rule.mode"><option v-for="m in options.modes" :key="m.value" :value="m.value">{{ m.label }}</option></select></label>\
				<label class="cmEditions__field" v-if="ruleMode.needsAccount || rule.mode === \'external_organizer\'" :class="{ \'cmEditions__field--error\': errors.paymentAccountId }"><span>{{ t(\'payment.account\') }}</span>\
					<select class="pkpFormField__input" v-model="rule.paymentAccountId"><option value="">—</option><option v-for="a in options.accounts" :key="a.id" :value="a.id">{{ a.label }}</option></select><small v-if="!options.accounts.length">{{ t(\'payment.account.none\') }}</small><em v-if="errors.paymentAccountId">{{ err(\'paymentAccountId\') }}</em></label>\
				<label class="cmEditions__field" v-if="rule.mode !== \'free\'" :class="{ \'cmEditions__field--error\': errors.externalUrl }"><span>{{ t(\'payment.externalUrl\') }}<template v-if="ruleMode.needsUrl"> *</template></span>\
					<input class="pkpFormField__input" type="url" v-model="rule.externalUrl" maxlength="2048" placeholder="https://"><em v-if="errors.externalUrl">{{ err(\'externalUrl\') }}</em></label>\
			</div>\
			<div class="cmEditions__row" v-if="rule.mode !== \'free\'">\
				<label class="cmEditions__field cmEditions__field--wide" v-for="l in locales" :key="l.key"><span>{{ t(\'payment.instructions\') }} ({{ l.label }})</span>\
					<textarea class="pkpFormField__input" rows="3" v-model="rule.instructions[l.key]" maxlength="5000"></textarea></label>\
			</div>\
			<p class="cmEditions__note" v-if="rule.mode !== \'free\'">{{ t(\'payment.instructions.description\') }}</p>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" @click="saveRule">{{ t(\'save\') }}</pkp-button><pkp-button @click="rule = null">{{ t(\'cancel\') }}</pkp-button></div>\
		</form>\
	</section>\
	<section class="cmEditions__form cmFees__block" v-if="gateway">\
		<h3>{{ t(\'gateway.title\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'gateway.help\') }}</p>\
		<form class="cmFees__edit" @submit.prevent="saveGateway" novalidate>\
			<div class="cmEditions__row">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': gatewayErrors.gateway }"><span>{{ t(\'gateway.service\') }}</span>\
					<select class="pkpFormField__input" v-model="gatewayForm.gateway"><option value="">{{ t(\'gateway.none\') }}</option><option v-for="g in gateway.gateways" :key="g.id" :value="g.id">{{ g.name }}</option></select></label>\
				<label class="cmEditions__field" v-for="f in (gatewayChosen ? gatewayChosen.fields : [])" :key="f.name" :class="{ \'cmEditions__field--error\': gatewayError(f.name) }"><span>{{ t(f.label.replace(\'plugins.generic.conferenceManager.\', \'\')) }} *</span>\
					<input class="pkpFormField__input" :type="f.secret ? \'password\' : \'text\'" v-model="gatewayForm.values[f.name]" maxlength="500" autocomplete="off" :placeholder="f.secret && gateway.gateway === gatewayForm.gateway && gateway.secretsSet[f.name] ? t(\'gateway.secretSet\') : \'\'"><em v-if="gatewayError(f.name)">{{ gatewayError(f.name) }}</em></label>\
			</div>\
			<label class="cmEditions__check" v-if="gatewayChosen"><input type="checkbox" v-model="gatewayForm.sandbox"> {{ t(\'gateway.sandbox\') }}</label>\
			<p class="cmEditions__note cmEditions__note--warning" v-if="gatewayChosen && gatewayForm.sandbox" role="alert">{{ t(\'gateway.sandboxWarning\') }}</p>\
			<p class="cmEditions__note" v-if="gatewayChosen">{{ t(\'gateway.currencies\') }} {{ gatewayChosen.currencies.join(\', \') }}. {{ t(\'gateway.ruleHint\') }}</p>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" @click="saveGateway">{{ t(\'save\') }}</pkp-button></div>\
		</form>\
	</section>\
	<section class="cmEditions__form cmFees__block">\
		<h3>{{ t(\'invoiceTemplate.title\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'invoiceTemplate.description\') }}</p>\
		<label class="cmEditions__check"><input type="checkbox" v-model="personalTaxId" @change="savePersonalTaxId"> {{ t(\'payer.taxIdSetting\') }}</label>\
		<p class="cmEditions__note">{{ t(\'payer.taxIdSettingHelp\') }}</p>\
		<table class="cmEditions__table">\
			<tbody><tr v-for="tpl in templates" :key="tpl.kind">\
				<td><strong>{{ t(\'invoiceTemplate.kind.\' + tpl.kind) }}</strong></td>\
				<td>{{ tpl.custom ? t(\'invoiceTemplate.custom\') : t(\'invoiceTemplate.builtIn\') }}</td>\
				<td class="cmEditions__actions"><pkp-button @click="editTemplate(tpl)">{{ t(\'edit\') }}</pkp-button></td>\
			</tr></tbody>\
		</table>\
		<form v-if="template" class="cmFees__edit" @submit.prevent="saveTemplate(false)" novalidate>\
			<label class="cmEditions__field cmEditions__field--wide" :class="{ \'cmEditions__field--error\': templateErrors.html }"><span>{{ t(\'invoiceTemplate.kind.\' + template.kind) }} (HTML)</span>\
				<textarea class="pkpFormField__input cmInvoices__html" rows="18" v-model="template.html" spellcheck="false"></textarea>\
				<small class="cmInvoices__vars">{{ t(\'invoiceTemplate.variables\') }} <code v-for="v in template.variables" :key="v">{{ \'{\' + v + \'}\' }} </code></small>\
				<em v-if="templateErrors.html">{{ (templateErrors.html || []).join(\' \') }}</em></label>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" @click="saveTemplate(false)">{{ t(\'save\') }}</pkp-button><pkp-button :is-warnable="true" @click="saveTemplate(true)">{{ t(\'invoiceTemplate.reset\') }}</pkp-button><pkp-button @click="template = null">{{ t(\'cancel\') }}</pkp-button></div>\
		</form>\
	</section>\
	</template>\
</div>',
	});

	pkp.registry.registerComponent('ConferenceInvoicesList', {
		name: 'ConferenceInvoicesList',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			invoiceApiUrl: { type: String, required: true },
			pdfUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return {
				isLoading: true,
				items: [],
				itemsMax: 0,
				statuses: [],
				filter: { searchPhrase: '', status: '' },
				page: 1,
				perPage: 50,
				opened: null,
				showJournal: false,
				showExport: false,
			};
		},
		computed: {
			pages: function () {
				return Math.max(1, Math.ceil(this.itemsMax / this.perPage));
			},
			journalUrl: function () {
				return this.apiUrl.replace(/invoices$/, 'payment-events');
			},
		},
		mounted: function () {
			this.load();
		},
		methods: {
			load: function () {
				var self = this;
				var f = self.filter;
				var q = 'count=' + self.perPage + '&offset=' + (self.page - 1) * self.perPage;
				if (f.searchPhrase) {
					q += '&searchPhrase=' + encodeURIComponent(f.searchPhrase);
				}
				if (f.status) {
					q += '&status=' + encodeURIComponent(f.status);
				}
				self.isLoading = true;
				self.api('GET', self.apiUrl + '?' + q).then(function (r) {
					self.isLoading = false;
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
						return;
					}
					self.items = r.data.items || [];
					self.itemsMax = r.data.itemsMax || 0;
					self.statuses = r.data.statuses || self.statuses;
				});
			},
			search: function () {
				this.page = 1;
				this.load();
			},
			go: function (page) {
				this.page = Math.min(this.pages, Math.max(1, page));
				this.load();
			},
			pdf: function (i) {
				return this.pdfUrl.replace('__id__', i.id);
			},
			after: function (r, message) {
				if (r.ok) {
					notify(message);
					this.opened = null;
					this.load();
				} else {
					notify(firstError(r.data), 'warning');
				}
			},
			markPaid: function (i) {
				var self = this;
				var amount = window.prompt(self.t('invoice.paidPrompt', { amount: i.due.decimal, currency: i.due.currency }), i.due.decimal);
				if (amount === null) {
					return;
				}
				self.api('POST', self.invoiceApiUrl + '/' + i.id + '/paid', { amount: amount }).then(function (r) { self.after(r, self.t('invoice.paidDone')); });
			},
			waive: function (i) {
				var self = this;
				var reason = window.prompt(self.t('invoice.waivePrompt'));
				if (reason === null) {
					return;
				}
				self.api('POST', self.invoiceApiUrl + '/' + i.id + '/waive', { reason: reason }).then(function (r) { self.after(r, self.t('invoice.waiveDone')); });
			},
			reissue: function (i) {
				var self = this;
				var pages = window.prompt(self.t('invoice.reissuePrompt'), '');
				if (pages === null) {
					return;
				}
				self.api('POST', self.invoiceApiUrl + '/' + i.id + '/reissue', { pages: pages.trim() === '' ? null : Number(pages) }).then(function (r) { self.after(r, self.t('invoice.reissueDone')); });
			},
			requestRefund: function (i) {
				var self = this;
				var note = window.prompt(self.t('refund.requestPrompt'), '');
				if (note === null) {
					return;
				}
				self.api('POST', self.invoiceApiUrl + '/' + i.id + '/refund-request', { note: note }).then(function (r) { self.after(r, self.t('refund.requestDone')); });
			},
			refund: function (i) {
				var self = this;
				var left = Math.max(0, i.paid.minor - i.refunded.minor);
				var amount = window.prompt(self.t('refund.amountPrompt', { currency: i.paid.currency }), left === i.paid.minor ? i.paid.decimal : '');
				if (amount === null) {
					return;
				}
				var note = window.prompt(self.t('refund.notePrompt'), i.refundNote || '');
				if (note === null) {
					return;
				}
				self.api('POST', self.invoiceApiUrl + '/' + i.id + '/refund', { amount: amount.trim() === '' ? null : amount, note: note }).then(function (r) { self.after(r, self.t('refund.done')); });
			},
			cancelInvoice: function (i) {
				var self = this;
				if (!window.confirm(self.t('invoice.cancelConfirm', { number: i.number || i.id }))) {
					return;
				}
				self.api('POST', self.invoiceApiUrl + '/' + i.id + '/cancel').then(function (r) { self.after(r, self.t('invoice.cancelDone')); });
			},
			toggle: function (i) {
				var self = this;
				if (self.opened && self.opened.id === i.id) {
					self.opened = null;
					return;
				}
				self.api('GET', self.invoiceApiUrl + '/' + i.id).then(function (r) {
					r.ok ? (self.opened = r.data) : notify(firstError(r.data), 'warning');
				});
			},
		},
		template: '\
<div class="cmEditions cmInvoices">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'invoice.title\') }}: {{ editionName }}</h2>\
		<span><pkp-button :is-active="showExport" @click="showExport = !showExport">{{ t(\'export.title\') }}</pkp-button> <pkp-button :is-active="showJournal" @click="showJournal = !showJournal">{{ t(\'journal.title\') }}</pkp-button> <pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button></span>\
	</div>\
	<conference-accounting-export v-if="showExport" :api-url="apiUrl" :csrf-token="csrfToken" :i18n="i18n"></conference-accounting-export>\
	<conference-payment-journal v-if="showJournal" :api-url="journalUrl" :csrf-token="csrfToken" :i18n="i18n"></conference-payment-journal>\
	<form class="cmEditions__toolbar cmParticipants__filters" @submit.prevent="search">\
		<label class="cmEditions__filter">{{ t(\'invoice.search\') }}<input class="pkpFormField__input" type="search" v-model="filter.searchPhrase" maxlength="100"></label>\
		<label class="cmEditions__filter">{{ t(\'participants.status\') }}<select class="pkpFormField__input" v-model="filter.status" @change="search"><option value="">{{ t(\'participants.all\') }}</option><option v-for="o in statuses" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
		<pkp-button @click="search">{{ t(\'participants.find\') }}</pkp-button>\
	</form>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<p v-else-if="!items.length" class="cmEditions__empty">{{ t(\'invoice.empty\') }}</p>\
	<template v-else>\
	<p class="cmEditions__note" role="status">{{ t(\'participants.count\', { count: itemsMax }) }}</p>\
	<table class="cmEditions__table">\
		<thead><tr><th>{{ t(\'invoice.number\') }}</th><th>{{ t(\'invoice.payer\') }}</th><th>{{ t(\'invoice.total\') }}</th><th>{{ t(\'participants.status\') }}</th><th>{{ t(\'payment.mode\') }}</th><th></th></tr></thead>\
		<tbody><template v-for="i in items" :key="i.id"><tr :class="{ \'cmParticipants__row--cancelled\': !i.isOpen && i.status !== \'paid\' }">\
			<td><strong>{{ i.number }}</strong><div class="cmEditions__path" v-if="i.paymentReference">{{ i.paymentReference }}</div></td>\
			<td>{{ i.payer.name || i.payer.email }}<div class="cmEditions__path">{{ i.payer.email }}</div></td>\
			<td>{{ money(i.total) }}<div class="cmEditions__path" v-if="i.paid.minor > 0 && i.isOpen">{{ t(\'invoice.paidPart\', { amount: money(i.paid) }) }}</div></td>\
			<td>{{ i.statusLabel }} <span class="cmBadge" v-if="i.isGroup">{{ t(\'group.badge\', { count: i.items.length }) }}</span><div class="cmEditions__path" v-if="i.waiverReason">{{ i.waiverReason }}</div><div class="cmRefund" v-if="i.refunded.minor > 0">{{ t(\'refund.refunded\', { amount: money(i.refunded) }) }}</div><div class="cmEditions__path" v-if="i.refundNote">{{ i.refundNote }}</div></td>\
			<td>{{ i.modeLabel }}</td>\
			<td class="cmEditions__actions">\
				<a v-if="i.hasDocument" class="pkpButton" :href="pdf(i)" target="_blank" rel="noopener">PDF</a>\
				<pkp-button v-if="i.isOpen" @click="markPaid(i)">{{ t(\'invoice.markPaid\') }}</pkp-button>\
				<pkp-button v-if="(i.isOpen || i.status === \'paid\') && i.method !== \'waiver\' && !i.isGroup" @click="reissue(i)">{{ t(\'invoice.reissue\') }}</pkp-button>\
				<pkp-button v-if="i.isOpen && i.paid.minor === 0" @click="waive(i)">{{ t(\'invoice.waive\') }}</pkp-button>\
				<pkp-button v-if="i.isOpen && i.paid.minor === 0" :is-warnable="true" @click="cancelInvoice(i)">{{ t(\'cancel\') }}</pkp-button>\
				<pkp-button v-if="i.canRequestRefund && i.status !== \'refund_requested\'" @click="requestRefund(i)">{{ t(\'refund.request\') }}</pkp-button>\
				<pkp-button v-if="i.canRefund" @click="refund(i)">{{ t(\'refund.record\') }}</pkp-button>\
				<pkp-button @click="toggle(i)">{{ t(\'invoice.history\') }}</pkp-button>\
			</td>\
		</tr><tr v-if="opened && opened.id === i.id"><td colspan="6"><ul v-if="opened.isGroup" class="cmInvoices__chain"><li v-for="(it, n) in opened.items" :key="n">{{ it.description }} · {{ money(it.amount) }}</li></ul><ul v-if="opened.history && opened.history.length > 1" class="cmInvoices__chain"><li v-for="h in opened.history" :key="h.id">{{ h.number || (\'#\' + h.id) }} · {{ h.statusLabel }} · {{ money(h.total) }}<template v-if="h.paid.minor > 0"> · {{ t(\'invoice.paidPart\', { amount: money(h.paid) }) }}</template></li></ul><ul class="cmInvoices__events"><li v-for="(e, n) in opened.events" :key="n"><span class="cmJournal__time">{{ time(e.createdAt) }}</span> {{ t(\'invoice.event.\' + e.type) }}<template v-if="e.amount"> · {{ money(e.amount) }}</template><template v-if="e.payload && e.payload.note"> · {{ e.payload.note }}</template></li></ul></td></tr></template></tbody>\
	</table>\
	<div class="cmEditions__buttons" v-if="pages > 1">\
		<pkp-button :disabled="page <= 1" @click="go(page - 1)">‹</pkp-button>\
		<span>{{ t(\'participants.page\', { page: page, pages: pages }) }}</span>\
		<pkp-button :disabled="page >= pages" @click="go(page + 1)">›</pkp-button>\
	</div>\
	</template>\
</div>',
	});
	pkp.registry.registerComponent('ConferenceProofQueue', {
		name: 'ConferenceProofQueue',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			editionId: { type: Number, required: true },
			fileUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, items: [], itemsMax: 0, status: 'pending', page: 1, perPage: 30, busy: 0 };
		},
		computed: {
			pages: function () {
				return Math.max(1, Math.ceil(this.itemsMax / this.perPage));
			},
		},
		mounted: function () {
			this.load();
		},
		methods: {
			load: function () {
				var self = this;
				var q = 'editionId=' + self.editionId + '&status=' + encodeURIComponent(self.status) + '&count=' + self.perPage + '&offset=' + (self.page - 1) * self.perPage;
				self.isLoading = true;
				self.api('GET', self.apiUrl + '?' + q).then(function (r) {
					self.isLoading = false;
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
						return;
					}
					self.items = r.data.items || [];
					self.itemsMax = r.data.itemsMax || 0;
				});
			},
			show: function (status) {
				this.status = status;
				this.page = 1;
				this.load();
			},
			go: function (page) {
				this.page = Math.min(this.pages, Math.max(1, page));
				this.load();
			},
			file: function (p) {
				return this.fileUrl.replace('__id__', p.id);
			},
			size: function (bytes) {
				return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
			},
			after: function (r, message) {
				this.busy = 0;
				if (r.ok) {
					notify(message);
					this.load();
				} else {
					notify(firstError(r.data), 'warning');
				}
			},
			accept: function (p) {
				var self = this;
				self.busy = p.id;
				self.api('POST', self.apiUrl + '/' + p.id + '/accept', {}).then(function (r) { self.after(r, self.t('proof.acceptDone', { number: p.invoice.number || p.invoice.id })); });
			},
			reject: function (p) {
				var self = this;
				var note = window.prompt(self.t('proof.rejectPrompt'));
				if (note === null) {
					return;
				}
				self.busy = p.id;
				self.api('POST', self.apiUrl + '/' + p.id + '/reject', { note: note }).then(function (r) { self.after(r, self.t('proof.rejectDone')); });
			},
		},
		template: '\
<div class="cmEditions cmInvoices cmProofs">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'proof.title\') }}: {{ editionName }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div class="cmProofs__tabs" role="group">\
		<pkp-button :is-active="status === \'pending\'" @click="show(\'pending\')">{{ t(\'proof.tab.pending\') }}</pkp-button>\
		<pkp-button :is-active="status === \'accepted\'" @click="show(\'accepted\')">{{ t(\'proof.status.accepted\') }}</pkp-button>\
		<pkp-button :is-active="status === \'rejected\'" @click="show(\'rejected\')">{{ t(\'proof.status.rejected\') }}</pkp-button>\
		<pkp-button :is-active="status === \'all\'" @click="show(\'all\')">{{ t(\'participants.all\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<p v-else-if="!items.length" class="cmEditions__empty">{{ status === \'pending\' ? t(\'proof.emptyPending\') : t(\'proof.empty\') }}</p>\
	<template v-else>\
	<p class="cmEditions__note" role="status">{{ t(\'participants.count\', { count: itemsMax }) }}</p>\
	<table class="cmEditions__table">\
		<thead><tr><th>{{ t(\'invoice.number\') }}</th><th>{{ t(\'invoice.payer\') }}</th><th>{{ t(\'proof.due\') }}</th><th>{{ t(\'proof.file\') }}</th><th>{{ t(\'participants.status\') }}</th><th></th></tr></thead>\
		<tbody><tr v-for="p in items" :key="p.id">\
			<td><strong>{{ p.invoice.number }}</strong><div class="cmEditions__path" v-if="p.invoice.paymentReference">{{ p.invoice.paymentReference }}</div></td>\
			<td>{{ p.invoice.payer.name || p.invoice.payer.email }}<div class="cmEditions__path">{{ p.invoice.payer.email }}</div></td>\
			<td>{{ money(p.invoice.due) }}<div class="cmEditions__path">{{ t(\'invoice.total\') }}: {{ money(p.invoice.total) }}</div></td>\
			<td><a :href="file(p)" target="_blank" rel="noopener">{{ p.name }}</a><div class="cmEditions__path">{{ size(p.size) }} · {{ time(p.uploadedAt) }}</div><div v-if="p.duplicateOf && p.duplicateOf.length" class="cmBadge">{{ t(\'proof.duplicate\') }}</div></td>\
			<td>{{ p.statusLabel }}<div class="cmEditions__path" v-if="p.note">{{ p.note }}</div><div class="cmEditions__path" v-if="p.status === \'pending\'">{{ p.invoice.statusLabel }}</div></td>\
			<td class="cmEditions__actions">\
				<a class="pkpButton" :href="file(p)" target="_blank" rel="noopener">{{ t(\'proof.view\') }}</a>\
				<pkp-button v-if="p.status === \'pending\' && p.invoice.isOpen" :disabled="busy === p.id" @click="accept(p)">{{ t(\'proof.accept\') }}</pkp-button>\
				<pkp-button v-if="p.status === \'pending\'" :is-warnable="true" :disabled="busy === p.id" @click="reject(p)">{{ t(\'proof.reject\') }}</pkp-button>\
			</td>\
		</tr></tbody>\
	</table>\
	<div class="cmEditions__buttons" v-if="pages > 1">\
		<pkp-button :disabled="page <= 1" @click="go(page - 1)">‹</pkp-button>\
		<span>{{ t(\'participants.page\', { page: page, pages: pages }) }}</span>\
		<pkp-button :disabled="page >= pages" @click="go(page + 1)">›</pkp-button>\
	</div>\
	</template>\
</div>',
	});

	pkp.registry.registerComponent('ConferencePaymentJournal', {
		name: 'ConferencePaymentJournal',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		data: function () {
			return { isLoading: true, items: [], itemsMax: 0, page: 1, perPage: 20 };
		},
		computed: {
			pages: function () {
				return Math.max(1, Math.ceil(this.itemsMax / this.perPage));
			},
		},
		mounted: function () {
			this.load();
		},
		methods: {
			load: function () {
				var self = this;
				self.isLoading = true;
				self.api('GET', self.apiUrl + '?count=' + self.perPage + '&offset=' + (self.page - 1) * self.perPage).then(function (r) {
					self.isLoading = false;
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
						return;
					}
					self.items = r.data.items || [];
					self.itemsMax = r.data.itemsMax || 0;
				});
			},
			go: function (page) {
				this.page = Math.min(this.pages, Math.max(1, page));
				this.load();
			},
		},
		template: '\
<div class="cmJournal">\
	<h3 class="cmFees__title">{{ t(\'journal.title\') }}</h3>\
	<p class="cmEditions__note">{{ t(\'journal.description\') }}</p>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<p v-else-if="!items.length" class="cmEditions__empty">{{ t(\'journal.empty\') }}</p>\
	<template v-else>\
	<table class="cmEditions__table">\
		<thead><tr><th>{{ t(\'journal.time\') }}</th><th>{{ t(\'invoice.number\') }}</th><th>{{ t(\'journal.event\') }}</th><th>{{ t(\'journal.amount\') }}</th><th>{{ t(\'journal.note\') }}</th></tr></thead>\
		<tbody><tr v-for="e in items" :key="e.id">\
			<td class="cmJournal__time">{{ time(e.createdAt) }}</td>\
			<td class="cmJournal__time">{{ e.invoiceNumber || (\'#\' + e.invoiceId) }}</td>\
			<td>{{ e.typeLabel }}</td>\
			<td class="cmJournal__time">{{ money(e.amount) }}</td>\
			<td>{{ e.note }}</td>\
		</tr></tbody>\
	</table>\
	<div class="cmEditions__buttons" v-if="pages > 1">\
		<pkp-button :disabled="page <= 1" @click="go(page - 1)">‹</pkp-button>\
		<span>{{ t(\'participants.page\', { page: page, pages: pages }) }}</span>\
		<pkp-button :disabled="page >= pages" @click="go(page + 1)">›</pkp-button>\
	</div>\
	</template>\
</div>',
	});

	/** FEE-12: export for accounting; the file is a plain GET download. */
	pkp.registry.registerComponent('ConferenceAccountingExport', {
		name: 'ConferenceAccountingExport',
		mixins: [common],
		props: {
			/** .../editions/{id}/invoices */
			apiUrl: { type: String, required: true },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		data: function () {
			return { available: [], selected: [], xlsx: false, scope: 'edition', from: '', to: '', dateField: 'issued', withReplaced: false, isSaving: false, error: '' };
		},
		computed: {
			base: function () {
				return this.apiUrl.replace(/editions\/\d+\/invoices$/, 'exports/accounting');
			},
			editionId: function () {
				var m = this.apiUrl.match(/editions\/(\d+)\/invoices$/);
				return m ? m[1] : '';
			},
			unselected: function () {
				var self = this;
				return this.available.filter(function (c) { return self.selected.indexOf(c.value) === -1; });
			},
		},
		mounted: function () {
			var self = this;
			self.api('GET', self.base + '/columns').then(function (r) {
				if (r.ok) {
					self.available = r.data.available;
					self.selected = r.data.selected;
					self.xlsx = !!r.data.xlsx;
				}
			});
		},
		methods: {
			label: function (value) {
				var c = this.available.find(function (x) { return x.value === value; });
				return c ? c.label : value;
			},
			move: function (n, step) {
				var list = this.selected.slice();
				var to = n + step;
				if (to < 0 || to >= list.length) {
					return;
				}
				var v = list[n];
				list[n] = list[to];
				list[to] = v;
				this.selected = list;
			},
			remove: function (n) {
				if (this.selected.length > 1) {
					this.selected = this.selected.filter(function (x, i) { return i !== n; });
				}
			},
			add: function (value) {
				this.selected = this.selected.concat([value]);
			},
			saveColumns: function () {
				var self = this;
				self.isSaving = true;
				return self.api('PUT', self.base + '/columns', { columns: self.selected }).then(function (r) {
					self.isSaving = false;
					r.ok ? notify(self.t('export.columnsSaved')) : notify(firstError(r.data), 'warning');
					return r.ok;
				});
			},
			url: function (format) {
				var q = new URLSearchParams({ format: format, from: this.from, to: this.to, dateField: this.dateField });
				if (this.scope === 'edition') {
					q.set('editionId', this.editionId);
				}
				if (this.withReplaced) {
					q.set('withReplaced', '1');
				}
				return this.base + '?' + q.toString();
			},
			download: function (format) {
				var self = this;
				self.saveColumns().then(function (ok) {
					if (ok) {
						window.location.href = self.url(format);
					}
				});
			},
		},
		template: '\
<div class="cmExport">\
	<h3 class="cmFees__title">{{ t(\'export.title\') }}</h3>\
	<p class="cmEditions__note">{{ t(\'export.description\') }}</p>\
	<div class="cmExport__grid">\
		<fieldset class="cmExport__period">\
			<legend>{{ t(\'export.period\') }}</legend>\
			<label><input type="radio" value="edition" v-model="scope"> {{ t(\'export.scope.edition\') }}</label>\
			<label><input type="radio" value="all" v-model="scope"> {{ t(\'export.scope.all\') }}</label>\
			<label>{{ t(\'export.from\') }} <input type="date" v-model="from"></label>\
			<label>{{ t(\'export.to\') }} <input type="date" v-model="to"></label>\
			<label>{{ t(\'export.dateField\') }} <select v-model="dateField"><option value="issued">{{ t(\'export.dateField.issued\') }}</option><option value="paid">{{ t(\'export.dateField.paid\') }}</option></select></label>\
			<label><input type="checkbox" v-model="withReplaced"> {{ t(\'export.withReplaced\') }}</label>\
		</fieldset>\
		<fieldset class="cmExport__columns">\
			<legend>{{ t(\'export.columns\') }}</legend>\
			<ol class="cmExport__list">\
				<li v-for="(c, n) in selected" :key="c"><span>{{ label(c) }}</span>\
					<button type="button" class="cmExport__icon" :aria-label="t(\'export.up\')" :disabled="n === 0" @click="move(n, -1)">↑</button>\
					<button type="button" class="cmExport__icon" :aria-label="t(\'export.down\')" :disabled="n === selected.length - 1" @click="move(n, 1)">↓</button>\
					<button type="button" class="cmExport__icon" :aria-label="t(\'export.remove\')" :disabled="selected.length < 2" @click="remove(n)">×</button>\
				</li>\
			</ol>\
			<label v-if="unselected.length">{{ t(\'export.addColumn\') }} <select @change="add($event.target.value); $event.target.value = \'\'"><option value=""></option><option v-for="c in unselected" :key="c.value" :value="c.value">{{ c.label }}</option></select></label>\
		</fieldset>\
	</div>\
	<div class="cmEditions__buttons">\
		<pkp-button :is-primary="true" :disabled="isSaving" @click="download(\'csv\')">{{ t(\'export.csv\') }}</pkp-button>\
		<pkp-button v-if="xlsx" :disabled="isSaving" @click="download(\'xlsx\')">{{ t(\'export.xlsx\') }}</pkp-button>\
		<pkp-button :disabled="isSaving" @click="saveColumns">{{ t(\'export.saveColumns\') }}</pkp-button>\
	</div>\
</div>',
	});
})();
