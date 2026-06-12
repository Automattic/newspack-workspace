/**
 * Hides Redirection's log-retention controls on its options screen.
 *
 * Redirection's options page is a React SPA that renders asynchronously and on
 * hash navigation, so we watch for the two log-retention <select>s and hide
 * only their rows (not the "Logs" section header — track_hits / log_header live
 * under it and remain active). This is cosmetic: server-side filters are the
 * real enforcement, so if Redirection's markup changes this degrades to a no-op
 * (logging stays off regardless).
 *
 * Fallback (apply if hiding proves brittle in testing): instead of hiding the
 * row, set `select.disabled = true` and append a note element with
 * window.newspackRedirection.noteText. See the design doc, Layer 3.
 */
( function () {
	const SELECTORS = [ 'select[name="expire_redirect"]', 'select[name="expire_404"]' ];

	function hideLogRows() {
		SELECTORS.forEach( function ( selector ) {
			const control = document.querySelector( selector );
			if ( ! control ) {
				return;
			}
			const row = control.closest( 'tr' );
			if ( row && ! row.dataset.newspackHidden ) {
				row.style.display = 'none';
				row.dataset.newspackHidden = '1';
			}
		} );
	}

	function start() {
		hideLogRows();
		const observer = new MutationObserver( hideLogRows );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
