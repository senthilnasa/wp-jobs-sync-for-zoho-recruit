/**
 * Admin behaviour for Jobs Sync for Zoho Recruit.
 *
 * Every request goes through wp.apiFetch, which attaches the REST nonce, and
 * every endpoint it calls performs its own capability check on the server.
 */
( function ( wp, settings ) {
	'use strict';

	if ( ! settings ) {
		return;
	}

	var apiFetch = wp && wp.apiFetch ? wp.apiFetch : null;
	var i18n = settings.i18n || {};

	/**
	 * Add and remove field mapping rows.
	 */
	function initMapping() {
		var container = document.getElementById( 'jszr-mapping-rows' );
		var template = document.getElementById( 'jszr-mapping-template' );
		var addButton = document.getElementById( 'jszr-add-row' );

		if ( ! container ) {
			return;
		}

		container.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.jszr-remove-row' );

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			var row = button.closest( '.jszr-mapping-row' );

			if ( row && row.parentNode ) {
				row.parentNode.removeChild( row );
			}
		} );

		if ( ! addButton || ! template ) {
			return;
		}

		var nextIndex = container.querySelectorAll( '.jszr-mapping-row' ).length;

		addButton.addEventListener( 'click', function () {
			var markup = template.innerHTML.split( '__INDEX__' ).join( 'new' + nextIndex );
			var holder = document.createElement( 'tbody' );

			holder.innerHTML = markup;

			var row = holder.querySelector( '.jszr-mapping-row' );

			if ( row ) {
				container.appendChild( row );

				var firstField = row.querySelector( 'select' );

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
		var panel = document.getElementById( 'jszr-sync-progress' );

		if ( ! panel || ! apiFetch ) {
			return;
		}

		var runId = parseInt( panel.getAttribute( 'data-run-id' ), 10 );

		if ( ! runId ) {
			return;
		}

		var bar = panel.querySelector( '.jszr-progress-bar' );
		var fill = panel.querySelector( '.jszr-progress-fill' );
		var text = panel.querySelector( '.jszr-progress-text' );
		var timer = null;

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
				path: '/' + settings.restNamespace + '/sync/status?run_id=' + runId
			} ).then( function ( response ) {
				var run = response && response.data ? response.data.run : null;

				if ( ! run ) {
					stop( i18n.genericError || '' );
					return;
				}

				var total = parseInt( run.total, 10 ) || 0;
				var processed = parseInt( run.processed, 10 ) || 0;
				var percent = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 0;

				if ( fill ) {
					fill.style.width = percent + '%';
				}

				if ( bar ) {
					bar.setAttribute( 'aria-valuenow', String( percent ) );
				}

				if ( text ) {
					text.textContent = [
						( i18n.processed || 'Processed' ) + ': ' + processed + ( total ? ' / ' + total : '' ),
						( i18n.page || 'Page' ) + ': ' + run.page,
						run.state
					].join( ' — ' );
				}

				if ( [ 'completed', 'failed', 'partial', 'cancelled' ].indexOf( run.state ) !== -1 ) {
					stop(
						'failed' === run.state
							? ( i18n.syncFailed || '' )
							: ( i18n.syncComplete || '' )
					);

					window.setTimeout( function () {
						window.location.reload();
					}, 1500 );
				}
			} ).catch( function () {
				stop( i18n.genericError || '' );
			} );
		}

		panel.hidden = false;
		poll();
		timer = window.setInterval( poll, parseInt( settings.pollInterval, 10 ) || 3000 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initMapping();
		initProgress();
	} );
}( window.wp, window.jszrAdmin ) );
