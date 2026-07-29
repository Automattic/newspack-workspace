import matchingFunctions from './matching-functions';

const { date_range: dateRange } = matchingFunctions;

const absolute = date => ( { type: 'absolute', date } );
const relative = days => ( { type: 'relative', days } );

describe( 'date_range matching function', () => {
	beforeEach( () => {
		// Local-time construction: `new Date( '2026-07-28' )` would be parsed as UTC
		// and could land on the 27th or 29th depending on the runner's timezone.
		jest.useFakeTimers().setSystemTime( new Date( 2026, 6, 28, 12, 0, 0 ) );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'matches a value inside an absolute window', () => {
		const config = { value: { start: absolute( '2026-01-01' ), end: absolute( '2026-12-31' ) } };
		expect( dateRange( { value: '2026-06-15' }, config ) ).toBe( true );
	} );

	it( 'includes both edges of the window', () => {
		const config = { value: { start: absolute( '2026-01-01' ), end: absolute( '2026-12-31' ) } };
		expect( dateRange( { value: '2026-01-01' }, config ) ).toBe( true );
		expect( dateRange( { value: '2026-12-31' }, config ) ).toBe( true );
	} );

	it( 'rejects values outside the window', () => {
		const config = { value: { start: absolute( '2026-01-01' ), end: absolute( '2026-12-31' ) } };
		expect( dateRange( { value: '2025-12-31' }, config ) ).toBe( false );
		expect( dateRange( { value: '2027-01-01' }, config ) ).toBe( false );
	} );

	it( 'treats an absent bound as unbounded', () => {
		expect( dateRange( { value: '1999-01-01' }, { value: { end: absolute( '2026-12-31' ) } } ) ).toBe( true );
		expect( dateRange( { value: '2099-01-01' }, { value: { start: absolute( '2026-01-01' ) } } ) ).toBe( true );
		expect( dateRange( { value: '2026-06-15' }, { value: {} } ) ).toBe( true );
	} );

	it( 'resolves relative bounds against today', () => {
		// "in the last 30 days", evaluated on 2026-07-28.
		const config = { value: { start: relative( -30 ), end: relative( 0 ) } };
		expect( dateRange( { value: '2026-07-28' }, config ) ).toBe( true );
		expect( dateRange( { value: '2026-06-28' }, config ) ).toBe( true );
		expect( dateRange( { value: '2026-06-27' }, config ) ).toBe( false );
		expect( dateRange( { value: '2026-07-29' }, config ) ).toBe( false );
	} );

	it( 'resolves forward-looking relative bounds', () => {
		// "expiring within the next 30 days".
		const config = { value: { start: relative( 0 ), end: relative( 30 ) } };
		expect( dateRange( { value: '2026-08-27' }, config ) ).toBe( true );
		expect( dateRange( { value: '2026-08-28' }, config ) ).toBe( false );
	} );

	it( 'reads the date part of a datetime without converting timezones', () => {
		const config = { value: { start: absolute( '2026-01-15' ), end: absolute( '2026-01-15' ) } };
		expect( dateRange( { value: '2026-01-15T23:30:00-06:00' }, config ) ).toBe( true );
	} );

	it( 'never matches a non-ISO reader value', () => {
		// A legacy un-normalized Mailchimp value slices to a comparable string that
		// sorts below every ISO date, so an unvalidated compare would match here.
		const config = { value: { start: absolute( '2026-01-01' ), end: absolute( '2026-12-31' ) } };
		expect( dateRange( { value: '03/04/2026' }, config ) ).toBe( false );
	} );

	it( 'rejects a day that does not exist in its month, on either side', () => {
		// The bounded pattern admits '2026-02-30' — it sorts perfectly well between
		// real dates, so as a stored value it would match a window it isn't in, and
		// as a bound it would silently shift one edge of that window.
		expect( dateRange( { value: '2026-02-30' }, { value: { start: absolute( '2026-01-01' ), end: absolute( '2026-12-31' ) } } ) ).toBe( false );
		expect( dateRange( { value: '2026-06-15' }, { value: { start: absolute( '2026-02-30' ) } } ) ).toBe( false );
		expect( dateRange( { value: '2026-06-15' }, { value: { end: absolute( '2026-02-31' ) } } ) ).toBe( false );
		// Feb 29 is a date in a leap year and not otherwise.
		expect( dateRange( { value: '2024-02-29' }, { value: { start: absolute( '2024-01-01' ) } } ) ).toBe( true );
		expect( dateRange( { value: '2026-02-29' }, { value: { start: absolute( '2026-01-01' ) } } ) ).toBe( false );
	} );

	it( 'rejects a digit-shaped but impossible calendar date', () => {
		// '2026-13-45' has the right digit shape but no such month or day. The PHP
		// side stores exactly this kind of value verbatim on a parse failure,
		// expecting the matcher to reject it — an unvalidated compare would instead
		// sort it above every December date, matching this no-upper-bound window.
		const config = { value: { start: absolute( '2026-01-01' ) } };
		expect( dateRange( { value: '2026-13-45' }, config ) ).toBe( false );
	} );

	it( 'never matches an empty or non-string reader value', () => {
		const config = { value: { start: absolute( '2026-01-01' ) } };
		expect( dateRange( { value: '' }, config ) ).toBe( false );
		expect( dateRange( { value: undefined }, config ) ).toBe( false );
		expect( dateRange( { value: 20260615 }, config ) ).toBe( false );
	} );

	it( 'never matches when the criterion value is the wrong shape', () => {
		expect( dateRange( { value: '2026-06-15' }, { value: '2026-06-15' } ) ).toBe( false );
		expect( dateRange( { value: '2026-06-15' }, { value: [ '2026-06-15' ] } ) ).toBe( false );
		expect( dateRange( { value: '2026-06-15' }, { value: null } ) ).toBe( false );
	} );

	it( 'fails closed on a malformed bound rather than widening the window', () => {
		expect( dateRange( { value: '2026-06-15' }, { value: { start: { type: 'absolute' } } } ) ).toBe( false );
		expect( dateRange( { value: '2026-06-15' }, { value: { start: absolute( '15/06/2026' ) } } ) ).toBe( false );
		expect( dateRange( { value: '2026-06-15' }, { value: { end: { type: 'relative', days: 'ten' } } } ) ).toBe( false );
	} );

	it( 'fails closed on a relative offset beyond the Date range', () => {
		// The number input is unconstrained, so a nine-digit offset is typeable.
		// setDate() then yields an Invalid Date whose components format to the
		// truthy string 'NaN-NaN-NaN' — as an end bound an unguarded compare reads
		// that as satisfied and matches every reader with a valid date.
		expect( dateRange( { value: '2026-06-15' }, { value: { end: relative( 999999999 ) } } ) ).toBe( false );
		expect( dateRange( { value: '2026-06-15' }, { value: { start: relative( -999999999 ) } } ) ).toBe( false );
	} );

	it( 'still resolves a large but in-range relative offset', () => {
		// Guarding the overflow must not reject ordinary multi-year windows.
		expect( dateRange( { value: '2026-06-15' }, { value: { start: relative( -3650 ) } } ) ).toBe( true );
	} );
} );
