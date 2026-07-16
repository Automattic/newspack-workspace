/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';

const SUBTITLE_ID = 'newspack-post-subtitle-element';
export const META_FIELD_NAME = 'newspack_post_subtitle';

const SUBTITLE_RETRY_LIMIT = 20;
const SUBTITLE_RETRY_INTERVAL_MS = 100;

/**
 * Allowed inline tags and their permitted attributes for the subtitle preview.
 * Mirrors the $subtitle_allowed_tags allowlist in newspack_post_subtitle() (template-tags.php).
 * TODO: Keep in sync with $subtitle_allowed_tags in inc/template-tags.php.
 */
const SUBTITLE_ALLOWED_TAGS: Record< string, string[] > = {
	b: [],
	strong: [],
	i: [],
	em: [],
	mark: [],
	u: [],
	small: [],
	sub: [],
	sup: [],
	a: [ 'href' ],
};

/** URL attributes that must pass a protocol check before being allowed. */
const URL_ATTRS = [ 'href' ];

/**
 * Sanitizes a subtitle string to the inline-tag allowlist before injecting
 * into the editor DOM. Disallowed elements are unwrapped (their text content
 * is preserved); disallowed attributes are removed.
 *
 * @param {string} html Raw subtitle string, possibly containing HTML.
 * @return {string} Sanitized HTML string.
 */
const sanitizeSubtitle = ( html: string ): string => {
	const parsed = new DOMParser().parseFromString( html, 'text/html' );

	const walk = ( node: Node ) => {
		let i = 0;
		while ( i < node.childNodes.length ) {
			// Narrowed to Element below the nodeType check; DOM lib types don't refine on nodeType comparisons.
			const child = node.childNodes[ i ] as Element;
			if ( child.nodeType !== Node.ELEMENT_NODE ) {
				i++;
				continue;
			}
			const tag = child.tagName.toLowerCase();
			const allowedAttrs = SUBTITLE_ALLOWED_TAGS[ tag ];
			if ( allowedAttrs === undefined ) {
				child.replaceWith( ...Array.from( child.childNodes ) );
				// Don't increment i — promoted children now sit at position i
			} else {
				Array.from( child.attributes ).forEach( attr => {
					if ( ! allowedAttrs.includes( attr.name ) ) {
						child.removeAttribute( attr.name );
					}
				} );
				URL_ATTRS.forEach( urlAttr => {
					if ( ! child.hasAttribute( urlAttr ) ) {
						return;
					}
					const value = child.getAttribute( urlAttr )!.trim();
					let safe = false;
					try {
						const parsedUrl = new URL( value, 'https://x' );
						safe = [ 'http:', 'https:', 'mailto:', 'tel:' ].includes( parsedUrl.protocol );
					} catch ( e ) {
						safe = /^[#/]/.test( value );
					}
					if ( ! safe ) {
						child.removeAttribute( urlAttr );
					}
				} );
				walk( child );
				i++;
			}
		}
	};

	walk( parsed.body );
	return parsed.body.innerHTML;
};

/**
 * Tracks the single in-flight retry timeout to prevent overlapping chains.
 * Untyped `null` doesn't satisfy `clearTimeout`'s `number | undefined` param, so
 * this uses `undefined` as the "no timer" sentinel instead (behaviorally identical --
 * nothing else in this module distinguishes null from undefined here).
 */
let subtitleRetryTimeout: ReturnType< typeof setTimeout > | undefined;

/**
 * Appends subtitle to DOM, below the Title in the Editor.
 *
 * @param {string} subtitle   Subtitle text
 * @param {number} retryCount Internal retry counter
 */
export const appendSubtitleToTitleDOMElement = ( subtitle: string, retryCount = 0 ): void => {
	// In WordPress 7.0+ the editor is always iframed; use the canvas document.
	// TODO: Remove `document` fallback once WordPress 7.0 is released and the non-iframed editor is no longer supported.
	const editorCanvas = document.querySelector( 'iframe[name="editor-canvas"]' );
	const doc = ( editorCanvas && ( editorCanvas as HTMLIFrameElement ).contentDocument ) || document;
	const titleEl = doc.querySelector( '.edit-post-visual-editor__post-title-wrapper' );

	clearTimeout( subtitleRetryTimeout );

	if ( titleEl && typeof subtitle === 'string' ) {
		let subtitleEl = doc.getElementById( SUBTITLE_ID );
		const titleParent = titleEl.parentNode as HTMLElement;
		if ( ! subtitleEl ) {
			subtitleEl = doc.createElement( 'div' );
			subtitleEl.id = SUBTITLE_ID;
			titleParent.insertBefore( subtitleEl, titleEl.nextSibling );
		}
		subtitleEl.innerHTML = sanitizeSubtitle( subtitle );
	} else if ( ! titleEl && typeof subtitle === 'string' && subtitle.length > 0 && retryCount < SUBTITLE_RETRY_LIMIT ) {
		subtitleRetryTimeout = setTimeout( () => appendSubtitleToTitleDOMElement( subtitle, retryCount + 1 ), SUBTITLE_RETRY_INTERVAL_MS );
	}
};

export const connectWithSelect = withSelect( select => {
	// The editor selectors are untyped for string-keyed stores; assert at the store boundary.
	const { getEditedPostAttribute } = select( 'core/editor' ) as {
		getEditedPostAttribute: ( attribute: string ) => Record< string, unknown >;
	};
	return {
		subtitle: getEditedPostAttribute( 'meta' )[ META_FIELD_NAME ] as string,
	};
} );
