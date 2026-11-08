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

	it( 'keeps a scheduled date on one line', () => {
		const status = getFields().find( field => field.id === 'status' );
		const { children } = status.render( {
			item: { newspack_newsletters_status: { kind: 'scheduled', scheduled_at: Date.UTC( 2026, 10, 18, 17, 42 ) / 1000 } },
		} ).props;
		const { container } = render( <div>{ children }</div> );
		expect( container.textContent ).toBe( 'Scheduled for November 18, 2026 5:42 pm' );
		expect( container.querySelector( '.newspack-dataviews__date' ) ).toHaveTextContent( 'November 18, 2026 5:42 pm' );
	} );
} );
