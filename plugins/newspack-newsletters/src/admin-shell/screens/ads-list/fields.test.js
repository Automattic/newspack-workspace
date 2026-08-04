/**
 * Ad windows are whole calendar days: the meta is `Y-m-d`, and the server
 * compares it as a string against the newsletter's own date. The list columns
 * therefore have to show the day that was stored, with no clock time attached.
 *
 * Two regressions are pinned here. The columns used to format with the site's
 * `date_format` option, which may legitimately include a time — on a site set
 * to `F j, Y, g:i a` the noon-UTC anchor surfaced as "8:00 am", implying a
 * time-of-day boundary that does not exist. And formatting into the site
 * timezone shifts the day itself once the offset is far enough from UTC.
 */

import { setSettings } from '@wordpress/date';

import { getFields } from './fields';

const L10N = {
	locale: 'en_US',
	months: [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ],
	monthsShort: [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ],
	weekdays: [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ],
	weekdaysShort: [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ],
	meridiem: { am: 'am', pm: 'pm', AM: 'AM', PM: 'PM' },
	relative: { future: '%s from now', past: '%s ago' },
	startOfWeek: 0,
};

/**
 * Configure @wordpress/date the way WordPress does for a given site.
 *
 * @param {number} offset     Site UTC offset in hours.
 * @param {string} dateFormat The site's `date_format` option.
 */
const configureSite = ( offset, dateFormat = 'F j, Y' ) =>
	setSettings( {
		l10n: L10N,
		formats: {
			time: 'g:i a',
			date: dateFormat,
			datetime: 'F j, Y g:i a',
			datetimeAbbreviated: 'M j, Y g:i a',
		},
		timezone: { offset, offsetFormatted: String( offset ), string: '', abbr: '' },
	} );

const fieldById = id => getFields().find( field => field.id === id );

/** Render a field and return its text, unwrapping the tooltip element. */
const renderText = ( id, item ) => {
	const output = fieldById( id ).render( { item } );
	return typeof output === 'string' ? output : output.props.children;
};

const adWithMeta = meta => ( { id: 1, meta } );

describe( 'Ads list date columns', () => {
	afterEach( () => configureSite( 0 ) );

	it.each( [
		[ 'UTC', 0 ],
		[ 'Honolulu', -10 ],
		[ 'Los Angeles', -7 ],
		[ 'New York', -4 ],
		[ 'Paris', 2 ],
		[ 'Tokyo', 9 ],
		[ 'Kiritimati', 14 ],
	] )( 'shows the stored day in %s', ( _label, offset ) => {
		configureSite( offset );

		expect( renderText( 'start_date', adWithMeta( { start_date: '2026-08-04' } ) ) ).toBe( 'August 4, 2026' );
		expect( renderText( 'expiry_date', adWithMeta( { expiry_date: '2026-08-04' } ) ) ).toBe( 'August 4, 2026' );
	} );

	it( 'never renders a clock time, even when the site date format carries one', () => {
		configureSite( -4, 'F j, Y, g:i a' );

		const rendered = renderText( 'start_date', adWithMeta( { start_date: '2026-08-04' } ) );
		expect( rendered ).toBe( 'August 4, 2026' );
		expect( rendered ).not.toMatch( /\d:\d{2}\s*[ap]m/i );
	} );

	it( 'reduces a legacy stored datetime to its date', () => {
		configureSite( -4 );

		expect( renderText( 'start_date', adWithMeta( { start_date: '2026-08-04T23:59:59' } ) ) ).toBe( 'August 4, 2026' );
	} );

	it( 'renders nothing when the date is unset', () => {
		configureSite( -4 );

		expect( renderText( 'start_date', adWithMeta( {} ) ) ).toBe( '' );
		expect( renderText( 'expiry_date', adWithMeta( { expiry_date: '' } ) ) ).toBe( '' );
	} );

	it( 'explains the window semantics on hover', () => {
		configureSite( -4 );

		const output = fieldById( 'start_date' ).render( { item: adWithMeta( { start_date: '2026-08-04' } ) } );
		expect( output.props.title ).toMatch( /whole days/i );
		expect( output.props.title ).toMatch( /included/i );
	} );
} );

describe( 'Ads list status column dates', () => {
	afterEach( () => configureSite( 0 ) );

	// `starts_at` / `expires_at` are anchored at noon UTC by the REST layer.
	const NOON_UTC_AUG_4 = Date.UTC( 2026, 7, 4, 12 ) / 1000;

	it.each( [
		[ 'New York', -4 ],
		[ 'Kiritimati', 14 ],
	] )( 'dates the scheduled label by the stored day in %s', ( _label, offset ) => {
		configureSite( offset, 'F j, Y, g:i a' );

		const item = {
			id: 1,
			meta: {},
			newspack_newsletters_ad_status: { kind: 'scheduled', starts_at: NOON_UTC_AUG_4 },
		};
		const label = fieldById( 'status' ).render( { item } ).props.children[ 1 ].props.children;

		expect( label ).toBe( 'Starts August 4, 2026' );
	} );
} );
