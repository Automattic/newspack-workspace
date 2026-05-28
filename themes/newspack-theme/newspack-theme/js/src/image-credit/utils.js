/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';

const CAPTION_ID = 'newspack-featured-image-caption';
export const CAPTION_FIELD_NAME = 'terminal_featured_meta_caption';
export const CREDIT_FIELD_NAME = 'terminal_featured_meta_credit';

const CAPTION_RETRY_LIMIT = 20;
const CAPTION_RETRY_INTERVAL_MS = 100;

/** Tracks the single in-flight retry timeout to prevent overlapping chains. */
let captionRetryTimeout = null;

/**
 * Appends featured image caption to DOM, below the Title in the Editor.
 *
 * @param {string}  caption        Caption text.
 * @param {string}  credit         Credit text.
 * @param {boolean} isInCodeEditor Whether the editor is in code (text) mode.
 * @param {number}  retryCount     Internal retry counter.
 */
export const appendFeaturedImageToDomElement = (
	caption,
	credit,
	isInCodeEditor,
	retryCount = 0
) => {
	// In WordPress 7.0+ the editor is always iframed; use the canvas document.
	// TODO: Remove `document` fallback once WordPress 7.0 is released and the non-iframed editor is no longer supported.
	const editorCanvas = document.querySelector( 'iframe[name="editor-canvas"]' );
	const doc = ( editorCanvas && editorCanvas.contentDocument ) || document;

	let titleEl = doc.querySelector( '#newspack-post-subtitle-element' );
	if ( ! titleEl ) {
		titleEl = doc.querySelector( '.edit-post-visual-editor__post-title-wrapper' );
	}

	clearTimeout( captionRetryTimeout );

	if (
		titleEl &&
		typeof caption === 'string' &&
		typeof credit === 'string'
	) {
		let captionEl = doc.getElementById( CAPTION_ID );
		if ( ! captionEl ) {
			captionEl = doc.createElement( 'div' );
			captionEl.id = CAPTION_ID;
			// special style for the code (raw text) editor
			if ( isInCodeEditor ) {
				captionEl.style.paddingLeft = '14px';
				captionEl.style.marginBottom = '4px';
			}
			titleEl.appendChild( captionEl );
		}
		captionEl.innerHTML = `${ caption } ${ credit }`;
	} else if (
		! titleEl &&
		typeof caption === 'string' &&
		typeof credit === 'string' &&
		( caption.length > 0 || credit.length > 0 ) &&
		retryCount < CAPTION_RETRY_LIMIT
	) {
		captionRetryTimeout = setTimeout(
			() => appendFeaturedImageToDomElement( caption, credit, isInCodeEditor, retryCount + 1 ),
			CAPTION_RETRY_INTERVAL_MS
		);
	}
};

export const connectWithSelect = withSelect( ( select ) => ( {
	caption:
		select( 'core/editor' ).getEditedPostAttribute( 'meta' )[
			CAPTION_FIELD_NAME
		],
	credit: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[
		CREDIT_FIELD_NAME
	],
	mode: select( 'core/edit-post' ).getEditorMode(),
} ) );
