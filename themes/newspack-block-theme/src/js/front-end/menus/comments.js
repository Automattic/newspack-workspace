/**
 * Internal dependencies.
 */
import { domReady } from '../utils';
import { createMenu, createFocusTrap } from './index';
import { ANIMATION_DURATION } from './consts';

/**
 * Swaps the .wp-block-comments element inside the panel with the one found in
 * the parsed HTML document, then updates the URL and focus trap.
 *
 * @param {Document}    doc      Parsed HTML document from a fetch response.
 * @param {string}      finalUrl The URL of the fetched page (after any redirects).
 * @param {HTMLElement} contents The .comments-menu__contents panel element.
 */
const swapCommentsBlock = ( doc, finalUrl, contents ) => {
	const commentsBlock = contents.querySelector( '.wp-block-comments' );
	if ( ! commentsBlock ) {
		return false;
	}

	const newBlock = doc.querySelector( '.comments-menu__contents .wp-block-comments' ) || doc.querySelector( '.wp-block-comments' );

	if ( ! newBlock ) {
		return false;
	}

	commentsBlock.replaceWith( newBlock );

	// Update the URL so refresh / auto-open logic stays accurate without adding a new history entry.
	history.replaceState( null, doc.title, finalUrl );

	// Scroll to the new comment if the URL has a hash, otherwise scroll to top.
	const hash = new URL( finalUrl ).hash;
	if ( hash ) {
		setTimeout( () => {
			const target = contents.querySelector( hash );
			if ( target ) {
				target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}, 100 );
	} else {
		contents.scrollTop = 0;
	}

	// Re-create the focus trap now that the DOM has changed.
	createFocusTrap( contents );

	return true;
};

/**
 * Loads a comment page via fetch and swaps the comments block content in the
 * panel without a full page reload.
 *
 * @param {string}      url      The URL to fetch (comment pagination link).
 * @param {HTMLElement} contents The .comments-menu__contents panel element.
 */
const loadCommentPage = ( url, contents ) => {
	const commentsBlock = contents.querySelector( '.wp-block-comments' );
	if ( ! commentsBlock ) {
		return;
	}

	// Show a loading state by reducing opacity.
	commentsBlock.style.opacity = '0.4';
	commentsBlock.style.pointerEvents = 'none';

	fetch( url )
		.then( response => {
			if ( ! response.ok ) {
				throw new Error( response.statusText );
			}
			const finalUrl = response.url;
			return response.text().then( html => ( { html, finalUrl } ) );
		} )
		.then( ( { html, finalUrl } ) => {
			const doc = new DOMParser().parseFromString( html, 'text/html' );
			if ( ! swapCommentsBlock( doc, finalUrl, contents ) ) {
				window.location.href = finalUrl;
			}
		} )
		.catch( () => {
			// On any error, fall back to normal navigation so pagination still works.
			window.location.href = url;
		} );
};

/**
 * Shows an error message inside the comment form and re-enables it.
 *
 * @param {HTMLFormElement} form    The comment form element.
 * @param {string}          message The error message to display.
 */
const showCommentFormError = ( form, message ) => {
	let noticeEl = form.querySelector( '.newspack-ui__notice--error' );
	if ( ! noticeEl ) {
		const wrapper = document.createElement( 'div' );
		wrapper.className = 'newspack-ui';
		noticeEl = document.createElement( 'p' );
		noticeEl.className = 'newspack-ui__notice newspack-ui__notice--error';
		wrapper.appendChild( noticeEl );
		form.prepend( wrapper );
	}
	noticeEl.textContent = message;
};

/**
 * Submits the comment form via fetch and swaps the comments block content in
 * the panel without a full page reload, keeping the panel open.
 *
 * @param {HTMLFormElement} form     The comment form element.
 * @param {HTMLElement}     contents The .comments-menu__contents panel element.
 */
const submitCommentForm = ( form, contents ) => {
	const commentsBlock = contents.querySelector( '.wp-block-comments' );
	if ( ! commentsBlock ) {
		return;
	}

	// Show a loading state by reducing opacity.
	commentsBlock.style.opacity = '0.4';
	commentsBlock.style.pointerEvents = 'none';

	fetch( form.action, {
		method: 'POST',
		body: new FormData( form ),
		redirect: 'follow',
	} )
		.then( response => {
			// Handle rate limiting with a user-visible message rather than a
			// silent fallback — the native form.submit() would hit the same limit.
			if ( response.status === 429 ) {
				commentsBlock.style.opacity = '';
				commentsBlock.style.pointerEvents = '';
				showCommentFormError(
					form,
					window.newspackScreenReaderText?.comment_too_fast ||
						'You are posting comments too quickly. Please wait a moment before trying again.'
				);
				return null;
			}
			if ( ! response.ok ) {
				throw new Error( response.statusText );
			}
			const finalUrl = response.url;
			return response.text().then( html => ( { html, finalUrl } ) );
		} )
		.then( result => {
			if ( ! result ) {
				return;
			}
			const { html, finalUrl } = result;
			const doc = new DOMParser().parseFromString( html, 'text/html' );
			if ( ! swapCommentsBlock( doc, finalUrl, contents ) ) {
				// Use the prototype method directly — WordPress's comment form has
				// <input name="submit"> which shadows the native form.submit().
				HTMLFormElement.prototype.submit.call( form );
			}
		} )
		.catch( () => {
			// On any error, fall back to normal form submission.
			HTMLFormElement.prototype.submit.call( form );
		} );
};

domReady( function () {
	const contents = document.querySelector( '.comments-menu__contents' );

	createMenu( {
		menuType: 'comments-menu',
		containerSelector: '.comments-menu',
		toggleSelector: '.comments-menu__toggle',
		contentsSelector: '.comments-menu__contents',
		onOpen: panelContents => {
			// Wait a bit for any dynamic content to load (like Disqus)
			setTimeout( () => {
				// Re-create focus trap after dynamic content loads
				createFocusTrap( panelContents );
			}, 100 );
		},
	} ).init();

	// Intercept pagination clicks inside the comment panel and load the new page inline rather than reloading page.
	if ( contents ) {
		contents.addEventListener( 'click', event => {
			const link = event.target.closest( '.wp-block-comments-pagination a' );
			if ( ! link ) {
				return;
			}
			// Only intercept same-origin links.
			if ( new URL( link.href ).origin !== window.location.origin ) {
				return;
			}
			event.preventDefault();
			loadCommentPage( link.href, contents );
		} );

		// Intercept comment form submission to keep the panel open after posting.
		contents.addEventListener( 'submit', event => {
			const form = event.target.closest( '#commentform' );
			if ( ! form ) {
				return;
			}
			// Only intercept same-origin form actions.
			if ( new URL( form.action ).origin !== window.location.origin ) {
				return;
			}
			event.preventDefault();
			submitCommentForm( form, contents );
		} );
	}

	// Auto-open the comments panel when the page loads via a comment pagination link (?cpage=N or /comment-page-N/) or a direct comment link (#comment-N).
	const isCommentPagination =
		new URLSearchParams( window.location.search ).has( 'cpage' ) || /\/comment-page-\d+\//i.test( window.location.pathname );
	const commentHash = /^#comment-\d+$/.test( window.location.hash ) ? window.location.hash : null;

	if ( isCommentPagination || commentHash ) {
		// The first .comments-menu__toggle in the DOM is the outer "Comments" open button.
		const toggle = document.querySelector( '.comments-menu__toggle' );
		if ( toggle ) {
			toggle.click();

			// After the panel's slide-in animation, scroll to the target comment.
			if ( commentHash ) {
				setTimeout( () => {
					const target = document.querySelector( commentHash );
					if ( target ) {
						target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				}, ANIMATION_DURATION.POSITION + 150 );
			}
		}
	}
} );
