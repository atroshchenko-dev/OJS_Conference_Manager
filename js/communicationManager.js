/**
 * Communication and reports of an edition, opened from the editions page:
 *
 *   ConferenceEditionDashboard   numbers of an edition (RPT-01)
 *   ConferenceCampaigns          mailings to the participants (COM-02)
 *   ConferenceReminders          automatic reminders of the journal (COM-03)
 *   ConferenceAuditLog           who did what and when, versions of the
 *                                privacy notice (DAT-01, DAT-05)
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

	var common = {
		methods: {
			t: function (key, params) {
				var s = this.i18n[key] || key;
				Object.keys(params || {}).forEach(function (p) { s = s.split('{$' + p + '}').join(params[p]); });
				return s;
			},
			time: function (iso) {
				return iso ? String(iso).replace('T', ' ').slice(0, 16) + ' UTC' : '';
			},
			money: function (m) {
				return m ? m.decimal + ' ' + m.currency : '';
			},
			call: function (method, url, body) {
				return request(this.csrfToken, method, url, body);
			},
		},
	};

	pkp.registry.registerComponent('ConferenceEditionDashboard', {
		name: 'ConferenceEditionDashboard',
		mixins: [common],
		props: {
			/** .../conference */
			apiUrl: { type: String, required: true },
			editionId: { type: Number, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { data: null, error: '', dimension: '', report: null, reportError: '' };
		},
		mounted: function () {
			this.load();
		},
		computed: {
			csvUrl: function () {
				return this.apiUrl + '/editions/' + this.editionId + '/reports/' + this.dimension + '?format=csv';
			},
		},
		methods: {
			load: function () {
				var self = this;
				this.call('GET', this.apiUrl + '/editions/' + this.editionId + '/dashboard').then(function (r) {
					if (!r.ok) {
						self.error = firstError(r.data) || self.t('errors');
						return;
					}
					self.data = r.data;
					if (!self.dimension && r.data.reportDimensions && r.data.reportDimensions.length) {
						self.dimension = r.data.reportDimensions[0].value;
					}
					self.loadReport();
				});
			},
			loadReport: function () {
				var self = this;
				if (!this.dimension) {
					return;
				}
				this.reportError = '';
				this.call('GET', this.apiUrl + '/editions/' + this.editionId + '/reports/' + this.dimension).then(function (r) {
					if (!r.ok) {
						self.reportError = firstError(r.data) || self.t('errors');
						self.report = null;
						return;
					}
					self.report = r.data;
				});
			},
		},
		template: '\
<div class="cmEditions cmDashboard">\
	<div class="cmEditions__toolbar">\
		<h2>{{ t(\'dashboard.title\') }}: {{ editionName }}</h2>\
		<pkp-button @click="load">{{ t(\'dashboard.refresh\') }}</pkp-button>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<p v-if="error" class="cmPanel__error" role="alert">{{ error }}</p>\
	<p v-else-if="!data">{{ t(\'loading\') }}</p>\
	<template v-else>\
	<p v-if="data.scheduler && data.scheduler.status !== \'ok\'" class="cmPanel__error cmDashboard__scheduler" role="alert">\
		{{ data.scheduler.status === \'never\' ? t(\'scheduler.never\') : t(\'scheduler.stale\', { minutes: data.scheduler.minutes }) }}\
	</p>\
	<p v-else-if="data.scheduler" class="cmPanel__muted cmDashboard__scheduler">{{ t(\'scheduler.ok\', { minutes: data.scheduler.minutes }) }}</p>\
	<div class="cmDashboard__grid">\
		<section class="cmDashboard__card">\
			<h3>{{ t(\'dashboard.submissions\') }}</h3>\
			<p class="cmDashboard__big">{{ data.submissions.total }}</p>\
			<dl class="cmDashboard__list">\
				<dt>{{ t(\'dashboard.waiting\') }}</dt><dd>{{ data.submissions.waiting }}</dd>\
				<dt>{{ t(\'dashboard.review\') }}</dt><dd>{{ data.submissions.review }}</dd>\
				<dt>{{ t(\'dashboard.accepted\') }}</dt><dd>{{ data.submissions.accepted }}</dd>\
				<dt>{{ t(\'dashboard.declined\') }}</dt><dd>{{ data.submissions.declined }}</dd>\
				<dt>{{ t(\'dashboard.published\') }}</dt><dd>{{ data.submissions.published }}</dd>\
				<template v-if="data.submissions.fullText !== undefined"><dt>{{ t(\'dashboard.fullText\') }}</dt><dd>{{ data.submissions.fullText }} / {{ data.submissions.accepted }}</dd></template>\
			</dl>\
			<template v-if="data.submissions.byType.length">\
				<h4>{{ t(\'dashboard.byType\') }}</h4>\
				<dl class="cmDashboard__list"><template v-for="b in data.submissions.byType" :key="b.value"><dt>{{ b.label }}</dt><dd>{{ b.count }}</dd></template></dl>\
			</template>\
		</section>\
		<section class="cmDashboard__card">\
			<h3>{{ t(\'dashboard.participants\') }}</h3>\
			<table class="cmDashboard__table">\
				<thead><tr><th></th><th>{{ t(\'dashboard.active\') }}</th><th>{{ t(\'dashboard.cancelled\') }}</th></tr></thead>\
				<tbody><tr v-for="p in data.participants" :key="p.role"><th>{{ p.label }}</th><td>{{ p.active }}</td><td>{{ p.cancelled }}</td></tr></tbody>\
			</table>\
		</section>\
		<section class="cmDashboard__card">\
			<h3>{{ t(\'dashboard.payments\') }}</h3>\
			<p v-if="!data.payments.length" class="cmPanel__muted">{{ t(\'dashboard.noInvoices\') }}</p>\
			<dl v-for="p in data.payments" :key="p.currency" class="cmDashboard__list">\
				<dt>{{ t(\'dashboard.paid\') }}</dt><dd>{{ p.paid.count }} · {{ money(p.paid.amount) }}</dd>\
				<dt>{{ t(\'dashboard.open\') }}</dt><dd>{{ p.open.count }} · {{ t(\'dashboard.due\') }} {{ money(p.open.due) }}</dd>\
				<template v-if="p.open.partPaid"><dt>{{ t(\'dashboard.partPaid\') }}</dt><dd>{{ p.open.partPaid }} · {{ money(p.open.received) }}</dd></template>\
				<dt>{{ t(\'dashboard.waived\') }}</dt><dd>{{ p.waived }}</dd>\
				<dt>{{ t(\'dashboard.replaced\') }}</dt><dd>{{ p.replaced }}</dd>\
			</dl>\
			<p>{{ t(\'dashboard.pendingProofs\') }}: <strong>{{ data.pendingProofs }}</strong></p>\
		</section>\
		<section class="cmDashboard__card">\
			<h3>{{ t(\'dashboard.reminders\') }}</h3>\
			<dl class="cmDashboard__list">\
				<dt>{{ t(\'reminders.payment\') }}</dt><dd>{{ data.reminders.payment || 0 }}</dd>\
				<dt>{{ t(\'reminders.paper\') }}</dt><dd>{{ data.reminders.paper || 0 }}</dd>\
				<dt>{{ t(\'reminders.event\') }}</dt><dd>{{ data.reminders.event || 0 }}</dd>\
			</dl>\
		</section>\
	</div>\
	<section v-if="data.reportDimensions && data.reportDimensions.length" class="cmDashboard__card cmReport">\
		<h3>{{ t(\'report.title\') }}</h3>\
		<div class="cmReport__toolbar">\
			<label>{{ t(\'report.by\') }} <select v-model="dimension" @change="loadReport">\
				<option v-for="d in data.reportDimensions" :key="d.value" :value="d.value">{{ d.label }}</option>\
			</select></label>\
			<a class="pkpButton" :href="csvUrl">{{ t(\'report.csv\') }}</a>\
		</div>\
		<p v-if="reportError" class="cmPanel__error" role="alert">{{ reportError }}</p>\
		<p v-else-if="report && !report.rows.length" class="cmPanel__muted">{{ t(\'report.empty\') }}</p>\
		<div v-else-if="report" class="cmReport__scroll">\
		<table class="cmDashboard__table cmReport__table">\
			<thead><tr><th scope="col">{{ (data.reportDimensions.find(function (d) { return d.value === report.dimension; }) || {}).label }}</th><th v-for="c in report.columns" :key="c.value" scope="col">{{ c.label }}</th></tr></thead>\
			<tbody><tr v-for="row in report.rows" :key="row.label"><th scope="row">{{ row.label }}</th><td v-for="c in report.columns" :key="c.value">{{ row.counts[c.value] }}</td></tr></tbody>\
			<tfoot><tr><th scope="row">{{ t(\'report.total\') }}</th><td v-for="c in report.columns" :key="c.value">{{ report.totals[c.value] }}</td></tr></tfoot>\
		</table>\
		</div>\
	</section>\
	</template>\
</div>',
	});

	pkp.registry.registerComponent('ConferenceCampaigns', {
		name: 'ConferenceCampaigns',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			editionId: { type: Number, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			uiLocale: { type: String, default: '' },
			submissionMode: { type: String, default: '' },
			options: { type: Object, default: function () { return { roles: [], payments: [] }; } },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return {
				filter: { role: '', categoryId: '', payment: '', section: '', accepted: false, fullText: '' },
				subject: '',
				body: '',
				categories: [],
				preview: null,
				items: [],
				active: null,
				timer: null,
				isBusy: false,
				message: '',
				error: '',
			};
		},
		computed: {
			base: function () {
				return this.apiUrl + '/editions/' + this.editionId + '/campaigns';
			},
			payload: function () {
				return Object.assign({ subject: this.subject, body: this.body }, this.filter);
			},
			sampleEmails: function () {
				return this.preview ? this.preview.sample.map(function (s) { return s.email; }).join(', ') : '';
			},
			canSend: function () {
				return !this.isBusy && this.subject.trim() !== '' && this.body.trim() !== '';
			},
		},
		mounted: function () {
			var self = this;
			this.load();
			this.call('GET', this.apiUrl + '/editions/' + this.editionId + '/fees').then(function (r) {
				self.categories = r.ok ? (r.data.categories || []) : [];
			});
		},
		beforeUnmount: function () {
			this.stopTimer();
		},
		methods: {
			localized: function (v) {
				if (!v || typeof v !== 'object') {
					return v || '';
				}
				return v[this.uiLocale] || v[Object.keys(v).find(function (k) { return v[k]; })] || '';
			},
			load: function () {
				var self = this;
				this.call('GET', this.base).then(function (r) {
					self.items = r.ok ? r.data.items : [];
					var running = self.items.find(function (c) { return !c.finished; });
					if (running && !self.active) {
						self.follow(running.id);
					}
				});
			},
			runPreview: function () {
				var self = this;
				this.error = '';
				this.call('POST', this.base + '/preview', this.filter).then(function (r) {
					if (!r.ok) {
						self.error = firstError(r.data) || self.t('errors');
						return;
					}
					self.preview = r.data;
				});
			},
			sendTest: function () {
				var self = this;
				this.isBusy = true;
				this.error = this.message = '';
				this.call('POST', this.base + '/test', this.payload).then(function (r) {
					self.isBusy = false;
					if (!r.ok) {
						self.error = firstError(r.data) || self.t('errors');
						return;
					}
					self.message = self.t('campaign.testSent', { email: r.data.sentTo });
				});
			},
			start: function () {
				var self = this;
				var count = this.preview ? this.preview.count : '?';
				if (!this.canSend || !window.confirm(this.t('campaign.confirm', { count: count }))) {
					return;
				}
				this.isBusy = true;
				this.error = this.message = '';
				this.call('POST', this.base, this.payload).then(function (r) {
					self.isBusy = false;
					if (!r.ok) {
						self.error = firstError(r.data) || self.t('errors');
						return;
					}
					self.subject = self.body = '';
					self.preview = null;
					self.follow(r.data.id);
				});
			},
			follow: function (id) {
				var self = this;
				this.stopTimer();
				var poll = function () {
					self.call('GET', self.apiUrl + '/campaigns/' + id).then(function (r) {
						if (!r.ok) {
							self.stopTimer();
							return;
						}
						self.active = r.data;
						if (r.data.finished) {
							self.stopTimer();
							self.load();
						}
					});
				};
				poll();
				this.timer = window.setInterval(poll, 3000);
			},
			stopTimer: function () {
				if (this.timer) {
					window.clearInterval(this.timer);
					this.timer = null;
				}
			},
			done: function (c) {
				return (c.counts.sent || 0) + (c.counts.failed || 0);
			},
			insert: function (name) {
				this.body += '{$' + name + '}';
			},
		},
		template: '\
<div class="cmEditions cmCampaigns">\
	<div class="cmEditions__toolbar">\
		<h2>{{ t(\'campaign.title\') }}: {{ editionName }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<p class="cmEditions__hint">{{ t(\'campaign.description\') }}</p>\
	<div v-if="active" class="cmDecisions__run" role="status">\
		<strong>{{ active.subject }}</strong>: {{ t(\'campaign.progress\', { done: done(active), total: active.total }) }}\
		<span v-if="active.counts.failed"> · {{ t(\'campaign.failedCount\', { count: active.counts.failed }) }}</span>\
		<span v-if="active.finished"> · {{ t(\'campaign.finished\') }}</span>\
		<ul v-if="active.failed.length" class="cmDecisions__failed"><li v-for="f in active.failed" :key="f.email">{{ f.email }}: {{ f.error }}</li></ul>\
	</div>\
	<fieldset class="cmCampaigns__filter">\
		<legend>{{ t(\'campaign.recipients\') }}</legend>\
		<label>{{ t(\'participants.role\') }}<select v-model="filter.role"><option value="">{{ t(\'participants.all\') }}</option><option v-for="o in options.roles" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
		<label v-if="categories.length">{{ t(\'participants.category\') }}<select v-model="filter.categoryId"><option value="">{{ t(\'participants.all\') }}</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ localized(c.name) }}</option></select></label>\
		<label>{{ t(\'participants.payment\') }}<select v-model="filter.payment"><option value="">{{ t(\'participants.all\') }}</option><option v-for="o in options.payments" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>\
		<label>{{ t(\'campaign.section\') }}<input type="text" v-model="filter.section"></label>\
		<label class="cmCampaigns__check"><input type="checkbox" v-model="filter.accepted"> {{ t(\'campaign.accepted\') }}</label>\
		<label v-if="submissionMode === \'abstracts_and_papers\'">{{ t(\'campaign.fullText\') }}<select v-model="filter.fullText"><option value="">{{ t(\'participants.all\') }}</option><option value="yes">{{ t(\'campaign.fullText.yes\') }}</option><option value="no">{{ t(\'campaign.fullText.no\') }}</option></select></label>\
		<pkp-button @click="runPreview">{{ t(\'campaign.preview\') }}</pkp-button>\
		<p v-if="preview" role="status"><strong>{{ t(\'campaign.count\', { count: preview.count }) }}</strong><span v-if="preview.count > preview.max" class="cmPanel__error"> {{ t(\'campaign.tooMany\', { max: preview.max }) }}</span><br><span class="cmPanel__muted">{{ sampleEmails }}<span v-if="preview.count > preview.sample.length">…</span></span></p>\
	</fieldset>\
	<div class="cmCampaigns__letter">\
		<label>{{ t(\'campaign.subject\') }}<input type="text" v-model="subject" maxlength="255"></label>\
		<label>{{ t(\'campaign.body\') }}<textarea v-model="body" rows="10"></textarea></label>\
		<p class="cmPanel__muted">{{ t(\'campaign.variables\') }}:\
			<button v-for="v in [\'recipientName\', \'conferenceName\', \'cabinetUrl\', \'contextName\']" :key="v" type="button" class="cmCampaigns__var" @click="insert(v)">{{ \'{$\' + v + \'}\' }}</button>\
		</p>\
		<div class="cmCampaigns__actions">\
			<pkp-button :disabled="!canSend" @click="sendTest">{{ t(\'campaign.test\') }}</pkp-button>\
			<pkp-button :is-primary="true" :disabled="!canSend" @click="start">{{ t(\'campaign.send\') }}</pkp-button>\
		</div>\
		<p v-if="message" class="cmPanel__message" role="status">{{ message }}</p>\
		<p v-if="error" class="cmPanel__error" role="alert">{{ error }}</p>\
	</div>\
	<div v-if="items.length" class="cmDecisions__runs">\
		<h3>{{ t(\'campaign.history\') }}</h3>\
		<ul><li v-for="c in items" :key="c.id"><a href="#" @click.prevent="follow(c.id)">{{ time(c.createdAt) }} · {{ c.subject }}</a> · {{ t(\'campaign.progress\', { done: done(c), total: c.total }) }}<span v-if="c.counts.failed"> · {{ t(\'campaign.failedCount\', { count: c.counts.failed }) }}</span></li></ul>\
	</div>\
</div>',
	});

	pkp.registry.registerComponent('ConferenceReminders', {
		name: 'ConferenceReminders',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			csrfToken: { type: String, required: true },
			canEdit: { type: Boolean, default: false },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { settings: null, errors: {}, message: '', isSaving: false };
		},
		mounted: function () {
			var self = this;
			this.call('GET', this.apiUrl + '/reminders').then(function (r) {
				self.settings = r.ok ? r.data.settings : null;
			});
		},
		methods: {
			save: function () {
				var self = this;
				this.isSaving = true;
				this.errors = {};
				this.message = '';
				var body = {};
				Object.keys(this.settings).forEach(function (k) {
					body[k] = { enabled: !!self.settings[k].enabled, days: Number(self.settings[k].days) };
				});
				this.call('PUT', this.apiUrl + '/reminders', body).then(function (r) {
					self.isSaving = false;
					if (!r.ok) {
						self.errors = r.data || {};
						return;
					}
					self.settings = r.data.settings;
					self.message = self.t('reminders.saved');
				});
			},
		},
		template: '\
<div class="cmEditions cmReminders">\
	<div class="cmEditions__toolbar">\
		<h2>{{ t(\'reminders.title\') }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<p class="cmEditions__hint">{{ t(\'reminders.description\') }}</p>\
	<p v-if="!settings">{{ t(\'loading\') }}</p>\
	<form v-else @submit.prevent="save">\
		<fieldset v-for="k in [\'payment\', \'paper\', \'event\']" :key="k" class="cmReminders__kind">\
			<legend>{{ t(\'reminders.\' + k) }}</legend>\
			<p class="cmPanel__muted">{{ t(\'reminders.\' + k + \'.description\') }}</p>\
			<label class="cmCampaigns__check"><input type="checkbox" v-model="settings[k].enabled" :disabled="!canEdit"> {{ t(\'reminders.enabled\') }}</label>\
			<label>{{ t(\'reminders.days\') }}<input type="number" min="1" max="60" v-model="settings[k].days" :disabled="!canEdit || !settings[k].enabled"></label>\
			<p v-if="errors[k]" class="cmPanel__error" role="alert">{{ errors[k].join(\' \') }}</p>\
		</fieldset>\
		<pkp-button v-if="canEdit" :is-primary="true" :disabled="isSaving" @click="save">{{ t(\'save\') }}</pkp-button>\
		<p v-if="message" class="cmPanel__message" role="status">{{ message }}</p>\
	</form>\
</div>',
	});

	pkp.registry.registerComponent('ConferenceAuditLog', {
		name: 'ConferenceAuditLog',
		mixins: [common],
		props: {
			apiUrl: { type: String, required: true },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { tab: 'log', items: [], itemsMax: 0, entities: [], entity: '', offset: 0, count: 50, open: null, versions: null };
		},
		mounted: function () {
			this.load();
		},
		methods: {
			load: function () {
				var self = this;
				var q = new URLSearchParams({ entity: this.entity, count: String(this.count), offset: String(this.offset) });
				this.call('GET', this.apiUrl + '/audit-log?' + q.toString()).then(function (r) {
					if (!r.ok) {
						return;
					}
					self.items = r.data.items;
					self.itemsMax = r.data.itemsMax;
					self.entities = r.data.entities;
				});
			},
			filterBy: function () {
				this.offset = 0;
				this.load();
			},
			page: function (delta) {
				this.offset = Math.max(0, this.offset + delta * this.count);
				this.load();
			},
			showVersions: function () {
				var self = this;
				this.tab = 'consent';
				if (this.versions === null) {
					this.call('GET', this.apiUrl + '/consent-versions').then(function (r) {
						self.versions = r.ok ? r.data.items : [];
					});
				}
			},
			entityLabel: function (e) {
				var key = 'audit.entity.' + e;
				return this.i18n[key] || e;
			},
			json: function (v) {
				return v === null || v === undefined ? '' : JSON.stringify(v, null, 2);
			},
		},
		template: '\
<div class="cmEditions cmAudit">\
	<div class="cmEditions__toolbar">\
		<h2>{{ t(\'audit.title\') }}</h2>\
		<pkp-button :is-primary="tab === \'log\'" @click="tab = \'log\'">{{ t(\'audit.log\') }}</pkp-button>\
		<pkp-button :is-primary="tab === \'consent\'" @click="showVersions">{{ t(\'audit.consent\') }}</pkp-button>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<template v-if="tab === \'log\'">\
		<p class="cmEditions__hint">{{ t(\'audit.description\') }}</p>\
		<label>{{ t(\'audit.entity\') }}<select v-model="entity" @change="filterBy"><option value="">{{ t(\'participants.all\') }}</option><option v-for="e in entities" :key="e" :value="e">{{ entityLabel(e) }}</option></select></label>\
		<p v-if="!items.length" class="cmEditions__empty">{{ t(\'audit.empty\') }}</p>\
		<table v-else class="cmEditions__table">\
			<thead><tr><th>{{ t(\'journal.time\') }}</th><th>{{ t(\'audit.actor\') }}</th><th>{{ t(\'audit.entity\') }}</th><th>{{ t(\'audit.action\') }}</th><th></th></tr></thead>\
			<tbody>\
				<template v-for="i in items" :key="i.id">\
					<tr>\
						<td>{{ time(i.createdAt) }}</td>\
						<td>{{ i.actor || t(\'audit.system\') }}</td>\
						<td>{{ entityLabel(i.entity) }}<span v-if="i.entityId"> #{{ i.entityId }}</span></td>\
						<td>{{ i.action }}</td>\
						<td><button v-if="i.before || i.after" type="button" class="cmCampaigns__var" @click="open = open === i.id ? null : i.id">{{ t(\'audit.details\') }}</button></td>\
					</tr>\
					<tr v-if="open === i.id"><td colspan="5" class="cmAudit__details">\
						<div v-if="i.before"><strong>{{ t(\'audit.before\') }}</strong><pre>{{ json(i.before) }}</pre></div>\
						<div v-if="i.after"><strong>{{ t(\'audit.after\') }}</strong><pre>{{ json(i.after) }}</pre></div>\
					</td></tr>\
				</template>\
			</tbody>\
		</table>\
		<div v-if="itemsMax > count" class="cmAudit__pages">\
			<pkp-button :disabled="offset === 0" @click="page(-1)">←</pkp-button>\
			<span>{{ offset + 1 }}–{{ Math.min(offset + count, itemsMax) }} / {{ itemsMax }}</span>\
			<pkp-button :disabled="offset + count >= itemsMax" @click="page(1)">→</pkp-button>\
		</div>\
	</template>\
	<template v-else>\
		<p class="cmEditions__hint">{{ t(\'audit.consent.description\') }}</p>\
		<p v-if="versions === null">{{ t(\'loading\') }}</p>\
		<p v-else-if="!versions.length" class="cmEditions__empty">{{ t(\'audit.consent.empty\') }}</p>\
		<div v-for="v in versions" :key="v.version" class="cmAudit__version">\
			<h3>{{ time(v.createdAt) }} · {{ t(\'audit.consent.agreements\', { count: v.agreements }) }}</h3>\
			<p class="cmPanel__muted">{{ v.version }}</p>\
			<details v-for="(text, locale) in v.texts" :key="locale"><summary>{{ locale }}</summary><p class="cmAudit__text">{{ text }}</p></details>\
		</div>\
	</template>\
</div>',
	});
})();
