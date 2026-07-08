/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */

/**
 * Internal dependencies
 */
import { writeDateRangeUrl, writeComparisonUrl } from './controlsUrl';

describe( 'controlsUrl', () => {
	beforeEach( () => {
		window.history.replaceState( {}, '', '/wp-admin/admin.php?page=newspack-insights' );
	} );

	it( 'writes a preset range and clears custom start/end', () => {
		writeDateRangeUrl( { preset: 'last-30', start: '2026-05-01', end: '2026-05-30' } );
		const params = new URLSearchParams( window.location.search );
		expect( params.get( 'range' ) ).toBe( 'last-30' );
		expect( params.get( 'start' ) ).toBeNull();
		expect( params.get( 'end' ) ).toBeNull();
	} );

	it( 'writes a custom range with start and end', () => {
		writeDateRangeUrl( { preset: 'custom', start: '2026-04-01', end: '2026-04-30' } );
		const params = new URLSearchParams( window.location.search );
		expect( params.get( 'range' ) ).toBe( 'custom' );
		expect( params.get( 'start' ) ).toBe( '2026-04-01' );
		expect( params.get( 'end' ) ).toBe( '2026-04-30' );
	} );

	it( 'writes compare=1 when enabled and compare=0 when disabled', () => {
		writeComparisonUrl( true );
		expect( new URLSearchParams( window.location.search ).get( 'compare' ) ).toBe( '1' );
		writeComparisonUrl( false );
		expect( new URLSearchParams( window.location.search ).get( 'compare' ) ).toBe( '0' );
	} );
} );
