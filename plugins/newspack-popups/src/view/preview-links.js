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
	// Only localized on a prompt preview, so its absence means there is nothing
	// to propagate. Read through `window.` rather than as a bare identifier:
	// optional chaining guards a null value, not an undeclared binding, and an
	// undeclared one throws.
	const params = window.newspack_popups_view?.preview_query_params;
	if ( ! params?.length ) {
		return;
	}

	const current = new URLSearchParams( window.location.search );
	const present = params.filter( key => current.has( key ) ).map( key => [ key, current.get( key ) ] );
	if ( ! present.length ) {
		return;
	}

	// One eager pass at domReady. Overlays are already in the DOM (they print at
	// wp_footer), but anchors added later — "Load more", modal checkout, anything
	// AJAX — keep their own hrefs and leave the preview on the first click. That
	// matches the handler this replaced; a capture-phase click interceptor would
	// close the gap if it ever proves worth the extra surface.
	document.querySelectorAll( 'a[href]' ).forEach( anchor => {
		// Read the attribute rather than the `href` property: the selector also
		// matches SVG <a>, whose property is an SVGAnimatedString, and resolving
		// that yields a same-origin garbage path that would overwrite the link.
		const href = anchor.getAttribute( 'href' );

		// Leave in-page anchors alone; adding query params would reload the page
		// instead of jumping within it.
		if ( href.startsWith( '#' ) ) {
			return;
		}

		let url;
		try {
			// Resolve against the document base so relative hrefs work and a <base>
			// tag is respected.
			url = new URL( href, document.baseURI );
		} catch ( e ) {
			return;
		}

		// Off-site links, and schemes like mailto: and tel: that resolve to a
		// null origin, are none of our business.
		if ( url.origin !== window.location.origin ) {
			return;
		}

		present.forEach( ( [ key, value ] ) => url.searchParams.set( key, value ) );
		anchor.setAttribute( 'href', url.toString() );
	} );
}
