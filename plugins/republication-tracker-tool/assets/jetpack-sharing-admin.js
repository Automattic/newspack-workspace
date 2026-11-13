/**
 * Republish button on Jetpack's sharing settings screen.
 *
 * Jetpack prints the service tiles as escaped text, so the icon is copied in
 * from a template printed in PHP. In the "Official buttons" style, Jetpack's
 * live preview empties every button that is not on its own list of services;
 * the Republish button keeps its normal look on the front end in that style,
 * so the preview gets it back from Jetpack's hidden copy of every button.
 */
( function () {
	const template = document.getElementById( 'republication-tracker-tool-jetpack-icon' );
	if ( ! template || ! template.content.firstElementChild ) {
		return;
	}

	document.querySelectorAll( 'li.service.share-republish .options-left' ).forEach( function ( tile ) {
		tile.prepend( template.content.firstElementChild.cloneNode( true ) );
	} );

	const preview = document.getElementById( 'live-preview' );
	const buttonStyle = document.getElementById( 'button_style' );
	const original = preview && preview.querySelector( 'ul.archive li.preview-republish' );
	if ( ! buttonStyle || ! original ) {
		return;
	}

	const restoreOfficialPreview = function () {
		if ( 'official' !== buttonStyle.value ) {
			return;
		}
		preview.querySelectorAll( 'li.preview-republish' ).forEach( function ( item ) {
			if ( item.closest( 'ul.archive' ) || item.querySelector( '.sd-button' ) ) {
				return;
			}
			item.replaceWith( original.cloneNode( true ) );
		} );
	};

	new MutationObserver( restoreOfficialPreview ).observe( preview, { childList: true, subtree: true } );
	restoreOfficialPreview();
} )();
