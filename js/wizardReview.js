/**
 * Review of the "Conference participation" step in the submission wizard
 * (REG-02). Shows the current values of the step's form and the errors
 * that OJS returns when it checks the submission.
 */
(function () {
	if (!window.pkp || !pkp.registry) {
		return;
	}
	var CONSENT = 'conferenceConsent';
	var EDITION = 'conferenceEditionId';
	var MINOR = 'conferencePresenterMinor';
	var GUARDIAN = 'conferenceGuardianConsent';

	function isEmpty(value) {
		return value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length);
	}

	/*
	 * A link to a step (submission?id=5#conference) should open that step.
	 * The OJS 3.5 wizard opens the first step when the address names a
	 * step and writes that step into the address before reading it, so
	 * every such link opened "Details". This script runs before the wizard,
	 * keeps the step from the address and asks for it again once the
	 * wizard is ready: the wizard follows changes of the address itself.
	 */
	var wantedStep = (window.location.hash || '').replace(/^#/, '');
	if (/^[A-Za-z0-9_-]+$/.test(wantedStep)) {
		var tries = 0;
		var reopen = function () {
			var now = (window.location.hash || '').replace(/^#/, '');
			if (now === wantedStep) {
				return;
			}
			if (now) {
				window.location.hash = wantedStep;
			} else if (++tries < 40) {
				setTimeout(reopen, 100);
			}
		};
		if (document.readyState === 'complete') {
			setTimeout(reopen, 0);
		} else {
			window.addEventListener('load', function () {
				setTimeout(reopen, 0);
			});
		}
	}

	/*
	 * The OJS wizard sends a step's form only when the author leaves the
	 * step or presses "Save for later". An author who closes the tab or
	 * leaves through the menu lost the answers of this step, while the
	 * prefilled ones came back. So the answers are also sent as soon as
	 * they change (the same request the wizard makes).
	 */
	var config = window.conferenceManagerWizard || {};
	if (config.saveUrl && window.fetch && window.URLSearchParams) {
		var timer = null;
		var formOf = function (el) {
			return el && el.closest ? el.closest('form') : null;
		};
		var save = function () {
			var form = formOf(document.querySelector('select[name="' + EDITION + '"]'));
			if (!form) {
				return;
			}
			var body = new URLSearchParams();
			form.querySelectorAll('input[name^="conference"], select[name^="conference"]').forEach(function (el) {
				if (el.type === 'radio') {
					if (el.checked) {
						body.set(el.name, el.value);
					}
				} else if (el.type === 'checkbox') {
					// One box per field; an unticked one is sent empty, as the wizard does.
					if (el.checked) {
						body.append(el.name + '[]', el.value);
					} else {
						body.set(el.name, '');
					}
				} else {
					body.set(el.name, el.value);
				}
			});
			if (!body.has(EDITION)) {
				return;
			}
			fetch(config.saveUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-Csrf-Token': config.csrfToken, 'X-Http-Method-Override': 'PUT' },
				body: body,
			}).catch(function () {});
		};
		var changed = function (e) {
			var el = e.target;
			if (!el || !el.name || el.name.indexOf('conference') !== 0
				|| formOf(el) !== formOf(document.querySelector('select[name="' + EDITION + '"]'))) {
				return;
			}
			clearTimeout(timer);
			timer = setTimeout(save, 800);
		};
		document.addEventListener('change', changed, true);
	}

	pkp.registry.registerComponent('ConferenceRegistrationReview', {
		name: 'ConferenceRegistrationReview',
		props: {
			steps: {type: Array, default: function () { return []; }},
			errors: {type: Object, default: function () { return {}; }},
			stepId: {type: String, default: 'conference'},
			noneLabel: {type: String, default: ''},
			consentGiven: {type: String, default: ''},
			consentMissing: {type: String, default: ''}
		},
		computed: {
			rows: function () {
				var self = this;
				var step = (this.steps || []).find(function (s) { return s.id === self.stepId; });
				var section = step && step.sections && step.sections[0];
				var fields = (section && section.form && section.form.fields) || [];
				var edition = fields.find(function (f) { return f.name === EDITION; });
				var hasEdition = edition && !isEmpty(edition.value);
				var minor = fields.find(function (f) { return f.name === MINOR; });
				var isMinor = minor && !isEmpty(minor.value);
				return fields
					.filter(function (f) { return f.component !== 'field-html' && (f.name === EDITION || hasEdition); })
					// EVT-04: shown only for a presenter under 18, or when OJS reports an error.
					.filter(function (f) { return (f.name !== MINOR && f.name !== GUARDIAN) || isMinor || ((self.errors || {})[f.name] || []).length; })
					.map(function (f) {
						var display;
						if (f.name === CONSENT) {
							display = isEmpty(f.value) ? self.consentMissing : self.consentGiven;
						} else if (isEmpty(f.value)) {
							display = self.noneLabel;
						} else if (Array.isArray(f.options)) {
							var option = f.options.find(function (o) { return String(o.value) === String(f.value); });
							display = option ? option.label : String(f.value);
						} else {
							display = String(f.value);
						}
						return {name: f.name, label: f.label, display: display, errors: (self.errors || {})[f.name] || []};
					});
			}
		},
		template:
			'<div>' +
			'<div v-for="row in rows" :key="row.name" class="submissionWizard__reviewPanel__item">' +
			'<notification v-for="(error, i) in row.errors" :key="i" type="warning">' +
			'<icon icon="Error" class="h-5 w-5"></icon> {{ error }}' +
			'</notification>' +
			'<h4 class="submissionWizard__reviewPanel__item__header">{{ row.label }}</h4>' +
			'<div class="submissionWizard__reviewPanel__item__value">{{ row.display }}</div>' +
			'</div>' +
			'</div>'
	});
})();
