/**
 * Bids of reviewers for the submissions of an edition (DEC-10), opened
 * from the editions page: the period of the choice and the number of
 * reviewers per submission, the invitation letter, the bids by submission
 * and the proposed distribution the manager confirms.
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

	pkp.registry.registerComponent('ConferenceBidding', {
		name: 'ConferenceBidding',
		props: {
			/** .../conference/editions/{id} */
			apiUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, busy: false, data: null, form: { biddingUntil: '', reviewersPerSubmission: '2' }, errors: {}, picked: {} };
		},
		mounted: function () {
			this.load();
		},
		computed: {
			proposal: function () {
				return this.data ? this.data.proposal : [];
			},
			pickedCount: function () {
				var self = this;
				return self.proposal.filter(function (p) { return self.picked[self.key(p)]; }).length;
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
			key: function (p) {
				return p.submissionId + ':' + p.userId;
			},
			take: function (data) {
				var self = this;
				self.data = data;
				self.form = { biddingUntil: data.settings.biddingUntil || '', reviewersPerSubmission: String(data.settings.reviewersPerSubmission) };
				var picked = {};
				data.proposal.forEach(function (p) { picked[self.key(p)] = true; });
				self.picked = picked;
			},
			load: function () {
				var self = this;
				self.call('GET', self.apiUrl + '/bids').then(function (r) {
					self.isLoading = false;
					r.ok ? self.take(r.data) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			saveSettings: function () {
				var self = this;
				self.busy = true;
				self.call('PUT', self.apiUrl + '/bids/settings', { biddingUntil: self.form.biddingUntil || null, reviewersPerSubmission: self.form.reviewersPerSubmission }).then(function (r) {
					self.busy = false;
					if (!r.ok) {
						self.errors = r.data || {};
						return;
					}
					self.errors = {};
					self.take(r.data);
					notify(self.t('saved'));
				});
			},
			invite: function () {
				var self = this;
				self.busy = true;
				self.call('POST', self.apiUrl + '/bids/invite', {}).then(function (r) {
					self.busy = false;
					r.ok ? notify(self.t('bids.invited', { n: r.data.sent })) : notify((r.data.biddingUntil || [r.data.errorMessage || '']).join(' '), 'warning');
				});
			},
			assign: function () {
				var self = this;
				var pairs = self.proposal.filter(function (p) { return self.picked[self.key(p)]; }).map(function (p) { return { submissionId: p.submissionId, userId: p.userId }; });
				if (!pairs.length) {
					return;
				}
				self.busy = true;
				self.call('POST', self.apiUrl + '/bids/assign', { pairs: pairs }).then(function (r) {
					self.busy = false;
					if (!r.ok) {
						notify(r.data.errorMessage || '', 'warning');
						return;
					}
					self.take(r.data);
					notify(self.t('bids.assigned', { n: r.data.assigned, skipped: r.data.skipped }), r.data.skipped ? 'warning' : 'success');
				});
			},
			names: function (list) {
				return list.length ? list.join(', ') : '—';
			},
			fieldError: function (field) {
				return (this.errors[field] || []).join(' ');
			},
		},
		template: '\
<div class="cmEditions cmBidding">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'bids.manager\') }}<span v-if="editionName">: {{ editionName }}</span></h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else-if="data">\
		<p class="cmEditions__note">{{ t(\'bids.managerHelp\') }}</p>\
		<p v-if="!data.usable" class="cmEditions__note" role="status">{{ t(\'bids.light\') }}</p>\
		<form class="cmPartners__form cmBidding__settings" @submit.prevent="saveSettings">\
			<div class="cmFees__field">\
				<label for="cmBidUntil">{{ t(\'bids.until\') }}</label>\
				<input id="cmBidUntil" type="date" v-model="form.biddingUntil" aria-describedby="cmBidUntilHelp">\
				<p id="cmBidUntilHelp" class="cmEditions__note">{{ t(\'bids.untilHelp\') }}</p>\
				<p v-if="fieldError(\'biddingUntil\')" class="cmPartners__error" role="alert">{{ fieldError(\'biddingUntil\') }}</p>\
			</div>\
			<div class="cmFees__field">\
				<label for="cmBidPer">{{ t(\'bids.perSubmission\') }}</label>\
				<input id="cmBidPer" type="number" min="1" max="10" v-model="form.reviewersPerSubmission">\
				<p v-if="fieldError(\'reviewersPerSubmission\')" class="cmPartners__error" role="alert">{{ fieldError(\'reviewersPerSubmission\') }}</p>\
			</div>\
			<div class="cmEditions__buttons">\
				<pkp-button :is-primary="true" :disabled="busy" @click="saveSettings">{{ t(\'save\') }}</pkp-button>\
				<pkp-button v-if="data.open" :disabled="busy" @click="invite">{{ t(\'bids.invite\') }}</pkp-button>\
			</div>\
			<p v-if="data.open && data.bidUrl" class="cmEditions__note">{{ t(\'bids.pageUrl\') }} <a :href="data.bidUrl">{{ data.bidUrl }}</a></p>\
		</form>\
		<h3>{{ t(\'bids.reviewers\') }}</h3>\
		<p v-if="!data.reviewers.length" class="cmEditions__note">{{ t(\'bids.noReviewers\') }}</p>\
		<table v-else class="cmEditions__table">\
			<thead><tr><th>{{ t(\'bids.reviewer\') }}</th><th>{{ t(\'bids.wanted\') }}</th><th>{{ t(\'bids.load\') }}</th></tr></thead>\
			<tbody><tr v-for="r in data.reviewers" :key="r.userId"><td>{{ r.name }} <span class="cmEditions__path">{{ r.email }}</span></td><td>{{ r.bids }}</td><td>{{ r.load }}</td></tr></tbody>\
		</table>\
		<h3>{{ t(\'bids.proposal\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'bids.proposalHelp\') }}</p>\
		<p v-if="!proposal.length" class="cmEditions__note">{{ t(\'bids.noProposal\') }}</p>\
		<template v-else>\
			<table class="cmEditions__table">\
				<thead><tr><th><span class="cmVisuallyHidden">{{ t(\'bids.pick\') }}</span></th><th>{{ t(\'bids.submission\') }}</th><th>{{ t(\'bids.reviewer\') }}</th><th>{{ t(\'bids.bid\') }}</th></tr></thead>\
				<tbody><tr v-for="p in proposal" :key="key(p)">\
					<td><input type="checkbox" :id="\'cmPick\' + key(p)" v-model="picked[key(p)]" :aria-label="t(\'bids.pick\') + \': \' + p.title + \' — \' + p.name"></td>\
					<td>{{ p.title }}</td><td>{{ p.name }}</td><td>{{ t(\'bids.bid.\' + p.bid) }}</td>\
				</tr></tbody>\
			</table>\
			<pkp-button :is-primary="true" :disabled="busy || !pickedCount" @click="assign">{{ t(\'bids.assign\', { n: pickedCount }) }}</pkp-button>\
		</template>\
		<h3>{{ t(\'bids.bySubmission\') }}</h3>\
		<p v-if="!data.submissions.length" class="cmEditions__note">{{ t(\'bids.noSubmissions\') }}</p>\
		<table v-else class="cmEditions__table cmBidding__table">\
			<thead><tr><th>{{ t(\'bids.submission\') }}</th><th>{{ t(\'bids.bid.eager\') }}</th><th>{{ t(\'bids.bid.willing\') }}</th><th>{{ t(\'bids.bid.unwilling\') }}</th><th>{{ t(\'bids.bid.conflict\') }}</th><th>{{ t(\'bids.assignedNow\') }}</th></tr></thead>\
			<tbody><tr v-for="s in data.submissions" :key="s.id">\
				<td>{{ s.title }}<div class="cmEditions__path">{{ s.section }}<span v-if="!s.inReview"> · {{ t(\'bids.notInReview\') }}</span></div></td>\
				<td>{{ names(s.bids.eager) }}</td><td>{{ names(s.bids.willing) }}</td><td>{{ names(s.bids.unwilling) }}</td><td>{{ names(s.bids.conflict) }}</td><td>{{ names(s.assigned) }}</td>\
			</tr></tbody>\
		</table>\
	</template>\
</div>',
	});
})();
