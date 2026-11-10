// The `newspack-components` stub only intercepts the bare package specifier, so the
// vocabulary itself is still reachable and the rule can be asserted on marks rather
// than on a copy of the pair list kept here.

import { render } from '@testing-library/react';
import { setSettings } from '@wordpress/date';

import { getFields, STATUS_KIND_STATUSES } from './fields';
import { statusGlyph } from 'newspack-components/src/status-indicator';

describe( 'STATUS_KIND_STATUSES', () => {
	it( 'names every kind the list can report', () => {
		expect( STATUS_KIND_STATUSES ).toEqual( { sent: 'done', scheduled: 'scheduled', draft: 'draft', trash: 'trash' } );
	} );

	it( 'gives no two kinds the same mark', () => {
		const glyphs = Object.values( STATUS_KIND_STATUSES ).map( name => statusGlyph( name ) );
		expect( new Set( glyphs ).size ).toBe( glyphs.length );
	} );

	it( 'reads a sent newsletter as finished', () => {
		expect( STATUS_KIND_STATUSES.sent ).toBe( 'done' );
	} );
} );

describe( 'status column dates', () => {
	beforeAll( () => {
		setSettings( {
			l10n: {
				locale: 'en_US',
				months: [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ],
				monthsShort: [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ],
				weekdays: [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ],
				weekdaysShort: [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ],
				meridiem: { am: 'am', pm: 'pm', AM: 'AM', PM: 'PM' },
				relative: { future: '%s from now', past: '%s ago' },
				startOfWeek: 0,
			},
			formats: { time: 'g:i a', date: 'F j, Y', datetime: 'F j, Y g:i a', datetimeAbbreviated: 'M j, Y g:i a' },
			timezone: { offset: 0, offsetFormatted: '0', string: '', abbr: '' },
		} );
	} );

	it( 'shows only the status label, with the date as its tooltip', () => {
		const status = getFields().find( field => field.id === 'status' );
		const scheduled = status.render( {
			item: { newspack_newsletters_status: { kind: 'scheduled', scheduled_at: Date.UTC( 2026, 10, 18, 17, 42 ) / 1000 } },
		} );
		expect( scheduled.props.text ).toBe( 'November 18, 2026 5:42 pm' );
		const trigger = scheduled.props.children;
		expect( trigger.type ).toBe( 'button' );
		expect( trigger.props.children.props.children ).toBe( 'Scheduled' );
	} );

	it( 'gives a sent newsletter its send date as the tooltip', () => {
		const status = getFields().find( field => field.id === 'status' );
		const sent = status.render( { item: { newspack_newsletters_status: { kind: 'sent', sent_at: Date.UTC( 2026, 9, 31, 8, 0 ) / 1000 } } } );
		expect( sent.props.text ).toBe( 'October 31, 2026 8:00 am' );
		expect( sent.props.children.props.children.props.children ).toBe( 'Sent' );
	} );

	it( 'leaves a draft without a tooltip', () => {
		const status = getFields().find( field => field.id === 'status' );
		const draft = status.render( { item: { newspack_newsletters_status: { kind: 'draft' } } } );
		expect( draft.props.children ).toBe( 'Draft' );
		expect( draft.props.text ).toBeUndefined();
	} );

	it( 'shows a dash for empty cells', () => {
		const fields = getFields();
		const empty = id => render( fields.find( field => field.id === id ).render( { item: { id: 1, meta: {} } } ) ).container.textContent;
		expect( empty( 'author' ) ).toBe( '—' );
		expect( empty( 'categories' ) ).toBe( '—' );
		expect( empty( 'tags' ) ).toBe( '—' );
		expect( empty( 'send_list' ) ).toBe( '—' );
	} );
} );
