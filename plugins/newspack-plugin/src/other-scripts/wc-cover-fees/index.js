/* globals jQuery */
( function ( $ ) {
	if ( ! $ ) {
		return;
	}
	const $body = $( document.body );
	const getInputs = function () {
		return Array.from( document.querySelectorAll( '.newspack-cover-fees input' ) );
	};
	$body.on( 'init_checkout', function () {
		const form = document.querySelector( 'form.checkout' );
		if ( ! form ) {
			return;
		}
		let checked = document.querySelector( '.newspack-cover-fees input' )?.checked;
		form.addEventListener( 'change', function () {
			// Get elements on every change because the DOM is replaced by AJAX.
			const inputs = getInputs();
			if ( ! inputs.length ) {
				return;
			}

			// Check if any checkbox has changed.
			const update = inputs.find( function ( input ) {
				if ( input.checked !== checked ) {
					return true;
				}
				return false;
			} );

			// Update checked state of all checkboxes.
			if ( update ) {
				checked = update.checked;
				inputs.forEach( function ( input ) {
					input.checked = checked;
				} );
				$body.trigger( 'update_checkout', { update_shipping_method: false } );
			}
		} );
		// Trigger checkout update on payment method change so it updates the fee.
		$( document ).on( 'payment_method_selected', function () {
			$body.trigger( 'update_checkout', { update_shipping_method: false } );
		} );
	} );
} )( jQuery );
