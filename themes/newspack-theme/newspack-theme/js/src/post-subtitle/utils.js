/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';

const SUBTITLE_ID = 'newspack-post-subtitle-element';
export const META_FIELD_NAME = 'newspack_post_subtitle';

const TITLE_SELECTOR = '.edit-post-visual-editor__post-title-wrapper';

/**
 * Allowed inline tags and their permitted attributes for the subtitle preview.
 * Mirrors the $subtitle_allowed_tags allowlist in newspack_post_subtitle() (template-tags.php).
 * TODO: Keep in sync with $subtitle_allowed_tags in inc/template-tags.php.
 */
const SUBTITLE_ALLOWED_TAGS = {
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
const sanitizeSubtitle = html => {
	const parsed = new DOMParser().parseFromString( html, 'text/html' );

	const walk = node => {
		let i = 0;
		while ( i < node.childNodes.length ) {
			const child = node.childNodes[ i ];
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
					const value = child.getAttribute( urlAttr ).trim();
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

/** Sanitized markup for the current subtitle, or null until the meta value is known. */
let subtitleHtml = null;
let pageObserver = null;
let canvasObserver = null;
let observedCanvas = null;
let observedCanvasDoc = null;
let renderScheduled = false;

/**
 * Inserts or updates the subtitle below the title, if the title is rendered.
 * Safe to call repeatedly: it only touches the DOM when something is missing or stale.
 */
const renderSubtitle = () => {
	renderScheduled = false;

	// The post editor canvas is normally an iframe; fall back to the outer document when it isn't.
	// TODO: Remove the `document` fallback once WordPress no longer renders a non-iframed post editor.
	const editorCanvas = document.querySelector( 'iframe[name="editor-canvas"]' );
	const doc = ( editorCanvas && editorCanvas.contentDocument ) || document;

	// The canvas swaps its document as it loads (about:blank, then the editor) without
	// changing the outer page, so listen for that and observe whichever document is current.
	if ( editorCanvas && editorCanvas !== observedCanvas ) {
		editorCanvas.addEventListener( 'load', scheduleRender );
		observedCanvas = editorCanvas;
	}
	if ( doc !== document && doc !== observedCanvasDoc ) {
		canvasObserver.disconnect();
		canvasObserver.observe( doc, { childList: true, subtree: true } );
		observedCanvasDoc = doc;
	}

	const titleEl = doc.querySelector( TITLE_SELECTOR );
	if ( ! titleEl || subtitleHtml === null ) {
		return;
	}

	let subtitleEl = doc.getElementById( SUBTITLE_ID );
	if ( ! subtitleEl ) {
		subtitleEl = doc.createElement( 'div' );
		subtitleEl.id = SUBTITLE_ID;
		titleEl.parentNode.insertBefore( subtitleEl, titleEl.nextSibling );
	}
	// Comparing first keeps our own write from re-triggering the observer in a loop.
	if ( subtitleEl.innerHTML !== subtitleHtml ) {
		subtitleEl.innerHTML = subtitleHtml;
	}
};

/** Batches render requests (observers, canvas load) to at most one per animation frame. */
const scheduleRender = () => {
	if ( renderScheduled ) {
		return;
	}
	renderScheduled = true;
	window.requestAnimationFrame( renderSubtitle );
};

/**
 * Shows the subtitle below the Title in the Editor.
 *
 * The title renders at an unpredictable time after the editor loads (later in some
 * browsers), and can be re-rendered (e.g. after switching from the code editor), so
 * observers re-add the subtitle whenever the title is present without it.
 *
 * @param {string|undefined} subtitle Subtitle text, or undefined while the post meta is loading.
 */
export const appendSubtitleToTitleDOMElement = subtitle => {
	subtitleHtml = null;
	if ( typeof subtitle === 'string' ) {
		// Writing can change sanitized markup (e.g. the parser splits nested links), so store
		// the form the browser reads back; otherwise the comparison in renderSubtitle never settles.
		const normalizer = document.createElement( 'template' );
		normalizer.innerHTML = sanitizeSubtitle( subtitle );
		subtitleHtml = normalizer.innerHTML;
	}

	if ( ! pageObserver ) {
		canvasObserver = new MutationObserver( scheduleRender );
		pageObserver = new MutationObserver( scheduleRender );
		pageObserver.observe( document.body, { childList: true, subtree: true } );
	}

	renderSubtitle();
};

export const connectWithSelect = withSelect( select => ( {
	subtitle: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[ META_FIELD_NAME ],
} ) );
