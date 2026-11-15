/**
 * Fees and participants of an edition (FEE-01…03, REG-07, REG-08), opened
 * from the editions page. Two Vue components registered with the OJS 3.5
 * registry before the page app starts:
 *
 *   ConferenceFeesEditor           categories, tariff matrix, calculator
 *   ConferenceParticipantsRegister register with filters, paging, export,
 *                                  attendance marks and the import of a
 *                                  meeting report (ATT-01, ATT-02), an
 *                                  invitation (REG-10) and one invoice for
 *                                  the selected participants (REG-09)
 *
 * Amounts are sent as decimal strings ("150.50"); the server keeps them in
 * minor units, so nothing here does arithmetic with money.
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
			localized: function (value) {
				if (!value) {
					return '';
				}
				return value[this.uiLocale] || value[this.primaryLocale] || Object.values(value)[0] || '';
			},
			api: function (method, url, body) {
				return request(this.csrfToken, method, url, body);
			},
			labelOf: function (list, value) {
				var found = (list || []).filter(function (o) { return o.value === value; })[0];
				return found ? found.label : (value || '—');
			},
		},
	};

	pkp.registry.registerComponent('ConferenceFeesEditor', {
		name: 'ConferenceFeesEditor',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			locales: { type: Array, required: true },
			primaryLocale: { type: String, required: true },
			uiLocale: { type: String, default: '' },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return {
				isLoading: true,
				categories: [],
				tariffs: [],
				options: { materials: [], pricing: [], presentationTypes: [], institutionTypes: [], currencies: [] },
				timezone: 'UTC',
				presetType: '',
				category: null,
				tariff: null,
				errors: {},
				quoteInput: { categoryId: '', material: 'abstract', pages: '', presentationType: '' },
				quoteResult: null,
			};
		},
		computed: {
			quoteMaterials: function () {
				return (this.options.materials || []).filter(function (m) { return m.value !== 'any'; });
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
					self.categories = r.data.categories || [];
					self.tariffs = r.data.tariffs || [];
					self.options = r.data.options || self.options;
					self.timezone = r.data.timezone || 'UTC';
					self.presetType = self.presetType || self.options.institutionType || '';
					if (!self.quoteInput.categoryId && self.categories.length) {
						self.quoteInput.categoryId = self.categories[0].id;
					}
				});
			},
			categoryName: function (id) {
				var c = this.categories.filter(function (x) { return x.id === id; })[0];
				return c ? this.localized(c.name) : '—';
			},
			newCategory: function () {
				var name = {};
				this.locales.forEach(function (l) { name[l.key] = ''; });
				this.errors = {};
				this.category = { id: null, code: '', name: name, requiresProof: false };
			},
			editCategory: function (c) {
				var name = {};
				this.locales.forEach(function (l) { name[l.key] = (c.name || {})[l.key] || ''; });
				this.errors = {};
				this.category = { id: c.id, code: c.code, name: name, requiresProof: c.requiresProof };
			},
			saveCategory: function () {
				var self = this;
				var c = self.category;
				var url = self.apiUrl + '/categories' + (c.id ? '/' + c.id : '');
				self.api(c.id ? 'PUT' : 'POST', url, { code: c.code, name: c.name, requiresProof: c.requiresProof }).then(function (r) {
					if (r.ok) {
						self.category = null;
						notify(self.t('fee.saved'));
						self.load();
					} else {
						self.errors = r.data || {};
					}
				});
			},
			deleteCategory: function (c) {
				var self = this;
				if (!window.confirm(self.t('fee.deleteCategoryConfirm', { name: self.localized(c.name) }))) {
					return;
				}
				self.api('DELETE', self.apiUrl + '/categories/' + c.id).then(function (r) {
					r.ok ? self.load() : notify(firstError(r.data), 'warning');
				});
			},
			applyPresets: function () {
				var self = this;
				self.api('POST', self.apiUrl + '/presets', { institutionType: self.presetType }).then(function (r) {
					r.ok ? self.load() : notify(firstError(r.data), 'warning');
				});
			},
			newTariff: function () {
				this.errors = {};
				this.tariff = {
					id: null, categoryId: this.categories.length ? this.categories[0].id : '', material: 'abstract', pricing: 'flat',
					amount: '', currency: (this.options.currencies || ['UAH'])[0], presentationType: '', minPages: '', maxPages: '',
					validFrom: '', validTo: '',
				};
			},
			editTariff: function (t) {
				this.errors = {};
				this.tariff = {
					id: t.id, categoryId: t.categoryId, material: t.material, pricing: t.pricing, amount: t.amount, currency: t.currency,
					presentationType: t.presentationType || '', minPages: t.minPages || '', maxPages: t.maxPages || '',
					validFrom: (t.local && t.local.validFrom) || '', validTo: (t.local && t.local.validTo) || '',
				};
			},
			saveTariff: function () {
				var self = this;
				var t = self.tariff;
				var body = Object.assign({}, t);
				delete body.id;
				self.api(t.id ? 'PUT' : 'POST', self.apiUrl + '/tariffs' + (t.id ? '/' + t.id : ''), body).then(function (r) {
					if (r.ok) {
						self.tariff = null;
						notify(self.t('fee.saved'));
						self.load();
					} else {
						self.errors = r.data || {};
					}
				});
			},
			deleteTariff: function (t) {
				var self = this;
				if (!window.confirm(self.t('fee.deleteTariffConfirm'))) {
					return;
				}
				self.api('DELETE', self.apiUrl + '/tariffs/' + t.id).then(function (r) {
					r.ok ? self.load() : notify(firstError(r.data), 'warning');
				});
			},
			pagesText: function (t) {
				if (!t.minPages && !t.maxPages) {
					return '';
				}
				return (t.minPages || 1) + '–' + (t.maxPages || '∞');
			},
			periodText: function (t) {
				var from = t.local && t.local.validFrom;
				var to = t.local && t.local.validTo;
				if (!from && !to) {
					return this.t('fee.always');
				}
				return (from || '…') + ' — ' + (to || '…');
			},
			quote: function () {
				var self = this;
				var q = self.quoteInput;
				var params = ['categoryId=' + encodeURIComponent(q.categoryId), 'material=' + encodeURIComponent(q.material)];
				if (q.pages) {
					params.push('pages=' + encodeURIComponent(q.pages));
				}
				if (q.presentationType) {
					params.push('presentationType=' + encodeURIComponent(q.presentationType));
				}
				self.api('GET', self.apiUrl + '/quote?' + params.join('&')).then(function (r) {
					self.quoteResult = r.ok ? r.data : { problem: 'error' };
				});
			},
			quoteText: function () {
				var r = this.quoteResult;
				if (!r) {
					return '';
				}
				if (r.problem) {
					return this.t('fee.quote.' + r.problem);
				}
				return r.total && r.total.minor === 0 ? this.t('fee.free') : r.total.decimal + ' ' + r.total.currency;
			},
			err: function (field) {
				return (this.errors[field] || []).join(' ');
			},
		},
		template: '\
<div class="cmEditions cmFees">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'fee.title\') }}: {{ editionName }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else>\
	<section class="cmEditions__form cmFees__block">\
		<h3>{{ t(\'fee.categories\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'fee.categories.description\') }}</p>\
		<table v-if="categories.length" class="cmEditions__table">\
			<thead><tr><th>{{ t(\'fee.category.name\') }}</th><th>{{ t(\'fee.category.code\') }}</th><th>{{ t(\'fee.requiresProof\') }}</th><th></th></tr></thead>\
			<tbody><tr v-for="c in categories" :key="c.id">\
				<td>{{ localized(c.name) }}</td><td><code>{{ c.code }}</code></td><td>{{ c.requiresProof ? \'✓\' : \'\' }}</td>\
				<td class="cmEditions__actions"><pkp-button @click="editCategory(c)">{{ t(\'edit\') }}</pkp-button><pkp-button :is-warnable="true" @click="deleteCategory(c)">{{ t(\'delete\') }}</pkp-button></td>\
			</tr></tbody>\
		</table>\
		<p v-else class="cmEditions__note">{{ t(\'fee.categories.empty\') }}</p>\
		<div class="cmEditions__buttons">\
			<pkp-button @click="newCategory">{{ t(\'fee.category.add\') }}</pkp-button>\
			<select class="pkpFormField__input cmFees__preset" v-model="presetType" :aria-label="t(\'fee.presets\')">\
				<option v-for="o in options.institutionTypes" :key="o.value" :value="o.value">{{ o.label }}</option>\
			</select>\
			<pkp-button @click="applyPresets">{{ t(\'fee.presets\') }}</pkp-button>\
		</div>\
		<form v-if="category" class="cmFees__edit" @submit.prevent="saveCategory" novalidate>\
			<div class="cmEditions__row">\
				<label class="cmEditions__field" v-for="l in locales" :key="l.key" :class="{ \'cmEditions__field--error\': errors.name && l.key === primaryLocale }">\
					<span>{{ t(\'fee.category.name\') }} ({{ l.label }})<template v-if="l.key === primaryLocale"> *</template></span>\
					<input class="pkpFormField__input" type="text" v-model="category.name[l.key]" maxlength="255">\
				</label>\
			</div>\
			<em class="cmFees__error" v-if="errors.name">{{ err(\'name\') }}</em>\
			<div class="cmEditions__row">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.code }"><span>{{ t(\'fee.category.code\') }} *</span>\
					<input class="pkpFormField__input" type="text" v-model="category.code" maxlength="32" pattern="[a-z0-9][a-z0-9_-]*">\
					<small>{{ t(\'fee.category.code.description\') }}</small><em v-if="errors.code">{{ err(\'code\') }}</em></label>\
				<label class="cmFees__check"><input type="checkbox" v-model="category.requiresProof"> {{ t(\'fee.requiresProof\') }}</label>\
			</div>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" @click="saveCategory">{{ t(\'save\') }}</pkp-button><pkp-button @click="category = null">{{ t(\'cancel\') }}</pkp-button></div>\
		</form>\
	</section>\
	<section class="cmEditions__form cmFees__block">\
		<h3>{{ t(\'fee.tariffs\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'fee.tariffs.description\', { timezone: timezone }) }}</p>\
		<table v-if="tariffs.length" class="cmEditions__table">\
			<thead><tr><th>{{ t(\'fee.category\') }}</th><th>{{ t(\'fee.material\') }}</th><th>{{ t(\'fee.presentationType\') }}</th><th>{{ t(\'fee.pages\') }}</th><th>{{ t(\'fee.amount\') }}</th><th>{{ t(\'fee.period\') }}</th><th></th></tr></thead>\
			<tbody><tr v-for="tr in tariffs" :key="tr.id">\
				<td>{{ categoryName(tr.categoryId) }}</td>\
				<td>{{ labelOf(options.materials, tr.material) }}</td>\
				<td>{{ tr.presentationType ? labelOf(options.presentationTypes, tr.presentationType) : t(\'fee.anyType\') }}</td>\
				<td>{{ pagesText(tr) }}</td>\
				<td><strong v-if="tr.amountMinor === 0">{{ t(\'fee.free\') }}</strong><template v-else>{{ tr.amount }} {{ tr.currency }}<span v-if="tr.pricing === \'per_page\'"> / {{ t(\'fee.perPageShort\') }}</span></template></td>\
				<td>{{ periodText(tr) }}</td>\
				<td class="cmEditions__actions"><pkp-button @click="editTariff(tr)">{{ t(\'edit\') }}</pkp-button><pkp-button :is-warnable="true" @click="deleteTariff(tr)">{{ t(\'delete\') }}</pkp-button></td>\
			</tr></tbody>\
		</table>\
		<p v-else class="cmEditions__note">{{ t(\'fee.tariffs.empty\') }}</p>\
		<div class="cmEditions__buttons"><pkp-button :disabled="!categories.length" @click="newTariff">{{ t(\'fee.tariff.add\') }}</pkp-button></div>\
		<form v-if="tariff" class="cmFees__edit" @submit.prevent="saveTariff" novalidate>\
			<div class="cmEditions__grid">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.categoryId }"><span>{{ t(\'fee.category\') }}</span>\
					<select class="pkpFormField__input" v-model="tariff.categoryId"><option v-for="c in categories" :key="c.id" :value="c.id">{{ localized(c.name) }}</option></select></label>\
				<label class="cmEditions__field"><span>{{ t(\'fee.material\') }}</span>\
					<select class="pkpFormField__input" v-model="tariff.material"><option v-for="o in options.materials" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
				<label class="cmEditions__field" v-if="tariff.material !== \'listener\'"><span>{{ t(\'fee.presentationType\') }}</span>\
					<select class="pkpFormField__input" v-model="tariff.presentationType"><option value="">{{ t(\'fee.anyType\') }}</option><option v-for="o in options.presentationTypes" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
				<label class="cmEditions__field" v-if="tariff.material !== \'listener\'"><span>{{ t(\'fee.pricing\') }}</span>\
					<select class="pkpFormField__input" v-model="tariff.pricing"><option v-for="o in options.pricing" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.amount }"><span>{{ t(\'fee.amount\') }}</span>\
					<input class="pkpFormField__input" type="text" inputmode="decimal" v-model="tariff.amount" placeholder="0.00"><small>{{ t(\'fee.amount.description\') }}</small><em v-if="errors.amount">{{ err(\'amount\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.currency }"><span>{{ t(\'fee.currency\') }}</span>\
					<input class="pkpFormField__input" type="text" v-model="tariff.currency" maxlength="3" list="cmFeeCurrencies"><datalist id="cmFeeCurrencies"><option v-for="c in options.currencies" :key="c" :value="c"></option></datalist><em v-if="errors.currency">{{ err(\'currency\') }}</em></label>\
				<label class="cmEditions__field" v-if="tariff.material === \'paper\' || tariff.material === \'any\'" :class="{ \'cmEditions__field--error\': errors.maxPages }"><span>{{ t(\'fee.minPages\') }}</span>\
					<input class="pkpFormField__input" type="number" min="1" step="1" v-model="tariff.minPages"></label>\
				<label class="cmEditions__field" v-if="tariff.material === \'paper\' || tariff.material === \'any\'" :class="{ \'cmEditions__field--error\': errors.maxPages }"><span>{{ t(\'fee.maxPages\') }}</span>\
					<input class="pkpFormField__input" type="number" min="1" step="1" v-model="tariff.maxPages"><em v-if="errors.maxPages">{{ err(\'maxPages\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.validFrom || errors.period }"><span>{{ t(\'fee.validFrom\') }}</span>\
					<input class="pkpFormField__input" type="datetime-local" v-model="tariff.validFrom"></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.validTo || errors.period }"><span>{{ t(\'fee.validTo\') }}</span>\
					<input class="pkpFormField__input" type="datetime-local" v-model="tariff.validTo"><em v-if="errors.period">{{ err(\'period\') }}</em></label>\
			</div>\
			<template v-for="f in [\'material\', \'overlap\', \'pricing\', \'presentationType\', \'id\']" :key="f"><em class="cmFees__error" v-if="errors[f]">{{ err(f) }}</em></template>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" @click="saveTariff">{{ t(\'save\') }}</pkp-button><pkp-button @click="tariff = null">{{ t(\'cancel\') }}</pkp-button></div>\
		</form>\
	</section>\
	<section class="cmEditions__form cmFees__block" v-if="categories.length">\
		<h3>{{ t(\'fee.quote\') }}</h3>\
		<div class="cmEditions__grid">\
			<label class="cmEditions__field"><span>{{ t(\'fee.category\') }}</span>\
				<select class="pkpFormField__input" v-model="quoteInput.categoryId"><option v-for="c in categories" :key="c.id" :value="c.id">{{ localized(c.name) }}</option></select></label>\
			<label class="cmEditions__field"><span>{{ t(\'fee.material\') }}</span>\
				<select class="pkpFormField__input" v-model="quoteInput.material"><option v-for="o in quoteMaterials" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
			<label class="cmEditions__field" v-if="quoteInput.material !== \'listener\'"><span>{{ t(\'fee.presentationType\') }}</span>\
				<select class="pkpFormField__input" v-model="quoteInput.presentationType"><option value="">—</option><option v-for="o in options.presentationTypes" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
			<label class="cmEditions__field" v-if="quoteInput.material === \'paper\'"><span>{{ t(\'fee.pages\') }}</span>\
				<input class="pkpFormField__input" type="number" min="1" step="1" v-model="quoteInput.pages"></label>\
		</div>\
		<div class="cmEditions__buttons"><pkp-button @click="quote">{{ t(\'fee.quote.run\') }}</pkp-button><strong class="cmFees__quote" role="status">{{ quoteText() }}</strong></div>\
	</section>\
	</template>\
</div>',
	});

	pkp.registry.registerComponent('ConferenceParticipantsRegister', {
		name: 'ConferenceParticipantsRegister',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			cancelUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			primaryLocale: { type: String, required: true },
			uiLocale: { type: String, default: '' },
			options: { type: Object, required: true },
			/** .../editions/{id}, for attendance */
			editionUrl: { type: String, default: '' },
			threshold: { type: Number, default: 30 },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return {
				showImport: false,
				importFile: null,
				importName: '',
				importThreshold: this.threshold,
				importResult: null,
				importBusy: false,
				showInvite: false,
				invite: { email: '', givenName: '', familyName: '', affiliation: '', role: 'presenter', note: '', sendLetter: true },
				inviteErrors: {},
				selectedIds: [],
				showGroup: false,
				group: { organization: '', legalId: '', contactParticipationId: '' },
				groupErrors: {},
				groupRefused: [],
				groupBusy: false,
				items: [],
				itemsMax: 0,
				categories: [],
				filter: { searchPhrase: '', role: '', categoryId: '', status: '', confirmation: '', payment: '', attended: '' },
				page: 1,
				perPage: 50,
			};
		},
		computed: {
			pages: function () {
				return Math.max(1, Math.ceil(this.itemsMax / this.perPage));
			},
			exportUrl: function () {
				return this.apiUrl + '/export?' + this.query(false);
			},
			baseUrl: function () {
				var tail = '/participants';
				return this.apiUrl.slice(-tail.length) === tail ? this.apiUrl.slice(0, -tail.length) : this.apiUrl;
			},
			selectedRows: function () {
				var ids = this.selectedIds;
				return this.items.filter(function (p) { return ids.indexOf(p.participationId) !== -1; });
			},
		},
		mounted: function () {
			this.load();
		},
		methods: {
			query: function (paged) {
				var f = this.filter;
				var params = [];
				Object.keys(f).forEach(function (k) {
					if (f[k] !== '') {
						params.push(k + '=' + encodeURIComponent(f[k]));
					}
				});
				if (paged) {
					params.push('count=' + this.perPage, 'offset=' + (this.page - 1) * this.perPage);
				}
				return params.join('&');
			},
			load: function () {
				var self = this;
				self.isLoading = true;
				self.api('GET', self.apiUrl + '?' + self.query(true)).then(function (r) {
					self.isLoading = false;
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
						return;
					}
					self.items = r.data.items || [];
					self.itemsMax = r.data.itemsMax || 0;
					self.categories = r.data.categories || self.categories;
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
			setCategory: function (p, value) {
				var self = this;
				self.api('PUT', self.cancelUrl + '/' + p.participationId + '/category', { categoryId: value === '' ? null : Number(value) }).then(function (r) {
					r.ok ? (notify(self.t('fee.saved')), self.load()) : notify(firstError(r.data), 'warning');
				});
			},
			issueInvoice: function (p) {
				var self = this;
				var body = {};
				if (p.role !== 'listener') {
					var pages = window.prompt(self.t('invoice.pagesPrompt'), '');
					if (pages === null) {
						return;
					}
					body.pages = pages;
				}
				self.api('POST', self.cancelUrl + '/' + p.participationId + '/invoice', body).then(function (r) {
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
					} else if (r.data.invoice) {
						notify(self.t('invoice.issued', { number: r.data.invoice.number || '' }));
					} else {
						notify(r.data.message || '', 'warning');
					}
				});
			},
			setAttended: function (p, value) {
				var self = this;
				self.api('PUT', self.editionUrl + '/attendance', { participationIds: [p.participationId], attended: value }).then(function (r) {
					if (r.ok) {
						p.attended = value;
						p.attendanceSource = 'manual';
						p.attendedMinutes = null;
					} else {
						notify(firstError(r.data), 'warning');
					}
				});
			},
			markPage: function (value) {
				var self = this;
				var ids = self.items.filter(function (p) { return p.status === 'active'; }).map(function (p) { return p.participationId; });
				if (!ids.length) {
					return;
				}
				self.api('PUT', self.editionUrl + '/attendance', { participationIds: ids, attended: value }).then(function (r) {
					r.ok ? (notify(self.t('attendance.marked', { count: r.data.changed || 0 })), self.load()) : notify(firstError(r.data), 'warning');
				});
			},
			pickFile: function (event) {
				var self = this;
				var file = event.target.files && event.target.files[0];
				self.importResult = null;
				self.importFile = null;
				self.importName = file ? file.name : '';
				if (!file) {
					return;
				}
				var reader = new FileReader();
				reader.onload = function () {
					// Sent as base64, so UTF-16 reports from Teams keep their bytes.
					var bytes = new Uint8Array(reader.result);
					var bin = '';
					for (var i = 0; i < bytes.length; i += 0x8000) {
						bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
					}
					self.importFile = window.btoa(bin);
				};
				reader.readAsArrayBuffer(file);
			},
			runImport: function (apply) {
				var self = this;
				if (!self.importFile) {
					notify(self.t('attendance.chooseFile'), 'warning');
					return;
				}
				self.importBusy = true;
				self.api('POST', self.editionUrl + '/attendance/import', { csvBase64: self.importFile, threshold: self.importThreshold, apply: apply }).then(function (r) {
					self.importBusy = false;
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
						return;
					}
					self.importResult = r.data;
					if (apply) {
						notify(self.t('attendance.applied', { present: r.data.present }));
						self.load();
					}
				});
			},
			toggleSelected: function (p, on) {
				var i = this.selectedIds.indexOf(p.participationId);
				if (on && i === -1) {
					this.selectedIds.push(p.participationId);
				} else if (!on && i !== -1) {
					this.selectedIds.splice(i, 1);
				}
			},
			openGroup: function () {
				this.showGroup = true;
				this.groupErrors = {};
				this.groupRefused = [];
				if (this.selectedIds.indexOf(Number(this.group.contactParticipationId)) === -1) {
					this.group.contactParticipationId = this.selectedIds.length ? this.selectedIds[0] : '';
				}
			},
			nameOf: function (participationId) {
				var p = this.items.filter(function (x) { return x.participationId === participationId; })[0];
				return p ? (p.name || p.email) : '#' + participationId;
			},
			issueGroup: function () {
				var self = this;
				self.groupBusy = true;
				self.groupErrors = {};
				self.groupRefused = [];
				self.api('POST', self.baseUrl + '/group-invoices', {
					participationIds: self.selectedIds,
					contactParticipationId: Number(self.group.contactParticipationId),
					organization: self.group.organization,
					legalId: self.group.legalId,
				}).then(function (r) {
					self.groupBusy = false;
					if (!r.ok) {
						self.groupRefused = r.data.refused || [];
						var errors = Object.assign({}, r.data);
						delete errors.refused;
						self.groupErrors = errors;
						notify(firstError(errors), 'warning');
						return;
					}
					notify(self.t('group.issued', { number: r.data.number || '', count: (r.data.items || []).length }));
					self.showGroup = false;
					self.selectedIds = [];
					self.group = { organization: '', legalId: '', contactParticipationId: '' };
					self.load();
				});
			},
			sendInvite: function () {
				var self = this;
				self.inviteErrors = {};
				self.api('POST', self.baseUrl + '/invitations', self.invite).then(function (r) {
					if (!r.ok) {
						self.inviteErrors = r.data || {};
						notify(firstError(r.data), 'warning');
						return;
					}
					notify(self.t(r.data.letter ? 'invite.sent' : 'invite.saved', { email: self.invite.email }));
					self.invite = { email: '', givenName: '', familyName: '', affiliation: '', role: self.invite.role, note: '', sendLetter: true };
					self.showInvite = false;
					self.load();
				});
			},
			cancelParticipation: function (p) {
				var self = this;
				if (!window.confirm(self.t('participants.cancelConfirm', { name: p.name || p.email }))) {
					return;
				}
				self.api('POST', self.cancelUrl + '/' + p.participationId + '/cancel').then(function (r) {
					if (r.ok) {
						var inv = r.data.invoices || {};
						notify(self.t('participants.cancelled', { cancelled: inv.cancelled || 0, refund: inv.refundRequested || 0 }));
						self.load();
					} else {
						notify(firstError(r.data), 'warning');
					}
				});
			},
		},
		template: '\
<div class="cmEditions cmParticipants">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'participants.title\') }}: {{ editionName }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<form class="cmEditions__toolbar cmParticipants__filters" @submit.prevent="search">\
		<label class="cmEditions__filter">{{ t(\'participants.search\') }}<input class="pkpFormField__input" type="search" v-model="filter.searchPhrase" maxlength="100"></label>\
		<label class="cmEditions__filter">{{ t(\'participants.role\') }}<select class="pkpFormField__input" v-model="filter.role" @change="search"><option value="">{{ t(\'participants.all\') }}</option><option v-for="o in options.roles" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
		<label class="cmEditions__filter">{{ t(\'participants.category\') }}<select class="pkpFormField__input" v-model="filter.categoryId" @change="search"><option value="">{{ t(\'participants.all\') }}</option><option value="0">{{ t(\'participants.noCategory\') }}</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>\
		<label class="cmEditions__filter">{{ t(\'participants.confirmation\') }}<select class="pkpFormField__input" v-model="filter.confirmation" @change="search"><option value="">{{ t(\'participants.all\') }}</option><option v-for="o in options.confirmations" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
		<label class="cmEditions__filter">{{ t(\'participants.payment\') }}<select class="pkpFormField__input" v-model="filter.payment" @change="search"><option value="">{{ t(\'participants.all\') }}</option><option v-for="o in (options.payments || [])" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
		<label class="cmEditions__filter">{{ t(\'participants.status\') }}<select class="pkpFormField__input" v-model="filter.status" @change="search"><option value="">{{ t(\'participants.all\') }}</option><option v-for="o in options.statuses" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
		<label class="cmEditions__filter" v-if="editionUrl">{{ t(\'attendance.title\') }}<select class="pkpFormField__input" v-model="filter.attended" @change="search"><option value="">{{ t(\'participants.all\') }}</option><option value="1">{{ t(\'attendance.present\') }}</option><option value="0">{{ t(\'attendance.absent\') }}</option></select></label>\
		<pkp-button @click="search">{{ t(\'participants.find\') }}</pkp-button>\
		<a class="pkpButton" :href="exportUrl">{{ t(\'participants.export\') }}</a>\
		<pkp-button v-if="editionUrl" @click="showImport = !showImport">{{ t(\'attendance.import\') }}</pkp-button>\
		<pkp-button @click="showInvite = !showInvite">{{ t(\'invite.open\') }}</pkp-button>\
	</form>\
	<section v-if="showInvite" class="cmInvite" :aria-label="t(\'invite.title\')">\
		<h3 class="cmFees__title">{{ t(\'invite.title\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'invite.help\') }}</p>\
		<form class="cmInvite__form" @submit.prevent="sendInvite">\
			<label class="cmEditions__filter">{{ t(\'invite.email\') }} *<input class="pkpFormField__input" type="email" required maxlength="255" v-model="invite.email" autocomplete="off"><span class="cmField__error" v-if="inviteErrors.email">{{ inviteErrors.email.join(\' \') }}</span></label>\
			<label class="cmEditions__filter">{{ t(\'invite.givenName\') }} *<input class="pkpFormField__input" required maxlength="255" v-model="invite.givenName"><span class="cmField__error" v-if="inviteErrors.givenName">{{ inviteErrors.givenName.join(\' \') }}</span></label>\
			<label class="cmEditions__filter">{{ t(\'invite.familyName\') }}<input class="pkpFormField__input" maxlength="255" v-model="invite.familyName"></label>\
			<label class="cmEditions__filter">{{ t(\'invite.affiliation\') }}<input class="pkpFormField__input" maxlength="255" v-model="invite.affiliation"></label>\
			<label class="cmEditions__filter">{{ t(\'participants.role\') }}<select class="pkpFormField__input" v-model="invite.role"><option v-for="o in (options.invitedRoles || [])" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
			<label class="cmEditions__filter cmInvite__note">{{ t(\'invite.note\') }}<textarea class="pkpFormField__input" rows="3" maxlength="2000" v-model="invite.note"></textarea></label>\
			<label class="cmAttendance"><input type="checkbox" v-model="invite.sendLetter"> {{ t(\'invite.sendLetter\') }}</label>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" @click="sendInvite">{{ t(\'invite.submit\') }}</pkp-button><pkp-button @click="showInvite = false">{{ t(\'cancel\') }}</pkp-button></div>\
		</form>\
	</section>\
	<section v-if="showGroup" class="cmInvite" :aria-label="t(\'group.title\')">\
		<h3 class="cmFees__title">{{ t(\'group.title\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'group.help\') }}</p>\
		<p><strong>{{ t(\'group.selected\', { count: selectedIds.length }) }}</strong> {{ selectedIds.map(nameOf).join(\', \') }}</p>\
		<form class="cmInvite__form" @submit.prevent="issueGroup">\
			<label class="cmEditions__filter">{{ t(\'group.organization\') }} *<input class="pkpFormField__input" required maxlength="255" v-model="group.organization"><span class="cmField__error" v-if="groupErrors.organization">{{ groupErrors.organization.join(\' \') }}</span></label>\
			<label class="cmEditions__filter">{{ options.legalIdLabel || t(\'group.legalId\') }} *<input class="pkpFormField__input" required maxlength="32" v-model="group.legalId"><span class="cmField__error" v-if="groupErrors.legalId">{{ groupErrors.legalId.join(\' \') }}</span></label>\
			<label class="cmEditions__filter">{{ t(\'group.contact\') }}<select class="pkpFormField__input" v-model="group.contactParticipationId"><option v-for="id in selectedIds" :key="id" :value="id">{{ nameOf(id) }}</option></select><span class="cmEditions__path">{{ t(\'group.contactHelp\') }}</span></label>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" :disabled="groupBusy || selectedIds.length < 2" @click="issueGroup">{{ t(\'group.submit\') }}</pkp-button><pkp-button @click="showGroup = false">{{ t(\'cancel\') }}</pkp-button></div>\
		</form>\
		<div v-if="groupRefused.length" class="cmPanel__conflict" role="alert"><p>{{ t(\'group.refusedTitle\') }}</p><ul><li v-for="x in groupRefused" :key="x.participationId">{{ nameOf(x.participationId) }}: {{ x.reasonLabel }}</li></ul></div>\
	</section>\
	<section v-if="showImport" class="cmAttendanceImport" :aria-label="t(\'attendance.import\')">\
		<p class="cmEditions__note">{{ t(\'attendance.importHelp\') }}</p>\
		<div class="cmEditions__toolbar">\
			<label class="cmEditions__filter">{{ t(\'attendance.file\') }}<input type="file" accept=".csv,.tsv,.txt,text/csv" @change="pickFile"></label>\
			<label class="cmEditions__filter">{{ t(\'attendance.threshold\') }}<input class="pkpFormField__input cmAttendanceImport__minutes" type="number" min="1" max="1440" v-model.number="importThreshold"></label>\
			<pkp-button :disabled="importBusy || !importFile" @click="runImport(false)">{{ t(\'attendance.preview\') }}</pkp-button>\
			<pkp-button :is-primary="true" :disabled="importBusy || !importResult || importResult.applied" @click="runImport(true)">{{ t(\'attendance.apply\') }}</pkp-button>\
		</div>\
		<template v-if="importResult">\
			<p role="status"><strong>{{ t(importResult.applied ? \'attendance.resultApplied\' : \'attendance.resultPreview\', { rows: importResult.rows, matched: importResult.matched.length, present: importResult.present, unmatched: importResult.unmatched.length, skipped: importResult.skipped, threshold: importResult.threshold }) }}</strong></p>\
			<table class="cmEditions__table" v-if="importResult.matched.length">\
				<thead><tr><th>{{ t(\'participants.name\') }}</th><th>{{ t(\'attendance.minutes\') }}</th><th>{{ t(\'attendance.title\') }}</th></tr></thead>\
				<tbody><tr v-for="m in importResult.matched" :key="m.email"><td>{{ m.name || m.email }}<div class="cmEditions__path">{{ m.email }}</div></td><td>{{ m.minutes }}</td><td><span class="cmBadge" :class="{ \'cmBadge--ok\': m.attended || m.keptManual }">{{ m.attended ? t(\'attendance.present\') : (m.keptManual ? t(\'attendance.keptManual\') : t(\'attendance.absent\')) }}</span></td></tr></tbody>\
			</table>\
			<details v-if="importResult.unmatched.length"><summary>{{ t(\'attendance.unmatched\', { count: importResult.unmatched.length }) }}</summary><ul class="cmAttendanceImport__unmatched"><li v-for="u in importResult.unmatched" :key="u.email">{{ u.email }} — {{ u.minutes }}</li></ul></details>\
		</template>\
	</section>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<p v-else-if="!items.length" class="cmEditions__empty">{{ t(\'participants.empty\') }}</p>\
	<template v-else>\
	<div class="cmEditions__toolbar"><p class="cmEditions__note" role="status">{{ t(\'participants.count\', { count: itemsMax }) }}</p><pkp-button :disabled="selectedIds.length < 2" @click="openGroup">{{ t(\'group.open\', { count: selectedIds.length }) }}</pkp-button><template v-if="editionUrl"><pkp-button @click="markPage(true)">{{ t(\'attendance.markPage\') }}</pkp-button><pkp-button @click="markPage(false)">{{ t(\'attendance.unmarkPage\') }}</pkp-button></template></div>\
	<table class="cmEditions__table">\
		<thead><tr><th><span class="cmVisuallyHidden">{{ t(\'group.select\') }}</span></th><th>{{ t(\'participants.name\') }}</th><th>{{ t(\'participants.role\') }}</th><th>{{ t(\'participants.category\') }}</th><th>{{ t(\'participants.confirmation\') }}</th><th>{{ t(\'participants.status\') }}</th><th v-if="editionUrl">{{ t(\'attendance.title\') }}</th><th></th></tr></thead>\
		<tbody><tr v-for="p in items" :key="p.participationId" :class="{ \'cmParticipants__row--cancelled\': p.status === \'cancelled\' }">\
			<td><input type="checkbox" v-if="p.status === \'active\'" :checked="selectedIds.indexOf(p.participationId) !== -1" @change="toggleSelected(p, $event.target.checked)" :aria-label="t(\'group.selectOne\', { name: p.name || p.email })"></td>\
			<td><strong>{{ p.name || p.email }}</strong> <span class="cmBadge cmBadge--ok" v-if="p.invited">{{ t(\'invite.badge\') }}</span><div class="cmEditions__path">{{ p.email }}</div><div class="cmEditions__path" v-if="p.affiliation">{{ p.affiliation }}<template v-if="p.position">, {{ p.position }}</template></div><div class="cmEditions__path" v-if="p.minor"><span class="cmBadge">{{ t(\'participants.minor\') }}</span></div></td>\
			<td>{{ p.roleLabel }}<div class="cmEditions__path">{{ p.formLabel }}<template v-if="p.submissionId"> · #{{ p.submissionId }}</template></div></td>\
			<td><select class="pkpFormField__input" :value="p.categoryId === null ? \'\' : p.categoryId" @change="setCategory(p, $event.target.value)" :aria-label="t(\'participants.category\')"><option value="">—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></td>\
			<td>{{ p.confirmationLabel }}</td>\
			<td>{{ p.statusLabel }}</td>\
			<td v-if="editionUrl"><label class="cmAttendance"><input type="checkbox" :checked="p.attended" :disabled="p.status !== \'active\'" @change="setAttended(p, $event.target.checked)"> {{ p.attended ? t(\'attendance.present\') : t(\'attendance.absent\') }}</label><div class="cmEditions__path" v-if="p.attended && p.attendanceSource === \'import\'">{{ t(\'attendance.fromImport\', { minutes: p.attendedMinutes === null ? \'—\' : p.attendedMinutes }) }}</div></td>\
			<td class="cmEditions__actions"><pkp-button v-if="p.status === \'active\'" @click="issueInvoice(p)">{{ t(\'invoice.issue\') }}</pkp-button><pkp-button v-if="p.status === \'active\'" :is-warnable="true" @click="cancelParticipation(p)">{{ t(\'participants.cancel\') }}</pkp-button></td>\
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
})();
