/**
 * Co-organisers and partners of an edition (INS-04), opened from the
 * editions page: names in the form languages, role, site, logo, order and,
 * for a co-organiser, the payment account that receives the fees.
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	if (!window.pkp || !pkp.registry) {
		return;
	}

	function notify(message, type) {
		if (window.pkp && pkp.eventBus) {
			pkp.eventBus.$emit('notify', message, type || 'success');
		}
	}

	pkp.registry.registerComponent('ConferencePartners', {
		name: 'ConferencePartners',
		props: {
			/** .../conference/editions/{id} */
			apiUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			locales: { type: Array, required: true },
			primaryLocale: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, busy: false, data: null, form: null, errors: {}, confirmDelete: null, logoErrors: {} };
		},
		mounted: function () {
			this.load();
		},
		computed: {
			partners: function () {
				return this.data ? this.data.partners : [];
			},
			roleLabels: function () {
				var out = {};
				(this.data ? this.data.roles : []).forEach(function (r) { out[r.value] = r.label; });
				return out;
			},
			canAdd: function () {
				return this.data && this.partners.length < this.data.max;
			},
		},
		methods: {
			t: function (key, params) {
				var s = this.i18n[key] || key;
				Object.keys(params || {}).forEach(function (p) { s = s.split('{$' + p + '}').join(params[p]); });
				return s;
			},
			call: function (method, url, body) {
				var init = { method: method, credentials: 'same-origin', headers: { 'X-Csrf-Token': this.csrfToken, Accept: 'application/json' } };
				if (body instanceof FormData) {
					init.body = body;
				} else if (body !== undefined) {
					init.headers['Content-Type'] = 'application/json';
					init.body = JSON.stringify(body);
				}
				return fetch(url, init).then(function (response) {
					return response.json().catch(function () { return {}; }).then(function (data) {
						return { ok: response.ok, status: response.status, data: data };
					});
				});
			},
			load: function () {
				var self = this;
				self.call('GET', self.apiUrl + '/partners').then(function (r) {
					self.isLoading = false;
					r.ok ? (self.data = r.data) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			localeName: function (code) {
				var found = this.locales.find(function (l) { return l.key === code; });
				return found ? found.label : code;
			},
			startNew: function () {
				var name = {};
				this.locales.forEach(function (l) { name[l.key] = ''; });
				this.form = { id: null, role: 'partner', name: name, url: '', paymentAccountId: '' };
				this.errors = {};
				this.focusForm();
			},
			startEdit: function (p) {
				var name = {};
				this.locales.forEach(function (l) { name[l.key] = (p.name && p.name[l.key]) || ''; });
				this.form = { id: p.id, role: p.role, name: name, url: p.url || '', paymentAccountId: p.paymentAccountId === null ? '' : String(p.paymentAccountId) };
				this.errors = {};
				this.focusForm();
			},
			focusForm: function () {
				var self = this;
				self.$nextTick(function () {
					var el = self.$el.querySelector('#cmPartnerRole');
					if (el) {
						el.focus();
					}
				});
			},
			save: function () {
				var self = this;
				var f = self.form;
				var body = { role: f.role, name: f.name, url: f.url, paymentAccountId: f.role === 'co_organizer' ? f.paymentAccountId : '' };
				self.busy = true;
				self.call(f.id === null ? 'POST' : 'PUT', self.apiUrl + '/partners' + (f.id === null ? '' : '/' + f.id), body).then(function (r) {
					self.busy = false;
					if (!r.ok) {
						self.errors = r.data || {};
						return;
					}
					self.data = r.data;
					self.form = null;
					notify(self.t('saved'));
				});
			},
			move: function (p, direction) {
				var self = this;
				self.call('POST', self.apiUrl + '/partners/' + p.id + '/move', { direction: direction }).then(function (r) {
					r.ok ? (self.data = r.data) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			remove: function (p) {
				var self = this;
				if (self.confirmDelete !== p.id) {
					self.confirmDelete = p.id;
					return;
				}
				self.call('DELETE', self.apiUrl + '/partners/' + p.id).then(function (r) {
					self.confirmDelete = null;
					r.ok ? (self.data = r.data, notify(self.t('deleted'))) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			uploadLogo: function (p, event) {
				var self = this;
				var file = event.target.files && event.target.files[0];
				if (!file) {
					return;
				}
				var body = new FormData();
				body.append('logo', file);
				self.busy = true;
				self.call('POST', self.apiUrl + '/partners/' + p.id + '/logo', body).then(function (r) {
					self.busy = false;
					event.target.value = '';
					var errors = Object.assign({}, self.logoErrors);
					if (r.ok) {
						delete errors[p.id];
						self.data = r.data;
						notify(self.t('partner.logoSaved'));
					} else {
						errors[p.id] = (r.data.logo || [r.data.errorMessage || '']).join(' ');
					}
					self.logoErrors = errors;
				});
			},
			removeLogo: function (p) {
				var self = this;
				self.call('DELETE', self.apiUrl + '/partners/' + p.id + '/logo').then(function (r) {
					r.ok ? (self.data = r.data) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			fieldError: function (field) {
				return (this.errors[field] || []).join(' ');
			},
		},
		template: '\
<div class="cmEditions cmPartners">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'partner.title\') }}<span v-if="editionName">: {{ editionName }}</span></h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<p class="cmEditions__note">{{ t(\'partner.help\') }}</p>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else-if="data">\
		<p v-if="!partners.length" class="cmEditions__note">{{ t(\'partner.empty\') }}</p>\
		<ol v-else class="cmPartners__list">\
			<li v-for="(p, i) in partners" :key="p.id" class="cmPartners__item">\
				<div class="cmPartners__logo">\
					<img v-if="p.logoUrl" :src="p.logoUrl" :alt="t(\'partner.logoOf\', { name: p.localizedName })">\
					<span v-else class="cmPartners__noLogo">{{ t(\'partner.noLogo\') }}</span>\
				</div>\
				<div class="cmPartners__body">\
					<strong>{{ p.localizedName }}</strong> <span class="cmPartners__role">{{ roleLabels[p.role] }}</span>\
					<div v-if="p.url" class="cmEditions__path">{{ p.url }}</div>\
					<div v-if="p.paymentAccountName" class="cmPartners__account">{{ t(\'partner.receivesFees\', { account: p.paymentAccountName }) }}</div>\
					<div class="cmEditions__buttons">\
						<pkp-button :disabled="i === 0" :aria-label="t(\'partner.moveUp\') + \': \' + p.localizedName" @click="move(p, -1)">↑</pkp-button>\
						<pkp-button :disabled="i === partners.length - 1" :aria-label="t(\'partner.moveDown\') + \': \' + p.localizedName" @click="move(p, 1)">↓</pkp-button>\
						<pkp-button @click="startEdit(p)">{{ t(\'edit\') }}</pkp-button>\
						<label class="cmPartners__upload pkpButton">{{ p.logoUrl ? t(\'partner.replaceLogo\') : t(\'partner.uploadLogo\') }}\
							<input type="file" accept="image/png,image/jpeg,image/webp" class="cmPartners__file" :disabled="busy" @change="uploadLogo(p, $event)">\
						</label>\
						<pkp-button v-if="p.logoUrl" @click="removeLogo(p)">{{ t(\'partner.removeLogo\') }}</pkp-button>\
						<pkp-button :is-warnable="true" @click="remove(p)">{{ confirmDelete === p.id ? t(\'partner.deleteConfirm\') : t(\'delete\') }}</pkp-button>\
					</div>\
					<p v-if="logoErrors[p.id]" class="cmPartners__error" role="alert">{{ logoErrors[p.id] }}</p>\
				</div>\
			</li>\
		</ol>\
		<p class="cmEditions__note">{{ t(\'partner.logoHelp\') }}</p>\
		<pkp-button v-if="!form && canAdd" :is-primary="true" @click="startNew">{{ t(\'partner.add\') }}</pkp-button>\
		<p v-if="!canAdd" class="cmEditions__note">{{ t(\'partner.limit\', { n: data.max }) }}</p>\
		<form v-if="form" class="cmPartners__form" @submit.prevent="save">\
			<h3>{{ form.id === null ? t(\'partner.add\') : t(\'edit\') }}</h3>\
			<div class="cmFees__field">\
				<label for="cmPartnerRole">{{ t(\'partner.role\') }}</label>\
				<select id="cmPartnerRole" v-model="form.role">\
					<option v-for="r in data.roles" :key="r.value" :value="r.value">{{ r.label }}</option>\
				</select>\
				<p v-if="fieldError(\'role\')" class="cmPartners__error" role="alert">{{ fieldError(\'role\') }}</p>\
			</div>\
			<div v-for="l in locales" :key="l.key" class="cmFees__field">\
				<label :for="\'cmPartnerName-\' + l.key">{{ t(\'partner.name\') }} ({{ l.label }})<span v-if="l.key === primaryLocale"> *</span></label>\
				<input :id="\'cmPartnerName-\' + l.key" type="text" maxlength="255" v-model="form.name[l.key]" :required="l.key === primaryLocale">\
			</div>\
			<p v-if="fieldError(\'name\')" class="cmPartners__error" role="alert">{{ fieldError(\'name\') }}</p>\
			<div class="cmFees__field">\
				<label for="cmPartnerUrl">{{ t(\'partner.url\') }}</label>\
				<input id="cmPartnerUrl" type="url" maxlength="2048" placeholder="https://" v-model="form.url">\
				<p v-if="fieldError(\'url\')" class="cmPartners__error" role="alert">{{ fieldError(\'url\') }}</p>\
			</div>\
			<div v-if="form.role === \'co_organizer\'" class="cmFees__field">\
				<label for="cmPartnerAccount">{{ t(\'partner.account\') }}</label>\
				<select id="cmPartnerAccount" v-model="form.paymentAccountId" aria-describedby="cmPartnerAccountHelp">\
					<option value="">{{ t(\'partner.noAccount\') }}</option>\
					<option v-for="a in data.accounts" :key="a.id" :value="String(a.id)">{{ a.name }}</option>\
				</select>\
				<p id="cmPartnerAccountHelp" class="cmEditions__note">{{ data.accounts.length ? t(\'partner.accountHelp\') : t(\'partner.noAccounts\') }}</p>\
				<p v-if="fieldError(\'paymentAccountId\')" class="cmPartners__error" role="alert">{{ fieldError(\'paymentAccountId\') }}</p>\
			</div>\
			<div class="cmEditions__buttons">\
				<pkp-button :is-primary="true" :disabled="busy" @click="save">{{ t(\'save\') }}</pkp-button>\
				<pkp-button @click="form = null">{{ t(\'cancel\') }}</pkp-button>\
			</div>\
		</form>\
	</template>\
</div>',
	});
})();
