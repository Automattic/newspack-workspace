/**
 * Comments Panel Block — Frontend Script
 *
 * One panel per page (id="newspack-comments-panel"), controlled by any number of
 * trigger buttons. Handles opening and closing the panel, plus the comment-specific
 * behaviors inside it: inline pagination, inline form submission, loading state,
 * and auto-opening when the page loads on a comment link or paginated view.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

const PANEL_ID = 'newspack-comments-panel';
const BODY_OPEN_CLASS = 'comments-panel-open';
const SLIDE_FALLBACK_MS = 600;
// Slide duration (250ms in style.scss) plus a short buffer before scrolling to a linked comment.
const SCROLL_TO_COMMENT_DELAY_MS = 400;

// Focusable element selector.
const FOCUSABLE_SELECTOR =
	'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), ' +
	'textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), iframe, object, embed, ' +
	'[contenteditable="true"]';

/**
 * Returns all visible, focusable elements within a container.
 *
 * @param {HTMLElement} container
 * @return {HTMLElement[]} Visible, focusable elements within the container.
 */
const getVisibleFocusable = container =>
	Array.from( container.querySelectorAll( FOCUSABLE_SELECTOR ) ).filter( el => {
		try {
			const rect = el.getBoundingClientRect();
			const style = window.getComputedStyle( el );
			return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none' && ! el.hasAttribute( 'hidden' );
		} catch {
			return false;
		}
	} );

/**
 * Creates the single comments panel controller for the page.
 *
 * @param {HTMLElement}   panel    The panel element (#newspack-comments-panel).
 * @param {HTMLElement[]} triggers All trigger buttons that control the panel.
 */
const createCommentsPanel = ( panel, triggers ) => {
	const closeBtn = panel.querySelector( '.comments-panel__close' );

	let isOpen = false;
	let lastFocused = null;
	let overlay = null;
	let focusTrapCleanup = null;
	let escCleanup = null;

	// Move the panel to document.body once so position:fixed works regardless of
	// ancestor CSS transforms / stacking contexts.
	document.body.appendChild( panel );

	// ─── Open/close: overlay, slide animation, focus trap, and trigger wiring ────

	const showOverlay = color => {
		overlay = document.createElement( 'div' );
		overlay.className = 'comments-panel__scrim alignfull';
		overlay.setAttribute( 'aria-hidden', 'true' );
		overlay.style.opacity = '0';
		if ( color ) {
			overlay.style.background = color;
		}
		overlay.addEventListener( 'click', closePanel );
		document.body.appendChild( overlay );
		void overlay.offsetHeight;
		requestAnimationFrame( () => {
			overlay.style.opacity = '1';
		} );
	};

	const hideOverlay = () => {
		if ( ! overlay ) {
			return;
		}
		const el = overlay;
		overlay = null;
		el.style.opacity = '0';
		const cleanup = () => el.remove();
		el.addEventListener( 'transitionend', cleanup, { once: true } );
		setTimeout( cleanup, SLIDE_FALLBACK_MS );
	};

	const slideIn = () => {
		void panel.offsetHeight;
		panel.classList.add( 'comments-panel__panel--open' );
	};

	const slideOut = () => {
		panel.classList.remove( 'comments-panel__panel--open' );
	};

	const trapFocus = () => {
		const handleKeyDown = e => {
			if ( e.key !== 'Tab' ) {
				return;
			}
			const focusable = getVisibleFocusable( panel );
			if ( ! focusable.length ) {
				e.preventDefault();
				return;
			}
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			const active = panel.ownerDocument.activeElement;
			if ( e.shiftKey && active === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && active === last ) {
				e.preventDefault();
				first.focus();
			}
		};
		// Tab presses inside iframes (e.g. Disqus) never reach this document, so
		// also pull focus back if it lands outside the panel.
		const handleFocusIn = e => {
			if ( panel.contains( e.target ) ) {
				return;
			}
			const first = getVisibleFocusable( panel )[ 0 ] || closeBtn;
			if ( first ) {
				first.focus();
			}
		};
		document.addEventListener( 'keydown', handleKeyDown, true );
		document.addEventListener( 'focusin', handleFocusIn );
		return () => {
			document.removeEventListener( 'keydown', handleKeyDown, true );
			document.removeEventListener( 'focusin', handleFocusIn );
		};
	};

	const openPanel = () => {
		if ( isOpen ) {
			return;
		}
		isOpen = true;
		lastFocused = panel.ownerDocument.activeElement;

		slideIn();

		triggers.forEach( t => t.setAttribute( 'aria-expanded', 'true' ) );
		panel.setAttribute( 'aria-hidden', 'false' );
		panel.removeAttribute( 'inert' );
		document.body.classList.add( BODY_OPEN_CLASS );

		showOverlay( panel.dataset.overlayColor || '' );

		focusTrapCleanup = trapFocus();

		const onEsc = e => {
			if ( e.key === 'Escape' ) {
				closePanel();
			}
		};
		document.addEventListener( 'keydown', onEsc );
		escCleanup = () => document.removeEventListener( 'keydown', onEsc );

		setTimeout( () => {
			const firstFocusable = getVisibleFocusable( panel )[ 0 ] || closeBtn;
			if ( firstFocusable && document.contains( firstFocusable ) ) {
				firstFocusable.focus();
			}
		}, 50 );
	};

	const closePanel = () => {
		if ( ! isOpen ) {
			return;
		}
		isOpen = false;

		if ( focusTrapCleanup ) {
			focusTrapCleanup();
			focusTrapCleanup = null;
		}
		if ( escCleanup ) {
			escCleanup();
			escCleanup = null;
		}

		triggers.forEach( t => t.setAttribute( 'aria-expanded', 'false' ) );
		panel.setAttribute( 'aria-hidden', 'true' );
		panel.setAttribute( 'inert', '' );
		document.body.classList.remove( BODY_OPEN_CLASS );

		if ( lastFocused && document.contains( lastFocused ) ) {
			lastFocused.focus();
		}

		hideOverlay();
		slideOut();
	};

	triggers.forEach( trigger => {
		trigger.addEventListener( 'click', () => ( isOpen ? closePanel() : openPanel() ) );
	} );

	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', e => {
			e.preventDefault();
			closePanel();
		} );
	}

	// ─── Comment behaviors: inline pagination and form submission ────────────────

	const getCommentIds = () => new Set( Array.from( panel.querySelectorAll( '[id^="comment-"]' ), el => el.id ) );

	// The swap removes whatever had focus, so move it to the new comment or the
	// comments heading to keep keyboard and screen reader users inside the panel.
	const focusWithin = el => {
		if ( ! isOpen || ! el ) {
			return;
		}
		if ( ! el.hasAttribute( 'tabindex' ) ) {
			el.setAttribute( 'tabindex', '-1' );
		}
		el.focus( { preventScroll: true } );
	};

	// Swaps the .wp-block-comments element inside the panel with the one in the
	// fetched document, updates the URL, and moves scroll and focus. Pass the
	// comment IDs present before a submission to land on the newly posted comment;
	// the redirect's #comment-N can't be used because Response.url drops fragments.
	const swapCommentsBlock = ( doc, finalUrl, previousCommentIds = null ) => {
		const commentsBlock = panel.querySelector( '.wp-block-comments' );
		if ( ! commentsBlock ) {
			return false;
		}
		const newBlock = doc.querySelector( '#newspack-comments-panel .wp-block-comments' ) || doc.querySelector( '.wp-block-comments' );
		if ( ! newBlock ) {
			return false;
		}
		commentsBlock.replaceWith( newBlock );

		const newComment = previousCommentIds
			? Array.from( newBlock.querySelectorAll( '[id^="comment-"]' ) ).find(
					el => /^comment-\d+$/.test( el.id ) && ! previousCommentIds.has( el.id )
			  )
			: null;

		const url = new URL( finalUrl );
		if ( newComment ) {
			url.hash = newComment.id;
		}
		history.replaceState( null, doc.title, url.href );

		if ( newComment ) {
			focusWithin( newComment );
			setTimeout( () => newComment.scrollIntoView( { behavior: 'smooth', block: 'start' } ), 100 );
		} else {
			focusWithin( newBlock.querySelector( '.wp-block-comments-title' ) || newBlock );
			panel.scrollTop = 0;
		}

		return true;
	};

	const setLoading = ( block, loading ) => {
		block.style.opacity = loading ? '0.4' : '';
		block.style.pointerEvents = loading ? 'none' : '';
	};

	const loadCommentPage = url => {
		const commentsBlock = panel.querySelector( '.wp-block-comments' );
		if ( ! commentsBlock ) {
			return;
		}
		setLoading( commentsBlock, true );
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
				if ( ! swapCommentsBlock( doc, finalUrl ) ) {
					window.location.href = finalUrl;
				}
			} )
			.catch( () => {
				window.location.href = url;
			} );
	};

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

	let isSubmitting = false;

	const submitCommentForm = form => {
		const commentsBlock = panel.querySelector( '.wp-block-comments' );
		if ( ! commentsBlock ) {
			return;
		}
		isSubmitting = true;
		const previousCommentIds = getCommentIds();
		setLoading( commentsBlock, true );

		const showError = message => {
			isSubmitting = false;
			setLoading( commentsBlock, false );
			showCommentFormError( form, message || panel.dataset.errorMessage );
		};

		fetch( form.action, {
			method: 'POST',
			body: new FormData( form ),
			redirect: 'follow',
		} ).then(
			response => {
				if ( response.status === 429 ) {
					showError( panel.dataset.rateLimitMessage );
					return;
				}
				// The server has handled the comment from here on, so never re-submit it:
				// that would post it twice or replay the same error. Validation and
				// duplicate errors come back as a wp_die() page, so surface its message.
				return response
					.text()
					.then( html => {
						const doc = new DOMParser().parseFromString( html, 'text/html' );
						if ( response.ok && swapCommentsBlock( doc, response.url, previousCommentIds ) ) {
							isSubmitting = false;
							return;
						}
						showError( doc.querySelector( '.wp-die-message' )?.textContent.trim() );
					} )
					.catch( () => showError() );
			},
			() => {
				// The request never got a response, so fall back to a regular submission.
				// WordPress's comment form has <input name="submit"> which shadows the
				// native form.submit(); call the prototype method directly.
				HTMLFormElement.prototype.submit.call( form );
			}
		);
	};

	// Intercept pagination clicks (same-origin) and load inline.
	panel.addEventListener( 'click', event => {
		const link = event.target.closest( '.wp-block-comments-pagination a' );
		if ( ! link ) {
			return;
		}
		if ( new URL( link.href ).origin !== window.location.origin ) {
			return;
		}
		event.preventDefault();
		loadCommentPage( link.href );
	} );

	// Intercept comment form submission (same-origin) to keep the panel open.
	panel.addEventListener( 'submit', event => {
		const form = event.target.closest( '#commentform' );
		if ( ! form ) {
			return;
		}
		if ( new URL( form.action ).origin !== window.location.origin ) {
			return;
		}
		event.preventDefault();
		// Enter in a field can submit again while the first request is pending.
		if ( isSubmitting ) {
			return;
		}
		submitCommentForm( form );
	} );

	// Auto-open on comment pagination (?cpage / /comment-page-N) or a comment link
	// (#comment-N, or core's #comments / #respond from comment-count links).
	const isCommentPagination =
		new URLSearchParams( window.location.search ).has( 'cpage' ) || /\/comment-page-\d+(\/|$)/i.test( window.location.pathname );
	const commentHash = /^#(comment-\d+|comments|respond)$/.test( window.location.hash ) ? window.location.hash : null;

	if ( isCommentPagination || commentHash ) {
		openPanel();
		if ( commentHash ) {
			setTimeout( () => {
				const target = document.querySelector( commentHash );
				if ( target ) {
					target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			}, SCROLL_TO_COMMENT_DELAY_MS );
		}
	}
};

// Initialization.
domReady( () => {
	const panel = document.getElementById( PANEL_ID );
	const triggers = Array.from( document.querySelectorAll( '.comments-panel__trigger' ) );
	if ( ! panel || ! triggers.length ) {
		return;
	}
	createCommentsPanel( panel, triggers );
} );
