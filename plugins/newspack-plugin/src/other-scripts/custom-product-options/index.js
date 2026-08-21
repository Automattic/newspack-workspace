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
		// Also sync the per-seat/per-team split directly, so a saved pricing mode
		// renders correctly even if the enabled checkbox's own change handler above
		// doesn't fire (e.g. an unchecked box, where the fields stay hidden anyway
		// but should still reflect the right internal state once revealed).
		$( 'select[id^="_newspack_group_subscription_pricing_mode"]' ).trigger( 'change' );
		$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded', init );
		$( '.woocommerce_variation' ).on( 'click', 'h3', init );
	}

	function showOrHidePricingOptions( e ) {
		// Group subscription checkbox.
		const $scope = $( e.currentTarget ).closest( '.woocommerce_variation,#woocommerce-product-data' );
		const $fields = $scope.find( '.show_if_newspack_group_subscription_enabled' );

		if ( $( e.currentTarget ).is( ':checked' ) ) {
			$fields.show();
			// Re-apply the pricing mode split, since the blanket .show() above just
			// revealed both the per-team and per-seat rows regardless of mode.
			showOrHidePerSeatOptions( $scope );
		} else {
			$fields.hide();
		}
	}

	// Pricing mode select. The variation row's select ID carries a `_<loop>` suffix
	// (e.g. `_newspack_group_subscription_pricing_mode_0`), hence the prefix match.
	function showOrHidePerSeatOptions( scope ) {
		const $scope = $( scope );
		const mode = $scope.find( 'select[id^="_newspack_group_subscription_pricing_mode"]' ).val();
		$scope.find( '.show_if_newspack_group_subscription_per_seat' ).toggle( mode === 'per_seat' );
		$scope.find( '.show_if_newspack_group_subscription_per_team' ).toggle( mode !== 'per_seat' );
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
	$( '#woocommerce-product-data' ).on( 'change', 'select[id^="_newspack_group_subscription_pricing_mode"]', function () {
		showOrHidePerSeatOptions( $( this ).closest( '.woocommerce_variation,#woocommerce-product-data' ) );
	} );

	$( document ).ready( init );
} )( jQuery );
