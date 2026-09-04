/* globals newspackCsvExport */
import './style.scss';

/**
 * Drives the batched CSV export from the admin list tables: the export button
 * opens an options dialog, then one AJAX request per page (WooCommerce
 * product-exporter style), then a nonce-protected download of the assembled
 * file.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	document.querySelectorAll( '.newspack-csv-export' ).forEach( button => {
		const wrap = button.parentElement;
		const type = button.dataset.export;
		const status = wrap.querySelector( '.newspack-csv-export__status' );
		const announcer = wrap.querySelector( '.newspack-csv-export__announce' );
		// The dialog lives in the footer, outside the list table's own form.
		const dialog = document.getElementById( `newspack-csv-export-modal-${ type }` );
		const form = dialog && dialog.querySelector( 'form' );
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
		const processStep = ( step, exportConfig, filename ) => {
			const body = new URLSearchParams( {
				action: newspackCsvExport.action,
				security: newspackCsvExport.nonce,
				export: type,
				step,
				list_args: window.location.search.replace( /^\?/, '' ),
				export_config: exportConfig,
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
						processStep( response.data.step, exportConfig, response.data.filename );
					}
				} )
				.catch( () => fail() );
		};
		const startExport = () => {
			// The dialog returns focus to the trigger on close, and disabling it
			// would then drop focus to <body> — at the exact moment the status
			// region starts announcing progress. Park focus on the status first.
			setStatus( `${ newspackCsvExport.labels.exporting } 0%`, true );
			status.setAttribute( 'tabindex', '-1' );
			status.focus();
			button.disabled = true;
			// Serialized once and replayed on every step, so the column set,
			// delimiter and date format can't drift mid-run.
			processStep( 1, form ? new URLSearchParams( new FormData( form ) ).toString() : '' );
		};

		if ( ! dialog || ! form ) {
			button.addEventListener( 'click', startExport );
			return;
		}

		// The custom date format input only matters once "Custom…" is picked.
		const dateFormat = form.querySelector( '.newspack-csv-export-modal__date-format' );
		const customWrap = form.querySelector( '.newspack-csv-export-modal__date-format-custom' );
		const customInput = customWrap && customWrap.querySelector( 'input' );
		if ( dateFormat && customWrap && customInput ) {
			const syncCustomDateFormat = focusIt => {
				const isCustom = 'custom' === dateFormat.value;
				customWrap.hidden = ! isCustom;
				customInput.required = isCustom;
				// A field that appears with no cue is found only on the next tab,
				// or on the validation error it causes.
				if ( isCustom && focusIt ) {
					customInput.focus();
				}
			};
			dateFormat.addEventListener( 'change', () => syncCustomDateFormat( true ) );
			syncCustomDateFormat( false );
		}

		const cancel = form.querySelector( '.newspack-csv-export-modal__cancel' );
		if ( cancel ) {
			cancel.addEventListener( 'click', () => dialog.close( 'cancel' ) );
		}

		button.addEventListener( 'click', () => {
			// Escape closes a dialog with no result, leaving returnValue as it
			// was; only showModal() clears it, and only in engines new enough to
			// implement that. Clearing it here keeps a second Escape from
			// replaying the previous run's "export".
			dialog.returnValue = '';
			dialog.showModal();
		} );
		// A dialog form closes the dialog on submit, carrying the pressed
		// button's value.
		dialog.addEventListener( 'close', () => {
			if ( 'export' === dialog.returnValue ) {
				startExport();
			}
		} );
	} );
} );
