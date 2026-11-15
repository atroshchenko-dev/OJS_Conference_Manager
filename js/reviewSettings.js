/**
 * Review forms of the two rounds of a two-phase edition (DEC-07), opened
 * from the editions page.
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

	pkp.registry.registerComponent('ConferenceReviewSettings', {
		name: 'ConferenceReviewSettings',
		props: {
			/** .../conference/editions/{id} */
			apiUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			formsUrl: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, busy: false, data: null, form: { abstractFormId: '', paperFormId: '' }, errors: {} };
		},
		mounted: function () {
			this.load();
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
			setData: function (d) {
				this.data = d;
				this.form = { abstractFormId: d.settings.abstractFormId || '', paperFormId: d.settings.paperFormId || '' };
				this.errors = {};
			},
			load: function () {
				var self = this;
				self.call('GET', self.apiUrl + '/review-settings').then(function (r) {
					self.isLoading = false;
					r.ok ? self.setData(r.data) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			save: function () {
				var self = this;
				self.busy = true;
				self.call('PUT', self.apiUrl + '/review-settings', {
					abstractFormId: self.form.abstractFormId || null,
					paperFormId: self.form.paperFormId || null,
				}).then(function (r) {
					self.busy = false;
					if (r.ok) {
						self.setData(r.data);
						notify(self.t('saved'));
					} else {
						self.errors = r.status === 400 ? r.data : {};
						notify(r.data.errorMessage || self.t('review.settings.notSaved'), 'warning');
					}
				});
			},
			error: function (field) {
				var e = this.errors[field];
				return e && e.length ? e.join(' ') : '';
			},
		},
		template: '\
<div class="cmEditions cmReviewSettings">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'review.settings.title\') }}<span v-if="editionName">: {{ editionName }}</span></h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else-if="data">\
		<section class="cmEditions__form cmFees__block">\
			<p>{{ t(\'review.settings.help\') }}</p>\
			<p v-if="data.mode !== \'two_phase\'" class="cmEditions__note" role="status">{{ t(\'review.settings.notTwoPhase\') }}</p>\
			<p v-if="!data.forms.length" class="cmEditions__note" role="status">{{ t(\'review.settings.noForms\') }} <a v-if="formsUrl" :href="formsUrl">{{ t(\'review.settings.formsLink\') }}</a></p>\
			<div class="cmBook__row">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': error(\'abstractFormId\') }"><span>{{ t(\'review.settings.abstractForm\') }}</span>\
					<select class="pkpFormField__input" v-model="form.abstractFormId"><option value="">{{ t(\'review.settings.noForm\') }}</option><option v-for="f in data.forms" :key="f.id" :value="f.id">{{ f.title }}</option></select>\
					<small>{{ t(\'review.settings.abstractFormHelp\') }}</small><em v-if="error(\'abstractFormId\')" role="alert">{{ error(\'abstractFormId\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': error(\'paperFormId\') }"><span>{{ t(\'review.settings.paperForm\') }}</span>\
					<select class="pkpFormField__input" v-model="form.paperFormId"><option value="">{{ t(\'review.settings.noForm\') }}</option><option v-for="f in data.forms" :key="f.id" :value="f.id">{{ f.title }}</option></select>\
					<small>{{ t(\'review.settings.paperFormHelp\') }}</small><em v-if="error(\'paperFormId\')" role="alert">{{ error(\'paperFormId\') }}</em></label>\
			</div>\
			<h3>{{ t(\'review.settings.howTitle\') }}</h3>\
			<ol class="cmReviewSettings__steps"><li>{{ t(\'review.settings.step1\') }}</li><li>{{ t(\'review.settings.step2\') }}</li><li>{{ t(\'review.settings.step3\') }}</li><li>{{ t(\'review.settings.step4\') }}</li></ol>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" :disabled="busy" @click="save">{{ t(\'save\') }}</pkp-button></div>\
		</section>\
	</template>\
</div>',
	});
})();
