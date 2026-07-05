/* global jQuery,newspack_change_nicename_params */

// eslint-disable object-shorthand

/**
 * Internal dependencies
 */
import './style.scss';

( function ( $ ) {
	function newspack_change_nicename_set_loading_state() {
		$( '#newspack_change_nicename_check' ).prop( 'disabled', true );
		$( '#newspack_change_nicename_submit' ).prop( 'disabled', true );
		$( '#newspack_change_nicename' ).prop( 'disabled', true );
		$( '#newspack_change_nicename_message_success' ).html( '' ).hide();
		$( '#newspack_change_nicename_message_error' ).html( '' ).hide();
	}

	function newspack_change_nicename_unset_loading_state() {
		$( '#newspack_change_nicename_check' ).prop( 'disabled', false );
		$( '#newspack_change_nicename_submit' ).prop( 'disabled', false );
		$( '#newspack_change_nicename' ).prop( 'disabled', false );
	}

	function newspack_change_nicename_process_response( response ) {
		if ( response.success ) {
			$( '#newspack_change_nicename_message_success' ).html( response.message ).show();
		} else {
			$( '#newspack_change_nicename_message_error' ).html( response.message ).show();
		}
	}

	$( '#newspack_change_nicename_check' ).on( 'click', function () {
		event.preventDefault();
		const new_nicename = $( '#newspack_change_nicename' ).val();
		if ( ! new_nicename ) {
			alert( newspack_change_nicename_params.empty_message ); // eslint-disable-line no-alert
			return;
		}
		newspack_change_nicename_set_loading_state();
		const nonce = $( '#newspack_change_nicename_nonce' ).val();
		$.ajax( {
			type: 'POST',
			url: newspack_change_nicename_params.ajax_url,
			data: {
				action: 'newspack_change_nicename_check',
				new_nicename,
				nonce,
			},
			success( response ) {
				newspack_change_nicename_unset_loading_state();
				newspack_change_nicename_process_response( response );
			},
		} );
	} );

	$( '#newspack_change_nicename_submit' ).on( 'click', function () {
		event.preventDefault();
		const new_nicename = $( '#newspack_change_nicename' ).val();
		if ( ! new_nicename ) {
			alert( newspack_change_nicename_params.empty_message ); // eslint-disable-line no-alert
			return;
		}
		newspack_change_nicename_set_loading_state();
		const user_id = $( this ).data( 'user-id' );
		const nonce = $( '#newspack_change_nicename_nonce' ).val();
		$.ajax( {
			type: 'POST',
			url: newspack_change_nicename_params.ajax_url,
			data: {
				action: 'newspack_change_nicename',
				new_nicename,
				nonce,
				user_id,
			},
			success( response ) {
				newspack_change_nicename_unset_loading_state();
				newspack_change_nicename_process_response( response );
			},
		} );
	} );
} )( jQuery );
