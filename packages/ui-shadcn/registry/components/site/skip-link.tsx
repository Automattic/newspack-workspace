const SKIP_LINK = 'site-skip-link';

/**
 * "Skip to content": hidden until it has focus, then shown at the top left of the viewport.
 * `href` is the id of the page's main region, `#main` by default.
 */
export function SkipLink( { href = '#main' }: { href?: string } ) {
	return (
		<a className={ SKIP_LINK } href={ href }>
			Skip to content
		</a>
	);
}
