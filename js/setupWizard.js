/**
 * First setup wizard (INS-08): institution type → preset (DST-07) →
 * first edition. Opened from the editions page; shown by itself while a
 * journal has no editions.
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	if (!window.pkp || !pkp.registry) {
		return;
	}

	pkp.registry.registerComponent('ConferenceSetupWizard', {
		name: 'ConferenceSetupWizard',
		props: {
			/** .../conference */
			apiUrl: { type: String, required: true },
			csrfToken: { type: String, required: true },
			locales: { type: Array, required: true },
			primaryLocale: { type: String, required: true },
			uiLocale: { type: String, default: '' },
			defaultTimezone: { type: String, default: 'UTC' },
			i18n: { type: Object, required: true },
		},
		emits: ['back', 'created'],
		data: function () {
			var name = {};
			this.locales.forEach(function (l) { name[l.key] = ''; });
			return {
				isLoading: true,
				busy: false,
				step: 1,
				state: null,
				type: '',
				presetCode: '',
				form: {
					name: name, path: '', timezone: this.defaultTimezone, startsAt: '', endsAt: '', cfpOpensAt: '',
					abstractDeadline: '', paperDeadline: '', registrationClosesAt: '', externalProceedingsUrl: '',
					conferenceSite: true,
				},
				errors: {},
				result: null,
			};
		},
		computed: {
			presets: function () {
				var type = this.type;
				return this.state ? this.state.presets.filter(function (p) {
					return !p.institutionTypes.length || p.institutionTypes.indexOf(type) !== -1;
				}) : [];
			},
			others: function () {
				var codes = this.presets.map(function (p) { return p.code; });
				return this.state ? this.state.presets.filter(function (p) { return codes.indexOf(p.code) === -1; }) : [];
			},
			preset: function () {
				var code = this.presetCode;
				return this.state ? this.state.presets.filter(function (p) { return p.code === code; })[0] || null : null;
			},
			submissionMode: function () {
				return this.preset && this.preset.edition.submissionMode ? this.preset.edition.submissionMode : 'abstracts';
			},
			dateFields: function () {
				var f = ['startsAt', 'endsAt'];
				if (this.submissionMode !== 'none') {
					f.push('cfpOpensAt', 'abstractDeadline');
				}
				if (this.submissionMode === 'abstracts_and_papers') {
					f.push('paperDeadline');
				}
				f.push('registrationClosesAt');
				return f;
			},
			needsUrl: function () {
				return !!this.preset && this.preset.edition.publicationMode === 'external';
			},
			missingAccounts: function () {
				if (!this.preset || !this.state) {
					return [];
				}
				var have = this.state.accounts;
				var need = { bank_transfer: ['bank', 'partner'], treasury_transfer: ['treasury'] };
				var labels = this.state.labels.paymentModes;
				var out = [];
				this.preset.paymentRules.forEach(function (r) {
					var kinds = need[r.mode];
					if (kinds && !kinds.some(function (k) { return have.indexOf(k) !== -1; }) && out.indexOf(labels[r.mode]) === -1) {
						out.push(labels[r.mode]);
					}
				});
				return out;
			},
		},
		mounted: function () {
			var self = this;
			self.call('GET', self.apiUrl + '/setup').then(function (r) {
				self.isLoading = false;
				if (!r.ok) {
					self.notify(r.data.errorMessage || self.t('errors'), 'warning');
					return;
				}
				self.state = r.data;
				self.type = r.data.institutionType;
			});
		},
		methods: {
			t: function (key, params) {
				var s = this.i18n[key] || key;
				Object.keys(params || {}).forEach(function (k) { s = s.split('{$' + k + '}').join(params[k]); });
				return s;
			},
			text: function (value) {
				if (!value) {
					return '';
				}
				return value[this.uiLocale] || value[this.primaryLocale] || value.en || Object.values(value)[0] || '';
			},
			notify: function (message, type) {
				if (window.pkp && pkp.eventBus) {
					pkp.eventBus.$emit('notify', message, type || 'success');
				}
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
			categoryName: function (c) {
				return this.text(c.name) || this.state.labels.categories[c.code] || c.code;
			},
			categoryByCode: function (code) {
				var self = this;
				var c = self.preset.categories.filter(function (x) { return x.code === code; })[0];
				return c ? self.categoryName(c) : code;
			},
			tariffLine: function (t) {
				return this.categoryByCode(t.category) + ', ' + this.state.labels.materials[t.material] + ': ' + t.amount + ' ' + t.currency;
			},
			ruleLine: function (r) {
				return (r.category ? this.categoryByCode(r.category) : this.t('setup.rule.default')) + ': ' + this.state.labels.paymentModes[r.mode];
			},
			choose: function (code) {
				this.presetCode = code;
				this.step = 3;
			},
			fieldError: function (field) {
				var e = this.errors[field];
				return Array.isArray(e) ? e.join(' ') : e || '';
			},
			create: function () {
				var self = this;
				var body = { preset: self.presetCode, name: self.form.name, path: self.form.path.trim(), timezone: self.form.timezone, conferenceSite: self.form.conferenceSite };
				self.dateFields.forEach(function (f) { body[f] = self.form[f]; });
				if (self.needsUrl) {
					body.externalProceedingsUrl = self.form.externalProceedingsUrl.trim();
				}
				self.busy = true;
				self.call('POST', self.apiUrl + '/setup', body).then(function (r) {
					self.busy = false;
					if (r.ok) {
						self.errors = {};
						self.result = r.data;
						self.step = 4;
						self.notify(self.t('setup.created'));
					} else if (r.status === 400) {
						self.errors = r.data || {};
						if (self.errors.preset) {
							self.step = 2;
						}
						self.notify(self.t('setup.fix'), 'warning');
					} else {
						self.notify(r.data.errorMessage || self.t('errors'), 'warning');
					}
				});
			},
		},
		template: '\
<div class="cmEditions cmSetup">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'setup.title\') }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else-if="state">\
		<p class="cmSetup__intro">{{ t(\'setup.intro\') }}</p>\
		<ol class="cmSetup__steps">\
			<li v-for="n in 3" :key="n" :class="{ \'cmSetup__step--active\': step === n, \'cmSetup__step--done\': step > n }">{{ t(\'setup.step\' + n) }}</li>\
		</ol>\
		<fieldset v-if="step === 1" class="cmEditions__section">\
			<legend>{{ t(\'setup.step1\') }}</legend>\
			<label class="cmEditions__field"><span>{{ t(\'setup.type\') }}</span>\
				<select class="pkpFormField__input" v-model="type">\
					<option v-for="(label, value) in state.labels.institutionTypes" :key="value" :value="value">{{ label }}</option>\
				</select>\
				<small>{{ t(\'setup.type.help\') }}</small>\
			</label>\
			<pkp-button :is-primary="true" @click="step = 2">{{ t(\'setup.next\') }}</pkp-button>\
		</fieldset>\
		<div v-else-if="step === 2">\
			<p v-if="!presets.length" class="cmEditions__empty">{{ t(\'setup.none\') }}</p>\
			<div class="cmSetup__presets">\
				<article v-for="p in presets.concat(others)" :key="p.code" class="cmSetup__preset" :class="{ \'cmSetup__preset--other\': others.indexOf(p) !== -1 }">\
					<h3>{{ text(p.name) }}</h3>\
					<p>{{ text(p.description) }}</p>\
					<ul class="cmSetup__facts">\
						<li v-if="p.edition.eventType">{{ state.labels.eventTypes[p.edition.eventType] }}<span v-if="p.edition.format">, {{ state.labels.formats[p.edition.format] }}</span></li>\
						<li v-if="p.edition.submissionMode">{{ state.labels.submissionModes[p.edition.submissionMode] }}</li>\
						<li v-if="p.edition.publicationMode">{{ state.labels.publicationModes[p.edition.publicationMode] }}</li>\
						<li>{{ t(\'setup.categories\', { n: p.categories.length }) }}, {{ t(\'setup.tariffs\', { n: p.tariffs.length }) }}</li>\
						<li v-if="p.professionalDevelopment">{{ t(\'setup.pk\', { hours: p.edition.hours || 0 }) }}</li>\
						<li v-if="p.vector">{{ t(\'setup.vector\') }}</li>\
						<li v-if="p.source === \'site\'"><em>{{ t(\'setup.sitePreset\') }}</em></li>\
					</ul>\
					<pkp-button :is-primary="others.indexOf(p) === -1" @click="choose(p.code)">{{ t(\'setup.choose\') }}</pkp-button>\
				</article>\
			</div>\
			<p v-if="others.length" class="cmSetup__note">{{ t(\'setup.othersNote\') }}</p>\
			<pkp-button @click="step = 1">{{ t(\'setup.previous\') }}</pkp-button>\
		</div>\
		<div v-else-if="step === 3 && preset">\
			<div class="cmSetup__summary">\
				<h3>{{ text(preset.name) }}</h3>\
				<p><strong>{{ t(\'setup.willCreate\') }}</strong></p>\
				<ul>\
					<li>{{ t(\'setup.categoriesList\') }}: {{ preset.categories.map(categoryName).join(\', \') }}</li>\
					<li v-if="preset.tariffs.length">{{ t(\'setup.tariffsList\') }}: <span v-for="(tr, i) in preset.tariffs" :key="i">{{ tariffLine(tr) }}<span v-if="i < preset.tariffs.length - 1">; </span></span></li>\
					<li>{{ t(\'setup.rulesList\') }}: {{ preset.paymentRules.map(ruleLine).join(\'; \') }}</li>\
				</ul>\
				<p v-if="missingAccounts.length" class="cmSetup__warning" role="status">{{ t(\'setup.accountMissing\', { modes: missingAccounts.join(\', \') }) }}</p>\
			</div>\
			<fieldset class="cmEditions__section">\
				<legend>{{ t(\'setup.step3\') }}</legend>\
				<label v-for="l in locales" :key="l.key" class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.name && l.key === primaryLocale }">\
					<span>{{ t(\'field.name\') }} ({{ l.label }})<span v-if="l.key === primaryLocale"> *</span></span>\
					<input class="pkpFormField__input" type="text" v-model="form.name[l.key]" maxlength="500">\
					<em v-if="errors.name && l.key === primaryLocale">{{ fieldError(\'name\') }}</em>\
				</label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.path }">\
					<span>{{ t(\'field.path\') }} *</span>\
					<input class="pkpFormField__input" type="text" v-model="form.path" maxlength="64" pattern="[a-z0-9][a-z0-9_-]*">\
					<small>{{ t(\'field.path.description\') }}</small>\
					<em v-if="errors.path">{{ fieldError(\'path\') }}</em>\
				</label>\
				<div class="cmEditions__grid">\
					<label v-for="f in dateFields" :key="f" class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors[f] }">\
						<span>{{ t(\'field.\' + f) }}</span>\
						<input class="pkpFormField__input" type="datetime-local" v-model="form[f]">\
						<em v-if="errors[f]">{{ fieldError(f) }}</em>\
					</label>\
				</div>\
				<small>{{ t(\'setup.timezone\', { zone: form.timezone }) }}</small>\
				<label v-if="needsUrl" class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.externalProceedingsUrl }">\
					<span>{{ t(\'field.externalProceedingsUrl\') }}</span>\
					<input class="pkpFormField__input" type="url" v-model="form.externalProceedingsUrl" maxlength="2048">\
					<em v-if="errors.externalProceedingsUrl">{{ fieldError(\'externalProceedingsUrl\') }}</em>\
				</label>\
				<label class="cmSetup__check"><input type="checkbox" v-model="form.conferenceSite"> {{ t(\'siteMode.conferenceSite\') }}</label>\
				<small>{{ t(\'siteMode.wizardNote\') }}</small>\
				<p class="cmSetup__note">{{ t(\'setup.draftNote\') }}</p>\
				<div class="cmSetup__buttons">\
					<pkp-button @click="step = 2">{{ t(\'setup.previous\') }}</pkp-button>\
					<pkp-button :is-primary="true" :disabled="busy" @click="create">{{ t(\'setup.create\') }}</pkp-button>\
				</div>\
			</fieldset>\
		</div>\
		<div v-else-if="step === 4 && result" class="cmSetup__done">\
			<h3>{{ t(\'setup.doneTitle\', { name: text(result.edition.name) }) }}</h3>\
			<p>{{ t(\'setup.doneText\') }}</p>\
			<ul v-if="result.warnings.length" class="cmSetup__warnings">\
				<li v-for="(w, i) in result.warnings" :key="i" class="cmSetup__warning">{{ w.message }}</li>\
			</ul>\
			<pkp-button :is-primary="true" @click="$emit(\'created\', result.edition)">{{ t(\'setup.toEditions\') }}</pkp-button>\
		</div>\
	</template>\
</div>',
	});
})();
