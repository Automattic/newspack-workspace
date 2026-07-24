/**
 * Internal dependencies
 */
import { analyzeIpRangeEntries } from './utils';
import sharedValidationCases from '../../../../../../tests/fixtures/ip-range-validation-cases.json';

describe( 'shared client/server validation fixture', () => {
	// The same fixture drives the PHPUnit data provider in
	// tests/unit-tests/content-gate/class-ip-access-rule.php, so a change that
	// makes one validator disagree with the other fails CI on one side or the other.
	it.each( sharedValidationCases.cases.map( testCase => [ testCase.label, testCase.entry, testCase.valid ] ) )(
		'%s: %j is kept by the server: %s',
		( label, entry, valid ) => {
			expect( analyzeIpRangeEntries( entry ).invalid ).toEqual( valid ? [] : [ entry ] );
		}
	);
} );

describe( 'analyzeIpRangeEntries: invalid entries', () => {
	it( 'accepts single IPv4 addresses, CIDR blocks, and dash ranges', () => {
		expect( analyzeIpRangeEntries( '10.0.0.5' ).invalid ).toEqual( [] );
		expect( analyzeIpRangeEntries( '192.168.1.0/24' ).invalid ).toEqual( [] );
		expect( analyzeIpRangeEntries( '203.0.113.0-203.0.113.255' ).invalid ).toEqual( [] );
		expect( analyzeIpRangeEntries( '192.168.1.0/24, 10.0.0.5, 203.0.113.0-203.0.113.255' ).invalid ).toEqual( [] );
	} );

	it( 'tolerates whitespace around tokens and separators', () => {
		expect( analyzeIpRangeEntries( ' 192.168.1.0 / 24 , 203.0.113.0 - 203.0.113.255 ' ).invalid ).toEqual( [] );
	} );

	it( 'treats an empty or separators-only value as valid (no entries)', () => {
		expect( analyzeIpRangeEntries( '' ).invalid ).toEqual( [] );
		expect( analyzeIpRangeEntries( ' ,, , ' ).invalid ).toEqual( [] );
	} );

	it( 'flags invalid IPs and CIDR blocks', () => {
		expect( analyzeIpRangeEntries( 'not-an-ip' ).invalid ).toEqual( [ 'not-an-ip' ] );
		expect( analyzeIpRangeEntries( '999.999.999.999' ).invalid ).toEqual( [ '999.999.999.999' ] );
		expect( analyzeIpRangeEntries( '10.0.0.0/33' ).invalid ).toEqual( [ '10.0.0.0/33' ] );
		expect( analyzeIpRangeEntries( '10.0.0.0/foo' ).invalid ).toEqual( [ '10.0.0.0/foo' ] );
		expect( analyzeIpRangeEntries( '10.0.0.0/' ).invalid ).toEqual( [ '10.0.0.0/' ] );
		expect( analyzeIpRangeEntries( '10.0.0.0/-1' ).invalid ).toEqual( [ '10.0.0.0/-1' ] );
	} );

	it( 'flags malformed and reversed dash ranges', () => {
		expect( analyzeIpRangeEntries( '10.0.0.1-' ).invalid ).toEqual( [ '10.0.0.1-' ] );
		expect( analyzeIpRangeEntries( '-10.0.0.9' ).invalid ).toEqual( [ '-10.0.0.9' ] );
		expect( analyzeIpRangeEntries( '10.0.0.1-banana' ).invalid ).toEqual( [ '10.0.0.1-banana' ] );
		expect( analyzeIpRangeEntries( '10.0.0.1-10.0.0.4-10.0.0.9' ).invalid ).toEqual( [ '10.0.0.1-10.0.0.4-10.0.0.9' ] );
		// Reversed range (end < start) matches nothing server-side, so warn.
		expect( analyzeIpRangeEntries( '203.0.113.255-203.0.113.0' ).invalid ).toEqual( [ '203.0.113.255-203.0.113.0' ] );
	} );

	it( 'flags tokens carrying both separators, which PHP treats as CIDR and drops', () => {
		expect( analyzeIpRangeEntries( '10.0.0.0/24-10.0.0.5' ).invalid ).toEqual( [ '10.0.0.0/24-10.0.0.5' ] );
		expect( analyzeIpRangeEntries( '10.0.0.1-10.0.0.9/24' ).invalid ).toEqual( [ '10.0.0.1-10.0.0.9/24' ] );
	} );

	it( 'accepts ranges straddling the ip2long sign boundary', () => {
		expect( analyzeIpRangeEntries( '127.255.255.255-128.0.0.1' ).invalid ).toEqual( [] );
		expect( analyzeIpRangeEntries( '0.0.0.0-255.255.255.255' ).invalid ).toEqual( [] );
	} );

	it( 'flags leading-zero octets, matching PHP filter_var behavior', () => {
		expect( analyzeIpRangeEntries( '010.0.0.1' ).invalid ).toEqual( [ '010.0.0.1' ] );
	} );

	it( 'flags IPv6 entries: the matcher is IPv4-only', () => {
		expect( analyzeIpRangeEntries( '2001:db8::1' ).invalid ).toEqual( [ '2001:db8::1' ] );
		expect( analyzeIpRangeEntries( '::ffff:10.0.0.5' ).invalid ).toEqual( [ '::ffff:10.0.0.5' ] );
	} );

	it( 'flags entries with Unicode whitespace, which PHP trim() does not strip', () => {
		// Trailing NBSP on a token: PHP keeps the NBSP, so the entry is inert server-side.
		expect( analyzeIpRangeEntries( '1.2.3.4 ' ).invalid ).toEqual( [ '1.2.3.4 ' ] );
		// NBSP after the comma separator.
		expect( analyzeIpRangeEntries( '192.168.1.0/24, 10.0.0.5' ).invalid ).toEqual( [ ' 10.0.0.5' ] );
		// Ideographic space.
		expect( analyzeIpRangeEntries( '10.0.0.1　' ).invalid ).toEqual( [ '10.0.0.1　' ] );
	} );

	it( 'flags a range written with an en dash, the most likely copy-paste error', () => {
		expect( analyzeIpRangeEntries( '192.168.1.1–192.168.1.9' ).invalid ).toEqual( [ '192.168.1.1–192.168.1.9' ] );
	} );

	it( 'returns only the invalid entries from a mixed list, in input order', () => {
		expect( analyzeIpRangeEntries( 'garbage, 192.168.1.0/24, 10.0.0.9-10.0.0.1, 10.0.0.5' ).invalid ).toEqual( [
			'garbage',
			'10.0.0.9-10.0.0.1',
		] );
	} );
} );

describe( 'analyzeIpRangeEntries: confusable characters', () => {
	it( 'names the character behind an entry that would otherwise be valid', () => {
		expect( analyzeIpRangeEntries( '192.168.1.1 – 192.168.1.9' ).confusableCharacters ).toEqual( [ 'en-dash' ] );
		expect( analyzeIpRangeEntries( '192.168.1.1—192.168.1.9' ).confusableCharacters ).toEqual( [ 'em-dash' ] );
		expect( analyzeIpRangeEntries( '192.168.1.1−192.168.1.9' ).confusableCharacters ).toEqual( [ 'minus-sign' ] );
		expect( analyzeIpRangeEntries( '10.0.0.5 ' ).confusableCharacters ).toEqual( [ 'non-breaking-space' ] );
		expect( analyzeIpRangeEntries( '10.0.0​.1' ).confusableCharacters ).toEqual( [ 'zero-width-space' ] );
	} );

	it( 'reports each character once across a list', () => {
		expect( analyzeIpRangeEntries( '10.0.0.1 – 10.0.0.9, 192.168.0.1 – 192.168.0.9' ).confusableCharacters ).toEqual( [ 'en-dash' ] );
	} );

	it( 'does not blame a confusable character for an entry that is broken anyway', () => {
		expect( analyzeIpRangeEntries( 'banana – 10.0.0.9' ).confusableCharacters ).toEqual( [] );
		expect( analyzeIpRangeEntries( 'not-an-ip' ).confusableCharacters ).toEqual( [] );
	} );
} );

describe( 'analyzeIpRangeEntries: over-broad ranges', () => {
	it( 'flags a dash range wider than a /16', () => {
		// One wrong digit in the end address: ~1.6 billion addresses.
		expect( analyzeIpRangeEntries( '203.0.113.0-243.0.113.255' ).overBroad ).toEqual( [ '203.0.113.0-243.0.113.255' ] );
		expect( analyzeIpRangeEntries( '0.0.0.0-255.255.255.255' ).overBroad ).toEqual( [ '0.0.0.0-255.255.255.255' ] );
	} );

	it( 'leaves plausible ranges alone, including a full /16', () => {
		expect( analyzeIpRangeEntries( '203.0.113.0-203.0.113.255' ).overBroad ).toEqual( [] );
		expect( analyzeIpRangeEntries( '10.0.0.0-10.0.255.255' ).overBroad ).toEqual( [] );
	} );

	it( 'does not warn about CIDR blocks or invalid ranges', () => {
		expect( analyzeIpRangeEntries( '0.0.0.0/0' ).overBroad ).toEqual( [] );
		expect( analyzeIpRangeEntries( '255.255.255.255-0.0.0.0' ).overBroad ).toEqual( [] );
	} );

	it( 'is independent of the invalid-entry list', () => {
		const analysis = analyzeIpRangeEntries( '0.0.0.0-255.255.255.255, garbage' );
		expect( analysis.invalid ).toEqual( [ 'garbage' ] );
		expect( analysis.overBroad ).toEqual( [ '0.0.0.0-255.255.255.255' ] );
	} );
} );
