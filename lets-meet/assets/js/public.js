/**
 * Let's Meet — Public Booking Widget
 *
 * Handles calendar navigation, slot fetching via AJAX, booking form,
 * and submission. All AJAX calls use the nonce from lmData.
 */
(function () {
	'use strict';

	/* ── State ─────────────────────────────────────────────────── */

	var state = {
		serviceId: 0,
		serviceDuration: 0,
		selectedDate: '',
		selectedTime: '',
		currentMonth: null, // Date object (1st of displayed month)
		config: null,
	};

	/* ── DOM refs ──────────────────────────────────────────────── */

	var widget, calBody, monthLabel, prevBtn, nextBtn;
	var slotsWrap, slotsList, slotsPrompt, slotsLoading, slotsEmpty;
	var stepService, stepDatetime, stepForm, stepSuccess;
	var form, submitBtn, errorBox, summaryBox, successDetails;

	/* ── Init ──────────────────────────────────────────────────── */

	function init() {
		widget = document.getElementById('lm-booking-widget');
		if (!widget) return;

		// Parse config from JSON block.
		var configEl = document.getElementById('lm-config');
		if (configEl) {
			try {
				state.config = JSON.parse(configEl.textContent);
			} catch (e) {
				state.config = { availableDays: [], horizon: 60, preselectedId: 0, singleService: false };
			}
		}

		// Cache DOM elements.
		calBody      = widget.querySelector('.lm-cal-body');
		monthLabel   = widget.querySelector('.lm-cal-month-label');
		prevBtn      = widget.querySelector('.lm-cal-prev');
		nextBtn      = widget.querySelector('.lm-cal-next');
		slotsWrap    = widget.querySelector('.lm-slots-wrap');
		slotsList    = widget.querySelector('.lm-slots-list');
		slotsPrompt  = widget.querySelector('.lm-slots-prompt');
		slotsLoading = widget.querySelector('.lm-slots-loading');
		slotsEmpty   = widget.querySelector('.lm-slots-empty');
		stepService  = widget.querySelector('.lm-step-service');
		stepDatetime = widget.querySelector('.lm-step-datetime');
		stepForm     = widget.querySelector('.lm-step-form');
		stepSuccess  = widget.querySelector('.lm-step-success');
		form         = widget.querySelector('.lm-booking-form');
		submitBtn    = widget.querySelector('.lm-btn--submit');
		errorBox     = widget.querySelector('.lm-form-error');
		summaryBox   = widget.querySelector('.lm-selected-summary');
		successDetails = widget.querySelector('.lm-success-details');

		// Set initial month to today.
		var now = new Date();
		state.currentMonth = new Date(now.getFullYear(), now.getMonth(), 1);

		// Service selection.
		bindServiceSelection();

		// Calendar navigation.
		prevBtn.addEventListener('click', function () {
			state.currentMonth.setMonth(state.currentMonth.getMonth() - 1);
			renderCalendar();
		});
		nextBtn.addEventListener('click', function () {
			state.currentMonth.setMonth(state.currentMonth.getMonth() + 1);
			renderCalendar();
		});

		// Form events.
		form.addEventListener('submit', handleFormSubmit);
		widget.querySelector('.lm-btn--back').addEventListener('click', handleBack);

		// Auto-select service if single or preselected.
		autoSelectService();

		renderCalendar();
	}

	/* ── Service selection ─────────────────────────────────────── */

	function bindServiceSelection() {
		var radios = widget.querySelectorAll('input[name="lm_service"]');
		radios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				state.serviceId = parseInt(this.value, 10);
				state.serviceDuration = parseInt(this.getAttribute('data-duration'), 10);

				// If we had a date selected, re-fetch slots for the new service.
				if (state.selectedDate) {
					fetchSlots(state.selectedDate);
				}
			});
		});
	}

	function autoSelectService() {
		var checked = widget.querySelector('input[name="lm_service"]:checked');
		if (checked) {
			state.serviceId = parseInt(checked.value, 10);
			state.serviceDuration = parseInt(checked.getAttribute('data-duration'), 10);
		}
	}

	/* ── Calendar rendering ────────────────────────────────────── */

	function renderCalendar() {
		var year  = state.currentMonth.getFullYear();
		var month = state.currentMonth.getMonth();

		// Month/year label.
		var monthNames = [
			'January', 'February', 'March', 'April', 'May', 'June',
			'July', 'August', 'September', 'October', 'November', 'December'
		];
		monthLabel.textContent = monthNames[month] + ' ' + year;

		// Prev/next button states.
		var now = new Date();
		var thisMonth = new Date(now.getFullYear(), now.getMonth(), 1);
		prevBtn.disabled = (state.currentMonth <= thisMonth);

		var maxDate = new Date();
		maxDate.setDate(maxDate.getDate() + (state.config ? state.config.horizon : 60));
		var maxMonth = new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);
		nextBtn.disabled = (state.currentMonth >= maxMonth);

		// Build calendar grid.
		var firstDay  = new Date(year, month, 1).getDay(); // 0=Sun
		var daysInMonth = new Date(year, month + 1, 0).getDate();
		var today = formatDate(now);

		var availDays = state.config ? state.config.availableDays : [];

		var html = '';
		var dayNum = 1;
		var started = false;

		for (var row = 0; row < 6; row++) {
			if (dayNum > daysInMonth) break;
			html += '<tr>';
			for (var col = 0; col < 7; col++) {
				if (!started && col < firstDay) {
					html += '<td></td>';
					continue;
				}
				started = true;
				if (dayNum > daysInMonth) {
					html += '<td></td>';
					continue;
				}

				var dateStr = year + '-' + pad(month + 1) + '-' + pad(dayNum);
				var dayOfWeek = new Date(year, month, dayNum).getDay();
				var isPast = dateStr < today;
				var isBeyondHorizon = dateStr > formatDate(maxDate);
				var hasAvailability = availDays.indexOf(dayOfWeek) !== -1;
				var isDisabled = isPast || isBeyondHorizon || !hasAvailability;
				var isToday = dateStr === today;
				var isSelected = dateStr === state.selectedDate;

				var classes = 'lm-cal-day';
				if (isDisabled) classes += ' lm-cal-day--disabled';
				if (isToday) classes += ' lm-cal-day--today';
				if (isSelected) classes += ' lm-cal-day--selected';

				if (isDisabled) {
					html += '<td><span class="' + classes + '">' + dayNum + '</span></td>';
				} else {
					html += '<td><button type="button" class="' + classes + '" data-date="' + dateStr + '">' + dayNum + '</button></td>';
				}

				dayNum++;
			}
			html += '</tr>';
		}

		calBody.innerHTML = html;

		// Bind day click events.
		calBody.querySelectorAll('button.lm-cal-day').forEach(function (btn) {
			btn.addEventListener('click', function () {
				handleDayClick(this.getAttribute('data-date'));
			});
		});
	}

	function handleDayClick(dateStr) {
		// Validate date format before use.
		if (!/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
			return;
		}

		// Ensure a service is selected.
		if (!state.serviceId) {
			// Show service step if hidden.
			if (stepService) {
				stepService.classList.remove('lm-step--hidden');
			}
			return;
		}

		state.selectedDate = dateStr;
		state.selectedTime = '';

		// Update calendar UI.
		calBody.querySelectorAll('.lm-cal-day--selected').forEach(function (el) {
			el.classList.remove('lm-cal-day--selected');
		});
		var selected = calBody.querySelector('[data-date="' + dateStr + '"]');
		if (selected) {
			selected.classList.add('lm-cal-day--selected');
		}

		// Hide form step if visible.
		stepForm.classList.add('lm-hidden');

		fetchSlots(dateStr);
	}

	/* ── Slot fetching ─────────────────────────────────────────── */

	function fetchSlots(dateStr) {
		slotsPrompt.classList.add('lm-hidden');
		slotsEmpty.classList.add('lm-hidden');
		slotsList.innerHTML = '';
		slotsLoading.classList.remove('lm-hidden');

		var data = new FormData();
		data.append('action', 'lm_get_slots');
		data.append('nonce', lmData.nonce);
		data.append('date', dateStr);
		data.append('service_id', state.serviceId);

		fetch(lmData.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(function (res) { return res.json(); })
			.then(function (response) {
				slotsLoading.classList.add('lm-hidden');

				if (!response.success || !response.data.slots.length) {
					slotsEmpty.classList.remove('lm-hidden');
					return;
				}

				renderSlots(response.data.slots, response.data.duration);
			})
			.catch(function () {
				slotsLoading.classList.add('lm-hidden');
				slotsEmpty.classList.remove('lm-hidden');
			});
	}

	function renderSlots(slots, duration) {
		var html = '';
		slots.forEach(function (slot) {
			html += '<button type="button" class="lm-slot-btn" data-time="' +
				escAttr(slot.value) + '" data-display="' +
				escAttr(slot.display) + '">' +
				escHtml(slot.display) + '</button>';
		});
		slotsList.innerHTML = html;

		// Bind slot clicks.
		slotsList.querySelectorAll('.lm-slot-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				handleSlotClick(this);
			});
		});
	}

	function handleSlotClick(btn) {
		state.selectedTime = btn.getAttribute('data-time');

		// Update slot UI.
		slotsList.querySelectorAll('.lm-slot-btn--selected').forEach(function (el) {
			el.classList.remove('lm-slot-btn--selected');
		});
		btn.classList.add('lm-slot-btn--selected');

		// Show the form.
		showFormStep();
	}

	/* ── Form step ─────────────────────────────────────────────── */

	function showFormStep() {
		// Populate hidden fields.
		form.querySelector('[name="lm_service_id"]').value = state.serviceId;
		form.querySelector('[name="lm_date"]').value = state.selectedDate;
		form.querySelector('[name="lm_time"]').value = state.selectedTime;
		form.querySelector('[name="lm_rendered_at"]').value = Math.floor(Date.now() / 1000);

		// Build summary.
		var dateObj = new Date(state.selectedDate + 'T00:00:00');
		var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
		var monthNames = [
			'January', 'February', 'March', 'April', 'May', 'June',
			'July', 'August', 'September', 'October', 'November', 'December'
		];
		var dateDisplay = dayNames[dateObj.getDay()] + ', ' +
			monthNames[dateObj.getMonth()] + ' ' + dateObj.getDate() + ', ' + dateObj.getFullYear();

		var timeDisplay = slotsList.querySelector('.lm-slot-btn--selected');
		var timeText = timeDisplay ? timeDisplay.getAttribute('data-display') : state.selectedTime;

		var serviceName = '';
		var checked = widget.querySelector('input[name="lm_service"]:checked');
		if (checked) {
			var infoEl = checked.parentElement.querySelector('.lm-service-name');
			if (infoEl) serviceName = infoEl.textContent;
		}

		summaryBox.innerHTML =
			'<strong>' + escHtml(serviceName) + '</strong><br>' +
			escHtml(dateDisplay) + ' at ' + escHtml(timeText);

		// Show form, hide error.
		stepForm.classList.remove('lm-hidden');
		errorBox.classList.add('lm-hidden');

		// Scroll to form.
		stepForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function handleBack() {
		stepForm.classList.add('lm-hidden');

		// Scroll back to calendar.
		stepDatetime.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	/* ── Form submission ───────────────────────────────────────── */

	function handleFormSubmit(e) {
		e.preventDefault();

		// Client-side validation.
		var name  = form.querySelector('[name="lm_name"]').value.trim();
		var email = form.querySelector('[name="lm_email"]').value.trim();

		if (!name || !email) {
			showError(lmData.i18n.errorGeneric || 'Please fill in all required fields.');
			return;
		}

		// Basic email check.
		if (email.indexOf('@') === -1 || email.indexOf('.') === -1) {
			showError('Please enter a valid email address.');
			return;
		}

		// Disable submit.
		submitBtn.disabled = true;
		submitBtn.textContent = lmData.i18n.submitting || 'Booking...';
		errorBox.classList.add('lm-hidden');

		var data = new FormData();
		data.append('action', 'lm_submit_booking');
		data.append('nonce', lmData.nonce);
		data.append('service_id', form.querySelector('[name="lm_service_id"]').value);
		data.append('date', form.querySelector('[name="lm_date"]').value);
		data.append('time', form.querySelector('[name="lm_time"]').value);
		data.append('name', name);
		data.append('email', email);
		data.append('phone', form.querySelector('[name="lm_phone"]').value.trim());
		data.append('notes', form.querySelector('[name="lm_notes"]').value.trim());
		data.append('honeypot', form.querySelector('[name="lm_website"]').value);
		data.append('rendered_at', form.querySelector('[name="lm_rendered_at"]').value);

		fetch(lmData.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(function (res) { return res.json(); })
			.then(function (response) {
				submitBtn.disabled = false;
				submitBtn.textContent = lmData.i18n.bookNow || 'Book Now';

				if (!response.success) {
					showError(response.data && response.data.message ? response.data.message : lmData.i18n.errorGeneric);
					return;
				}

				showSuccess(response.data);
			})
			.catch(function () {
				submitBtn.disabled = false;
				submitBtn.textContent = lmData.i18n.bookNow || 'Book Now';
				showError(lmData.i18n.errorGeneric);
			});
	}

	function showError(message) {
		errorBox.textContent = message;
		errorBox.classList.remove('lm-hidden');
		errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}

	/* ── Success ───────────────────────────────────────────────── */

	function showSuccess(data) {
		// Hide other steps.
		if (stepService) stepService.classList.add('lm-step--hidden');
		stepDatetime.classList.add('lm-hidden');
		stepForm.classList.add('lm-hidden');

		// Build success details.
		var html = '';
		if (data.service)  html += '<strong>' + escHtml(data.service) + '</strong><br>';
		if (data.date)     html += escHtml(data.date) + '<br>';
		if (data.time)     html += escHtml(data.time) + '<br>';
		if (data.duration) html += escHtml(data.duration) + '<br>';

		successDetails.innerHTML = html;
		stepSuccess.classList.remove('lm-hidden');
		stepSuccess.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	/* ── Helpers ───────────────────────────────────────────────── */

	function pad(n) {
		return n < 10 ? '0' + n : '' + n;
	}

	function formatDate(d) {
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	function escAttr(str) {
		return str
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	/* ── Boot ──────────────────────────────────────────────────── */

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
