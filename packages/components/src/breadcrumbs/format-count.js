/**
 * Group a number for the site's locale rather than the browser's, so a crumb's count
 * matches both the translated phrasing beside it and every server-rendered figure on
 * the page. Callers that build their own `countLabel` share this so the visible count
 * and the spoken one are grouped the same way.
 *
 * @param {number} count Number to format.
 * @return {string} The grouped number.
 */
export const formatCount = count => {
	const lang = ( typeof document !== 'undefined' && document.documentElement.lang ) || '';
	try {
		return Number( count ).toLocaleString( lang.replace( '_', '-' ) || undefined );
	} catch {
		// WordPress ships locales Intl rejects, e.g. `pt_PT_ao90`.
		return Number( count ).toLocaleString();
	}
};
