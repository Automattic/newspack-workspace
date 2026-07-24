/**
 * Client-side validation helpers for institution access rules.
 *
 * Mirrors the server-side parsing in `IP_Access_Rule::parse_ip_ranges()`:
 * a valid entry is a single IPv4 address, a CIDR block (`<ipv4>/<0-32>`),
 * or a dash range (`<ipv4>-<ipv4>` with start <= end). Whitespace around
 * tokens and around the `/` and `-` separators is tolerated. The matcher
 * is IPv4-only, so IPv6 entries are reported as invalid.
 *
 * Parity with the server is pinned by `tests/fixtures/ip-range-validation-cases.json`,
 * a fixture both this module's jest suite and the PHPUnit suite assert against.
 */

// Matches a full IPv4 address without leading-zero octets, like PHP's filter_var( FILTER_VALIDATE_IP | FILTER_FLAG_IPV4 ).
const IPV4_OCTET = '(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])';
const IPV4_REGEX = new RegExp( `^${ IPV4_OCTET }(\\.${ IPV4_OCTET }){3}$` );

/**
 * Dash ranges wider than a /16 are more likely a typo than an institution's
 * real allocation: a single wrong digit in the end address (`10.0.1.0-110.0.1.255`)
 * silently authorizes millions of addresses, which is a revenue leak nobody notices.
 */
export const OVER_BROAD_RANGE_SIZE = 65535;

/**
 * Characters that look like a hyphen or a space but aren't, keyed by a stable
 * identifier the UI maps to a translated label. Word, Google Docs and Outlook
 * autocorrect ` - ` into an en dash, so these arrive by copy-paste far more
 * often than they are typed.
 */
export const CONFUSABLE_CHARACTERS = {
	'en-dash': '\u2013',
	'em-dash': '\u2014',
	'minus-sign': '\u2212',
	'non-breaking-space': '\u00a0',
	'narrow-no-break-space': '\u202f',
	'ideographic-space': '\u3000',
	'zero-width-space': '\u200b',
} as const;

export type ConfusableCharacterKey = keyof typeof CONFUSABLE_CHARACTERS;

export type IpRangeAnalysis = {
	/** Entries the server-side matcher ignores, so they never grant access. */
	invalid: string[];
	/** Confusable characters found in invalid entries that would otherwise be valid. */
	confusableCharacters: ConfusableCharacterKey[];
	/** Valid dash ranges wide enough to be worth a second look. */
	overBroad: string[];
};

export const EMPTY_IP_RANGE_ANALYSIS: IpRangeAnalysis = {
	invalid: [],
	confusableCharacters: [],
	overBroad: [],
};

function isIpv4( value: string ): boolean {
	return IPV4_REGEX.test( value );
}

// PHP trim() strips only these characters. String.prototype.trim() also strips
// Unicode whitespace (NBSP, ideographic space, …), which would let an entry pass
// validation here while the server-side parser keeps the character and drops the
// entry as inert — exactly the silent failure this validator exists to surface.
const PHP_TRIM_REGEX = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;

function phpTrim( value: string ): string {
	return value.replace( PHP_TRIM_REGEX, '' );
}

function ipv4ToNumber( value: string ): number {
	return value.split( '.' ).reduce( ( acc, octet ) => acc * 256 + Number( octet ), 0 );
}

/**
 * Split on the first occurrence of a separator, like PHP's `explode( $sep, $str, 2 )`.
 */
function splitOnce( value: string, separator: string ): [ string, string ] {
	const index = value.indexOf( separator );
	if ( index === -1 ) {
		return [ value, '' ];
	}
	return [ value.slice( 0, index ), value.slice( index + separator.length ) ];
}

/**
 * Whether a single (already trimmed) entry will be kept by the server-side parser.
 *
 * @param token The entry to check.
 * @return Whether the entry can grant access.
 */
function isValidEntry( token: string ): boolean {
	if ( token.includes( '/' ) ) {
		const [ subnet, bits ] = splitOnce( token, '/' ).map( phpTrim );
		return isIpv4( subnet ) && /^\d+$/.test( bits ) && Number( bits ) <= 32;
	}
	if ( token.includes( '-' ) ) {
		const [ start, end ] = splitOnce( token, '-' ).map( phpTrim );
		// A reversed range (end < start) matches nothing server-side, so it counts as invalid.
		return isIpv4( start ) && isIpv4( end ) && ipv4ToNumber( start ) <= ipv4ToNumber( end );
	}
	return isIpv4( token );
}

/**
 * Replace confusable characters with their plain ASCII equivalent.
 */
function replaceConfusableCharacters( token: string ): string {
	return token
		.replace( /[\u2013\u2014\u2212]/g, '-' )
		.replace( /\u200b/g, '' )
		.replace( /[\u00a0\u202f\u3000]/g, ' ' );
}

/**
 * Identify the confusable characters that are the reason an entry is invalid.
 *
 * Only reports characters whose replacement makes the entry valid, so a genuinely
 * malformed entry that happens to contain an en dash isn't blamed on the dash.
 *
 * @param token The invalid entry.
 * @return The keys of the confusable characters responsible.
 */
function getConfusableCharacterKeys( token: string ): ConfusableCharacterKey[] {
	const keys = ( Object.keys( CONFUSABLE_CHARACTERS ) as ConfusableCharacterKey[] ).filter( key => token.includes( CONFUSABLE_CHARACTERS[ key ] ) );
	if ( ! keys.length || ! isValidEntry( phpTrim( replaceConfusableCharacters( token ) ) ) ) {
		return [];
	}
	return keys;
}

/**
 * Whether a valid dash range spans an implausibly large number of addresses.
 */
function isOverBroadRange( token: string ): boolean {
	if ( token.includes( '/' ) || ! token.includes( '-' ) || ! isValidEntry( token ) ) {
		return false;
	}
	const [ start, end ] = splitOnce( token, '-' ).map( phpTrim );
	return ipv4ToNumber( end ) - ipv4ToNumber( start ) > OVER_BROAD_RANGE_SIZE;
}

/**
 * Inspect a comma-separated IP list for entries worth warning the admin about.
 *
 * @param raw The raw comma-separated input value.
 * @return The invalid entries, the confusable characters behind them, and any over-broad ranges.
 */
export function analyzeIpRangeEntries( raw: string ): IpRangeAnalysis {
	const tokens = raw
		.split( ',' )
		.map( phpTrim )
		.filter( token => token.length > 0 );
	const invalid = tokens.filter( token => ! isValidEntry( token ) );
	const confusableCharacters = [ ...new Set( invalid.flatMap( getConfusableCharacterKeys ) ) ];
	return {
		invalid,
		confusableCharacters,
		overBroad: tokens.filter( isOverBroadRange ),
	};
}
