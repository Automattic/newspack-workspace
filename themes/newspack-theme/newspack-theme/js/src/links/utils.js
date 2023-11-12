/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';

const LINK_ID = 'newspack-external-link';
export const LINK_FIELD_NAME = 'link';

/**
 * Appends link to DOM, below the Title in the Editor.
 */
export const appendLinkToDomElement = ( link, isInCodeEditor ) => {
	let titleEl = document.querySelector( '.editor-post-title__block' ); // Legacy selector
	if ( ! titleEl ) {
		titleEl = document.querySelector(
			'.edit-post-visual-editor__post-title-wrapper'
		);
	}

	if ( titleEl && typeof link === 'string' ) {
		let linkEl = document.getElementById( LINK_ID );
		if ( ! linkEl ) {
			linkEl = document.createElement( 'div' );
			linkEl.id = LINK_ID;
			// special style for the code (raw text) editor
			if ( isInCodeEditor ) {
				linkEl.style.paddingLeft = '14px';
				linkEl.style.marginBottom = '4px';
			}
			titleEl.appendChild( linkEl );
		}
		linkEl.innerHTML = link;
	}
};

export const connectWithSelect = withSelect( ( select ) => ( {
	link: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[
		LINK_FIELD_NAME
	],
	mode: select( 'core/edit-post' ).getEditorMode(),
} ) );
