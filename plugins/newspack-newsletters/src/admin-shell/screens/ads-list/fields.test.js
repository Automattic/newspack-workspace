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

import { render, screen } from '@testing-library/react';
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

/** Render a field and return its text, unwrapping `<Tooltip><span>…</span></Tooltip>`. */
const renderText = ( id, item ) => {
	const output = fieldById( id ).render( { item } );
	return typeof output === 'string' ? output : output.props.children.props.children;
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

	it( "keeps the site's configured date pattern when it carries no time", () => {
		// de_DE's default `date_format`. Locale ordering is the publisher's
		// setting, so only a pattern containing a time should be overridden.
		configureSite( -4, 'j. F Y' );

		expect( renderText( 'start_date', adWithMeta( { start_date: '2026-08-04' } ) ) ).toBe( '4. August 2026' );
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

	it( 'gives each column its own hint about what the date means', () => {
		configureSite( -4 );

		const start = fieldById( 'start_date' ).render( { item: adWithMeta( { start_date: '2026-08-04' } ) } );
		const expiry = fieldById( 'expiry_date' ).render( { item: adWithMeta( { expiry_date: '2026-08-04' } ) } );

		expect( start.props.text ).toBe( 'Runs from this day, in the site timezone.' );
		expect( expiry.props.text ).toBe( 'Runs through the end of this day, in the site timezone.' );
	} );

	it( 'mounts the tooltip-wrapped cell without error', () => {
		configureSite( -4 );

		// The props assertions above never mount `Tooltip`; this catches the
		// runtime shape it requires (a single element child).
		render( fieldById( 'start_date' ).render( { item: adWithMeta( { start_date: '2026-08-04' } ) } ) );

		expect( screen.getByText( 'August 4, 2026' ) ).toBeInTheDocument();
	} );
} );

describe( 'Ads list status column dates', () => {
	afterEach( () => configureSite( 0 ) );

	const statusLabel = ( status, postStatus = 'publish' ) =>
		fieldById( 'status' ).render( {
			item: { id: 1, meta: {}, status: postStatus, newspack_newsletters_ad_status: status },
		} ).props.children[ 1 ].props.children;

	it( 'drops the clock time when the site date format carries one', () => {
		configureSite( -4, 'F j, Y, g:i a' );

		// Noon-UTC anchor derived from `start_date` meta of 2026-08-04.
		const label = statusLabel( { kind: 'scheduled', starts_at: Date.UTC( 2026, 7, 4, 12 ) / 1000 } );

		expect( label ).toBe( 'Starts August 4, 2026' );
		expect( label ).not.toMatch( /\d:\d{2}\s*[ap]m/i );
	} );

	// For a `future` post the REST layer sends `post_date_gmt` — a real publish
	// instant, not a calendar-day anchor. Formatting that in UTC dates a
	// late-evening scheduled ad a day forward.
	it( 'dates a future-scheduled ad by its site-local publish day', () => {
		configureSite( -4 );

		// 2026-08-10 21:00 America/New_York === 2026-08-11 01:00 UTC.
		const label = statusLabel( { kind: 'scheduled', starts_at: Date.UTC( 2026, 7, 11, 1 ) / 1000 }, 'future' );

		expect( label ).toBe( 'Starts August 10, 2026' );
	} );

	// The other half of the pair: a published ad with a future start date sends
	// a noon-UTC anchor, which site time would move to the next day at UTC+12
	// and beyond — disagreeing with the Start column in the same row.
	it.each( [
		[ 'New York', -4 ],
		[ 'Auckland', 12 ],
		[ 'Kiritimati', 14 ],
	] )( 'dates an anchored start by the stored day in %s', ( _label, offset ) => {
		configureSite( offset );

		const label = statusLabel( { kind: 'scheduled', starts_at: Date.UTC( 2026, 7, 4, 12 ) / 1000 } );

		expect( label ).toBe( 'Starts August 4, 2026' );
	} );

	it.each( [
		[ 'New York', -4 ],
		[ 'Kiritimati', 14 ],
	] )( 'dates an expired ad by its stored expiry day in %s', ( _label, offset ) => {
		configureSite( offset );

		const label = statusLabel( { kind: 'expired', expires_at: Date.UTC( 2026, 7, 4, 12 ) / 1000 } );

		expect( label ).toBe( 'Expired August 4, 2026' );
	} );
} );
