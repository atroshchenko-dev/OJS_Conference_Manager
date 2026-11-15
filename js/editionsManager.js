/**
 * Editions page of the Conference Manager plugin (EDN-01…05, 09).
 *
 * A Vue component registered with the OJS 3.5 registry before the page
 * app starts. It talks to /{context}/api/v1/conference/editions only.
 * Dates are shown and entered in the local time of the edition; the API
 * converts them to UTC.
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	function emptyLocalized(locales) {
		var out = {};
		locales.forEach(function (l) { out[l.key] = ''; });
		return out;
	}

	pkp.registry.registerComponent('ConferenceEditionsManager', {
		name: 'ConferenceEditionsManager',
		props: {
			apiUrl: { type: String, required: true },
			publicUrl: { type: String, required: true },
			issueUrl: { type: String, default: '' },
			/** page link of an invoice PDF with "__id__" */
			invoicePdfUrl: { type: String, default: '' },
			certificatePdfUrl: { type: String, default: '' },
			attendanceThreshold: { type: Number, default: 30 },
			proofFileUrl: { type: String, default: '' },
			reviewFormsUrl: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			locales: { type: Array, required: true },
			primaryLocale: { type: String, required: true },
			uiLocale: { type: String, default: '' },
			defaultTimezone: { type: String, default: 'UTC' },
			timezones: { type: Array, default: function () { return []; } },
			localizedFields: { type: Array, required: true },
			dateFields: { type: Array, required: true },
			options: { type: Object, required: true },
			i18n: { type: Object, required: true },
			/** false for registration managers: only the register of participants */
			canEditEditions: { type: Boolean, default: true },
			/** editions given by the page when the editions API is not open to the user */
			initialItems: { type: Array, default: null },
			participantOptions: { type: Object, default: function () { return { roles: [], statuses: [], confirmations: [] }; } },
		},
		data: function () {
			return {
				view: 'list',
				items: [],
				itemsMax: 0,
				statusFilter: '',
				isLoading: false,
				isSaving: false,
				form: null,
				editingId: null,
				copyFromId: null,
				copyParts: ['fees', 'paymentRules', 'rooms', 'settings', 'partners'],
				errors: {},
				formLocale: this.primaryLocale,
				selected: null,
				autoSetup: true,
			};
		},
		computed: {
			statusLabels: function () {
				var map = {};
				this.options.statuses.forEach(function (s) { map[s.value] = s.label; });
				return map;
			},
			typeLabels: function () {
				var map = {};
				this.options.eventTypes.forEach(function (s) { map[s.value] = s.label; });
				return map;
			},
			allowedStatuses: function () {
				if (!this.form) {
					return [];
				}
				if (this.editingId === null) {
					return this.options.statuses.filter(function (s) {
						return s.value === 'draft' || s.value === 'cfp_open';
					});
				}
				var allowed = (this.form.allowedStatuses || []).concat([this.form.originalStatus]);
				return this.options.statuses.filter(function (s) { return allowed.indexOf(s.value) !== -1; });
			},
			errorCount: function () {
				return Object.keys(this.errors).length;
			},
		},
		mounted: function () {
			this.load();
		},
		watch: {
			// WCAG 2.4.3: the button that opened a view is gone, so the
			// keyboard focus moves to the heading of the new view.
			view: function () {
				var root = this.$el;
				this.$nextTick(function () {
					window.setTimeout(function () {
						var h = root && root.querySelector('h2, h3, legend');
						if (h) {
							h.setAttribute('tabindex', '-1');
							h.focus({ preventScroll: false });
						}
					}, 50);
				});
			},
		},
		methods: {
			t: function (key) {
				return this.i18n[key] || key;
			},
			localized: function (value) {
				if (!value) {
					return '';
				}
				return value[this.uiLocale] || value[this.primaryLocale] || Object.values(value)[0] || '';
			},
			request: function (method, url, body) {
				var init = {
					method: method,
					credentials: 'same-origin',
					headers: { 'X-Csrf-Token': this.csrfToken, Accept: 'application/json' },
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
			},
			notify: function (message, type) {
				if (window.pkp && pkp.eventBus) {
					pkp.eventBus.$emit('notify', message, type || 'success');
				}
			},
			openFees: function (edition) {
				this.selected = edition;
				this.view = 'fees';
			},
			openParticipants: function (edition) {
				this.selected = edition;
				this.view = 'participants';
			},
			openPayments: function (edition) {
				this.selected = edition;
				this.view = 'payments';
			},
			openInvoices: function (edition) {
				this.selected = edition;
				this.view = 'invoices';
			},
			openProgram: function (edition) {
				this.selected = edition;
				this.view = 'program';
			},
			openDecisions: function (edition) {
				this.selected = edition;
				this.view = 'decisions';
			},
			openDashboard: function (edition) {
				this.selected = edition;
				this.view = 'dashboard';
			},
			openCampaigns: function (edition) {
				this.selected = edition;
				this.view = 'campaigns';
			},
			openReminders: function () {
				this.selected = null;
				this.view = 'reminders';
			},
			openCertificates: function (edition) {
				this.selected = edition;
				this.view = 'certificates';
			},
			openCertificateTemplates: function () {
				this.selected = null;
				this.view = 'certificateTemplates';
			},
			openReview: function (edition) {
				this.selected = edition;
				this.view = 'review';
			},
			openPartners: function (edition) {
				this.selected = edition;
				this.view = 'partners';
			},
			openPromos: function (edition) {
				this.selected = edition;
				this.view = 'promos';
			},
			openBids: function (edition) {
				this.selected = edition;
				this.view = 'bids';
			},
			openBook: function (edition) {
				this.selected = edition;
				this.view = 'book';
			},
			openLegacy: function () {
				this.selected = null;
				this.view = 'legacy';
			},
			openPrivacy: function () {
				this.selected = null;
				this.view = 'privacy';
			},
			openAudit: function () {
				this.selected = null;
				this.view = 'audit';
			},
			openProofs: function (edition) {
				this.selected = edition;
				this.view = 'proofs';
			},
			openSetup: function () {
				this.selected = null;
				this.view = 'setup';
			},
			setupCreated: function () {
				this.view = 'list';
				this.load();
			},
			backToList: function () {
				this.selected = null;
				this.view = 'list';
			},
			load: function () {
				var self = this;
				if (self.initialItems) {
					self.items = self.initialItems.filter(function (e) { return !self.statusFilter || e.status === self.statusFilter; });
					self.itemsMax = self.items.length;
					return;
				}
				self.isLoading = true;
				var url = self.apiUrl + '?count=100' + (self.statusFilter ? '&status=' + encodeURIComponent(self.statusFilter) : '');
				self.request('GET', url).then(function (r) {
					self.isLoading = false;
					if (r.ok) {
						self.items = r.data.items || [];
						self.itemsMax = r.data.itemsMax || 0;
						// INS-08: a journal without editions starts with the setup wizard.
						if (self.autoSetup && self.canEditEditions && !self.statusFilter && self.itemsMax === 0) {
							self.view = 'setup';
						}
						self.autoSetup = false;
					} else {
						self.notify(r.data.errorMessage || self.t('errors'), 'warning');
					}
				});
			},
			blankForm: function () {
				var self = this;
				var form = {
					path: '',
					status: 'draft',
					originalStatus: 'draft',
					allowedStatuses: [],
					eventType: 'conference',
					submissionMode: 'abstracts',
					publicationMode: 'ojs_issue',
					externalProceedingsUrl: '',
					format: 'in_person',
					timezone: self.defaultTimezone,
					reviewMode: 'light',
					isbn: '',
					udc: '',
					vectorProgramId: '',
					hours: '',
					ects: '',
					issueId: null,
					programPublished: false,
					local: {},
				};
				self.localizedFields.forEach(function (f) { form[f] = emptyLocalized(self.locales); });
				self.dateFields.forEach(function (f) { form.local[f] = ''; });
				return form;
			},
			fromEdition: function (edition) {
				var self = this;
				var form = self.blankForm();
				Object.keys(form).forEach(function (key) {
					if (key === 'local' || self.localizedFields.indexOf(key) !== -1) {
						return;
					}
					if (edition[key] !== undefined && edition[key] !== null) {
						form[key] = edition[key];
					}
				});
				self.localizedFields.forEach(function (f) {
					form[f] = Object.assign(emptyLocalized(self.locales), edition[f] || {});
				});
				self.dateFields.forEach(function (f) {
					form.local[f] = (edition.local && edition.local[f]) || '';
				});
				form.originalStatus = edition.status;
				form.allowedStatuses = edition.allowedStatuses || [];
				form.ects = edition.ectsX100 === null || edition.ectsX100 === undefined ? '' : String(edition.ectsX100 / 100);
				form.hours = edition.hours === null || edition.hours === undefined ? '' : String(edition.hours);
				return form;
			},
			create: function () {
				this.form = this.blankForm();
				this.editingId = null;
				this.copyFromId = null;
				this.errors = {};
				this.view = 'form';
			},
			edit: function (edition) {
				this.form = this.fromEdition(edition);
				this.editingId = edition.id;
				this.copyFromId = null;
				this.errors = {};
				this.view = 'form';
			},
			copy: function (edition) {
				var self = this;
				var form = self.fromEdition(edition);
				form.path = '';
				form.status = 'draft';
				form.originalStatus = 'draft';
				form.isbn = '';
				self.dateFields.forEach(function (f) { form.local[f] = ''; });
				self.locales.forEach(function (l) {
					if (form.name[l.key]) {
						form.name[l.key] = form.name[l.key] + ' (' + self.t('copyOf') + ')';
					}
				});
				self.form = form;
				self.editingId = null;
				self.copyFromId = edition.id;
				self.copyParts = ['fees', 'paymentRules', 'rooms', 'settings', 'partners'];
				self.errors = {};
				self.view = 'form';
			},
			cancel: function () {
				this.view = 'list';
				this.form = null;
				this.errors = {};
			},
			onTypeChange: function () {
				if (this.editingId !== null) {
					return;
				}
				var type = this.form.eventType;
				var def = this.options.eventTypes.filter(function (e) { return e.value === type; })[0];
				if (def) {
					this.form.submissionMode = def.defaultSubmissionMode;
					this.form.publicationMode = def.defaultPublicationMode;
				}
			},
			payload: function () {
				var self = this;
				var f = self.form;
				var body = {
					path: f.path.trim().toLowerCase(),
					status: f.status,
					eventType: f.eventType,
					submissionMode: f.submissionMode,
					publicationMode: f.publicationMode,
					externalProceedingsUrl: f.externalProceedingsUrl,
					format: f.format,
					timezone: f.timezone,
					reviewMode: f.reviewMode,
					isbn: f.isbn,
					udc: f.udc,
					vectorProgramId: f.vectorProgramId,
					hours: f.hours === '' ? null : String(f.hours).trim(),
					ectsX100: f.ects === '' ? null : String(Math.round(parseFloat(String(f.ects).replace(',', '.')) * 100)),
					programPublished: !!f.programPublished,
				};
				if (body.ectsX100 === 'NaN') {
					body.ectsX100 = f.ects;
				}
				self.localizedFields.forEach(function (field) { body[field] = f[field]; });
				// Local wall time of the edition, e.g. "2027-05-20T09:00".
				self.dateFields.forEach(function (field) { body[field] = f.local[field] || ''; });
				return body;
			},
			save: function () {
				var self = this;
				var method = 'POST';
				var url = self.apiUrl;
				if (self.editingId !== null) {
					method = 'PUT';
					url = self.apiUrl + '/' + self.editingId;
				} else if (self.copyFromId !== null) {
					url = self.apiUrl + '/' + self.copyFromId + '/copy';
				}
				self.isSaving = true;
				var body = self.payload();
				if (self.editingId === null && self.copyFromId !== null) {
					body.copyParts = self.copyParts;
				}
				self.request(method, url, body).then(function (r) {
					self.isSaving = false;
					if (r.ok) {
						var c = r.data && r.data.copied;
						self.notify(c ? self.t('copy.done', { categories: c.categories, tariffs: c.tariffs, rules: c.paymentRules, rooms: c.rooms, partners: c.partners || 0, days: c.shiftDays }) : self.t('saved'));
						self.view = 'list';
						self.form = null;
						self.errors = {};
						self.load();
					} else if (r.status === 400) {
						self.errors = r.data || {};
						self.$nextTick(function () {
							var el = self.$el.querySelector('.cmEditions__errors');
							if (el) {
								el.scrollIntoView({ behavior: 'smooth', block: 'start' });
							}
						});
					} else {
						self.notify(r.data.errorMessage || self.t('errors'), 'warning');
					}
				});
			},
			remove: function (edition) {
				var self = this;
				if (!window.confirm(self.t('deleteConfirm'))) {
					return;
				}
				self.request('DELETE', self.apiUrl + '/' + edition.id).then(function (r) {
					if (r.ok) {
						self.notify(self.t('deleted'));
						self.load();
					} else {
						var messages = [];
						Object.keys(r.data || {}).forEach(function (k) {
							messages = messages.concat(r.data[k]);
						});
						self.notify(messages.join(' ') || r.data.errorMessage || self.t('errors'), 'warning');
					}
				});
			},
			fieldError: function (field) {
				var e = this.errors[field];
				return e ? e.join(' ') : '';
			},
			localizedError: function (field) {
				return this.fieldError(field);
			},
			publicLink: function (edition) {
				return this.publicUrl.replace('__path__', encodeURIComponent(edition.path));
			},
			dateRange: function (edition) {
				var s = edition.local && edition.local.startsAt ? edition.local.startsAt.replace('T', ' ') : '';
				var e = edition.local && edition.local.endsAt ? edition.local.endsAt.replace('T', ' ') : '';
				return s + (e ? ' – ' + e : '');
			},
			fieldLabel: function (field) {
				var key = 'field.' + (field === 'ectsX100' ? 'ects' : field);
				return this.i18n[key] || field;
			},
		},
		template: '\
<div class="cmEditions">\
	<div v-if="view === \'list\'">\
		<div class="cmEditions__toolbar">\
			<label class="cmEditions__filter">{{ t(\'filterStatus\') }}\
				<select class="pkpFormField__input" v-model="statusFilter" @change="load">\
					<option value="">{{ t(\'allStatuses\') }}</option>\
					<option v-for="s in options.statuses" :key="s.value" :value="s.value">{{ s.label }}</option>\
				</select>\
			</label>\
			<pkp-button @click="openReminders">{{ t(\'reminders\') }}</pkp-button>\
			<pkp-button v-if="canEditEditions" @click="openCertificateTemplates">{{ t(\'certificateTemplates\') }}</pkp-button>\
			<pkp-button v-if="canEditEditions" @click="openAudit">{{ t(\'audit\') }}</pkp-button>\
			<pkp-button v-if="canEditEditions" @click="openPrivacy">{{ t(\'privacy.title\') }}</pkp-button>\
			<pkp-button v-if="canEditEditions" @click="openLegacy">{{ t(\'legacy.title\') }}</pkp-button>\
			<pkp-button v-if="canEditEditions" @click="openSetup">{{ t(\'setup.open\') }}</pkp-button>\
			<pkp-button v-if="canEditEditions" :is-primary="true" @click="create">{{ t(\'new\') }}</pkp-button>\
		</div>\
		<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
		<p v-else-if="!items.length" class="cmEditions__empty">{{ t(\'empty\') }}</p>\
		<table v-else class="cmEditions__table">\
			<thead><tr>\
				<th>{{ t(\'field.name\') }}</th><th>{{ t(\'column.type\') }}</th><th>{{ t(\'column.dates\') }}</th><th>{{ t(\'column.status\') }}</th><th></th>\
			</tr></thead>\
			<tbody>\
				<tr v-for="e in items" :key="e.id">\
					<td><strong>{{ localized(e.name) }}</strong><div class="cmEditions__path">{{ e.path }}</div></td>\
					<td>{{ typeLabels[e.eventType] }}</td>\
					<td>{{ dateRange(e) }}<div class="cmEditions__tz">{{ e.timezone }}</div></td>\
					<td>{{ statusLabels[e.status] }}<div class="cmEditions__window" :class="{ \'cmEditions__window--open\': e.submissionsOpen }" v-if="e.submissionMode !== \'none\'">{{ e.submissionsOpen ? t(\'submissionsOpen\') : t(\'submissionsClosed\') }}</div></td>\
					<td class="cmEditions__actions">\
						<pkp-button @click="openDashboard(e)">{{ t(\'dashboard\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions" @click="edit(e)">{{ t(\'edit\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions" @click="openFees(e)">{{ t(\'fees\') }}</pkp-button>\
						<pkp-button @click="openParticipants(e)">{{ t(\'participants\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions" @click="openPayments(e)">{{ t(\'payments\') }}</pkp-button>\
						<pkp-button @click="openInvoices(e)">{{ t(\'invoices\') }}</pkp-button>\
						<pkp-button @click="openProofs(e)">{{ t(\'proofs\') }}</pkp-button>\
						<pkp-button @click="openCampaigns(e)">{{ t(\'campaigns\') }}</pkp-button>\
						<pkp-button @click="openCertificates(e)">{{ t(\'certificates\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions && e.submissionMode !== \'none\'" @click="openDecisions(e)">{{ t(\'decisions\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions" @click="openProgram(e)">{{ t(\'program.builder.open\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions && e.reviewMode === \'two_phase\'" @click="openReview(e)">{{ t(\'review.settings.open\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions && e.reviewMode !== \'light\'" @click="openBids(e)">{{ t(\'bids.open\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions" @click="openPartners(e)">{{ t(\'partner.open\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions" @click="openPromos(e)">{{ t(\'promo.open\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions && e.submissionMode !== \'none\'" @click="openBook(e)">{{ t(\'book.open\') }}</pkp-button>\
						<pkp-button v-if="canEditEditions" @click="copy(e)">{{ t(\'copy\') }}</pkp-button>\
						<a class="pkpButton" :href="publicLink(e)" target="_blank" rel="noopener">{{ t(\'publicPage\') }}</a>\
						<pkp-button v-if="canEditEditions" :is-warnable="true" @click="remove(e)">{{ t(\'delete\') }}</pkp-button>\
					</td>\
				</tr>\
			</tbody>\
		</table>\
	</div>\
	<conference-fees-editor v-else-if="view === \'fees\'" :api-url="apiUrl + \'/\' + selected.id + \'/fees\'" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :locales="locales" :primary-locale="primaryLocale" :ui-locale="uiLocale" :i18n="i18n" @back="backToList"></conference-fees-editor>\
	<conference-participants-register v-else-if="view === \'participants\'" :api-url="apiUrl + \'/\' + selected.id + \'/participants\'" :cancel-url="apiUrl.replace(/editions$/, \'participations\')" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :primary-locale="primaryLocale" :ui-locale="uiLocale" :options="participantOptions" :edition-url="apiUrl + \'/\' + selected.id" :threshold="attendanceThreshold" :i18n="i18n" @back="backToList"></conference-participants-register>\
	<conference-payment-settings v-else-if="view === \'payments\'" :api-url="apiUrl + \'/\' + selected.id + \'/payment-rules\'" :templates-url="apiUrl.replace(/editions$/, \'invoice-templates\')" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :locales="locales" :primary-locale="primaryLocale" :i18n="i18n" @back="backToList"></conference-payment-settings>\
	<conference-proof-queue v-else-if="view === \'proofs\'" :api-url="apiUrl.replace(/editions$/, \'proofs\')" :edition-id="selected.id" :file-url="proofFileUrl" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-proof-queue>\
	<conference-bulk-decisions v-else-if="view === \'decisions\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :edition-id="selected.id" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :presentation-types="options.presentationTypes || []" :i18n="i18n" @back="backToList"></conference-bulk-decisions>\
	<conference-edition-dashboard v-else-if="view === \'dashboard\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :edition-id="selected.id" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-edition-dashboard>\
	<conference-campaigns v-else-if="view === \'campaigns\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :edition-id="selected.id" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :ui-locale="uiLocale" :submission-mode="selected.submissionMode" :options="participantOptions" :i18n="i18n" @back="backToList"></conference-campaigns>\
	<conference-reminders v-else-if="view === \'reminders\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :csrf-token="csrfToken" :can-edit="canEditEditions" :i18n="i18n" @back="backToList"></conference-reminders>\
	<conference-certificates v-else-if="view === \'certificates\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :edition-id="selected.id" :edition-name="localized(selected.name)" :pdf-url="certificatePdfUrl" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-certificates>\
	<conference-certificate-templates v-else-if="view === \'certificateTemplates\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-certificate-templates>\
	<conference-setup-wizard v-else-if="view === \'setup\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :csrf-token="csrfToken" :locales="locales" :primary-locale="primaryLocale" :ui-locale="uiLocale" :default-timezone="defaultTimezone" :i18n="i18n" @back="backToList" @created="setupCreated"></conference-setup-wizard>\
	<conference-program-builder v-else-if="view === \'program\'" :api-url="apiUrl + \'/\' + selected.id" :public-url="publicLink(selected) + \'/program\'" :attendance-url="publicUrl.replace(\'__path__\', \'sessionAttendance\') + \'/\' + selected.id" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :locales="locales" :primary-locale="primaryLocale" :i18n="i18n" @back="backToList"></conference-program-builder>\
	<conference-review-settings v-else-if="view === \'review\'" :api-url="apiUrl + \'/\' + selected.id" :forms-url="reviewFormsUrl" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-review-settings>\
	<conference-partners v-else-if="view === \'partners\'" :api-url="apiUrl + \'/\' + selected.id" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :locales="locales" :primary-locale="primaryLocale" :i18n="i18n" @back="backToList"></conference-partners>\
	<conference-bidding v-else-if="view === \'bids\'" :api-url="apiUrl + \'/\' + selected.id" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-bidding>\
	<conference-promos v-else-if="view === \'promos\'" :api-url="apiUrl + \'/\' + selected.id" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-promos>\
	<conference-abstract-book v-else-if="view === \'book\'" :api-url="apiUrl + \'/\' + selected.id" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-abstract-book>\
	<conference-legacy-urls v-else-if="view === \'legacy\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-legacy-urls>\
	<conference-personal-data v-else-if="view === \'privacy\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-personal-data>\
	<conference-audit-log v-else-if="view === \'audit\'" :api-url="apiUrl.replace(/.editions$/, \'\')" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-audit-log>\
	<conference-invoices-list v-else-if="view === \'invoices\'" :api-url="apiUrl + \'/\' + selected.id + \'/invoices\'" :invoice-api-url="apiUrl.replace(/editions$/, \'invoices\')" :pdf-url="invoicePdfUrl" :edition-name="localized(selected.name)" :csrf-token="csrfToken" :i18n="i18n" @back="backToList"></conference-invoices-list>\
	<form v-else class="cmEditions__form" @submit.prevent="save" novalidate>\
		<div v-if="errorCount" class="cmEditions__errors" role="alert">\
			<strong>{{ t(\'errors\') }}</strong>\
			<ul><li v-for="(msgs, field) in errors" :key="field">{{ fieldLabel(field) }}: {{ msgs.join(\' \') }}</li></ul>\
		</div>\
		<div class="cmEditions__locales" v-if="locales.length > 1">\
			<button type="button" v-for="l in locales" :key="l.key" class="cmEditions__locale" :class="{ \'cmEditions__locale--active\': formLocale === l.key }" @click="formLocale = l.key">{{ l.label }}<span v-if="l.key === primaryLocale"> *</span></button>\
		</div>\
		<fieldset class="cmEditions__section">\
			<legend>{{ t(\'section.main\') }}</legend>\
			<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.name }">\
				<span>{{ t(\'field.name\') }} ({{ formLocale }})</span>\
				<input class="pkpFormField__input" type="text" v-model="form.name[formLocale]" maxlength="500">\
				<em v-if="errors.name">{{ fieldError(\'name\') }}</em>\
			</label>\
			<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.path }">\
				<span>{{ t(\'field.path\') }}</span>\
				<input class="pkpFormField__input" type="text" v-model="form.path" maxlength="64" pattern="[a-z0-9][a-z0-9_-]*">\
				<small>{{ t(\'field.path.description\') }}</small>\
				<em v-if="errors.path">{{ fieldError(\'path\') }}</em>\
			</label>\
			<div class="cmEditions__row">\
				<label class="cmEditions__field"><span>{{ t(\'field.eventType\') }}</span>\
					<select class="pkpFormField__input" v-model="form.eventType" @change="onTypeChange">\
						<option v-for="o in options.eventTypes" :key="o.value" :value="o.value">{{ o.label }}</option>\
					</select></label>\
				<label class="cmEditions__field"><span>{{ t(\'field.format\') }}</span>\
					<select class="pkpFormField__input" v-model="form.format">\
						<option v-for="o in options.formats" :key="o.value" :value="o.value">{{ o.label }}</option>\
					</select></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.status }"><span>{{ t(\'field.status\') }}</span>\
					<select class="pkpFormField__input" v-model="form.status">\
						<option v-for="o in allowedStatuses" :key="o.value" :value="o.value">{{ o.label }}</option>\
					</select><em v-if="errors.status">{{ fieldError(\'status\') }}</em></label>\
			</div>\
			<label class="cmEditions__field"><span>{{ t(\'field.venue\') }} ({{ formLocale }})</span>\
				<input class="pkpFormField__input" type="text" v-model="form.venue[formLocale]" maxlength="500"></label>\
			<label class="cmEditions__field"><span>{{ t(\'field.description\') }} ({{ formLocale }})</span>\
				<textarea class="pkpFormField__input" rows="5" v-model="form.description[formLocale]"></textarea></label>\
		</fieldset>\
		<fieldset class="cmEditions__section">\
			<legend>{{ t(\'section.modes\') }}</legend>\
			<div class="cmEditions__row">\
				<label class="cmEditions__field"><span>{{ t(\'field.submissionMode\') }}</span>\
					<select class="pkpFormField__input" v-model="form.submissionMode">\
						<option v-for="o in options.submissionModes" :key="o.value" :value="o.value">{{ o.label }}</option>\
					</select></label>\
				<label class="cmEditions__field"><span>{{ t(\'field.reviewMode\') }}</span>\
					<select class="pkpFormField__input" v-model="form.reviewMode" :disabled="form.submissionMode === \'none\'">\
						<option v-for="o in options.reviewModes" :key="o.value" :value="o.value">{{ o.label }}</option>\
					</select></label>\
				<label class="cmEditions__field"><span>{{ t(\'field.publicationMode\') }}</span>\
					<select class="pkpFormField__input" v-model="form.publicationMode">\
						<option v-for="o in options.publicationModes" :key="o.value" :value="o.value">{{ o.label }}</option>\
					</select></label>\
			</div>\
			<label v-if="form.publicationMode === \'external\'" class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.externalProceedingsUrl }">\
				<span>{{ t(\'field.externalProceedingsUrl\') }}</span>\
				<input class="pkpFormField__input" type="url" v-model="form.externalProceedingsUrl" placeholder="https://">\
				<em v-if="errors.externalProceedingsUrl">{{ fieldError(\'externalProceedingsUrl\') }}</em></label>\
			<p v-if="form.publicationMode === \'ojs_issue\' && form.issueId" class="cmEditions__note">{{ t(\'issue\') }} #{{ form.issueId }}</p>\
		</fieldset>\
		<fieldset class="cmEditions__section">\
			<legend>{{ t(\'section.dates\') }}</legend>\
			<p class="cmEditions__note">{{ t(\'field.dates.description\') }}</p>\
			<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.timezone }"><span>{{ t(\'field.timezone\') }}</span>\
				<input class="pkpFormField__input" type="text" list="cmEditionsTimezones" v-model="form.timezone">\
				<datalist id="cmEditionsTimezones"><option v-for="tz in timezones" :key="tz" :value="tz"></option></datalist>\
				<em v-if="errors.timezone">{{ fieldError(\'timezone\') }}</em></label>\
			<div class="cmEditions__grid">\
				<label v-for="f in dateFields" :key="f" class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors[f] }"\
					v-show="form.submissionMode !== \'none\' || [\'cfpOpensAt\', \'abstractDeadline\', \'paperDeadline\'].indexOf(f) === -1">\
					<span>{{ t(\'field.\' + f) }}</span>\
					<input class="pkpFormField__input" type="datetime-local" v-model="form.local[f]">\
					<em v-if="errors[f]">{{ fieldError(f) }}</em>\
				</label>\
			</div>\
		</fieldset>\
		<fieldset class="cmEditions__section">\
			<legend>{{ t(\'section.texts\') }}</legend>\
			<label class="cmEditions__field" v-if="form.submissionMode !== \'none\'"><span>{{ t(\'field.cfpText\') }} ({{ formLocale }})</span>\
				<textarea class="pkpFormField__input" rows="6" v-model="form.cfpText[formLocale]"></textarea></label>\
			<label class="cmEditions__field"><span>{{ t(\'field.learningOutcomes\') }} ({{ formLocale }})</span>\
				<textarea class="pkpFormField__input" rows="4" v-model="form.learningOutcomes[formLocale]"></textarea></label>\
			<label class="cmEditions__field"><span>{{ t(\'field.partialQualifications\') }} ({{ formLocale }})</span>\
				<textarea class="pkpFormField__input" rows="2" v-model="form.partialQualifications[formLocale]"></textarea></label>\
		</fieldset>\
		<fieldset class="cmEditions__section">\
			<legend>{{ t(\'section.program\') }}</legend>\
			<p class="cmEditions__help">{{ t(\'program.help\') }}</p>\
			<label class="cmEditions__check"><input type="checkbox" v-model="form.programPublished"> {{ t(\'field.programPublished\') }}</label>\
			<label class="cmEditions__field"><span>{{ t(\'field.programText\') }} ({{ formLocale }})</span>\
				<textarea class="pkpFormField__input" rows="4" v-model="form.programText[formLocale]"></textarea></label>\
			<p v-if="editingId !== null && form.path"><a :href="publicUrl.replace(\'__path__\', encodeURIComponent(form.path)) + \'/program\'" target="_blank" rel="noopener">{{ t(\'program.open\') }}</a></p>\
		</fieldset>\
		<fieldset class="cmEditions__section">\
			<legend>{{ t(\'section.details\') }}</legend>\
			<div class="cmEditions__grid">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.isbn }"><span>{{ t(\'field.isbn\') }}</span>\
					<input class="pkpFormField__input" type="text" v-model="form.isbn" placeholder="978-…"><em v-if="errors.isbn">{{ fieldError(\'isbn\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.udc }"><span>{{ t(\'field.udc\') }}</span>\
					<input class="pkpFormField__input" type="text" v-model="form.udc" maxlength="32"></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.vectorProgramId }"><span>{{ t(\'field.vectorProgramId\') }}</span>\
					<input class="pkpFormField__input" type="text" v-model="form.vectorProgramId" maxlength="64"></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.hours }"><span>{{ t(\'field.hours\') }}</span>\
					<input class="pkpFormField__input" type="number" min="0" step="1" v-model="form.hours"><em v-if="errors.hours">{{ fieldError(\'hours\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.ectsX100 }"><span>{{ t(\'field.ects\') }}</span>\
					<input class="pkpFormField__input" type="number" min="0" step="0.01" v-model="form.ects"><em v-if="errors.ectsX100">{{ fieldError(\'ectsX100\') }}</em></label>\
			</div>\
		</fieldset>\
		<fieldset v-if="editingId === null && copyFromId !== null" class="cmEditions__section cmCopyParts">\
			<legend>{{ t(\'copy.partsTitle\') }}</legend>\
			<p class="cmPanel__muted">{{ t(\'copy.partsHelp\') }}</p>\
			<label v-for="part in [\'fees\', \'paymentRules\', \'rooms\', \'settings\', \'partners\']" :key="part" class="cmCopyParts__item"><input type="checkbox" :value="part" v-model="copyParts"> {{ t(\'copy.part.\' + part) }}</label>\
		</fieldset>\
		<div class="cmEditions__buttons">\
			<pkp-button :is-primary="true" :disabled="isSaving" @click="save">{{ t(\'save\') }}</pkp-button>\
			<pkp-button :is-warnable="true" @click="cancel">{{ t(\'cancel\') }}</pkp-button>\
			<spinner v-if="isSaving"></spinner>\
		</div>\
	</form>\
</div>',
	});
})();
