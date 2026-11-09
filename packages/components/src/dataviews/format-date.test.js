/**
 * WordPress dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { formatSiteDate, formatSiteDateTime, formatSiteTime } from './format-date';

const defaults = getSettings();

const useSite = ( { date, time, timezone } ) =>
	setSettings( {
		...defaults,
		formats: { ...defaults.formats, date, time },
		timezone: { ...defaults.timezone, ...timezone },
	} );

describe( 'site date formatting', () => {
	afterEach( () => setSettings( defaults ) );

	describe( 'with a 24-hour site ahead of UTC', () => {
		beforeEach( () => useSite( { date: 'd/m/Y', time: 'H:i', timezone: { string: 'Pacific/Auckland', offset: 13 } } ) );

		it( 'keeps a bare date on its own calendar day', () => {
			expect( formatSiteDate( '2026-11-09' ) ).toBe( '09/11/2026' );
		} );

		it( 'moves an ISO instant into the site timezone', () => {
			expect( formatSiteDate( '2026-11-09T20:00:00Z' ) ).toBe( '10/11/2026' );
			expect( formatSiteDateTime( '2026-11-09T20:00:00+00:00' ) ).toBe( '10/11/2026 09:00' );
		} );

		it( 'reads a Unix timestamp as seconds', () => {
			const seconds = Date.UTC( 2026, 10, 9, 20, 0 ) / 1000;
			expect( formatSiteDateTime( seconds ) ).toBe( '10/11/2026 09:00' );
			expect( formatSiteDateTime( String( seconds ) ) ).toBe( '10/11/2026 09:00' );
			expect( formatSiteTime( seconds ) ).toBe( '09:00' );
		} );

		it( 'reads a MySQL date without an offset as site time', () => {
			expect( formatSiteDateTime( '2026-11-09 08:30:00' ) ).toBe( '09/11/2026 08:30' );
		} );

		it( 'formats a Date', () => {
			expect( formatSiteDateTime( new Date( Date.UTC( 2026, 10, 9, 20, 0 ) ) ) ).toBe( '10/11/2026 09:00' );
		} );
	} );

	it( 'keeps a bare date on its own calendar day behind UTC', () => {
		useSite( { date: 'Y-m-d', time: 'H:i', timezone: { string: 'America/Los_Angeles', offset: -8 } } );
		expect( formatSiteDate( '2026-11-09' ) ).toBe( '2026-11-09' );
	} );

	describe( 'with a 12-hour site', () => {
		beforeEach( () => useSite( { date: 'F j, Y', time: 'g:i a', timezone: { string: '', offset: 0 } } ) );

		it( 'keeps AM and PM', () => {
			expect( formatSiteTime( '2026-11-09T15:05:00Z' ) ).toBe( '3:05 pm' );
			expect( formatSiteDateTime( '2026-11-09T09:05:00Z' ) ).toBe( 'November 9, 2026 9:05 am' );
		} );

		it( 'ignores the core datetime format', () => {
			setSettings( { ...getSettings(), formats: { ...getSettings().formats, datetime: 'Y-m-d H:i' } } );
			expect( formatSiteDateTime( '2026-11-09T15:05:00Z' ) ).toBe( 'November 9, 2026 3:05 pm' );
		} );
	} );

	it.each( [ null, undefined, '', 0, '0', 'not a date', new Date( NaN ) ] )( 'returns an empty string for %p', value => {
		expect( formatSiteDate( value ) ).toBe( '' );
		expect( formatSiteDateTime( value ) ).toBe( '' );
		expect( formatSiteTime( value ) ).toBe( '' );
	} );
} );
