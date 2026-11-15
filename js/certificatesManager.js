/**
 * Certificates, opened from the editions page:
 *
 *   ConferenceCertificates          who gets which certificate in an edition,
 *                                   issue and download (CRT-04, CRT-05)
 *   ConferenceCertificateTemplates  templates per type with variables and a
 *                                   preview, the number series (CRT-01, CRT-02)
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	if (!window.pkp || !pkp.registry) {
		return;
	}

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

	function notify(message, type) {
		if (window.pkp && pkp.eventBus) {
			pkp.eventBus.$emit('notify', message, type || 'success');
		}
	}

	var common = {
		methods: {
			t: function (key, params) {
				var s = this.i18n[key] || key;
				Object.keys(params || {}).forEach(function (p) { s = s.split('{$' + p + '}').join(params[p]); });
				return s;
			},
			call: function (method, url, body) {
				return request(this.csrfToken, method, url, body);
			},
		},
	};

	pkp.registry.registerComponent('ConferenceCertificates', {
		name: 'ConferenceCertificates',
		mixins: [common],
		props: {
			/** .../conference */
			apiUrl: { type: String, required: true },
			editionId: { type: Number, required: true },
			editionName: { type: String, default: '' },
			/** page URL with __id__ */
			pdfUrl: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, items: [], threshold: 30, chosen: [], busy: false, show: 'all' };
		},
		computed: {
			eligible: function () {
				return this.items.filter(function (c) { return c.type && !c.issued; });
			},
			issuedCount: function () {
				return this.items.filter(function (c) { return c.issued; }).length;
			},
			shown: function () {
				var show = this.show;
				return this.items.filter(function (c) {
					return show === 'all' || (show === 'eligible' && c.type && !c.issued) || (show === 'issued' && c.issued) || (show === 'none' && !c.type);
				});
			},
			allChosen: function () {
				return this.eligible.length > 0 && this.chosen.length === this.eligible.length;
			},
		},
		mounted: function () {
			this.load();
		},
		methods: {
			load: function () {
				var self = this;
				self.isLoading = true;
				self.call('GET', self.apiUrl + '/editions/' + self.editionId + '/certificate-candidates').then(function (r) {
					self.isLoading = false;
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
						return;
					}
					self.items = r.data.items || [];
					self.threshold = r.data.threshold || 30;
					self.chosen = [];
				});
			},
			toggleAll: function () {
				this.chosen = this.allChosen ? [] : this.eligible.map(function (c) { return c.participationId; });
			},
			issue: function () {
				var self = this;
				if (!self.chosen.length || !window.confirm(self.t('certificate.issueConfirm', { count: self.chosen.length }))) {
					return;
				}
				self.busy = true;
				// The server issues at most 50 at a time.
				var queue = self.chosen.slice();
				var issued = 0;
				var next = function () {
					var part = queue.splice(0, 50);
					if (!part.length) {
						self.busy = false;
						notify(self.t('certificate.issued', { count: issued }));
						self.load();
						return;
					}
					self.call('POST', self.apiUrl + '/editions/' + self.editionId + '/certificates', { participationIds: part }).then(function (r) {
						if (!r.ok) {
							self.busy = false;
							notify(firstError(r.data), 'warning');
							self.load();
							return;
						}
						issued += (r.data.items || []).filter(function (x) { return x.status === 'issued'; }).length;
						next();
					});
				};
				next();
			},
			link: function (id) {
				return this.pdfUrl.replace('__id__', String(id));
			},
		},
		template: '\
<div class="cmEditions cmCertificates">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'certificate.title\') }}: {{ editionName }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else>\
		<p class="cmEditions__note">{{ t(\'certificate.help\', { threshold: threshold }) }}</p>\
		<div class="cmEditions__toolbar">\
			<p role="status">{{ t(\'certificate.summary\', { total: items.length, eligible: eligible.length, issued: issuedCount }) }}</p>\
			<label class="cmEditions__filter">{{ t(\'certificate.show\') }}<select class="pkpFormField__input" v-model="show"><option value="all">{{ t(\'certificate.show.all\') }}</option><option value="eligible">{{ t(\'certificate.show.eligible\') }}</option><option value="issued">{{ t(\'certificate.show.issued\') }}</option><option value="none">{{ t(\'certificate.show.none\') }}</option></select></label>\
			<pkp-button :is-primary="true" :disabled="busy || !chosen.length" @click="issue">{{ t(\'certificate.issue\', { count: chosen.length }) }}</pkp-button>\
		</div>\
		<p v-if="!items.length" class="cmEditions__empty">{{ t(\'participants.empty\') }}</p>\
		<table v-else class="cmEditions__table">\
			<thead><tr><th><input type="checkbox" :checked="allChosen" :disabled="!eligible.length" @change="toggleAll" :aria-label="t(\'certificate.chooseAll\')"></th><th>{{ t(\'participants.name\') }}</th><th>{{ t(\'participants.role\') }}</th><th>{{ t(\'attendance.title\') }}</th><th>{{ t(\'certificate.type\') }}</th><th>{{ t(\'certificate.number\') }}</th></tr></thead>\
			<tbody><tr v-for="c in shown" :key="c.participationId">\
				<td><input type="checkbox" v-if="c.type && !c.issued" :value="c.participationId" v-model="chosen" :aria-label="c.name || c.email"></td>\
				<td><strong>{{ c.name || c.email }}</strong><div class="cmEditions__path">{{ c.email }}</div><div class="cmEditions__path" v-if="c.paperTitle">{{ c.paperTitle }}</div></td>\
				<td>{{ c.roleLabel }}</td>\
				<td>{{ c.attended ? t(\'attendance.present\') : t(\'attendance.absent\') }}<div class="cmEditions__path" v-if="c.minutes !== null">{{ t(\'attendance.minutesShort\', { minutes: c.minutes }) }}</div></td>\
				<td><template v-if="c.type">{{ c.typeLabel }}</template><span v-else class="cmEditions__path">{{ c.reasonLabel }}</span></td>\
				<td><a v-if="c.issued" :href="link(c.issued.id)" target="_blank" rel="noopener">{{ c.issued.number }}</a></td>\
			</tr></tbody>\
		</table>\
	</template>\
</div>',
	});

	pkp.registry.registerComponent('ConferenceCertificateTemplates', {
		name: 'ConferenceCertificateTemplates',
		mixins: [common],
		props: {
			/** .../conference */
			apiUrl: { type: String, required: true },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, items: [], variables: [], type: 'participation', html: '', preview: '', series: '', busy: false };
		},
		computed: {
			current: function () {
				var type = this.type;
				return this.items.filter(function (i) { return i.type === type; })[0] || null;
			},
		},
		mounted: function () {
			this.load();
		},
		methods: {
			load: function () {
				var self = this;
				self.isLoading = true;
				Promise.all([
					self.call('GET', self.apiUrl + '/certificate-templates'),
					self.call('GET', self.apiUrl + '/certificate-settings'),
				]).then(function (r) {
					self.isLoading = false;
					if (!r[0].ok) {
						notify(firstError(r[0].data), 'warning');
						return;
					}
					self.items = r[0].data.items || [];
					self.variables = r[0].data.variables || [];
					self.series = r[1].ok ? r[1].data.series : '';
					self.pick();
				});
			},
			pick: function () {
				var c = this.current;
				this.html = c ? (c.custom || c.builtIn) : '';
				this.preview = '';
			},
			insert: function (name) {
				var area = this.$refs.html;
				var token = '{' + name + '}';
				if (!area) {
					this.html += token;
					return;
				}
				var start = area.selectionStart || 0;
				var end = area.selectionEnd || 0;
				this.html = this.html.slice(0, start) + token + this.html.slice(end);
				this.$nextTick(function () {
					area.focus();
					area.selectionStart = area.selectionEnd = start + token.length;
				});
			},
			showPreview: function () {
				var self = this;
				self.call('POST', self.apiUrl + '/certificate-templates/' + self.type + '/preview', { html: self.html }).then(function (r) {
					r.ok ? (self.preview = r.data.html) : notify(firstError(r.data), 'warning');
				});
			},
			save: function (reset) {
				var self = this;
				if (reset && !window.confirm(self.t('certificate.template.resetConfirm'))) {
					return;
				}
				self.busy = true;
				self.call('PUT', self.apiUrl + '/certificate-templates/' + self.type, { html: reset ? '' : self.html }).then(function (r) {
					self.busy = false;
					r.ok ? (notify(self.t('fee.saved')), self.load()) : notify(firstError(r.data), 'warning');
				});
			},
			saveSeries: function () {
				var self = this;
				self.call('PUT', self.apiUrl + '/certificate-settings', { series: self.series }).then(function (r) {
					r.ok ? (self.series = r.data.series, notify(self.t('fee.saved'))) : notify(firstError(r.data), 'warning');
				});
			},
		},
		template: '\
<div class="cmEditions cmCertificateTemplates">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'certificate.templates\') }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else>\
		<form class="cmEditions__toolbar" @submit.prevent="saveSeries">\
			<label class="cmEditions__filter">{{ t(\'certificate.series\') }}<input class="pkpFormField__input" v-model="series" maxlength="10" pattern="[A-Za-zА-Яа-яІіЇїЄєҐґ0-9]{1,10}"></label>\
			<span class="cmEditions__path">{{ t(\'certificate.seriesHelp\', { example: (series || \'CERT\').toUpperCase() + \'-\' + new Date().getFullYear() + \'-00001\' }) }}</span>\
			<pkp-button @click="saveSeries">{{ t(\'save\') }}</pkp-button>\
		</form>\
		<div class="cmEditions__toolbar">\
			<label class="cmEditions__filter">{{ t(\'certificate.type\') }}<select class="pkpFormField__input" v-model="type" @change="pick"><option v-for="i in items" :key="i.type" :value="i.type">{{ i.label }}</option></select></label>\
			<span class="cmBadge" v-if="current">{{ current.custom ? t(\'certificate.template.custom\', { version: current.version }) : t(\'certificate.template.builtIn\') }}</span>\
		</div>\
		<div class="cmCertificateTemplates__editor">\
			<div>\
				<label class="cmCertificateTemplates__label" for="cmCertHtml">{{ t(\'certificate.template.html\') }}</label>\
				<textarea id="cmCertHtml" ref="html" class="pkpFormField__input cmCertificateTemplates__html" v-model="html" spellcheck="false"></textarea>\
				<p class="cmEditions__note">{{ t(\'certificate.template.help\') }}</p>\
				<div class="cmCertificateTemplates__vars"><button type="button" class="cmCertificateTemplates__var" v-for="v in variables" :key="v" @click="insert(v)">{{ \'{\' + v + \'}\' }}</button></div>\
				<div class="cmEditions__buttons">\
					<pkp-button @click="showPreview">{{ t(\'certificate.template.preview\') }}</pkp-button>\
					<pkp-button :is-primary="true" :disabled="busy" @click="save(false)">{{ t(\'save\') }}</pkp-button>\
					<pkp-button v-if="current && current.custom" :is-warnable="true" :disabled="busy" @click="save(true)">{{ t(\'certificate.template.reset\') }}</pkp-button>\
				</div>\
			</div>\
			<div v-if="preview" class="cmCertificateTemplates__preview">\
				<iframe sandbox="" :srcdoc="\'<!doctype html><meta charset=utf-8><style>body{margin:8mm;font-family:DejaVu Sans,Arial,sans-serif}</style>\' + preview" :title="t(\'certificate.template.preview\')"></iframe>\
			</div>\
		</div>\
	</template>\
</div>',
	});
})();
