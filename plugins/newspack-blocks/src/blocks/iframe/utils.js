/**
 * Whether the editor preview should load this source.
 *
 * Resolves the source the way the browser will, so whitespace or control characters in
 * the scheme are stripped before it is checked. Keep the allowed schemes in step with
 * view.php.
 *
 * @param {string} src Iframe source.
 * @return {boolean} Whether the source is http(s), or relative to the site.
 */
export const isEmbeddableSrc = src => {
	if ( ! src ) {
		return false;
	}
	try {
		return [ 'http:', 'https:' ].includes( new URL( src, window.location.href ).protocol );
	} catch {
		return false;
	}
};
