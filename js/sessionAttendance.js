/**
 * Presence at a session (ATT-01): filters the list by name, e-mail or
 * affiliation and ticks or unticks the people shown. The page works
 * without it.
 *
 * Distributed under the GNU GPL v3.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function () {
		var form = document.querySelector('.cmSessionAttendance');
		if (!form) {
			return;
		}
		var rows = Array.prototype.slice.call(form.querySelectorAll('[data-cm-person]'));
		var filter = form.querySelector('[data-cm-filter]');
		if (filter) {
			filter.addEventListener('input', function () {
				var q = filter.value.trim().toLowerCase();
				rows.forEach(function (row) {
					row.hidden = q !== '' && row.getAttribute('data-cm-person').indexOf(q) === -1;
				});
			});
		}
		Array.prototype.forEach.call(form.querySelectorAll('[data-cm-check]'), function (button) {
			button.addEventListener('click', function () {
				var on = button.getAttribute('data-cm-check') === '1';
				rows.forEach(function (row) {
					var box = row.querySelector('input[type="checkbox"]');
					if (!row.hidden && box) {
						box.checked = on;
					}
				});
			});
		});
	});
})();
