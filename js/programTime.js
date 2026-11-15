/**
 * PRG-04: shows the times of the program in the time zone of the event or
 * of the reader. Times come in <time datetime="UTC" data-cm-time>; the page
 * works without this script and shows the zone of the event.
 */
(function () {
	'use strict';
	function init() {
		var box = document.querySelector('[data-cm-tz]');
		if (!box || typeof Intl === 'undefined' || !Intl.DateTimeFormat) {
			return;
		}
		var eventZone = box.getAttribute('data-cm-tz');
		var mine;
		try {
			mine = Intl.DateTimeFormat().resolvedOptions().timeZone;
		} catch (e) {
			return;
		}
		if (!mine || mine === eventZone) {
			return;
		}
		var times = Array.prototype.slice.call(document.querySelectorAll('time[data-cm-time]'));
		var original = times.map(function (t) { return t.textContent; });
		var lang = document.documentElement.lang || undefined;
		var timeFmt, eventFmt;
		try {
			timeFmt = new Intl.DateTimeFormat(lang, { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: mine });
			eventFmt = new Intl.DateTimeFormat(lang, { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: eventZone });
		} catch (e) {
			return;
		}
		// The same clock under another name (Europe/Kiev and Europe/Kyiv): nothing to switch.
		var differs = times.some(function (t) {
			var d = new Date(t.getAttribute('datetime'));
			return !isNaN(d.getTime()) && timeFmt.format(d) !== eventFmt.format(d);
		});
		if (!differs) {
			return;
		}
		var dayFmt = new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: mine });
		var eventDayFmt = new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: eventZone });
		var shortDay = new Intl.DateTimeFormat(lang, { day: 'numeric', month: 'short', timeZone: mine });

		function apply(useMine) {
			times.forEach(function (t, i) {
				if (!useMine) {
					t.textContent = original[i];
					return;
				}
				var d = new Date(t.getAttribute('datetime'));
				if (isNaN(d.getTime())) {
					return;
				}
				var text = timeFmt.format(d);
				if (dayFmt.format(d) !== eventDayFmt.format(d)) {
					text += ' (' + shortDay.format(d) + ')';
				}
				t.textContent = text;
			});
			try {
				window.localStorage.setItem('cmProgramZone', useMine ? 'mine' : 'event');
			} catch (e) { /* storage may be off */ }
		}

		var label = document.createElement('label');
		label.className = 'cmProgram__tzSwitch';
		label.appendChild(document.createTextNode(box.getAttribute('data-label') + ' '));
		var select = document.createElement('select');
		[['event', box.getAttribute('data-event')], ['mine', box.getAttribute('data-mine') + ' (' + mine + ')']].forEach(function (o) {
			var opt = document.createElement('option');
			opt.value = o[0];
			opt.textContent = o[1];
			select.appendChild(opt);
		});
		label.appendChild(select);
		box.textContent = '';
		box.appendChild(label);
		var saved = null;
		try {
			saved = window.localStorage.getItem('cmProgramZone');
		} catch (e) { /* storage may be off */ }
		if (saved === 'mine') {
			select.value = 'mine';
			apply(true);
		}
		select.addEventListener('change', function () { apply(select.value === 'mine'); });
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
