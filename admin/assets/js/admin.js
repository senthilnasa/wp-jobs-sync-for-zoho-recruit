/**
 * Admin behaviour for Jobs Sync for Zoho Recruit.
 *
 * Every request goes through wp.apiFetch, which attaches the REST nonce, and
 * every endpoint it calls performs its own capability check on the server.
 *
 * @param {Object} wp       The WordPress global, for wp.apiFetch.
 * @param {Object} settings The localized jszrAdmin settings object.
 */
( function ( wp, settings ) {
	'use strict';

	if ( ! settings ) {
		return;
	}

	const apiFetch = wp && wp.apiFetch ? wp.apiFetch : null;
	const i18n = settings.i18n || {};

	/**
	 * Add and remove field mapping rows.
	 */
	function initMapping() {
		const container = document.getElementById( 'jszr-mapping-rows' );

		if ( ! container ) {
			return;
		}

		const template = document.getElementById( 'jszr-mapping-template' );
		const addButton = document.getElementById( 'jszr-add-row' );

		container.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '.jszr-remove-row' );

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			const row = button.closest( '.jszr-mapping-row' );

			if ( row && row.parentNode ) {
				row.parentNode.removeChild( row );
			}
		} );

		if ( ! addButton || ! template ) {
			return;
		}

		let nextIndex =
			container.querySelectorAll( '.jszr-mapping-row' ).length;

		addButton.addEventListener( 'click', function () {
			const markup = template.innerHTML
				.split( '__INDEX__' )
				.join( 'new' + nextIndex );
			const holder = document.createElement( 'tbody' );

			holder.innerHTML = markup;

			const row = holder.querySelector( '.jszr-mapping-row' );

			if ( row ) {
				container.appendChild( row );

				const firstField = row.querySelector( 'select' );

				if ( firstField ) {
					firstField.focus();
				}
			}

			nextIndex++;
		} );
	}

	/**
	 * Poll the protected status endpoint while a sync is running.
	 */
	function initProgress() {
		const panel = document.getElementById( 'jszr-sync-progress' );

		if ( ! panel || ! apiFetch ) {
			return;
		}

		const runId = parseInt( panel.getAttribute( 'data-run-id' ), 10 );

		if ( ! runId ) {
			return;
		}

		const bar = panel.querySelector( '.jszr-progress-bar' );
		const fill = panel.querySelector( '.jszr-progress-fill' );
		const text = panel.querySelector( '.jszr-progress-text' );
		let timer = null;

		function stop( message ) {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}

			if ( text ) {
				text.textContent = message;
			}
		}

		function poll() {
			apiFetch( {
				path:
					'/' +
					settings.restNamespace +
					'/sync/status?run_id=' +
					runId,
			} )
				.then( function ( response ) {
					const run =
						response && response.data ? response.data.run : null;

					if ( ! run ) {
						stop( i18n.genericError || '' );
						return;
					}

					const total = parseInt( run.total, 10 ) || 0;
					const processed = parseInt( run.processed, 10 ) || 0;
					const percent =
						total > 0
							? Math.min(
									100,
									Math.round( ( processed / total ) * 100 )
							  )
							: 0;

					if ( fill ) {
						fill.style.width = percent + '%';
					}

					if ( bar ) {
						bar.setAttribute( 'aria-valuenow', String( percent ) );
					}

					if ( text ) {
						text.textContent = [
							( i18n.processed || 'Processed' ) +
								': ' +
								processed +
								( total ? ' / ' + total : '' ),
							( i18n.page || 'Page' ) + ': ' + run.page,
							run.state,
						].join( ' — ' );
					}

					if (
						[
							'completed',
							'failed',
							'partial',
							'cancelled',
						].indexOf( run.state ) !== -1
					) {
						stop(
							'failed' === run.state
								? i18n.syncFailed || ''
								: i18n.syncComplete || ''
						);

						window.setTimeout( function () {
							window.location.reload();
						}, 1500 );
					}
				} )
				.catch( function () {
					stop( i18n.genericError || '' );
				} );
		}

		panel.hidden = false;
		poll();
		timer = window.setInterval(
			poll,
			parseInt( settings.pollInterval, 10 ) || 3000
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initMapping();
		initProgress();
	} );
} )( window.wp, window.jszrAdmin );
