/* globals newspack_popups_view */

/**
 * Carry the prompt-preview parameters onto same-origin links.
 *
 * A prompt preview renders the real front end inside an iframe in the editor,
 * with the previewed prompt's unsaved settings passed as query params. Those
 * params have to survive navigation: without them the first click inside the
 * preview lands on the ordinary published page, so the prompt stops following
 * the editor and unsaved edits stop being reflected.
 *
 * This runs in the previewed document rather than in the editor on purpose.
 * WordPress 7.1 serves the block editor with `Document-Isolation-Policy`, which
 * places it in its own agent cluster and severs synchronous access to the
 * preview iframe even though the frame is same-origin — so the editor can no
 * longer reach in and rewrite these links from outside. A document may always
 * rewrite its own. See NEWS-2889.
 */
export function propagatePreviewParams() {
	// Only localized on a preview request, so its absence means there is
	// nothing to propagate.
	const keys = newspack_popups_view?.preview_query_keys;
	if ( ! keys?.length ) {
		return;
	}

	const current = new URLSearchParams( window.location.search );
	const params = keys.filter( key => current.has( key ) ).map( key => [ key, current.get( key ) ] );
	if ( ! params.length ) {
		return;
	}

	document.querySelectorAll( 'a[href]' ).forEach( anchor => {
		const href = anchor.getAttribute( 'href' );

		// Leave in-page anchors alone; adding query params would reload the page
		// instead of jumping within it.
		if ( ! href || href.startsWith( '#' ) ) {
			return;
		}

		let url;
		try {
			url = new URL( anchor.href, window.location.origin );
		} catch ( e ) {
			return;
		}

		// Off-site links, and schemes like mailto: and tel: that resolve to a
		// null origin, are none of our business.
		if ( url.origin !== window.location.origin ) {
			return;
		}

		params.forEach( ( [ key, value ] ) => url.searchParams.set( key, value ) );
		anchor.setAttribute( 'href', url.toString() );
	} );
}
