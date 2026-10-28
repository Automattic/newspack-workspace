/**
 * Get the source the editor preview should load, or '' if it should load nothing.
 *
 * Resolves the source the way the browser will, so whitespace or control characters in
 * the scheme are stripped before it is checked, and returns only an http(s) URL. For a
 * source with no scheme it follows esc_url()'s main rules in view.php, so a well-formed
 * source previews what the front end embeds. It does not copy every esc_url() rewrite:
 * a malformed source the server repairs may preview nothing, and a relative source
 * resolves against the editor's page. Keep the allowed schemes in step with view.php.
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
