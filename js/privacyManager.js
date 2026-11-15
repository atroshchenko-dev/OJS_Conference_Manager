/**
 * Personal data, opened from the editions page (DAT-02, DAT-03, DAT-04):
 * retention periods, the export of a person's data and anonymisation.
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

	pkp.registry.registerComponent('ConferencePersonalData', {
		name: 'ConferencePersonalData',
		props: {
			/** .../conference */
			apiUrl: { type: String, required: true },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, retention: { proofsMonths: 0, participantsMonths: 0 }, email: '', busy: false };
		},
		computed: {
			validEmail: function () {
				return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email.trim());
			},
			exportUrl: function () {
				return this.apiUrl + '/personal-data?email=' + encodeURIComponent(this.email.trim());
			},
		},
		mounted: function () {
			var self = this;
			self.call('GET', self.apiUrl + '/retention').then(function (r) {
				self.isLoading = false;
				r.ok ? (self.retention = r.data) : notify(firstError(r.data), 'warning');
			});
		},
		methods: {
			t: function (key, params) {
				var s = this.i18n[key] || key;
				Object.keys(params || {}).forEach(function (p) { s = s.split('{$' + p + '}').join(params[p]); });
				return s;
			},
			call: function (method, url, body) {
				var init = { method: method, credentials: 'same-origin', headers: { 'X-Csrf-Token': this.csrfToken, Accept: 'application/json' } };
				if (body !== undefined) {
					init.headers['Content-Type'] = 'application/json';
					init.body = JSON.stringify(body);
				}
				return fetch(url, init).then(function (response) {
					return response.json().catch(function () { return {}; }).then(function (data) {
						return { ok: response.ok, status: response.status, data: data };
					});
				});
			},
			saveRetention: function () {
				var self = this;
				self.busy = true;
				self.call('PUT', self.apiUrl + '/retention', {
					proofsMonths: Number(self.retention.proofsMonths) || 0,
					participantsMonths: Number(self.retention.participantsMonths) || 0,
				}).then(function (r) {
					self.busy = false;
					r.ok ? (self.retention = r.data, notify(self.t('fee.saved'))) : notify(firstError(r.data), 'warning');
				});
			},
			anonymize: function () {
				var self = this;
				if (!self.validEmail || !window.confirm(self.t('privacy.anonymizeConfirm', { email: self.email.trim() }))) {
					return;
				}
				self.busy = true;
				self.call('POST', self.apiUrl + '/personal-data/anonymize', { email: self.email.trim() }).then(function (r) {
					self.busy = false;
					r.ok ? notify(self.t('privacy.anonymized', { count: r.data.anonymized })) : notify(firstError(r.data), 'warning');
				});
			},
		},
		template: '\
<div class="cmEditions cmPrivacy">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'privacy.title\') }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else>\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'privacy.retention\') }}</h3>\
			<p class="cmEditions__note">{{ t(\'privacy.retentionHelp\') }}</p>\
			<form class="cmPrivacy__grid" @submit.prevent="saveRetention">\
				<label class="cmEditions__field"><span>{{ t(\'privacy.proofsMonths\') }}</span><input class="pkpFormField__input" type="number" min="0" max="120" v-model="retention.proofsMonths"></label>\
				<label class="cmEditions__field"><span>{{ t(\'privacy.participantsMonths\') }}</span><input class="pkpFormField__input" type="number" min="0" max="120" v-model="retention.participantsMonths"></label>\
				<div class="cmEditions__buttons"><pkp-button :is-primary="true" :disabled="busy" @click="saveRetention">{{ t(\'save\') }}</pkp-button></div>\
			</form>\
		</section>\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'privacy.person\') }}</h3>\
			<p class="cmEditions__note">{{ t(\'privacy.personHelp\') }}</p>\
			<label class="cmEditions__field"><span>{{ t(\'privacy.email\') }}</span><input class="pkpFormField__input" type="email" v-model="email" autocomplete="off"></label>\
			<div class="cmEditions__buttons">\
				<a class="pkpButton" :class="{ \'cmPrivacy__disabled\': !validEmail }" :href="validEmail ? exportUrl : null" :aria-disabled="!validEmail">{{ t(\'privacy.export\') }}</a>\
				<pkp-button :is-warnable="true" :disabled="busy || !validEmail" @click="anonymize">{{ t(\'privacy.anonymize\') }}</pkp-button>\
			</div>\
		</section>\
	</template>\
</div>',
	});
})();
