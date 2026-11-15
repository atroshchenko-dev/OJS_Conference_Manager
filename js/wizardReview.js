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
