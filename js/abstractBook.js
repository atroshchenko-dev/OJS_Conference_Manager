/**
 * The book of abstracts of an edition (PUB-04), opened from the editions
 * page: the title page and imprint, the order of the papers, and the PDF.
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

	var TEXTS = [
		{ field: 'header', rows: 3 },
		{ field: 'title', rows: 2 },
		{ field: 'subtitle', rows: 3 },
		{ field: 'place', rows: 1 },
		{ field: 'imprint', rows: 6 },
		{ field: 'preface', rows: 8 },
	];

	pkp.registry.registerComponent('ConferenceAbstractBook', {
		name: 'ConferenceAbstractBook',
		props: {
			/** .../conference/editions/{id} */
			apiUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, busy: false, data: null, form: null, errors: {}, texts: TEXTS };
		},
		mounted: function () {
			this.load();
		},
		computed: {
			pdfUrl: function () {
				return this.apiUrl + '/abstract-book/pdf';
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
				this.form = Object.assign({}, d.settings);
				this.errors = {};
			},
			load: function () {
				var self = this;
				self.call('GET', self.apiUrl + '/abstract-book').then(function (r) {
					self.isLoading = false;
					r.ok ? self.setData(r.data) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			save: function () {
				var self = this;
				self.busy = true;
				return self.call('PUT', self.apiUrl + '/abstract-book', self.form).then(function (r) {
					self.busy = false;
					if (r.ok) {
						self.setData(r.data);
						notify(self.t('saved'));
						return true;
					}
					self.errors = r.status === 400 ? r.data : {};
					notify(r.data.errorMessage || self.t('book.notSaved'), 'warning');
					return false;
				});
			},
			download: function () {
				var self = this;
				self.save().then(function (ok) {
					if (ok) {
						window.location.href = self.pdfUrl;
					}
				});
			},
			error: function (field) {
				var e = this.errors[field];
				return e && e.length ? e.join(' ') : '';
			},
		},
		template: '\
<div class="cmEditions cmBook">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'book.title\') }}<span v-if="editionName">: {{ editionName }}</span></h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<p class="cmEditions__note">{{ t(\'book.help\') }}</p>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else-if="data">\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'book.contents\') }}</h3>\
			<p role="status"><strong>{{ t(\'book.count\', { n: data.count }) }}</strong></p>\
			<p v-if="!data.count" class="cmEditions__note">{{ t(\'book.empty\') }}</p>\
			<ul v-else class="cmBook__groups"><li v-for="(g, i) in data.groups" :key="i">{{ g.title || t(\'book.noGroup\') }}: {{ g.count }}</li></ul>\
			<details v-if="data.withoutAbstract.length" class="cmBook__missing"><summary>{{ t(\'book.withoutAbstract\') }} ({{ data.withoutAbstract.length }})</summary><ul><li v-for="p in data.withoutAbstract" :key="p.submissionId">#{{ p.submissionId }} {{ p.title }}</li></ul></details>\
			<p class="cmEditions__note">{{ data.isbn || data.udc ? t(\'book.codes\', { isbn: data.isbn || \'—\', udc: data.udc || \'—\' }) : t(\'book.noCodes\') }}</p>\
		</section>\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'book.layout\') }}</h3>\
			<div class="cmBook__row">\
				<label class="cmEditions__field"><span>{{ t(\'book.format\') }}</span><select class="pkpFormField__input" v-model="form.format"><option value="A5">A5</option><option value="A4">A4</option></select></label>\
				<label class="cmEditions__field"><span>{{ t(\'book.order\') }}</span><select class="pkpFormField__input" v-model="form.order"><option value="program">{{ t(\'book.order.program\') }}</option><option value="sections">{{ t(\'book.order.sections\') }}</option><option value="title">{{ t(\'book.order.title\') }}</option></select></label>\
			</div>\
			<label class="cmBook__check"><input type="checkbox" v-model="form.authorIndex"> {{ t(\'book.authorIndex\') }}</label>\
			<label class="cmBook__check"><input type="checkbox" v-model="form.keywords"> {{ t(\'book.keywords\') }}</label>\
		</section>\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'book.titlePage\') }}</h3>\
			<label v-for="f in texts" :key="f.field" class="cmEditions__field" :class="{ \'cmEditions__field--error\': error(f.field) }"><span>{{ t(\'book.field.\' + f.field) }}</span>\
				<input v-if="f.rows === 1" class="pkpFormField__input" type="text" v-model="form[f.field]">\
				<textarea v-else class="pkpFormField__input" :rows="f.rows" :style="{ height: (f.rows * 1.5 + 1) + \'rem\' }" v-model="form[f.field]"></textarea>\
				<small>{{ t(\'book.hint.\' + f.field) }}</small>\
				<em v-if="error(f.field)" role="alert">{{ error(f.field) }}</em>\
			</label>\
		</section>\
		<div class="cmEditions__buttons">\
			<pkp-button :disabled="busy" @click="save">{{ t(\'save\') }}</pkp-button>\
			<pkp-button :is-primary="true" :disabled="busy || !data.count" @click="download">{{ t(\'book.download\') }}</pkp-button>\
		</div>\
	</template>\
</div>',
	});
})();
