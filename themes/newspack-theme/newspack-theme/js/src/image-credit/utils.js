/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';

const CAPTION_ID = 'newspack-featured-image-caption';
export const CAPTION_FIELD_NAME = 'terminal_featured_image_caption';
export const CREDIT_FIELD_NAME = 'terminal_featured_image_credit';

/**
 * Appends featured image caption to DOM, below the Title in the Editor.
 *
 * @param {string} subtitle Subtitle text
 */
export const appendFeaturedImageToDomElement = ( caption, credit, isInCodeEditor ) => {
	let titleEl = document.querySelector('#newspack-post-subtitle-element');
	if ( ! titleEl ) {
		titleEl = document.querySelector( '.editor-post-title__block' ); // Legacy selector
	}
	if ( ! titleEl ) {
		titleEl = document.querySelector( '.edit-post-visual-editor__post-title-wrapper' );
	}

	if ( titleEl && typeof caption === 'string' && typeof credit === 'string' ) {
		let captionEl = document.getElementById( CAPTION_ID );
		if ( ! captionEl ) {
			captionEl = document.createElement( 'div' );
			captionEl.id = CAPTION_ID;
			// special style for the code (raw text) editor
			if ( isInCodeEditor ) {
				captionEl.style.paddingLeft = '14px';
				captionEl.style.marginBottom = '4px';
			}
			titleEl.appendChild( captionEl );
		}
		subtitleEl.innerHTML = `${caption} ${credit}`;
	}
};

export const connectWithSelect = withSelect( select => ( {
	caption: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[ CAPTION_FIELD_NAME ],
	credit: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[ CREDIT_FIELD_NAME ],
	mode: select( 'core/edit-post' ).getEditorMode(),
} ) );
