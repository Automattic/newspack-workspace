/**
 * Client-side validation helpers for institution access rules.
 *
 * Mirrors the server-side parsing in `IP_Access_Rule::parse_ip_ranges()`:
 * a valid entry is a single IPv4 address, a CIDR block (`<ipv4>/<0-32>`),
 * or a dash range (`<ipv4>-<ipv4>` with start <= end). Whitespace around
 * tokens and around the `/` and `-` separators is tolerated. The matcher
 * is IPv4-only, so IPv6 entries are reported as invalid.
 */

// Matches a full IPv4 address without leading-zero octets, like PHP's filter_var( FILTER_VALIDATE_IP | FILTER_FLAG_IPV4 ).
const IPV4_OCTET = '(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])';
const IPV4_REGEX = new RegExp( `^${ IPV4_OCTET }(\\.${ IPV4_OCTET }){3}$` );

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
	return [ value.slice( 0, index ), value.slice( index + separator.length ) ];
}

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
 * Return the entries of a comma-separated IP list that the server-side
 * matcher will ignore (i.e. that will never grant access).
 *
 * @param raw The raw comma-separated input value.
 * @return The invalid entries, in input order.
 */
export function getInvalidIpRangeEntries( raw: string ): string[] {
	return raw
		.split( ',' )
		.map( phpTrim )
		.filter( token => token.length > 0 )
		.filter( token => ! isValidEntry( token ) );
}
