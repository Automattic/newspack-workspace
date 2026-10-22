/**
 * Get the source the editor preview should load, or '' if it should load nothing.
 *
 * Resolves the source the way the browser will, so whitespace or control characters in
 * the scheme are stripped before it is checked. Sources with no scheme follow the same
 * rules as esc_url() in view.php, so the preview loads what the front end will, apart
 * from a relative source, which the editor resolves against its own page. Keep the
 * allowed schemes in step with view.php.
 *
 * @param {string} src Iframe source.
 * @return {string} The http(s) URL to load, or ''.
 */
export const getEmbeddableSrc = src => {
	// The URL parser drops these anywhere in a URL; drop them first so the scheme test sees
	// what the parser will.
	const value = ( src || '' ).replace( /[\t\n\r]/g, '' ).trim();

	// A blank source resolves to the current page, but the server renders nothing for it.
	if ( ! value ) {
		return '';
	}
	const hasScheme = /^[a-z][a-z0-9+.-]*:/i.test( value );
	const hasColon = value.includes( ':' );

	// As in esc_url(): unless the source starts with '/', the text before its first colon
	// is read as the scheme, and a source with no real scheme there renders nothing. Text
	// containing '/?' is the exception, kept as a relative URL.
	if ( hasColon && ! hasScheme && ! value.startsWith( '/' ) && ! value.split( ':' )[ 0 ].includes( '/?' ) ) {
		return '';
	}
	// esc_url() adds the scheme only when the source has no colon at all.
	const needsScheme = ! hasColon && ! /^[/?#]/.test( value );
	try {
		const url = new URL( needsScheme ? `https://${ value }` : value, window.location.href );
		return [ 'http:', 'https:' ].includes( url.protocol ) ? url.href : '';
	} catch {
		return '';
	}
};
