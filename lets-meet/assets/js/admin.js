/**
 * Let's Meet — Admin JavaScript
 */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initCopyRight();
		initCancelConfirm();
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

})();
