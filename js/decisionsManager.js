/**
 * Bulk decisions on the abstracts of an edition (DEC-03, DEC-04), opened
 * from the editions page. The editor filters the abstracts waiting for a
 * decision, ticks them, picks one decision and starts a run: every
 * submission is decided by its own job in the OJS queue with its own
 * letter. The page then follows the progress of the run.
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

	pkp.registry.registerComponent('ConferenceBulkDecisions', {
		name: 'ConferenceBulkDecisions',
		props: {
			/** .../conference */
			apiUrl: { type: String, required: true },
			editionId: { type: Number, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			presentationTypes: { type: Array, default: function () { return []; } },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return {
				items: [],
				sections: [],
				decisions: [],
				runs: [],
				maxItems: 200,
				search: '',
				type: '',
				section: '',
				selected: [],
				decision: '',
				isLoading: false,
				isStarting: false,
				error: '',
				activeRun: null,
				timer: null,
			};
		},
		computed: {
			allSelected: function () {
				return this.items.length > 0 && this.selected.length === this.items.length;
			},
			decisionLabel: function () {
				var self = this;
				var d = this.decisions.find(function (x) { return String(x.value) === String(self.decision); });
				return d ? d.label : '';
			},
			canStart: function () {
				return this.selected.length > 0 && this.selected.length <= this.maxItems && this.decision !== '' && !this.isStarting;
			},
		},
		mounted: function () {
			this.load();
		},
		beforeUnmount: function () {
			this.stopTimer();
		},
		methods: {
			t: function (key, params) {
				var s = this.i18n[key] || key;
				Object.keys(params || {}).forEach(function (p) { s = s.replace('{$' + p + '}', params[p]); });
				return s;
			},
			time: function (iso) {
				return iso ? String(iso).replace('T', ' ').slice(0, 16) + ' UTC' : '';
			},
			load: function () {
				var self = this;
				var q = new URLSearchParams({ search: this.search, presentationType: this.type, section: this.section });
				this.isLoading = true;
				request(this.csrfToken, 'GET', this.apiUrl + '/editions/' + this.editionId + '/decision-candidates?' + q.toString()).then(function (r) {
					self.isLoading = false;
					if (!r.ok) {
						self.error = firstError(r.data);
						return;
					}
					self.items = r.data.items;
					if (!self.section && !self.search && !self.type) {
						self.sections = r.data.sections;
					}
					self.decisions = r.data.decisions;
					self.runs = r.data.runs;
					self.maxItems = r.data.maxItems;
					var ids = self.items.map(function (i) { return i.submissionId; });
					self.selected = self.selected.filter(function (id) { return ids.indexOf(id) !== -1; });
					var running = self.runs.find(function (run) { return !run.finished; });
					if (running && !self.activeRun) {
						self.follow(running);
					}
				});
			},
			toggleAll: function () {
				this.selected = this.allSelected ? [] : this.items.map(function (i) { return i.submissionId; });
			},
			start: function () {
				var self = this;
				if (!this.canStart || !window.confirm(this.t('decision.bulk.confirm', { count: this.selected.length, decision: this.decisionLabel }))) {
					return;
				}
				this.isStarting = true;
				this.error = '';
				request(this.csrfToken, 'POST', this.apiUrl + '/editions/' + this.editionId + '/decisions/bulk', {
					decision: Number(this.decision),
					submissionIds: this.selected,
				}).then(function (r) {
					self.isStarting = false;
					if (!r.ok) {
						self.error = firstError(r.data);
						return;
					}
					self.selected = [];
					self.follow({ id: r.data.batchId, total: 0, counts: {}, finished: false, items: [] });
				});
			},
			follow: function (run) {
				var self = this;
				this.activeRun = run;
				this.stopTimer();
				var poll = function () {
					request(self.csrfToken, 'GET', self.apiUrl + '/decisions/bulk/' + run.id).then(function (r) {
						if (!r.ok) {
							self.stopTimer();
							return;
						}
						self.activeRun = r.data;
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
			doneCount: function (run) {
				return (run.counts && ((run.counts.done || 0) + (run.counts.failed || 0))) || 0;
			},
			failedItems: function (run) {
				return (run.items || []).filter(function (i) { return i.status === 'failed'; });
			},
		},
		template: '\
<div class="cmEditions cmDecisions">\
	<div class="cmEditions__toolbar">\
		<h2>{{ t(\'decision.bulk.title\') }}: {{ editionName }}</h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<p class="cmEditions__hint">{{ t(\'decision.bulk.description\', { max: maxItems }) }}</p>\
	<div v-if="activeRun" class="cmDecisions__run" role="status">\
		<strong>{{ activeRun.decisionLabel }}</strong>:\
		{{ t(\'decision.bulk.progress\', { done: doneCount(activeRun), total: activeRun.total }) }}\
		<span v-if="activeRun.counts && activeRun.counts.failed"> · {{ t(\'decision.bulk.failedCount\', { count: activeRun.counts.failed }) }}</span>\
		<span v-if="activeRun.finished"> · {{ t(\'decision.bulk.finished\') }}</span>\
		<ul v-if="failedItems(activeRun).length" class="cmDecisions__failed">\
			<li v-for="i in failedItems(activeRun)" :key="i.submissionId">#{{ i.submissionId }} {{ i.title }}: {{ i.error }}</li>\
		</ul>\
	</div>\
	<form class="cmParticipants__filters" @submit.prevent="load">\
		<label>{{ t(\'decision.bulk.search\') }}<input type="search" v-model="search"></label>\
		<label>{{ t(\'decision.bulk.type\') }}\
			<select v-model="type" @change="load">\
				<option value="">{{ t(\'decision.bulk.anyType\') }}</option>\
				<option v-for="p in presentationTypes" :key="p.value" :value="p.value">{{ p.label }}</option>\
			</select>\
		</label>\
		<label v-if="sections.length > 1">{{ t(\'decision.bulk.section\') }}\
			<select v-model="section" @change="load">\
				<option value="">{{ t(\'decision.bulk.anySection\') }}</option>\
				<option v-for="s in sections" :key="s" :value="s">{{ s }}</option>\
			</select>\
		</label>\
		<pkp-button @click="load">{{ t(\'decision.bulk.find\') }}</pkp-button>\
	</form>\
	<div class="cmDecisions__bar">\
		<span>{{ t(\'decision.bulk.selected\', { count: selected.length }) }}</span>\
		<label>{{ t(\'decision.bulk.decision\') }}\
			<select v-model="decision">\
				<option value="">{{ t(\'decision.bulk.choose\') }}</option>\
				<option v-for="d in decisions" :key="d.value" :value="d.value">{{ d.label }}</option>\
			</select>\
		</label>\
		<pkp-button :is-primary="true" :is-warnable="decision === \'8\' || decision === 8" :disabled="!canStart" @click="start">{{ t(\'decision.bulk.run\') }}</pkp-button>\
	</div>\
	<p v-if="selected.length > maxItems" class="cmPanel__error" role="alert">{{ t(\'decision.bulk.tooMany\', { max: maxItems }) }}</p>\
	<p v-if="error" class="cmPanel__error" role="alert">{{ error }}</p>\
	<p v-if="isLoading">{{ t(\'loading\') }}</p>\
	<p v-else-if="!items.length" class="cmEditions__empty">{{ t(\'decision.bulk.empty\') }}</p>\
	<table v-else class="cmEditions__table">\
		<thead><tr>\
			<th><input type="checkbox" :checked="allSelected" @change="toggleAll" :aria-label="t(\'decision.bulk.selectAll\')"></th>\
			<th>#</th>\
			<th>{{ t(\'decision.bulk.column.title\') }}</th>\
			<th>{{ t(\'decision.bulk.column.section\') }}</th>\
			<th>{{ t(\'decision.bulk.column.type\') }}</th>\
		</tr></thead>\
		<tbody>\
			<tr v-for="i in items" :key="i.submissionId">\
				<td><input type="checkbox" :value="i.submissionId" v-model="selected" :aria-label="i.title"></td>\
				<td>{{ i.submissionId }}</td>\
				<td><strong>{{ i.title }}</strong><div class="cmEditions__path">{{ i.authors }}</div></td>\
				<td>{{ i.section }}</td>\
				<td>{{ i.presentationTypeLabel }}<span v-if="i.presenterMinor" class="cmPanel__badge">{{ t(\'participants.minor\') }}</span></td>\
			</tr>\
		</tbody>\
	</table>\
	<div v-if="runs.length" class="cmDecisions__runs">\
		<h3>{{ t(\'decision.bulk.runs\') }}</h3>\
		<ul><li v-for="run in runs" :key="run.id">{{ time(run.createdAt) }} · {{ run.decisionLabel }} · {{ t(\'decision.bulk.progress\', { done: doneCount(run), total: run.total }) }}<span v-if="run.counts.failed"> · {{ t(\'decision.bulk.failedCount\', { count: run.counts.failed }) }}</span></li></ul>\
	</div>\
</div>',
	});
})();
