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
	let baseDateSettings;

	beforeAll( async () => {
		window.newspackAudienceCampaigns = window.newspackAudienceCampaigns || {
			api: '/newspack/v1/wizard/newspack-audience-campaigns',
			criteria: CRITERIA,
		};
		baseDateSettings = getSettings();
		( { segmentReachDescription } = await import( './utils' ) );
	} );

	afterEach( () => {
		setSettings( baseDateSettings );
	} );

	it( 'returns null when reach reporting is inactive', () => {
		expect( segmentReachDescription( { id: '12' } ) ).toBeNull();
	} );

	it( 'renders sessions, prompt audience, and the data date', () => {
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 1240, won: 320, as_of: '2026-08-06', range_days: 7 },
		} );
		expect( line ).toBe( `Reach (7d): ${ ( 1240 ).toLocaleString() } sessions · prompt audience: ${ ( 320 ).toLocaleString() } · as of Aug 6` );
	} );

	it( 'names the window the server reported rather than a fixed one', () => {
		const line = segmentReachDescription( {
			id: '12',
			reach: { matched: 5, won: 1, as_of: '2026-08-06', range_days: 28 },
		} );
		expect( line ).toContain( 'Reach (28d)' );
	} );

	it( 'falls back to 7 days for a cache written before the window was reported', () => {
		const line = segmentReachDescription( { id: '12', reach: { matched: 5, won: 1, as_of: '2026-08-06' } } );
		expect( line ).toContain( 'Reach (7d)' );
	} );

	it.each( [
		[ 'a negative offset', -5 ],
		[ 'a positive offset', 13 ],
	] )( 'reports the same calendar day on a site with %s', ( _label, offset ) => {
		// `as_of` labels the last day the GA4 report covers, computed in UTC.
		// Formatting it in site time shifted the day westward: at UTC-5 this
		// rendered "Aug 5" for an as_of of 2026-08-06.
		setSettings( { ...baseDateSettings, timezone: { offset, string: '', abbr: '' } } );
		const line = segmentReachDescription( { id: '12', reach: { matched: 1, won: 0, as_of: '2026-08-06' } } );
		expect( line ).toContain( 'as of Aug 6' );
	} );

	it( 'renders the no-data state distinctly from zero', () => {
		expect( segmentReachDescription( { id: '12', reach: { matched: null, won: null, as_of: '2026-08-06' } } ) ).toBe( 'No reach data yet' );
	} );
} );
