/**
 * Old OCS addresses of the journal (LEG-02, LEG-03), opened from the
 * editions page: the list of OCS papers from CSV, the address templates,
 * a check of one address and the redirect map for the web server.
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

	pkp.registry.registerComponent('ConferenceLegacyUrls', {
		name: 'ConferenceLegacyUrls',
		props: {
			/** .../conference */
			apiUrl: { type: String, required: true },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return {
				isLoading: true, busy: false, data: null, templatesText: '', templateErrors: [],
				csv: '', fileName: '', report: null, checkUrl: '', checked: null, confirmClear: false,
			};
		},
		mounted: function () {
			this.load();
		},
		computed: {
			mapUrl: function () {
				var base = this.apiUrl + '/legacy-urls/map?server=';
				return { nginx: base + 'nginx', apacheMap: base + 'apache-map', apacheRules: base + 'apache-rules' };
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
				this.templatesText = (d.templates || []).join('\n');
			},
			load: function () {
				var self = this;
				self.call('GET', self.apiUrl + '/legacy-urls').then(function (r) {
					self.isLoading = false;
					r.ok ? self.setData(r.data) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			readFile: function (event) {
				var self = this;
				var file = event.target.files && event.target.files[0];
				if (!file) {
					return;
				}
				self.fileName = file.name;
				var reader = new FileReader();
				reader.onload = function () { self.csv = String(reader.result || ''); };
				reader.readAsText(file, 'UTF-8');
			},
			importCsv: function () {
				var self = this;
				if (!self.csv.trim()) {
					return;
				}
				self.busy = true;
				self.call('POST', self.apiUrl + '/legacy-urls/import', { csv: self.csv }).then(function (r) {
					self.busy = false;
					if (!r.ok) {
						notify(r.data.errorMessage || '', 'warning');
						return;
					}
					self.report = r.data;
					notify(self.t('legacy.imported', { n: r.data.rows, matched: r.data.matched }));
					self.load();
				});
			},
			saveTemplates: function (restore) {
				var self = this;
				var list = restore ? [] : self.templatesText.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
				self.busy = true;
				self.call('PUT', self.apiUrl + '/legacy-urls/templates', { templates: list }).then(function (r) {
					self.busy = false;
					if (r.ok) {
						self.templateErrors = [];
						self.setData(r.data);
						notify(self.t('fee.saved'));
					} else {
						self.templateErrors = r.data.templates || [r.data.errorMessage || ''];
					}
				});
			},
			check: function () {
				var self = this;
				if (!self.checkUrl.trim()) {
					return;
				}
				self.call('GET', self.apiUrl + '/legacy-urls/check?url=' + encodeURIComponent(self.checkUrl.trim())).then(function (r) {
					self.checked = r.ok ? r.data : { notOcs: true };
				});
			},
			clear: function () {
				var self = this;
				if (!self.confirmClear) {
					self.confirmClear = true;
					return;
				}
				self.busy = true;
				self.call('DELETE', self.apiUrl + '/legacy-urls').then(function (r) {
					self.busy = false;
					self.confirmClear = false;
					self.report = null;
					r.ok ? (notify(self.t('legacy.cleared', { n: r.data.deleted })), self.load()) : notify(r.data.errorMessage || '', 'warning');
				});
			},
		},
		template: '\
<div class="cmEditions cmLegacy">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'legacy.title\') }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<p class="cmEditions__note">{{ t(\'legacy.help\') }}</p>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else>\
		<p class="cmLegacy__stats" role="status">{{ t(\'legacy.stats\', { matched: data.matched, n: data.total }) }}</p>\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'legacy.import\') }}</h3>\
			<p class="cmEditions__note">{{ t(\'legacy.importHelp\') }}</p>\
			<label class="cmEditions__field"><span>{{ t(\'legacy.file\') }}</span><input type="file" accept=".csv,text/csv,text/plain" @change="readFile"></label>\
			<label class="cmEditions__field"><span>{{ t(\'legacy.paste\') }}</span><textarea class="pkpFormField__input cmLegacy__csv" rows="5" v-model="csv" spellcheck="false" placeholder="ocs_paper_id,conference,sched_conf,title"></textarea></label>\
			<div class="cmEditions__buttons"><pkp-button :is-primary="true" :disabled="busy || !csv.trim()" @click="importCsv">{{ t(\'legacy.importButton\') }}</pkp-button></div>\
			<div v-if="report" class="cmLegacy__report">\
				<p><strong>{{ t(\'legacy.imported\', { n: report.rows, matched: report.matched }) }}</strong></p>\
				<details v-if="report.errors.length" open><summary>{{ t(\'legacy.errors\') }} ({{ report.errors.length }})</summary><ul><li v-for="(e, i) in report.errors" :key="\'e\' + i">{{ e.text }}</li></ul></details>\
				<details v-if="report.ambiguous.length"><summary>{{ t(\'legacy.ambiguous\') }} ({{ report.ambiguous.length }})</summary><p class="cmEditions__note">{{ t(\'legacy.ambiguousHelp\') }}</p><ul><li v-for="a in report.ambiguous" :key="\'a\' + a.ocsPaperId">#{{ a.ocsPaperId }} {{ a.title }} → {{ a.submissionIds.join(\', \') }}</li></ul></details>\
				<details v-if="report.unmatched.length"><summary>{{ t(\'legacy.unmatched\') }} ({{ report.unmatched.length }})</summary><p class="cmEditions__note">{{ t(\'legacy.unmatchedHelp\') }}</p><ul><li v-for="u in report.unmatched" :key="\'u\' + u.ocsPaperId">#{{ u.ocsPaperId }} {{ u.title }}</li></ul></details>\
			</div>\
		</section>\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'legacy.check\') }}</h3>\
			<form class="cmLegacy__check" @submit.prevent="check">\
				<label class="cmEditions__field"><span>{{ t(\'legacy.oldUrl\') }}</span><input class="pkpFormField__input" type="text" v-model="checkUrl" placeholder="https://conf.example.edu/CONF/CONF-2025/paper/view/123"></label>\
				<div class="cmEditions__buttons"><pkp-button @click="check">{{ t(\'legacy.checkButton\') }}</pkp-button></div>\
			</form>\
			<p v-if="checked" role="status" class="cmLegacy__result">\
				<template v-if="checked.notOcs">{{ t(\'legacy.notOcs\') }}</template>\
				<template v-else-if="checked.target">{{ t(\'legacy.leadsTo\', { id: checked.ocsPaperId }) }} <a :href="checked.target" target="_blank" rel="noopener">{{ checked.target }}</a></template>\
				<template v-else>{{ t(\'legacy.noArticle\', { id: checked.ocsPaperId }) }}</template>\
			</p>\
		</section>\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'legacy.map\') }}</h3>\
			<p class="cmEditions__note">{{ t(\'legacy.mapHelp\') }}</p>\
			<div class="cmEditions__buttons">\
				<a class="pkpButton" :href="mapUrl.nginx">{{ t(\'legacy.mapNginx\') }}</a>\
				<a class="pkpButton" :href="mapUrl.apacheMap">{{ t(\'legacy.mapApache\') }}</a>\
				<a class="pkpButton" :href="mapUrl.apacheRules">{{ t(\'legacy.mapApacheRules\') }}</a>\
			</div>\
		</section>\
		<section class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'legacy.templates\') }}</h3>\
			<p class="cmEditions__note">{{ t(\'legacy.templatesHelp\') }}</p>\
			<label class="cmEditions__field"><span class="cmProgram__sr">{{ t(\'legacy.templates\') }}</span><textarea class="pkpFormField__input cmLegacy__csv" rows="6" v-model="templatesText" spellcheck="false"></textarea></label>\
			<ul v-if="templateErrors.length" class="cmLegacy__errors" role="alert"><li v-for="(e, i) in templateErrors" :key="i">{{ e }}</li></ul>\
			<div class="cmEditions__buttons">\
				<pkp-button :is-primary="true" :disabled="busy" @click="saveTemplates(false)">{{ t(\'save\') }}</pkp-button>\
				<pkp-button :disabled="busy" @click="saveTemplates(true)">{{ t(\'legacy.restore\') }}</pkp-button>\
			</div>\
		</section>\
		<section v-if="data.total" class="cmEditions__form cmFees__block">\
			<h3>{{ t(\'legacy.clear\') }}</h3>\
			<p class="cmEditions__note">{{ t(\'legacy.clearHelp\') }}</p>\
			<div class="cmEditions__buttons"><pkp-button :is-warnable="true" :disabled="busy" @click="clear">{{ confirmClear ? t(\'legacy.clearConfirm\', { n: data.total }) : t(\'legacy.clear\') }}</pkp-button><pkp-button v-if="confirmClear" @click="confirmClear = false">{{ t(\'cancel\') }}</pkp-button></div>\
		</section>\
	</template>\
</div>',
	});
})();
