/**
 * Tests for the campaigns wizard utils.
 *
 * Regression coverage for NPPD-1852: a segment summary rendered the literal
 * string "[object Object]" when a criteria message resolved to a React element
 * (e.g. the async-resolved "Not subscribed to: <list>" label). `segmentDescription`
 * must return renderable React nodes, not a `.join( ' | ' )`-ed string that would
 * coerce those elements to "[object Object]".
 */

/**
 * External dependencies
 */
import { render, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { getSettings, setSettings } from '@wordpress/date';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// `utils.js` captures `window.newspackAudienceCampaigns.criteria` at module-load
// time, so the global must be set before the module is loaded (see the dynamic
// import in `beforeAll`).
const CRITERIA = [
	{
		id: 'newsletter',
		category: 'newsletter',
		name: 'Newsletter',
		options: [
			{ label: 'Subscribers and non-subscribers', value: '' },
			{ label: 'Subscribers', value: 'subscribers' },
			{ label: 'Non-subscribers', value: 'non-subscribers' },
		],
	},
	{
		id: 'not_subscribed_lists',
		category: 'newsletter',
		name: 'Newsletter',
	},
];

describe( 'segmentDescription', () => {
	let segmentDescription;

	beforeAll( async () => {
		window.newspackAudienceCampaigns = {
			api: '/newspack/v1/wizard/newspack-audience-campaigns',
			criteria: CRITERIA,
		};
		( { segmentDescription } = await import( './utils' ) );
	} );

	it( 'renders an element-valued criteria message as a label, not "[object Object]"', async () => {
		apiFetch.mockResolvedValue( [ { id: 1, name: 'Weekly Digest' } ] );

		const segment = {
			configuration: { is_disabled: false },
			criteria: [
				{ criteria_id: 'newsletter', value: 'non-subscribers' },
				{ criteria_id: 'not_subscribed_lists', value: [ 1 ] },
			],
		};

		const { container } = render( <div>{ segmentDescription( segment ) }</div> );

		// The plain-string criterion still renders.
		expect( container.textContent ).toContain( 'Newsletter: Non-subscribers' );
		// Regression: the element-valued criterion must not stringify to "[object Object]".
		expect( container.textContent ).not.toContain( '[object Object]' );
		// Once the list names resolve, it renders the human-readable label.
		await waitFor( () => expect( container.textContent ).toContain( 'Not subscribed to:' ) );
		await waitFor( () => expect( container.textContent ).toContain( 'Weekly Digest' ) );
	} );
} );

describe( 'segmentReachDescription', () => {
	let segmentReachDescription;
	let segmentReachCaveat;
	let segmentReachNotice;
	let baseDateSettings;

	beforeAll( async () => {
		window.newspackAudienceCampaigns = window.newspackAudienceCampaigns || {
			api: '/newspack/v1/wizard/newspack-audience-campaigns',
			criteria: CRITERIA,
		};
		baseDateSettings = getSettings();
		( { segmentReachDescription, segmentReachCaveat, segmentReachNotice } = await import( './utils' ) );
	} );

	afterEach( () => {
		setSettings( baseDateSettings );
	} );

	it( 'returns null when reach reporting is inactive', () => {
		expect( segmentReachDescription( { id: '12' } ) ).toBeNull();
	} );

	it( 'renders both shares against the audience, with the sample size', () => {
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 1240, won: 320, total_sessions: 3620, as_of: '2026-08-06', range_days: 7 },
		} );
		expect( line ).toBe( `7 days to Aug 6: matched 34% of ${ ( 3620 ).toLocaleString() } sessions · prompts reached 9%` );
	} );

	it( 'reads as a record of past sessions, not a live capability', () => {
		// Reordering segments changes which prompts readers see from here on
		// and cannot move these numbers, so the line must not read as a
		// standing "this segment can reach X" figure. The window leads and
		// every verb is past tense.
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 1240, won: 320, total_sessions: 3620, as_of: '2026-08-06', range_days: 7 },
		} );
		expect( line ).toMatch( /^7 days to Aug 6:/ );
		expect( line ).toContain( 'matched' );
		expect( line ).toContain( 'prompts reached' );
		expect( line ).not.toContain( 'prompt audience' );
	} );

	it( 'keeps the denominator visible so a small sample reads as one', () => {
		// 34% of 41 sessions and 34% of 41,000 support very different calls;
		// the share alone cannot tell them apart.
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 14, won: 4, total_sessions: 41, as_of: '2026-08-06', range_days: 7 },
		} );
		expect( line ).toContain( '34% of 41 sessions' );
	} );

	it( 'distinguishes a segment that reached somebody from one that reached nobody', () => {
		// Rounding 0.2% to "0%" would erase the distinction these numbers
		// exist to draw: a probation segment with a handful of readers is not
		// the same as one with none.
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 8, won: 0, total_sessions: 4000, as_of: '2026-08-06', range_days: 7 },
		} );
		expect( line ).toContain( 'matched <1% of' );
		expect( line ).toContain( 'prompts reached 0%' );
	} );

	it( 'names the window the server reported rather than a fixed one', () => {
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 5, won: 1, total_sessions: 100, as_of: '2026-08-06', range_days: 28 },
		} );
		expect( line ).toContain( '28 days to Aug 6' );
	} );

	it( 'falls back to 7 days for a cache written before the window was reported', () => {
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 5, won: 1, total_sessions: 100, as_of: '2026-08-06' },
		} );
		expect( line ).toContain( '7 days to Aug 6' );
	} );

	it.each( [
		[ 'a negative offset', -5 ],
		[ 'a positive offset', 13 ],
	] )( 'reports the same calendar day on a site with %s', ( _label, offset ) => {
		// `as_of` labels the last day the GA4 report covers, computed in UTC.
		// Formatting it in site time shifted the day westward: at UTC-5 this
		// rendered "Aug 5" for an as_of of 2026-08-06.
		setSettings( { ...baseDateSettings, timezone: { offset, string: '', abbr: '' } } );
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 1, won: 0, total_sessions: 100, as_of: '2026-08-06' },
		} );
		expect( line ).toContain( '7 days to Aug 6' );
	} );

	it( 'renders the no-data state distinctly from zero', () => {
		expect(
			segmentReachDescription( {
				id: '12',
				reach: { matched: null, won: null, total_sessions: 3620, as_of: '2026-08-06' },
			} )
		).toBe( 'No reach data yet' );
	} );

	it( 'reads as no data rather than dividing by an empty week', () => {
		expect(
			segmentReachDescription( {
				id: '12',
				reach: { matched: 0, won: 0, total_sessions: 0, as_of: '2026-08-06' },
			} )
		).toBe( 'No reach data yet' );
	} );

	describe( 'segmentReachCaveat', () => {
		it( 'says nothing while priority held for the whole window', () => {
			expect(
				segmentReachCaveat( {
					id: '12',
					reach: { matched: 1240, won: 320, total_sessions: 3620, as_of: '2026-08-06', range_days: 7 },
				} )
			).toBeNull();
		} );

		it( 'names the day priority moved inside the reported window', () => {
			// The moment someone drags a segment is the moment they expect
			// these numbers to move, and it is the one thing that cannot move
			// them: the window still spans days that ran under the old order.
			const caveat = segmentReachCaveat( {
				id: '12',
				reach: {
					matched: 1240,
					won: 320,
					total_sessions: 3620,
					as_of: '2026-08-06',
					range_days: 7,
					priority_changed: '2026-08-05',
				},
			} );
			expect( caveat ).toBe( 'Priority changed Aug 5. Part of this window ran under the previous order.' );
		} );

		it( 'reports the same day whatever the site timezone', () => {
			setSettings( { ...baseDateSettings, timezone: { offset: -5, string: '', abbr: '' } } );
			const caveat = segmentReachCaveat( {
				id: '12',
				reach: { matched: 1, won: 0, total_sessions: 100, as_of: '2026-08-06', priority_changed: '2026-08-05' },
			} );
			expect( caveat ).toContain( 'Aug 5' );
		} );
	} );

	describe( 'segmentReachNotice', () => {
		it( 'says nothing when no segment reports reach', () => {
			expect( segmentReachNotice( [ { id: '12' }, { id: '13' } ] ) ).toBeNull();
		} );

		it( 'says nothing when the reported window found no sessions', () => {
			expect( segmentReachNotice( [ { id: '12', reach: { matched: 0, won: 0, total_sessions: 0, as_of: '2026-08-06' } } ] ) ).toBeNull();
		} );

		it( 'names the source, the window, the refresh rate, and the reorder consequence', () => {
			const notice = segmentReachNotice( [
				{ id: '12', reach: { matched: 1240, won: 320, total_sessions: 3620, as_of: '2026-08-06', range_days: 7 } },
			] );
			expect( notice ).toContain( 'Google Analytics' );
			expect( notice ).toContain( '7 days to Aug 6' );
			expect( notice ).toContain( 'refresh once a day' );
			expect( notice ).toContain( 'reordering segments changes which prompts readers see from now on' );
		} );

		it( 'reads the window off whichever segment has numbers', () => {
			const notice = segmentReachNotice( [
				{ id: '12' },
				{ id: '13', reach: { matched: null, won: null, total_sessions: 0, as_of: '2026-08-06' } },
				{ id: '14', reach: { matched: 5, won: 1, total_sessions: 900, as_of: '2026-08-06', range_days: 28 } },
			] );
			expect( notice ).toContain( '28 days to Aug 6' );
		} );
	} );
} );
