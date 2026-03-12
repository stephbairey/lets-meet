/**
 * Let's Meet — Admin JavaScript
 */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initCopyRight();
		initCancelConfirm();
		initAdminReschedule();
	} );

	/**
	 * "Copy →" button: copies one day's availability dropdowns to the next day.
	 */
	function initCopyRight() {
		var buttons = document.querySelectorAll( '.lm-copy-right' );

		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var fromDay = btn.getAttribute( 'data-from' );
				var toDay   = btn.getAttribute( 'data-to' );

				if ( ! fromDay || ! toDay ) {
					return;
				}

				for ( var slot = 0; slot < 3; slot++ ) {
					var fromStart = document.querySelector(
						'select[name="lm_avail[' + fromDay + '][' + slot + '][start]"]'
					);
					var fromEnd = document.querySelector(
						'select[name="lm_avail[' + fromDay + '][' + slot + '][end]"]'
					);
					var toStart = document.querySelector(
						'select[name="lm_avail[' + toDay + '][' + slot + '][start]"]'
					);
					var toEnd = document.querySelector(
						'select[name="lm_avail[' + toDay + '][' + slot + '][end]"]'
					);

					if ( fromStart && toStart ) {
						toStart.value = fromStart.value;
					}
					if ( fromEnd && toEnd ) {
						toEnd.value = fromEnd.value;
					}
				}
			} );
		} );
	}

	/**
	 * Cancel booking confirmation dialog.
	 */
	function initCancelConfirm() {
		document.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( '.lm-cancel-link' );
			if ( ! link ) {
				return;
			}
			if ( ! confirm( 'Are you sure you want to cancel this booking?' ) ) {
				e.preventDefault();
			}
		} );
	}

	/**
	 * Admin reschedule: fetch time slots when date changes.
	 */
	function initAdminReschedule() {
		var dateInput  = document.getElementById( 'lm-new-date' );
		var timeSelect = document.getElementById( 'lm-new-time' );
		var submitBtn  = document.getElementById( 'lm-reschedule-submit' );
		var spinner    = document.getElementById( 'lm-slots-spinner' );

		if ( ! dateInput || ! timeSelect || ! window.lmAdminData ) {
			return;
		}

		dateInput.addEventListener( 'change', function () {
			var date = dateInput.value;
			if ( ! date ) {
				return;
			}

			timeSelect.innerHTML = '<option value="">Loading...</option>';
			timeSelect.disabled = true;
			submitBtn.disabled = true;
			if ( spinner ) spinner.style.display = 'inline';

			var data = new FormData();
			data.append( 'action', 'lm_get_slots' );
			data.append( 'nonce', lmAdminData.nonce );
			data.append( 'date', date );
			data.append( 'service_id', lmAdminData.serviceId );

			fetch( lmAdminData.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data,
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( response ) {
					if ( spinner ) spinner.style.display = 'none';

					if ( ! response.success || ! response.data || ! response.data.slots || ! response.data.slots.length ) {
						timeSelect.innerHTML = '<option value="">No available times</option>';
						return;
					}

					var html = '<option value="">Select a time</option>';
					response.data.slots.forEach( function ( slot ) {
						html += '<option value="' + slot.value + '">' + slot.display + '</option>';
					} );
					timeSelect.innerHTML = html;
					timeSelect.disabled = false;
				} )
				.catch( function () {
					if ( spinner ) spinner.style.display = 'none';
					timeSelect.innerHTML = '<option value="">Error loading times</option>';
				} );
		} );

		timeSelect.addEventListener( 'change', function () {
			submitBtn.disabled = ( '' === timeSelect.value );
		} );
	}

})();
