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
 *
 * AMP pages are not covered. The param list is localized alongside `view.js`,
 * which the inserter only registers on non-AMP requests, so a site in AMP
 * standard mode keeps the pre-7.1 behaviour. Closing that would mean rewriting
 * the links server-side, since AMP forbids arbitrary inline script.
 *
 * Kept deliberately in step with
 * newspack-plugin/src/content-gate/preview-links.js, which solves the same
 * problem for gate previews. The two are duplicated rather than shared: a shared
 * package would tie two independently released plugins to one version, which is
 * the wrong trade for a fix this small. If you change one, change the other.
 */
export function propagatePreviewParams() {
	// Previews only ever render inside the editor's preview iframe, so requiring a
	// frame is what keeps preview mode from following an admin around the site. An
	// admin who lands on a preview URL top-level — a new tab, a pasted link — is
	// browsing, not previewing, and should get ordinary links; preview mode also
	// hides the admin bar, so sticky params there would leave no way out but
	// editing the URL by hand. Comparing `window.top` is a reference check, which
	// cross-origin isolation still permits.
	if ( window.self === window.top ) {
		return;
	}

	// Only localized on a prompt preview, so its absence means there is nothing
	// to propagate. Read through `window.` rather than as a bare identifier:
	// optional chaining guards a null value, not an undeclared binding, and an
	// undeclared one throws.
	const params = window.newspack_popups_view?.preview_param_names;
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
		// null origin, are none of our business. Matching on origin rather than on
		// the site URL means a subdirectory install also stamps links that sit
		// outside WordPress; that is deliberate, because it is what makes
		// multibranded sites work, where brands are same-origin paths. A stray
		// `pid` on such a page resolves to no prompt and does nothing.
		if ( url.origin !== window.location.origin ) {
			return;
		}

		present.forEach( ( [ key, value ] ) => url.searchParams.set( key, value ) );

		// Keep the href in the shape the page wrote it, because a preview exists to
		// show what production does. Re-serializing through URL() turns a relative
		// href absolute, which breaks exactly the theme code a preview should
		// exercise: `a[href^="/"]` selectors, "current item" scripts comparing an
		// href against location.pathname. Only the query is ours to change. Two
		// residual differences we accept: a path-relative href comes back
		// root-relative, since resolving it is what told us where it points, and
		// URLSearchParams re-encodes an existing query (`%20` to `+`) — forms
		// servers treat as equivalent, and unpicking it would mean editing the
		// query string by hand.
		const isAbsolute = /^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test( href );
		anchor.setAttribute( 'href', isAbsolute ? url.toString() : url.pathname + url.search + url.hash );
	} );
}
