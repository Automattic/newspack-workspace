/**
 * Internal dependencies
 */
import { getInvalidIpRangeEntries } from './utils';

describe( 'getInvalidIpRangeEntries', () => {
	it( 'accepts single IPv4 addresses, CIDR blocks, and dash ranges', () => {
		expect( getInvalidIpRangeEntries( '10.0.0.5' ) ).toEqual( [] );
		expect( getInvalidIpRangeEntries( '192.168.1.0/24' ) ).toEqual( [] );
		expect( getInvalidIpRangeEntries( '142.74.1.0-142.74.1.255' ) ).toEqual( [] );
		expect( getInvalidIpRangeEntries( '192.168.1.0/24, 10.0.0.5, 142.74.1.0-142.74.1.255' ) ).toEqual( [] );
	} );

	it( 'tolerates whitespace around tokens and separators', () => {
		expect( getInvalidIpRangeEntries( ' 192.168.1.0 / 24 , 142.74.1.0 - 142.74.1.255 ' ) ).toEqual( [] );
	} );

	it( 'treats an empty or separators-only value as valid (no entries)', () => {
		expect( getInvalidIpRangeEntries( '' ) ).toEqual( [] );
		expect( getInvalidIpRangeEntries( ' ,, , ' ) ).toEqual( [] );
	} );

	it( 'flags invalid IPs and CIDR blocks', () => {
		expect( getInvalidIpRangeEntries( 'not-an-ip' ) ).toEqual( [ 'not-an-ip' ] );
		expect( getInvalidIpRangeEntries( '999.999.999.999' ) ).toEqual( [ '999.999.999.999' ] );
		expect( getInvalidIpRangeEntries( '10.0.0.0/33' ) ).toEqual( [ '10.0.0.0/33' ] );
		expect( getInvalidIpRangeEntries( '10.0.0.0/foo' ) ).toEqual( [ '10.0.0.0/foo' ] );
		expect( getInvalidIpRangeEntries( '10.0.0.0/' ) ).toEqual( [ '10.0.0.0/' ] );
		expect( getInvalidIpRangeEntries( '10.0.0.0/-1' ) ).toEqual( [ '10.0.0.0/-1' ] );
	} );

	it( 'flags malformed and reversed dash ranges', () => {
		expect( getInvalidIpRangeEntries( '10.0.0.1-' ) ).toEqual( [ '10.0.0.1-' ] );
		expect( getInvalidIpRangeEntries( '-10.0.0.9' ) ).toEqual( [ '-10.0.0.9' ] );
		expect( getInvalidIpRangeEntries( '10.0.0.1-banana' ) ).toEqual( [ '10.0.0.1-banana' ] );
		expect( getInvalidIpRangeEntries( '10.0.0.1-10.0.0.4-10.0.0.9' ) ).toEqual( [ '10.0.0.1-10.0.0.4-10.0.0.9' ] );
		// Reversed range (end < start) matches nothing server-side, so warn.
		expect( getInvalidIpRangeEntries( '142.74.1.255-142.74.1.0' ) ).toEqual( [ '142.74.1.255-142.74.1.0' ] );
	} );

	it( 'flags leading-zero octets, matching PHP filter_var behavior', () => {
		expect( getInvalidIpRangeEntries( '010.0.0.1' ) ).toEqual( [ '010.0.0.1' ] );
	} );

	it( 'flags IPv6 entries: the matcher is IPv4-only', () => {
		expect( getInvalidIpRangeEntries( '2001:db8::1' ) ).toEqual( [ '2001:db8::1' ] );
		expect( getInvalidIpRangeEntries( '::ffff:10.0.0.5' ) ).toEqual( [ '::ffff:10.0.0.5' ] );
	} );

	it( 'flags entries with Unicode whitespace, which PHP trim() does not strip', () => {
		// Trailing NBSP on a token: PHP keeps the NBSP, so the entry is inert server-side.
		expect( getInvalidIpRangeEntries( '1.2.3.4 ' ) ).toEqual( [ '1.2.3.4 ' ] );
		// NBSP after the comma separator.
		expect( getInvalidIpRangeEntries( '192.168.1.0/24, 10.0.0.5' ) ).toEqual( [ ' 10.0.0.5' ] );
		// Ideographic space.
		expect( getInvalidIpRangeEntries( '10.0.0.1　' ) ).toEqual( [ '10.0.0.1　' ] );
	} );

	it( 'returns only the invalid entries from a mixed list, in input order', () => {
		expect( getInvalidIpRangeEntries( 'garbage, 192.168.1.0/24, 10.0.0.9-10.0.0.1, 10.0.0.5' ) ).toEqual( [ 'garbage', '10.0.0.9-10.0.0.1' ] );
	} );
} );
