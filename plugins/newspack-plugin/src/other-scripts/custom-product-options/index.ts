/* globals jQuery */

/**
 * Custom Product Options admin JS.
 */

( function ( $ ) {
	if ( ! $ ) {
		return;
	}

	function init() {
		$( 'input#_newspack_group_subscription_enabled,input.variable_newspack_group_subscription_enabled' ).trigger( 'change' );
		$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded', init );
		$( '.woocommerce_variation' ).on( 'click', 'h3', init );
	}

	function showOrHidePricingOptions( e ) {
		// Group subscription checkbox.
		const $fields = $( e.currentTarget )
			.closest( '.woocommerce_variation,#woocommerce-product-data' )
			.find( '.show_if_newspack_group_subscription_enabled' );

		if ( $( e.currentTarget ).is( ':checked' ) ) {
			$fields.show();
		} else {
			$fields.hide();
		}
	}

	function showOrHideAllOptions( e ) {
		const $checkbox = $( '.show_if_subscription' );
		const $fields = $( '.show_if_newspack_group_subscription_enabled' );

		if ( e.currentTarget.value === 'subscription' || e.currentTarget.value === 'variable-subscription' ) {
			$checkbox.show();
			if ( $checkbox.is( ':checked' ) ) {
				$fields.show();
			} else {
				$fields.hide();
			}
		} else {
			$checkbox.hide();
		}
	}

	$( '#woocommerce-product-data' ).on(
		'change',
		'input#_newspack_group_subscription_enabled,input.variable_newspack_group_subscription_enabled',
		showOrHidePricingOptions
	);
	$( '#woocommerce-product-data' ).on( 'change', 'select#product-type', showOrHideAllOptions );

	$( document ).ready( init );
} )( jQuery );
