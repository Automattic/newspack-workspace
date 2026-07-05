/* global newspack_handoff */

/**
 * Add handoff notice to block editor.
 */
( function ( wp ) {
	wp.data.dispatch( 'core/notices' ).createNotice( 'info', newspack_handoff.text, {
		isDismissible: true,
		actions: [
			{
				url: newspack_handoff.returnURL,
				label: newspack_handoff.buttonText,
			},
		],
	} );
} )( window.wp );
