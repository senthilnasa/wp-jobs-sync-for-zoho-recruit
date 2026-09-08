/**
 * Progressive enhancement for the job listing.
 *
 * The listing is fully usable without JavaScript: the filters are a plain GET
 * form with a submit button, and every result is server-rendered, so search
 * engines and visitors without scripts get the real thing.
 *
 * What this adds on top is the difference between the four states the listing
 * can be in. Without it, a slow request looks identical to "there are no jobs",
 * and a failed one looks identical to "there are no jobs" as well -- which is
 * the worst possible lie to tell a candidate. With it:
 *
 *   loading        skeleton cards, results marked busy
 *   success        the new results, swapped in
 *   success, none  the server's own empty state
 *   failure        an error panel with a retry button
 *
 * Results are fetched as HTML from the same URL the form would have navigated
 * to, and the rendered region is swapped in. Nothing about the card markup is
 * duplicated here, so the two paths cannot drift apart.
 */
( function () {
	'use strict';

	const l10n = window.jszrJobsL10n || {};

	/**
	 * Text for a key, falling back to English if localisation is missing.
	 *
	 * @param {string} key      Key in the localisation object.
	 * @param {string} fallback Default text.
	 * @return {string} The string to display.
	 */
	function text( key, fallback ) {
		return typeof l10n[ key ] === 'string' && l10n[ key ] !== ''
			? l10n[ key ]
			: fallback;
	}

	/**
	 * Whether the browser can do the enhanced path at all.
	 *
	 * @return {boolean} True when fetch and history are available.
	 */
	function supported() {
		return (
			typeof window.fetch === 'function' &&
			typeof window.history === 'object' &&
			typeof window.history.pushState === 'function' &&
			typeof window.DOMParser === 'function'
		);
	}

	/**
	 * The listing root for a given element.
	 *
	 * @param {Element} element Any element inside the listing.
	 * @return {Element|null} The listing root.
	 */
	function rootOf( element ) {
		return element.closest( '.jszr-jobs' );
	}

	/**
	 * Show the skeleton while a request is in flight.
	 *
	 * @param {Element} root Listing root.
	 */
	function showLoading( root ) {
		const results = root.querySelector( '.jszr-jobs__results' );

		if ( ! results ) {
			return;
		}

		const skeleton = root.querySelector( '.jszr-skeleton' );
		const state = root.querySelector( '.jszr-state' );

		results.setAttribute( 'aria-busy', 'true' );

		// An empty or error panel from the previous render would otherwise sit
		// above the skeleton and contradict it.
		if ( state ) {
			state.hidden = true;
		}

		if ( skeleton ) {
			skeleton.hidden = false;
			skeleton.setAttribute( 'aria-hidden', 'true' );
		}
	}

	/**
	 * Hide the skeleton again.
	 *
	 * @param {Element} root Listing root.
	 */
	function clearLoading( root ) {
		const results = root.querySelector( '.jszr-jobs__results' );
		const skeleton = root.querySelector( '.jszr-skeleton' );

		if ( results ) {
			results.removeAttribute( 'aria-busy' );
		}

		if ( skeleton ) {
			skeleton.hidden = true;
		}
	}

	/**
	 * Replace the results region with the one from a fetched document.
	 *
	 * @param {Element} root Listing root.
	 * @param {string}  html Fetched page HTML.
	 * @return {boolean} True when the swap succeeded.
	 */
	function swapResults( root, html ) {
		const parsed = new window.DOMParser().parseFromString(
			html,
			'text/html'
		);
		const fresh = parsed.querySelector( '.jszr-jobs__results' );
		const current = root.querySelector( '.jszr-jobs__results' );

		if ( ! fresh || ! current ) {
			return false;
		}

		current.innerHTML = fresh.innerHTML;

		return true;
	}

	/**
	 * Render the failure state, with a button that tries the same URL again.
	 *
	 * @param {Element} root Listing root.
	 * @param {string}  url  URL to retry.
	 */
	function showError( root, url ) {
		const current = root.querySelector( '.jszr-jobs__results' );

		if ( ! current ) {
			return;
		}

		const panel = document.createElement( 'div' );
		panel.className = 'jszr-state jszr-state--error';
		panel.setAttribute( 'role', 'alert' );

		const title = document.createElement( 'h3' );
		title.className = 'jszr-state__title';
		title.textContent = text( 'errorTitle', 'Unable to load jobs' );

		const body = document.createElement( 'p' );
		body.className = 'jszr-state__body';
		body.textContent = text(
			'errorBody',
			'We could not load the current job openings. Please try again.'
		);

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'jszr-button jszr-jobs__retry';
		button.textContent = text( 'retry', 'Retry' );
		button.addEventListener( 'click', function () {
			load( root, url, false );
		} );

		panel.appendChild( title );
		panel.appendChild( body );
		panel.appendChild( button );

		current.innerHTML = '';
		current.appendChild( panel );

		// Move focus to the alert so a screen reader user is told immediately.
		button.focus();
	}

	/**
	 * Fetch a listing URL and render whatever comes back.
	 *
	 * @param {Element} root Listing root.
	 * @param {string}  url  URL to load.
	 * @param {boolean} push Whether to add a history entry.
	 */
	function load( root, url, push ) {
		showLoading( root );

		window
			.fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.text();
			} )
			.then( function ( html ) {
				clearLoading( root );

				if ( ! swapResults( root, html ) ) {
					throw new Error( 'No results region in the response' );
				}

				if ( push ) {
					window.history.pushState( { jszr: true }, '', url );
				}

				const heading = root.querySelector( '.jszr-jobs__count' );

				if ( heading ) {
					heading.setAttribute( 'tabindex', '-1' );
					heading.focus( { preventScroll: true } );
				}
			} )
			.catch( function () {
				clearLoading( root );
				showError( root, url );
			} );
	}

	/**
	 * Build the URL a filter form would navigate to.
	 *
	 * @param {HTMLFormElement} form Filter form.
	 * @return {string} The URL.
	 */
	function formUrl( form ) {
		const data = new window.URLSearchParams( new window.FormData( form ) );

		// A narrowed result set must not land the visitor on a page that no
		// longer exists.
		data.delete( 'jszr_page' );

		// Empty filters are noise in the address bar.
		Array.from( data.keys() ).forEach( function ( key ) {
			if ( data.get( key ) === '' ) {
				data.delete( key );
			}
		} );

		const query = data.toString();

		return form.action + ( query ? '?' + query : '' );
	}

	/**
	 * Enable the reset link only when a filter is actually set.
	 *
	 * @param {HTMLFormElement} form Filter form.
	 */
	function toggleReset( form ) {
		const reset = form.querySelector( '.jszr-filters__reset' );

		if ( ! reset ) {
			return;
		}

		const hasValue = Array.prototype.some.call(
			form.querySelectorAll( 'select, input[type="search"]' ),
			function ( field ) {
				return field.value !== '';
			}
		);

		reset.hidden = ! hasValue;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const roots = document.querySelectorAll( '.jszr-jobs' );

		Array.prototype.forEach.call( roots, function ( root ) {
			const form = root.querySelector( '.jszr-filters' );

			if ( form ) {
				toggleReset( form );

				if ( supported() ) {
					form.addEventListener( 'submit', function ( event ) {
						event.preventDefault();
						load( root, formUrl( form ), true );
					} );
				}
			}

			if ( ! supported() ) {
				return;
			}

			// Pagination and the empty state's "view all" link load in place too.
			root.addEventListener( 'click', function ( event ) {
				const link = event.target.closest(
					'.jszr-pagination a, .jszr-state a'
				);

				if ( ! link || ! rootOf( link ) ) {
					return;
				}

				// Leave modified clicks alone: they mean "open somewhere else".
				if (
					event.metaKey ||
					event.ctrlKey ||
					event.shiftKey ||
					event.altKey ||
					link.target === '_blank'
				) {
					return;
				}

				event.preventDefault();
				load( root, link.href, true );
			} );
		} );

		if ( supported() ) {
			window.addEventListener( 'popstate', function ( event ) {
				if ( ! event.state || ! event.state.jszr ) {
					return;
				}

				const root = document.querySelector( '.jszr-jobs' );

				if ( root ) {
					load( root, window.location.href, false );
				}
			} );
		}
	} );
} )();
