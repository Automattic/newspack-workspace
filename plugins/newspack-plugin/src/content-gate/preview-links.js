/**
 * Carry the gate-preview parameters onto same-origin links.
 *
 * A gate preview renders the real front end inside an iframe in the layout
 * editor, with the previewed layout's unsaved settings passed as query params.
 * Those params have to survive navigation, or the first click inside the preview
 * lands on the ordinary page and the editor loses what they were previewing.
 *
 * This runs in the previewed document rather than in the editor on purpose.
 * WordPress 7.1 serves the block editor with `Document-Isolation-Policy`, which
 * places it in its own agent cluster and severs synchronous access to the
 * preview iframe even though the frame is same-origin — so the editor can no
 * longer reach in and rewrite these links from outside. A document may always
 * rewrite its own. Same fix as NEWS-2889, applied to the gate preview.
 *
 * Kept deliberately in step with newspack-popups/src/view/preview-links.js,
 * which solves the same problem for prompt previews. The two are duplicated
 * rather than shared because there is nowhere good to share them to: `packages/`
 * is React components and build tooling, not view-layer utilities, and this is
 * ~50 lines of dependency-free DOM code reading a differently-named global in
 * each plugin. (Sharing would not create version coupling — workspace packages
 * are bundled into each plugin's own dist/ at build time — so that is not the
 * reason.) If you change one, change the other; there is a test file on each
 * side to catch drift.
 */
export function propagateGatePreviewParams() {
	// Previews only ever render inside the editor's preview iframe, so requiring a
	// frame is what keeps preview mode from following an editor around the site.
	// Someone who lands on a preview URL top-level — a new tab, a pasted link — is
	// browsing, not previewing, and should get ordinary links. Comparing
	// `window.top` is a reference check, which cross-origin isolation still
	// permits.
	if ( window.self === window.top ) {
		return;
	}

	// Only localized on a gate preview, so its absence means there is nothing to
	// propagate. Read through `window.` rather than as a bare identifier:
	// optional chaining guards a null value, not an undeclared binding, and an
	// undeclared one throws.
	const params = window.newspack_content_gate?.preview_param_names;
	if ( ! params?.length ) {
		return;
	}

	const current = new URLSearchParams( window.location.search );
	const present = params.filter( key => current.has( key ) ).map( key => [ key, current.get( key ) ] );
	if ( ! present.length ) {
		return;
	}

	// One eager pass at domReady. Anchors added later — "Load more", modal
	// checkout, anything AJAX — keep their own hrefs and leave the preview on the
	// first click. Neither does this reach the gate's own conversion CTAs, which
	// are <form target="newspack_modal_checkout_iframe"> rather than links. A
	// capture-phase click interceptor would close both gaps if it ever proves
	// worth the extra surface.
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

		// Only http(s). An opaque-path URL like `blob:https://site/uuid` reports the
		// inner origin, so it passes the origin test, but it has no meaningful path
		// or query — the shape logic below would rewrite it into an ordinary page URL
		// and destroy the link. Restricting the scheme keeps the rewrite to the two
		// the shape logic was written for.
		if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
			return;
		}

		// Off-site links are none of our business. Matching on origin rather than on
		// the site URL means a subdirectory install also stamps links that sit outside
		// WordPress; that is deliberate, because it is what makes multibranded
		// sites work, where brands are same-origin paths. A stray `ngp_id` on such
		// a page resolves to no layout and does nothing.
		if ( url.origin !== window.location.origin ) {
			return;
		}

		present.forEach( ( [ key, value ] ) => url.searchParams.set( key, value ) );

		// Keep the href in the shape the page wrote it, because a preview exists to
		// show what production does. Re-serializing through URL() turns a relative
		// href absolute, which breaks exactly the theme code a preview should
		// exercise: `a[href^="/"]` selectors, "current item" scripts comparing an
		// href against location.pathname. Only the query is ours to change. Three
		// residual differences we accept: a path-relative href comes back
		// root-relative, since resolving it is what told us where it points, and
		// URLSearchParams re-encodes an existing query (`%20` to `+`) — forms
		// servers treat as equivalent, and unpicking it would mean editing the
		// query string by hand. A same-origin protocol-relative href also comes back
		// scheme-absolute — the one shape we do not preserve, because telling it
		// apart needs a third branch and it is vanishingly rare in theme output.
		const isAbsolute = /^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test( href );
		anchor.setAttribute( 'href', isAbsolute ? url.toString() : url.pathname + url.search + url.hash );
	} );
}
