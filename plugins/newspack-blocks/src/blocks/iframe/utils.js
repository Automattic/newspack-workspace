/**
 * Get the source the editor preview should load, or '' if it should load nothing.
 *
 * Resolves the source the way the browser will, so whitespace or control characters in
 * the scheme are stripped before it is checked. A source with no scheme that is not
 * relative to the site gets https://, as view.php gives it, so the preview loads what
 * the front end will. Keep the allowed schemes in step with view.php.
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
	const isSiteRelative = /^[/?#]/.test( value );
	try {
		const url = new URL( hasScheme || isSiteRelative ? value : `https://${ value }`, window.location.href );
		return [ 'http:', 'https:' ].includes( url.protocol ) ? url.href : '';
	} catch {
		return '';
	}
};
