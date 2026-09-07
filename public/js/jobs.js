/**
 * Progressive enhancement for the job listing.
 *
 * The listing is fully usable without JavaScript: the filters are a plain GET
 * form with a submit button. This file only removes friction; it deliberately
 * does not auto-submit on select change, because that traps keyboard users who
 * move through options with the arrow keys.
 */
( function () {
	'use strict';

	/**
	 * Drop a stale page parameter when filters are re-submitted, so a narrowed
	 * result set never lands the visitor on an out-of-range page.
	 *
	 * @param {HTMLFormElement} form Filter form.
	 */
	function resetPaging( form ) {
		form.addEventListener( 'submit', function () {
			var stale = form.querySelector( 'input[name="jszr_page"]' );

			if ( stale && stale.parentNode ) {
				stale.parentNode.removeChild( stale );
			}
		} );
	}

	/**
	 * Mark the results region busy while the next page loads, so screen readers
	 * announce that something is happening.
	 *
	 * @param {HTMLFormElement} form Filter form.
	 */
	function markBusy( form ) {
		var results = document.querySelector( '.jszr-jobs__results' );

		if ( ! results ) {
			return;
		}

		form.addEventListener( 'submit', function () {
			results.setAttribute( 'aria-busy', 'true' );
		} );
	}

	/**
	 * Enable the reset link only when a filter is actually set.
	 *
	 * @param {HTMLFormElement} form Filter form.
	 */
	function toggleReset( form ) {
		var reset = form.querySelector( '.jszr-filters__reset' );

		if ( ! reset ) {
			return;
		}

		var hasValue = Array.prototype.some.call(
			form.querySelectorAll( 'select, input[type="search"]' ),
			function ( field ) {
				return field.value !== '';
			}
		);

		if ( ! hasValue ) {
			reset.hidden = true;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.jszr-filters' );

		Array.prototype.forEach.call( forms, function ( form ) {
			resetPaging( form );
			markBusy( form );
			toggleReset( form );
		} );
	} );
}() );
