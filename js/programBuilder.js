/**
 * Program builder of an edition (PRG-02, PRG-03): rooms, sessions and
 * accepted papers moved between sessions by drag and drop, or with the
 * "Move to" list for keyboard users. Conflicts are marked in place.
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	if (!window.pkp || !pkp.registry) {
		return;
	}

	function blankSession(locales, start) {
		var title = {};
		var description = {};
		var outcomes = {};
		locales.forEach(function (l) { title[l.key] = ''; description[l.key] = ''; outcomes[l.key] = ''; });
		return {
			id: null, type: 'section', roomId: '', startsAt: start || '', endsAt: '', slotMinutes: 15, chairs: [],
			streamUrl: '', streamVisibleFrom: '', hours: '', ects: '', title: title, description: description, learningOutcomes: outcomes,
		};
	}

	pkp.registry.registerComponent('ConferenceProgramBuilder', {
		name: 'ConferenceProgramBuilder',
		props: {
			/** .../conference/editions/{id} */
			apiUrl: { type: String, required: true },
			publicUrl: { type: String, default: '' },
			/** .../conference/sessionAttendance/{editionId}: presence at a session (ATT-01) */
			attendanceUrl: { type: String, default: '' },
			editionName: { type: String, default: '' },
			csrfToken: { type: String, required: true },
			locales: { type: Array, required: true },
			primaryLocale: { type: String, required: true },
			i18n: { type: Object, required: true },
		},
		emits: ['back'],
		data: function () {
			return {
				isLoading: true, busy: false, data: null, errors: {},
				roomForm: null, sessionForm: null, formLocale: this.primaryLocale,
				dragging: null, dropTarget: null, peopleFilter: '',
			};
		},
		computed: {
			days: function () {
				var days = [];
				var map = {};
				(this.data ? this.data.sessions : []).forEach(function (s) {
					var day = (s.local.startsAt || '').slice(0, 10);
					if (!map[day]) {
						map[day] = { day: day, sessions: [] };
						days.push(map[day]);
					}
					map[day].sessions.push(s);
				});
				return days;
			},
			roomNames: function () {
				var map = {};
				(this.data ? this.data.rooms : []).forEach(function (r) { map[r.id] = r.name; });
				return map;
			},
			typeLabels: function () {
				var map = {};
				(this.data ? this.data.types : []).forEach(function (t) { map[t.value] = t.label; });
				return map;
			},
			peopleNames: function () {
				var map = {};
				(this.data ? this.data.people : []).forEach(function (p) { map[p.id] = p.name; });
				return map;
			},
			paperSessions: function () {
				return this.data ? this.data.sessions.filter(function (s) { return s.type !== 'break'; }) : [];
			},
			filteredPeople: function () {
				var q = this.peopleFilter.trim().toLowerCase();
				var chosen = this.sessionForm ? this.sessionForm.chairs : [];
				return (this.data ? this.data.people : []).filter(function (p) {
					return chosen.indexOf(p.id) === -1 && (!q || p.name.toLowerCase().indexOf(q) !== -1);
				}).slice(0, 50);
			},
		},
		mounted: function () {
			this.load();
		},
		methods: {
			/** ATT-01: the attendance page of the session (a page of the journal, not of the backend). */
			openAttendance: function (s) {
				window.location.href = this.attendanceUrl + '/' + s.id;
			},
			t: function (key, params) {
				var s = this.i18n[key] || key;
				Object.keys(params || {}).forEach(function (k) { s = s.split('{$' + k + '}').join(params[k]); });
				return s;
			},
			notify: function (message, type) {
				if (window.pkp && pkp.eventBus) {
					pkp.eventBus.$emit('notify', message, type || 'success');
				}
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
			fail: function (r) {
				if (r.status === 400) {
					this.errors = r.data || {};
					this.notify(this.t('program.fix'), 'warning');
				} else {
					this.notify((r.data && r.data.errorMessage) || this.t('errors'), 'warning');
				}
			},
			fieldError: function (field) {
				var e = this.errors[field];
				return Array.isArray(e) ? e.join(' ') : e || '';
			},
			load: function () {
				var self = this;
				return self.call('GET', self.apiUrl + '/program-builder').then(function (r) {
					self.isLoading = false;
					r.ok ? (self.data = r.data) : self.fail(r);
				});
			},
			time: function (local) {
				return local ? local.slice(11, 16) : '';
			},
			dayLabel: function (day) {
				var d = new Date(day + 'T12:00:00');
				return isNaN(d) ? day : d.toLocaleDateString(document.documentElement.lang || undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
			},
			text: function (value) {
				return value ? value[this.primaryLocale] || Object.values(value)[0] || '' : '';
			},
			// Rooms
			newRoom: function () {
				this.errors = {};
				this.roomForm = { id: null, name: '', capacity: '', onlineUrl: '' };
			},
			editRoom: function (room) {
				this.errors = {};
				this.roomForm = { id: room.id, name: room.name, capacity: room.capacity === null ? '' : room.capacity, onlineUrl: room.onlineUrl || '' };
			},
			saveRoom: function () {
				var self = this;
				var f = self.roomForm;
				self.busy = true;
				self.call(f.id ? 'PUT' : 'POST', self.apiUrl + '/rooms' + (f.id ? '/' + f.id : ''), f).then(function (r) {
					self.busy = false;
					if (r.ok) {
						self.roomForm = null;
						self.errors = {};
						self.notify(self.t('program.saved'));
						self.load();
					} else {
						self.fail(r);
					}
				});
			},
			deleteRoom: function (room) {
				var self = this;
				if (!window.confirm(self.t('program.room.deleteConfirm', { name: room.name }))) {
					return;
				}
				self.call('DELETE', self.apiUrl + '/rooms/' + room.id).then(function (r) {
					r.ok ? self.load() : self.fail(r);
				});
			},
			// Sessions
			newSession: function (day) {
				this.errors = {};
				var start = day ? day + 'T10:00' : (this.data.edition.startsAt || '');
				this.sessionForm = blankSession(this.locales, start);
				this.formLocale = this.primaryLocale;
			},
			editSession: function (s) {
				var f = blankSession(this.locales, '');
				f.id = s.id;
				f.type = s.type;
				f.roomId = s.roomId === null ? '' : s.roomId;
				f.startsAt = s.local.startsAt || '';
				f.endsAt = s.local.endsAt || '';
				f.slotMinutes = s.slotMinutes;
				f.chairs = s.chairs.slice();
				f.streamUrl = s.streamUrl || '';
				f.streamVisibleFrom = s.local.streamVisibleFrom || '';
				f.hours = s.hours === null ? '' : s.hours;
				f.ects = s.ectsX100 === null ? '' : String(s.ectsX100 / 100);
				['title', 'description', 'learningOutcomes'].forEach(function (field) {
					Object.keys(s[field] || {}).forEach(function (l) { f[field][l] = s[field][l]; });
				});
				this.errors = {};
				this.sessionForm = f;
				this.formLocale = this.primaryLocale;
			},
			saveSession: function () {
				var self = this;
				var f = self.sessionForm;
				var body = {
					type: f.type, roomId: f.roomId === '' ? null : f.roomId, startsAt: f.startsAt, endsAt: f.endsAt,
					slotMinutes: f.slotMinutes, chairs: f.chairs, streamUrl: f.streamUrl, streamVisibleFrom: f.streamVisibleFrom,
					hours: f.hours, ectsX100: f.ects === '' ? '' : String(Math.round(parseFloat(String(f.ects).replace(',', '.')) * 100)),
					title: f.title, description: f.description, learningOutcomes: f.learningOutcomes,
				};
				if (body.ectsX100 === 'NaN') {
					body.ectsX100 = f.ects;
				}
				self.busy = true;
				self.call(f.id ? 'PUT' : 'POST', self.apiUrl + '/sessions' + (f.id ? '/' + f.id : ''), body).then(function (r) {
					self.busy = false;
					if (r.ok) {
						self.sessionForm = null;
						self.errors = {};
						self.notify(self.t('program.saved'));
						self.load();
					} else {
						self.fail(r);
					}
				});
			},
			deleteSession: function (s) {
				var self = this;
				if (!window.confirm(self.t('program.session.deleteConfirm', { name: self.text(s.title) }))) {
					return;
				}
				self.call('DELETE', self.apiUrl + '/sessions/' + s.id).then(function (r) {
					r.ok ? self.load() : self.fail(r);
				});
			},
			addChair: function (id) {
				if (id && this.sessionForm.chairs.indexOf(id) === -1) {
					this.sessionForm.chairs.push(id);
				}
				this.peopleFilter = '';
			},
			removeChair: function (id) {
				this.sessionForm.chairs = this.sessionForm.chairs.filter(function (c) { return c !== id; });
			},
			otherSessions: function (session) {
				return this.paperSessions.filter(function (o) { return o.id !== session.id; });
			},
			// Papers
			move: function (submissionId, sessionId, position, durationMin) {
				var self = this;
				self.busy = true;
				var body = { submissionId: submissionId, sessionId: sessionId, position: position };
				if (durationMin !== undefined) {
					body.durationMin = durationMin;
				}
				return self.call('POST', self.apiUrl + '/program-moves', body).then(function (r) {
					self.busy = false;
					r.ok ? self.load() : self.fail(r);
				});
			},
			moveTo: function (paper, value) {
				if (value === '') {
					return;
				}
				var target = value === 'none' ? null : parseInt(value, 10);
				var session = this.data.sessions.filter(function (s) { return s.id === target; })[0];
				this.move(paper.submissionId, target, session ? session.paperItems.length : 0);
			},
			shift: function (session, index, delta) {
				var item = session.paperItems[index];
				this.move(item.submissionId, session.id, index + delta);
			},
			setDuration: function (session, index, value) {
				var item = session.paperItems[index];
				this.move(item.submissionId, session.id, index, value === '' ? '' : value);
			},
			dragStart: function (event, submissionId) {
				this.dragging = submissionId;
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData('text/plain', String(submissionId));
			},
			dragEnd: function () {
				this.dragging = null;
				this.dropTarget = null;
			},
			dragOver: function (event, key) {
				if (this.dragging === null) {
					return;
				}
				event.preventDefault();
				this.dropTarget = key;
			},
			drop: function (event, sessionId, position) {
				event.preventDefault();
				var id = this.dragging || parseInt(event.dataTransfer.getData('text/plain'), 10);
				this.dragEnd();
				if (id) {
					this.move(id, sessionId, position);
				}
			},
		},
		template: '\
<div class="cmEditions cmProgram">\
	<div class="cmEditions__toolbar">\
		<h2 class="cmFees__title">{{ t(\'program.builder.title\') }}: {{ editionName }}</h2>\
		<a v-if="publicUrl" class="pkpButton" :href="publicUrl" target="_blank" rel="noopener">{{ t(\'program.builder.preview\') }}</a>\
		<pkp-button @click="$emit(\'back\')">{{ t(\'back\') }}</pkp-button>\
	</div>\
	<div v-if="isLoading" class="cmEditions__loading"><spinner></spinner> {{ t(\'loading\') }}</div>\
	<template v-else-if="data">\
		<p class="cmProgram__help">{{ t(\'program.builder.help\', { zone: data.timezone }) }}</p>\
		<div v-if="data.conflicts.length" class="cmProgram__alert" role="status">\
			<strong>{{ t(\'program.conflicts\') }}</strong>\
			<ul><li v-for="c in data.conflicts" :key="c.participantId">{{ t(\'program.conflict\', { name: c.name, n: c.sessionIds.length }) }}</li></ul>\
		</div>\
		<details class="cmProgram__rooms" :open="!data.rooms.length || roomForm !== null">\
			<summary>{{ t(\'program.rooms\') }} ({{ data.rooms.length }})</summary>\
			<table v-if="data.rooms.length" class="cmEditions__table">\
				<thead><tr><th>{{ t(\'program.room.name\') }}</th><th>{{ t(\'program.room.capacity\') }}</th><th>{{ t(\'program.room.onlineUrl\') }}</th><th></th></tr></thead>\
				<tbody><tr v-for="r in data.rooms" :key="r.id">\
					<td>{{ r.name }}</td><td>{{ r.capacity === null ? \'\' : r.capacity }}</td><td class="cmProgram__url">{{ r.onlineUrl }}</td>\
					<td class="cmEditions__actions"><pkp-button @click="editRoom(r)">{{ t(\'edit\') }}</pkp-button><pkp-button :is-warnable="true" @click="deleteRoom(r)">{{ t(\'delete\') }}</pkp-button></td>\
				</tr></tbody>\
			</table>\
			<div v-if="roomForm" class="cmProgram__form">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.name }"><span>{{ t(\'program.room.name\') }} *</span>\
					<input class="pkpFormField__input" type="text" v-model="roomForm.name" maxlength="255"><em v-if="errors.name">{{ fieldError(\'name\') }}</em></label>\
				<div class="cmEditions__grid">\
					<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.capacity }"><span>{{ t(\'program.room.capacity\') }}</span>\
						<input class="pkpFormField__input" type="number" min="0" v-model="roomForm.capacity"><em v-if="errors.capacity">{{ fieldError(\'capacity\') }}</em></label>\
					<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.onlineUrl }"><span>{{ t(\'program.room.onlineUrl\') }}</span>\
						<input class="pkpFormField__input" type="url" v-model="roomForm.onlineUrl"><em v-if="errors.onlineUrl">{{ fieldError(\'onlineUrl\') }}</em></label>\
				</div>\
				<div class="cmSetup__buttons"><pkp-button :is-primary="true" :disabled="busy" @click="saveRoom">{{ t(\'save\') }}</pkp-button><pkp-button @click="roomForm = null">{{ t(\'cancel\') }}</pkp-button></div>\
			</div>\
			<pkp-button v-else @click="newRoom">{{ t(\'program.room.add\') }}</pkp-button>\
		</details>\
		<div v-if="sessionForm" class="cmProgram__form cmProgram__sessionForm">\
			<h3>{{ sessionForm.id ? t(\'program.session.edit\') : t(\'program.session.add\') }}</h3>\
			<div class="cmEditions__locales" v-if="locales.length > 1">\
				<button type="button" v-for="l in locales" :key="l.key" class="cmEditions__locale" :class="{ \'cmEditions__locale--active\': formLocale === l.key }" @click="formLocale = l.key">{{ l.label }}<span v-if="l.key === primaryLocale"> *</span></button>\
			</div>\
			<div class="cmEditions__grid">\
				<label class="cmEditions__field"><span>{{ t(\'program.session.type\') }}</span>\
					<select class="pkpFormField__input" v-model="sessionForm.type"><option v-for="ty in data.types" :key="ty.value" :value="ty.value">{{ ty.label }}</option></select></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.title }"><span>{{ t(\'program.session.title\') }} ({{ formLocale }})</span>\
					<input class="pkpFormField__input" type="text" v-model="sessionForm.title[formLocale]" maxlength="500"><em v-if="errors.title">{{ fieldError(\'title\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.roomId }"><span>{{ t(\'program.session.room\') }}</span>\
					<select class="pkpFormField__input" v-model="sessionForm.roomId"><option value="">{{ t(\'program.session.noRoom\') }}</option><option v-for="r in data.rooms" :key="r.id" :value="r.id">{{ r.name }}</option></select>\
					<em v-if="errors.roomId">{{ fieldError(\'roomId\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.startsAt }"><span>{{ t(\'program.session.startsAt\') }}</span>\
					<input class="pkpFormField__input" type="datetime-local" v-model="sessionForm.startsAt"><em v-if="errors.startsAt">{{ fieldError(\'startsAt\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.endsAt }"><span>{{ t(\'program.session.endsAt\') }}</span>\
					<input class="pkpFormField__input" type="datetime-local" v-model="sessionForm.endsAt"><em v-if="errors.endsAt">{{ fieldError(\'endsAt\') }}</em></label>\
				<label v-if="sessionForm.type !== \'break\'" class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.slotMinutes }"><span>{{ t(\'program.session.slot\') }}</span>\
					<input class="pkpFormField__input" type="number" min="1" max="480" v-model="sessionForm.slotMinutes"><em v-if="errors.slotMinutes">{{ fieldError(\'slotMinutes\') }}</em></label>\
			</div>\
			<div v-if="sessionForm.type !== \'break\'" class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.chairs }">\
				<span>{{ t(\'program.session.chairs\') }}</span>\
				<div class="cmProgram__chips"><span v-for="c in sessionForm.chairs" :key="c" class="cmProgram__chip">{{ peopleNames[c] || (\'#\' + c) }} <button type="button" :aria-label="t(\'program.session.removeChair\')" @click="removeChair(c)">×</button></span></div>\
				<input class="pkpFormField__input" type="search" v-model="peopleFilter" :placeholder="t(\'program.session.findChair\')">\
				<ul v-if="peopleFilter" class="cmProgram__people"><li v-for="p in filteredPeople" :key="p.id"><button type="button" @click="addChair(p.id)">{{ p.name }}</button></li></ul>\
				<em v-if="errors.chairs">{{ fieldError(\'chairs\') }}</em>\
			</div>\
			<label class="cmEditions__field"><span>{{ t(\'program.session.description\') }} ({{ formLocale }})</span>\
				<textarea class="pkpFormField__input" rows="2" v-model="sessionForm.description[formLocale]"></textarea></label>\
			<div class="cmEditions__grid">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.streamUrl }"><span>{{ t(\'program.session.streamUrl\') }}</span>\
					<input class="pkpFormField__input" type="url" v-model="sessionForm.streamUrl"><em v-if="errors.streamUrl">{{ fieldError(\'streamUrl\') }}</em></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.streamVisibleFrom }"><span>{{ t(\'program.session.streamVisibleFrom\') }}</span>\
					<input class="pkpFormField__input" type="datetime-local" v-model="sessionForm.streamVisibleFrom"><small>{{ t(\'program.session.streamHelp\') }}</small></label>\
			</div>\
			<div v-if="sessionForm.type === \'seminar\'" class="cmEditions__grid">\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.hours }"><span>{{ t(\'program.session.hours\') }}</span>\
					<input class="pkpFormField__input" type="number" min="0" v-model="sessionForm.hours"></label>\
				<label class="cmEditions__field" :class="{ \'cmEditions__field--error\': errors.ectsX100 }"><span>{{ t(\'program.session.ects\') }}</span>\
					<input class="pkpFormField__input" type="text" inputmode="decimal" v-model="sessionForm.ects"></label>\
				<label class="cmEditions__field"><span>{{ t(\'program.session.outcomes\') }} ({{ formLocale }})</span>\
					<textarea class="pkpFormField__input" rows="2" v-model="sessionForm.learningOutcomes[formLocale]"></textarea></label>\
			</div>\
			<div class="cmSetup__buttons"><pkp-button :is-primary="true" :disabled="busy" @click="saveSession">{{ t(\'save\') }}</pkp-button><pkp-button @click="sessionForm = null">{{ t(\'cancel\') }}</pkp-button></div>\
		</div>\
		<div class="cmProgram__board">\
			<section class="cmProgram__pool" :class="{ \'cmProgram__drop--over\': dropTarget === \'pool\' }" @dragover="dragOver($event, \'pool\')" @drop="drop($event, null, 0)">\
				<h3>{{ t(\'program.unassigned\') }} ({{ data.unassigned.length }})</h3>\
				<p v-if="!data.unassigned.length" class="cmProgram__empty">{{ t(\'program.unassigned.empty\') }}</p>\
				<article v-for="p in data.unassigned" :key="p.submissionId" class="cmProgram__paper" draggable="true" @dragstart="dragStart($event, p.submissionId)" @dragend="dragEnd">\
					<div class="cmProgram__paperTitle">{{ p.title }}</div>\
					<div class="cmProgram__paperMeta">{{ p.authors }} · {{ p.section }}</div>\
					<label class="cmProgram__moveTo"><span>{{ t(\'program.moveTo\') }}</span>\
						<select @change="moveTo(p, $event.target.value); $event.target.value = \'\'"><option value="">…</option><option v-for="s in paperSessions" :key="s.id" :value="s.id">{{ time(s.local.startsAt) }} {{ text(s.title) }}</option></select></label>\
				</article>\
			</section>\
			<section class="cmProgram__days">\
				<div class="cmSetup__buttons"><pkp-button :is-primary="true" @click="newSession(null)">{{ t(\'program.session.add\') }}</pkp-button></div>\
				<p v-if="!days.length" class="cmProgram__empty">{{ t(\'program.sessions.empty\') }}</p>\
				<div v-for="d in days" :key="d.day" class="cmProgram__day">\
					<h3>{{ dayLabel(d.day) }}</h3>\
					<article v-for="s in d.sessions" :key="s.id" class="cmProgram__session" :class="[\'cmProgram__session--\' + s.type, { \'cmProgram__session--conflict\': s.conflicts.length || s.roomClashes.length }]">\
						<header class="cmProgram__sessionHead">\
							<div><strong>{{ time(s.local.startsAt) }}–{{ time(s.local.endsAt) }}</strong> · {{ typeLabels[s.type] }}<span v-if="s.roomId"> · {{ roomNames[s.roomId] }}</span></div>\
							<div class="cmProgram__sessionTitle">{{ text(s.title) }}</div>\
							<div v-if="s.chairs.length" class="cmProgram__paperMeta">{{ t(\'program.session.chairs\') }}: {{ s.chairs.map(function (c) { return peopleNames[c] || (\'#\' + c); }).join(\', \') }}</div>\
							<div class="cmEditions__actions"><pkp-button @click="editSession(s)">{{ t(\'edit\') }}</pkp-button><pkp-button v-if="attendanceUrl && s.type !== \'break\'" @click="openAttendance(s)">{{ t(\'program.session.attendance\') }}</pkp-button><pkp-button :is-warnable="true" @click="deleteSession(s)">{{ t(\'delete\') }}</pkp-button></div>\
						</header>\
						<p v-if="s.conflicts.length" class="cmProgram__warn">{{ t(\'program.session.conflict\', { names: s.conflicts.join(\', \') }) }}</p>\
						<p v-if="s.roomClashes.length" class="cmProgram__warn">{{ t(\'program.session.roomClash\') }}</p>\
						<p v-if="s.overrun" class="cmProgram__warn">{{ t(\'program.session.overrun\', { minutes: s.overrun }) }}</p>\
						<ol v-if="s.type !== \'break\'" class="cmProgram__items">\
							<li v-for="(it, i) in s.paperItems" :key="it.submissionId" class="cmProgram__item" :class="{ \'cmProgram__item--conflict\': it.conflict, \'cmProgram__item--gone\': !it.accepted, \'cmProgram__drop--over\': dropTarget === s.id + \':\' + i }"\
								draggable="true" @dragstart="dragStart($event, it.submissionId)" @dragend="dragEnd" @dragover="dragOver($event, s.id + \':\' + i)" @drop="drop($event, s.id, i)">\
								<span class="cmProgram__itemTime">{{ time(it.startsAt) }}</span>\
								<div class="cmProgram__itemBody">\
									<div class="cmProgram__paperTitle">{{ it.title || (\'#\' + it.submissionId) }}<span v-if="!it.accepted" class="cmBadge"> {{ t(\'program.notAccepted\') }}</span></div>\
									<div class="cmProgram__paperMeta">{{ it.authors }}</div>\
								</div>\
								<div class="cmProgram__itemTools">\
									<label><span class="cmProgram__sr">{{ t(\'program.duration\') }}</span><input type="number" min="1" max="480" :value="it.durationMin === null ? \'\' : it.durationMin" :placeholder="s.slotMinutes" :title="t(\'program.duration\')" @change="setDuration(s, i, $event.target.value)"> {{ t(\'program.minutes\') }}</label>\
									<button type="button" :disabled="i === 0 || busy" :aria-label="t(\'program.up\')" @click="shift(s, i, -1)">↑</button>\
									<button type="button" :disabled="i === s.paperItems.length - 1 || busy" :aria-label="t(\'program.down\')" @click="shift(s, i, 1)">↓</button>\
									<select :aria-label="t(\'program.moveTo\')" @change="moveTo(it, $event.target.value); $event.target.value = \'\'"><option value="">{{ t(\'program.moveTo\') }}…</option><option value="none">{{ t(\'program.takeOut\') }}</option><option v-for="o in otherSessions(s)" :key="o.id" :value="o.id">{{ time(o.local.startsAt) }} {{ text(o.title) }}</option></select>\
								</div>\
							</li>\
							<li class="cmProgram__dropEnd" :class="{ \'cmProgram__drop--over\': dropTarget === s.id + \':end\' }" @dragover="dragOver($event, s.id + \':end\')" @drop="drop($event, s.id, s.paperItems.length)">{{ s.paperItems.length ? t(\'program.dropHere\') : t(\'program.dropFirst\') }}</li>\
						</ol>\
					</article>\
					<pkp-button @click="newSession(d.day)">{{ t(\'program.session.addDay\') }}</pkp-button>\
				</div>\
			</section>\
		</div>\
	</template>\
</div>',
	});
})();
