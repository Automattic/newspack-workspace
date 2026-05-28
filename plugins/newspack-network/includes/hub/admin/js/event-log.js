/* globals newspackNetworkEventLogLabels, jQuery */

( function ( $ ) {
	$( document ).ready( function () {
		const dataColumns = document.querySelectorAll(
			'.newspack-network-data-column'
		);
		dataColumns.forEach( function ( column ) {
			const buttonEl = column.querySelector( 'button' );
			const textEl = column.querySelector( 'textarea' );
			if ( ! buttonEl || ! textEl ) {
				return;
			}
			const text = textEl.value;
			buttonEl.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				buttonEl.textContent = newspackNetworkEventLogLabels.copying;
				buttonEl.disabled = true;
				navigator.clipboard
					.writeText( text )
					.then( function () {
						buttonEl.textContent =
							newspackNetworkEventLogLabels.copied;
						setTimeout( function () {
							buttonEl.textContent =
								newspackNetworkEventLogLabels.copy;
							buttonEl.disabled = false;
						}, 1000 );
					} )
					.catch( function ( err ) {
						console.error( 'Failed to copy: ', err ); // eslint-disable-line no-console
						buttonEl.disabled = false;
					} );
			} );
		} );
	} );
} )( jQuery );
