/* globals newspackCsvExport */
import './style.scss';

/**
 * Drives the batched CSV export from the admin list tables: one AJAX request
 * per page (WooCommerce product-exporter style), then a nonce-protected
 * download of the assembled file.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	document.querySelectorAll( '.newspack-csv-export' ).forEach( button => {
		const status = button.parentElement.querySelector( '.newspack-csv-export__status' );
		const announcer = button.parentElement.querySelector( '.newspack-csv-export__announce' );
		// The visible status updates every step; the live region announces
		// only start/completion/errors so screen readers aren't flooded with
		// per-step percentages.
		const setStatus = ( text, announce = false ) => {
			status.hidden = false;
			status.textContent = text;
			if ( announce && announcer ) {
				announcer.textContent = text;
			}
		};
		const fail = message => {
			setStatus( message || newspackCsvExport.labels.error, true );
			button.disabled = false;
		};
		const processStep = ( step, filename ) => {
			const body = new URLSearchParams( {
				action: newspackCsvExport.action,
				security: newspackCsvExport.nonce,
				export: button.dataset.export,
				step,
				list_args: window.location.search.replace( /^\?/, '' ),
			} );
			if ( filename ) {
				body.set( 'filename', filename );
			}
			fetch( newspackCsvExport.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
				.then( response => response.json() )
				.then( response => {
					if ( ! response.success ) {
						fail( response.data && response.data.message );
						return;
					}
					if ( 'done' === response.data.step ) {
						// The server sends a notice when the exported set shrank
						// mid-run, so a short file isn't presented as complete.
						setStatus( response.data.notice || newspackCsvExport.labels.done, true );
						window.location = response.data.url;
						// Keep the button disabled while the download is served;
						// an immediate second click would restart the whole export.
						setTimeout( () => {
							button.disabled = false;
						}, 5000 );
					} else {
						setStatus( `${ newspackCsvExport.labels.exporting } ${ response.data.percentage }%` );
						processStep( response.data.step, response.data.filename );
					}
				} )
				.catch( () => fail() );
		};
		button.addEventListener( 'click', () => {
			button.disabled = true;
			setStatus( `${ newspackCsvExport.labels.exporting } 0%`, true );
			processStep( 1 );
		} );
	} );
} );
