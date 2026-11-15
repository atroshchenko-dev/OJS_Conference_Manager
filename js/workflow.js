/**
 * The submission card of the OJS 3.5 workflow (DEC-01, DEC-05, DEC-06):
 *
 *   - buttons of the decisions 901–904 in the submission stage, next to
 *     "Accept and skip review", for submissions of a conference edition;
 *   - the "Conference participation" panel in the side column at every
 *     stage: edition, presentation type (with a change after the
 *     decision), participants, invoice and the last receipt.
 *
 * window.conferenceManagerWorkflow is set by the plugin on the dashboard
 * page: {apiUrl, csrfToken, codes, i18n}.
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	var cfg = window.conferenceManagerWorkflow;
	if (!cfg || !window.pkp || !pkp.registry) {
		return;
	}

	function t(key) {
		return cfg.i18n[key] || key;
	}

	function request(method, url, body) {
		var init = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-Csrf-Token': cfg.csrfToken, Accept: 'application/json' },
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

	function publicationOf(args) {
		if (args.selectedPublication) {
			return args.selectedPublication;
		}
		var s = args.submission || {};
		return (s.publications || []).find(function (p) { return p.id === s.currentPublicationId; }) || null;
	}

	/** The stage open in the card: OJS 3.5.0 passes it in the menu state. */
	function stageOf(args) {
		return args.selectedStageId || (args.selectedMenuState && args.selectedMenuState.stageId) || 0;
	}

	/** The same address as the core decision buttons, with the way back to the card. */
	function decisionUrl(submissionId, decision, reviewRoundId) {
		var params = new URLSearchParams(window.location.search);
		params.set('workflowSubmissionId', submissionId);
		params.delete('workflowMenuKey');
		var query = new URLSearchParams({ decision: String(decision), ret: 'dashboard/editorial?' + params.toString() });
		if (reviewRoundId) {
			query.set('reviewRoundId', String(reviewRoundId));
			query.set('stageId', String(pkp.const.WORKFLOW_STAGE_ID_EXTERNAL_REVIEW));
		}
		return pkp.context.pageBaseUrl + 'decision/record/' + encodeURIComponent(submissionId) + '?' + query.toString();
	}

	pkp.registry.registerComponent('ConferenceDecisionButton', {
		name: 'ConferenceDecisionButton',
		props: {
			submissionId: { type: Number, required: true },
			decision: { type: Number, required: true },
			label: { type: String, required: true },
			isPrimary: { type: Boolean, default: false },
			reviewRoundId: { type: Number, default: 0 },
		},
		methods: {
			go: function () {
				window.location.href = decisionUrl(this.submissionId, this.decision, this.reviewRoundId);
			},
		},
		template: '<pkp-button :is-primary="isPrimary" :is-secondary="!isPrimary" class="cmDecisionButton" @click="go">{{ label }}</pkp-button>',
	});

	pkp.registry.registerComponent('ConferenceSubmissionPanel', {
		name: 'ConferenceSubmissionPanel',
		props: {
			submissionId: { type: Number, required: true },
			stageId: { type: Number, default: 0 },
		},
		data: function () {
			return { panel: null, type: '', isSaving: false, message: '', error: '', vote: '', comment: '', voteMessage: '', voteError: '' };
		},
		computed: {
			proof: function () {
				var list = (this.panel && this.panel.invoice && this.panel.invoice.proofs) || [];
				return list.length ? list[list.length - 1] : null;
			},
		},
		watch: {
			submissionId: function () { this.load(); },
			stageId: function () { this.load(); },
		},
		mounted: function () {
			this.load();
		},
		methods: {
			t: t,
			money: function (m) {
				return m ? m.decimal + ' ' + m.currency : '';
			},
			url: function () {
				return cfg.apiUrl + '/submissions/' + this.submissionId;
			},
			load: function () {
				var self = this;
				request('GET', this.url() + '/conference').then(function (r) {
					self.setPanel(r.ok ? r.data : null);
				});
			},
			setPanel: function (panel) {
				this.panel = panel;
				this.type = panel ? (panel.presentationType || '') : '';
				var mine = panel && panel.review && panel.review.myVote;
				this.vote = mine ? mine.vote : '';
				this.comment = mine ? mine.comment : '';
			},
			sendVote: function (value) {
				var self = this;
				this.isSaving = true;
				this.voteMessage = '';
				this.voteError = '';
				request('PUT', this.url() + '/vote', { vote: value, comment: value === 'none' ? '' : this.comment }).then(function (r) {
					self.isSaving = false;
					if (!r.ok) {
						self.voteError = firstError(r.data) || t('panel.error');
						return;
					}
					self.setPanel(r.data);
					self.voteMessage = value === 'none' ? t('review.withdrawn') : t('review.saved');
				});
			},
			deadline: function (iso) {
				return iso ? new Date(iso).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
			},
			changeType: function () {
				var self = this;
				this.isSaving = true;
				this.message = '';
				this.error = '';
				request('PUT', this.url() + '/presentation-type', { presentationType: this.type }).then(function (r) {
					self.isSaving = false;
					if (!r.ok) {
						self.error = firstError(r.data) || t('panel.error');
						return;
					}
					if (r.data.panel) {
						self.setPanel(r.data.panel);
					}
					self.message = r.data.changed ? t('panel.changeType.done') : '';
				});
			},
		},
		template: '\
<section v-if="panel" class="cmPanel" :aria-label="t(\'panel.title\')">\
	<h2 class="cmPanel__title">{{ t(\'panel.title\') }}</h2>\
	<dl class="cmPanel__list">\
		<dt>{{ t(\'panel.edition\') }}</dt>\
		<dd>{{ panel.edition.title }}</dd>\
		<dt>{{ t(\'panel.type\') }}</dt>\
		<dd>\
			<span>{{ panel.presentationTypeLabel || t(\'panel.type.none\') }}</span>\
			<span v-if="panel.presenterMinor" class="cmPanel__badge">{{ t(\'panel.minor\') }}</span>\
			<span v-if="!panel.registered" class="cmPanel__badge">{{ t(\'panel.notRegistered\') }}</span>\
		</dd>\
	</dl>\
	<form v-if="panel.canChangeType" class="cmPanel__change" @submit.prevent="changeType">\
		<label>{{ t(\'panel.changeType\') }}\
			<select v-model="type">\
				<option v-for="o in panel.presentationTypes" :key="o.value" :value="o.value">{{ o.label }}</option>\
			</select>\
		</label>\
		<pkp-button :disabled="isSaving || type === panel.presentationType" @click="changeType">{{ t(\'panel.changeType.save\') }}</pkp-button>\
	</form>\
	<p v-if="message" class="cmPanel__message" role="status">{{ message }}</p>\
	<p v-if="error" class="cmPanel__error" role="alert">{{ error }}</p>\
	<template v-if="panel.review">\
		<div v-for="(c, i) in panel.review.conflicts" :key="\'c\' + i" class="cmPanel__conflict" role="alert">\
			<strong>{{ t(\'review.conflict\') }}</strong> {{ (c.isViewer ? t(\'review.conflict.you\') : t(\'review.conflict.editor\')).replace(\'{$editor}\', c.editor).replace(\'{$author}\', c.author).replace(\'{$affiliation}\', c.affiliation) }}\
		</div>\
		<p v-if="panel.review.twoPhase" class="cmPanel__muted">{{ t(\'review.twoPhase\') }}<template v-if="panel.review.paperDeadline"> {{ t(\'review.paperDeadline\') }}: {{ deadline(panel.review.paperDeadline) }}.</template></p>\
		<template v-if="panel.review.voting">\
			<h3 class="cmPanel__subtitle">{{ t(\'review.votes\') }}</h3>\
			<p class="cmPanel__tally" role="status">{{ t(\'review.tally\').replace(\'{$for}\', panel.review.counts.for).replace(\'{$against}\', panel.review.counts.against).replace(\'{$abstain}\', panel.review.counts.abstain) }}</p>\
			<ul v-if="panel.review.votes.length" class="cmPanel__people">\
				<li v-for="v in panel.review.votes" :key="v.userId"><strong>{{ v.voteLabel }}</strong> · {{ v.name }}<span v-if="v.comment" class="cmPanel__muted">: {{ v.comment }}</span></li>\
			</ul>\
			<p v-else class="cmPanel__muted">{{ t(\'review.noVotes\') }}</p>\
			<fieldset class="cmPanel__vote">\
				<legend>{{ t(\'review.yourVote\') }}</legend>\
				<label v-for="o in panel.review.voteOptions" :key="o.value" class="cmPanel__voteOption"><input type="radio" name="cmVote" :value="o.value" v-model="vote"> {{ o.label }}</label>\
				<label class="cmPanel__comment">{{ t(\'review.comment\') }}<textarea v-model="comment" rows="2" maxlength="2000"></textarea></label>\
				<pkp-button :disabled="isSaving || !vote" @click="sendVote(vote)">{{ t(\'review.save\') }}</pkp-button>\
				<pkp-button v-if="panel.review.myVote" :is-warnable="true" :disabled="isSaving" @click="sendVote(\'none\')">{{ t(\'review.withdraw\') }}</pkp-button>\
			</fieldset>\
			<p v-if="voteMessage" class="cmPanel__message" role="status">{{ voteMessage }}</p>\
			<p v-if="voteError" class="cmPanel__error" role="alert">{{ voteError }}</p>\
		</template>\
	</template>\
	<h3 class="cmPanel__subtitle">{{ t(\'panel.participants\') }}</h3>\
	<ul class="cmPanel__people">\
		<li v-for="p in panel.participants" :key="p.participantId">{{ p.email }} <span class="cmPanel__muted">· {{ p.roleLabel }} · {{ p.confirmationLabel }}</span></li>\
	</ul>\
	<h3 class="cmPanel__subtitle">{{ t(\'panel.invoice\') }}</h3>\
	<p v-if="!panel.invoice" class="cmPanel__muted">{{ t(\'panel.invoice.none\') }}</p>\
	<p v-else>\
		№ {{ panel.invoice.number }} · {{ panel.invoice.statusLabel }}<br>\
		<span v-if="panel.invoice.isOpen">{{ t(\'panel.due\') }}: {{ money(panel.invoice.due) }}</span>\
		<span v-else>{{ t(\'panel.paid\') }}: {{ money(panel.invoice.paid) }}</span>\
	</p>\
	<p v-if="panel.invoice" class="cmPanel__muted">{{ t(\'panel.proof\') }}: {{ proof ? proof.statusLabel + (proof.note ? \' · \' + proof.note : \'\') : t(\'panel.proof.none\') }}</p>\
</section>',
	});

	pkp.registry.storeExtendFn('workflow', 'getActionItems', function (result, args) {
		var s = args && args.submission;
		var items = Array.isArray(result) ? result : (result && result.items);
		var stage = stageOf(args);
		if (!s || !items) {
			return result;
		}
		var publication = publicationOf(args);
		if (!publication || !publication.conferenceEditionId) {
			return result;
		}
		if (stage === pkp.const.WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
			// DEC-07: "abstract accepted, send the full text" in the first round of a two-phase edition.
			var round = args.selectedReviewRound;
			if ((cfg.twoPhaseEditions || []).indexOf(publication.conferenceEditionId) !== -1 && round && (!round.round || round.round === 1)) {
				(s.availableEditorialDecisions || []).forEach(function (d) {
					if (d.id === cfg.abstractAccepted) {
						items.unshift({ component: 'ConferenceDecisionButton', props: { submissionId: s.id, decision: d.id, label: d.label, isPrimary: true, reviewRoundId: round.id } });
					}
				});
			}
			return result;
		}
		if (stage !== pkp.const.WORKFLOW_STAGE_ID_SUBMISSION) {
			return result;
		}
		(s.availableEditorialDecisions || []).forEach(function (d) {
			if (cfg.codes.indexOf(d.id) !== -1 && d.stageId === pkp.const.WORKFLOW_STAGE_ID_SUBMISSION) {
				items.push({
					component: 'ConferenceDecisionButton',
					props: { submissionId: s.id, decision: d.id, label: d.label, isPrimary: d.id === cfg.codes[0] },
				});
			}
		});
		return result;
	});

	pkp.registry.storeExtendFn('workflow', 'getSecondaryItems', function (result, args) {
		var s = args && args.submission;
		if (!s) {
			return result;
		}
		var item = { component: 'ConferenceSubmissionPanel', props: { submissionId: s.id, stageId: stageOf(args) } };
		if (Array.isArray(result)) {
			result.unshift(item);
			return result;
		}
		if (result && Array.isArray(result.items)) {
			result.items.unshift(item);
			return result;
		}
		return [item];
	});
})();
