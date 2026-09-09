/**
 * Group a number for the site's locale rather than the browser's, so a crumb's count
 * matches both the translated phrasing beside it and every server-rendered figure on
 * the page. Callers that build their own `countLabel` share this so the visible count
 * and the spoken one are grouped the same way.
 *
 * WordPress ships locales `Intl` rejects outright — `pt_PT_ao90` yields the BCP-47
 * tag `pt-PT-ao90`, whose four-character variant subtag must start with a digit — so
 * the tag is shortened a subtag at a time until one is accepted. Falling straight
 * through to the browser's locale would group `1,234` beside a server-rendered
 * `1.234`, which is the divergence this helper exists to prevent.
 *
 * @param {number} count Number to format.
 * @return {string} The grouped number.
 */
export const formatCount = count => {
	const lang = ( typeof document !== 'undefined' && document.documentElement.lang ) || '';
	const subtags = lang.replace( /_/g, '-' ).split( '-' ).filter( Boolean );
	while ( subtags.length ) {
		try {
			return Number( count ).toLocaleString( subtags.join( '-' ) );
		} catch {
			subtags.pop();
		}
	}
	return Number( count ).toLocaleString();
};
