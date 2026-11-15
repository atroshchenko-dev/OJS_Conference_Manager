/**
 * Certificates, opened from the editions page:
 *
 *   ConferenceCertificates          who gets which certificate in an edition,
 *                                   issue, batch issue, reviewers, revoke and
 *                                   reissue, the "Vector" export (CRT-04…CRT-11)
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
			return {
				isLoading: true, items: [], issuedList: [], threshold: 30, chosen: [], busy: false, show: 'all',
				settings: { vectorEnabled: false, professionalDevelopment: false }, polls: 0, timer: null,
			};
		},
		beforeUnmount: function () {
			if (this.timer) {
				clearTimeout(this.timer);
			}
		},
		computed: {
			vectorUrl: function () {
				return this.apiUrl + '/editions/' + this.editionId + '/vector-export';
			},
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
			load: function (quiet) {
				var self = this;
				self.isLoading = !quiet;
				var base = self.apiUrl + '/editions/' + self.editionId;
				return Promise.all([
					self.call('GET', base + '/certificate-candidates'),
					self.call('GET', base + '/certificates'),
					self.call('GET', self.apiUrl + '/certificate-settings'),
				]).then(function (r) {
					self.isLoading = false;
					if (!r[0].ok) {
						notify(firstError(r[0].data), 'warning');
						return;
					}
					self.items = r[0].data.items || [];
					self.threshold = r[0].data.threshold || 30;
					self.issuedList = r[1].ok ? (r[1].data.items || []) : [];
					if (r[2].ok) {
						self.settings = r[2].data;
					}
					if (!quiet) {
						self.chosen = [];
					}
				});
			},
			toggleAll: function () {
				this.chosen = this.allChosen ? [] : this.eligible.map(function (c) { return c.participationId; });
			},
			/** CRT-06: the chosen ones, or everyone eligible; many go to the queue. */
			issue: function (everyone) {
				var self = this;
				var count = everyone ? self.eligible.length : self.chosen.length;
				if (!count || !window.confirm(self.t('certificate.issueConfirm', { count: count }))) {
					return;
				}
				self.busy = true;
				var body = everyone ? {} : { participationIds: self.chosen.slice() };
				self.call('POST', self.apiUrl + '/editions/' + self.editionId + '/certificates/batch', body).then(function (r) {
					self.busy = false;
					if (!r.ok) {
						notify(firstError(r.data), 'warning');
						self.load();
						return;
					}
					if (r.data.queued) {
						notify(self.t('certificate.queued', { count: r.data.queued }));
						self.polls = 0;
						self.poll();
					} else {
						notify(self.t('certificate.issued', { count: r.data.issued }));
					}
					self.load();
				});
			},
			/** Follows the queue until no more certificates appear. */
			poll: function () {
				var self = this;
				var before = self.issuedCount;
				self.timer = setTimeout(function () {
					self.load(true).then(function () {
						self.polls = self.issuedCount === before ? self.polls + 1 : 0;
						if (self.eligible.length && self.polls < 6) {
							self.poll();
						}
					});
				}, 5000);
			},
			addReviewers: function () {
				var self = this;
				self.busy = true;
				self.call('POST', self.apiUrl + '/editions/' + self.editionId + '/reviewers').then(function (r) {
					self.busy = false;
					r.ok ? notify(self.t('certificate.reviewersAdded', { found: r.data.found, added: r.data.added })) : notify(firstError(r.data), 'warning');
					self.load();
				});
			},
			revoke: function (c) {
				var self = this;
				var reason = window.prompt(self.t('certificate.revokePrompt', { number: c.number }), '');
				if (reason === null) {
					return;
				}
				self.call('POST', self.apiUrl + '/certificates/' + c.id + '/revoke', { reason: reason }).then(function (r) {
					r.ok ? notify(self.t('certificate.revokedDone', { number: c.number })) : notify(firstError(r.data), 'warning');
					self.load();
				});
			},
			reissue: function (c) {
				var self = this;
				var reason = window.prompt(self.t('certificate.reissuePrompt', { number: c.number }), '');
				if (reason === null) {
					return;
				}
				self.call('POST', self.apiUrl + '/certificates/' + c.id + '/reissue', { reason: reason }).then(function (r) {
					r.ok ? notify(self.t('certificate.reissuedDone', { number: r.data.number })) : notify(firstError(r.data), 'warning');
					self.load();
				});
			},
			date: function (iso) {
				var d = iso ? new Date(iso) : null;
				return d && !isNaN(d.getTime()) ? d.toLocaleDateString(document.documentElement.lang || undefined) : '';
			},
			numberOf: function (id) {
				var found = this.issuedList.filter(function (c) { return c.id === id; })[0];
				return found ? found.number : '';
			},
			link: function (id, lang) {
				var url = this.pdfUrl.replace('__id__', String(id));
				return lang ? url + (url.indexOf('?') === -1 ? '?' : '&') + 'lang=' + lang : url;
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
			<pkp-button :disabled="busy || !chosen.length" @click="issue(false)">{{ t(\'certificate.issue\', { count: chosen.length }) }}</pkp-button>\
			<pkp-button :is-primary="true" :disabled="busy || !eligible.length" @click="issue(true)">{{ t(\'certificate.issueAll\', { count: eligible.length }) }}</pkp-button>\
		</div>\
		<div class="cmEditions__toolbar">\
			<pkp-button :disabled="busy" @click="addReviewers">{{ t(\'certificate.addReviewers\') }}</pkp-button>\
			<template v-if="settings.professionalDevelopment && settings.vectorEnabled">\
				<a class="pkpButton" :href="vectorUrl">{{ t(\'certificate.vectorNew\') }}</a>\
				<a class="pkpButton" :href="vectorUrl + \'?all=1\'">{{ t(\'certificate.vectorAll\') }}</a>\
			</template>\
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
		<template v-if="issuedList.length">\
			<h3 class="cmFees__title">{{ t(\'certificate.issuedList\') }}</h3>\
			<table class="cmEditions__table cmCertificates__issued">\
				<thead><tr><th>{{ t(\'certificate.number\') }}</th><th>{{ t(\'certificate.type\') }}</th><th>{{ t(\'participants.name\') }}</th><th>{{ t(\'certificate.issuedAt\') }}</th><th>{{ t(\'certificate.state\') }}</th><th></th></tr></thead>\
				<tbody><tr v-for="c in issuedList" :key="c.id" :class="{ \'cmCertificates__revoked\': c.revokedAt }">\
					<td><a :href="link(c.id)" target="_blank" rel="noopener">{{ c.number }}</a><template v-for="l in c.languages.slice(1)" :key="l"> · <a :href="link(c.id, l)" target="_blank" rel="noopener">{{ l.toUpperCase() }}</a></template></td>\
					<td>{{ c.typeLabel }}</td>\
					<td>{{ c.recipientName }}</td>\
					<td>{{ date(c.issuedAt) }}</td>\
					<td><template v-if="c.revokedAt">{{ t(\'certificate.revoked\') }}<div class="cmEditions__path">{{ c.revokeReason }}</div><div class="cmEditions__path" v-if="c.replacedById">{{ t(\'certificate.replacedBy\', { number: numberOf(c.replacedById) }) }}</div></template><template v-else>{{ t(\'certificate.valid\') }}</template></td>\
					<td class="cmEditions__buttons"><pkp-button v-if="!c.replacedById" @click="reissue(c)">{{ t(\'certificate.reissue\') }}</pkp-button><pkp-button v-if="!c.revokedAt" :is-warnable="true" @click="revoke(c)">{{ t(\'certificate.revoke\') }}</pkp-button></td>\
				</tr></tbody>\
			</table>\
		</template>\
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
			return { isLoading: true, items: [], variables: [], type: 'participation', html: '', preview: '', series: '', vectorEnabled: false, professionalDevelopment: false, busy: false };
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
					self.vectorEnabled = r[1].ok && !!r[1].data.vectorEnabled;
					self.professionalDevelopment = r[1].ok && !!r[1].data.professionalDevelopment;
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
				var body = { series: self.series };
				if (self.professionalDevelopment) {
					body.vectorEnabled = self.vectorEnabled;
				}
				self.call('PUT', self.apiUrl + '/certificate-settings', body).then(function (r) {
					r.ok ? (self.series = r.data.series, self.vectorEnabled = !!r.data.vectorEnabled, notify(self.t('fee.saved'))) : notify(firstError(r.data), 'warning');
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
			<label v-if="professionalDevelopment" class="cmEditions__filter"><input type="checkbox" v-model="vectorEnabled"> {{ t(\'certificate.vectorEnabled\') }}</label>\
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
				<iframe sandbox="" :srcdoc="\'<!doctype html><meta charset=utf-8><style>html{zoom:.6}body{margin:6mm}</style>\' + preview" :title="t(\'certificate.template.preview\')"></iframe>\
			</div>\
		</div>\
	</template>\
</div>',
	});
})();
