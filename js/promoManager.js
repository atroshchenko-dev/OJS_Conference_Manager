/**
 * Seats and promo codes of an edition, opened from the editions page:
 * the number of in-person listener seats with the waiting list (REG-12)
 * and the promo codes with their uses (REG-11).
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	/**
	 * A date or local date-time of a form field ("2027-01-15" or
	 * "2027-01-15T09:30") in the language of the page; the time only when
	 * it is not midnight.
	 */
	function localDate(value) {
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(String(value || ''));
		if (!m) {
			return value ? String(value) : '';
		}
		var withTime = m[4] !== undefined && (m[4] !== '00' || m[5] !== '00');
		var d = new Date(+m[1], +m[2] - 1, +m[3], withTime ? +m[4] : 0, withTime ? +m[5] : 0);
		var options = withTime
			? { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }
			: { day: 'numeric', month: 'long', year: 'numeric' };
		return d.toLocaleString(document.documentElement.lang || undefined, options);
	}

	if (!window.pkp || !pkp.registry) {
		return;
	}

	function notify(message, type) {
		if (window.pkp && pkp.eventBus) {
			pkp.eventBus.$emit('notify', message, type || 'success');
		}
	}

	pkp.registry.registerComponent('ConferencePromos', {
		name: 'ConferencePromos',
		props: {
			/** .../conference/editions/{id} */
			apiUrl: { type: String, required: true },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return { isLoading: true, busy: false, data: null, form: null, errors: {}, seats: '', seatErrors: [], confirmDelete: null };
		},
		mounted: function () {
			this.load();
		},
		computed: {
			codes: function () {
				return this.data ? this.data.codes : [];
			},
			queue: function () {
				return this.data ? this.data.queue : [];
			},
		},
		methods: {
			/** A UTC time stamp of the server ("2027-01-15 09:30:00") in the language of the page. */
			stamp: function (value) {
				var d = value ? new Date(String(value).replace(' ', 'T').slice(0, 19) + 'Z') : null;
				return d && !isNaN(d.getTime()) ? d.toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' }) : (value || '');
			},
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
			take: function (data) {
				this.data = data;
				this.seats = data.seats === null ? '' : String(data.seats);
			},
			load: function () {
				var self = this;
				self.call('GET', self.apiUrl + '/promo-codes').then(function (r) {
					self.isLoading = false;
					r.ok ? self.take(r.data) : notify(r.data.errorMessage || '', 'warning');
				});
			},
			saveSeats: function () {
				var self = this;
				self.busy = true;
				self.call('PUT', self.apiUrl + '/seats', { seats: self.seats === '' ? null : self.seats }).then(function (r) {
					self.busy = false;
					if (!r.ok) {
						self.seatErrors = r.data.seats || [r.data.errorMessage || ''];
						return;
					}
					self.seatErrors = [];
					self.take(r.data);
					notify(r.data.given && r.data.given.length ? self.t('seats.given', { n: r.data.given.length }) : self.t('saved'));
				});
			},
			startNew: function () {
				this.form = { id: null, code: '', discountType: 'percent', percent: '', amount: '', currency: this.data.currencies[0], maxUses: '', validFrom: '', validUntil: '', active: true, note: '' };
				this.errors = {};
				this.focusForm();
			},
			startEdit: function (c) {
				this.form = {
					id: c.id, code: c.code, discountType: c.discountType, percent: c.percent === null ? '' : String(c.percent),
					amount: c.amount ? c.amount.decimal : '', currency: c.amount ? c.amount.currency : this.data.currencies[0],
					maxUses: c.maxUses === null ? '' : String(c.maxUses), validFrom: c.validFrom || '', validUntil: c.validUntil || '',
					active: c.active, note: c.note || '', used: c.uses > 0,
				};
				this.errors = {};
				this.focusForm();
			},
			focusForm: function () {
				var self = this;
				self.$nextTick(function () {
					var el = self.$el.querySelector('#cmPromoCodeField');
					if (el) {
						el.focus();
					}
				});
			},
			save: function () {
				var self = this;
				var f = self.form;
				self.busy = true;
				self.call(f.id === null ? 'POST' : 'PUT', self.apiUrl + '/promo-codes' + (f.id === null ? '' : '/' + f.id), f).then(function (r) {
					self.busy = false;
					if (!r.ok) {
						self.errors = r.data || {};
						return;
					}
					self.take(r.data);
					self.form = null;
					notify(self.t('saved'));
				});
			},
			remove: function (c) {
				var self = this;
				if (self.confirmDelete !== c.id) {
					self.confirmDelete = c.id;
					return;
				}
				self.call('DELETE', self.apiUrl + '/promo-codes/' + c.id).then(function (r) {
					self.confirmDelete = null;
					if (r.ok) {
						self.take(r.data);
						notify(self.t('deleted'));
					} else {
						notify((r.data.code || [r.data.errorMessage || '']).join(' '), 'warning');
					}
				});
			},
			period: function (c) {
				if (!c.validFrom && !c.validUntil) {
					return this.t('promo.always');
				}
				return (localDate(c.validFrom) || '…') + ' – ' + (localDate(c.validUntil) || '…');
			},
			fieldError: function (field) {
				return (this.errors[field] || []).join(' ');
			},
		},
		template: '\
<div class="cmEditions cmPromos">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'promo.title\') }}<span v-if="editionName">: {{ editionName }}</span></h2>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else-if="data">\
		<h3>{{ t(\'seats.title\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'seats.help\') }}</p>\
		<form class="cmPromos__seats" @submit.prevent="saveSeats">\
			<div class="cmFees__field">\
				<label for="cmSeats">{{ t(\'seats.field\') }}</label>\
				<input id="cmSeats" type="number" min="0" step="1" v-model="seats" :placeholder="t(\'seats.unlimited\')" aria-describedby="cmSeatsState">\
				<p v-if="seatErrors.length" class="cmPartners__error" role="alert">{{ seatErrors.join(\' \') }}</p>\
			</div>\
			<pkp-button :disabled="busy" @click="saveSeats">{{ t(\'save\') }}</pkp-button>\
		</form>\
		<p id="cmSeatsState" class="cmEditions__note" role="status">{{ data.seats === null ? t(\'seats.noLimit\', { taken: data.taken }) : t(\'seats.state\', { taken: data.taken, seats: data.seats, queue: queue.length }) }}</p>\
		<template v-if="queue.length">\
			<h4>{{ t(\'waitlist.title\') }}</h4>\
			<ol class="cmPromos__queue">\
				<li v-for="w in queue" :key="w.participationId">{{ (w.givenName + \' \' + w.familyName).trim() || w.email }} <span class="cmEditions__path">{{ w.email }} · {{ stamp(w.waitlistedAt) }}</span></li>\
			</ol>\
		</template>\
		<h3>{{ t(\'promo.codes\') }}</h3>\
		<p class="cmEditions__note">{{ t(\'promo.help\') }}</p>\
		<p v-if="!codes.length" class="cmEditions__note">{{ t(\'promo.empty\') }}</p>\
		<table v-else class="cmEditions__table cmPromos__table">\
			<thead><tr><th>{{ t(\'promo.code\') }}</th><th>{{ t(\'promo.discount\') }}</th><th>{{ t(\'promo.uses\') }}</th><th>{{ t(\'promo.period\') }}</th><th>{{ t(\'promo.state\') }}</th><th><span class="cmVisuallyHidden">{{ t(\'promo.actions\') }}</span></th></tr></thead>\
			<tbody><tr v-for="c in codes" :key="c.id">\
				<td><strong>{{ c.code }}</strong><div v-if="c.note" class="cmEditions__path">{{ c.note }}</div></td>\
				<td>{{ c.discount }}</td>\
				<td>{{ c.maxUses === null ? c.uses : t(\'promo.usesOf\', { uses: c.uses, max: c.maxUses }) }}</td>\
				<td>{{ period(c) }}</td>\
				<td>{{ c.active ? t(\'promo.on\') : t(\'promo.off\') }}</td>\
				<td><div class="cmEditions__buttons">\
					<pkp-button @click="startEdit(c)">{{ t(\'edit\') }}</pkp-button>\
					<pkp-button v-if="!c.uses" :is-warnable="true" @click="remove(c)">{{ confirmDelete === c.id ? t(\'promo.deleteConfirm\') : t(\'delete\') }}</pkp-button>\
				</div></td>\
			</tr></tbody>\
		</table>\
		<pkp-button v-if="!form && codes.length < data.max" :is-primary="true" @click="startNew">{{ t(\'promo.add\') }}</pkp-button>\
		<form v-if="form" class="cmPartners__form" @submit.prevent="save">\
			<h4>{{ form.id === null ? t(\'promo.add\') : t(\'edit\') + \': \' + form.code }}</h4>\
			<p v-if="form.used" class="cmEditions__note">{{ t(\'promo.usedHelp\') }}</p>\
			<div class="cmFees__field">\
				<label for="cmPromoCodeField">{{ t(\'promo.code\') }} *</label>\
				<input id="cmPromoCodeField" type="text" maxlength="32" v-model="form.code" :disabled="form.used" required aria-describedby="cmPromoCodeHelp">\
				<p id="cmPromoCodeHelp" class="cmEditions__note">{{ t(\'promo.codeHelp\') }}</p>\
				<p v-if="fieldError(\'code\')" class="cmPartners__error" role="alert">{{ fieldError(\'code\') }}</p>\
			</div>\
			<fieldset class="cmFees__field">\
				<legend>{{ t(\'promo.discount\') }}</legend>\
				<label><input type="radio" value="percent" v-model="form.discountType" :disabled="form.used"> {{ t(\'promo.percentType\') }}</label>\
				<label><input type="radio" value="amount" v-model="form.discountType" :disabled="form.used"> {{ t(\'promo.amountType\') }}</label>\
			</fieldset>\
			<div v-if="form.discountType === \'percent\'" class="cmFees__field">\
				<label for="cmPromoPercent">{{ t(\'promo.percent\') }}</label>\
				<input id="cmPromoPercent" type="number" min="1" max="100" v-model="form.percent" :disabled="form.used">\
				<p v-if="fieldError(\'percent\')" class="cmPartners__error" role="alert">{{ fieldError(\'percent\') }}</p>\
			</div>\
			<div v-else class="cmFees__field">\
				<label for="cmPromoAmount">{{ t(\'promo.amount\') }}</label>\
				<input id="cmPromoAmount" type="text" inputmode="decimal" v-model="form.amount" :disabled="form.used">\
				<label for="cmPromoCurrency" class="cmVisuallyHidden">{{ t(\'promo.currency\') }}</label>\
				<select id="cmPromoCurrency" v-model="form.currency" :disabled="form.used"><option v-for="c in data.currencies" :key="c" :value="c">{{ c }}</option></select>\
				<p v-if="fieldError(\'amount\')" class="cmPartners__error" role="alert">{{ fieldError(\'amount\') }}</p>\
			</div>\
			<div class="cmFees__field">\
				<label for="cmPromoMax">{{ t(\'promo.maxUses\') }}</label>\
				<input id="cmPromoMax" type="number" min="1" v-model="form.maxUses" :placeholder="t(\'promo.unlimited\')">\
				<p v-if="fieldError(\'maxUses\')" class="cmPartners__error" role="alert">{{ fieldError(\'maxUses\') }}</p>\
			</div>\
			<div class="cmFees__field">\
				<label for="cmPromoFrom">{{ t(\'promo.validFrom\') }}</label>\
				<input id="cmPromoFrom" type="date" v-model="form.validFrom">\
				<label for="cmPromoUntil">{{ t(\'promo.validUntil\') }}</label>\
				<input id="cmPromoUntil" type="date" v-model="form.validUntil">\
				<p v-if="fieldError(\'validFrom\') || fieldError(\'validUntil\')" class="cmPartners__error" role="alert">{{ fieldError(\'validFrom\') }} {{ fieldError(\'validUntil\') }}</p>\
			</div>\
			<div class="cmFees__field">\
				<label for="cmPromoNote">{{ t(\'promo.note\') }}</label>\
				<input id="cmPromoNote" type="text" maxlength="255" v-model="form.note">\
			</div>\
			<label class="cmFees__field"><input type="checkbox" v-model="form.active"> {{ t(\'promo.active\') }}</label>\
			<div class="cmEditions__buttons">\
				<pkp-button :is-primary="true" :disabled="busy" @click="save">{{ t(\'save\') }}</pkp-button>\
				<pkp-button @click="form = null">{{ t(\'cancel\') }}</pkp-button>\
			</div>\
		</form>\
	</template>\
</div>',
	});
})();
