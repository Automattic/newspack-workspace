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
	{
		id: 'LAST_GIFT_DATE',
		category: 'integrations',
		name: 'Last Gift Date',
		matching_function: 'date_range',
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

	it( 'renders a date range criterion instead of "[object Object]"', () => {
		// Each bound is itself an object, so the generic `key: value` pass would
		// stringify both ends.
		const withRange = value => ( {
			configuration: { is_disabled: false },
			criteria: [ { criteria_id: 'LAST_GIFT_DATE', value } ],
		} );

		const absolute = render(
			<div>
				{ segmentDescription(
					withRange( {
						start: { type: 'absolute', date: '2026-01-01' },
						end: { type: 'relative', days: 0 },
					} )
				) }
			</div>
		);
		expect( absolute.container.textContent ).not.toContain( '[object Object]' );
		expect( absolute.container.textContent ).toContain( 'Last Gift Date: 2026-01-01 to today' );

		const rolling = render(
			<div>{ segmentDescription( withRange( { start: { type: 'relative', days: -30 }, end: { type: 'relative', days: 0 } } ) ) }</div>
		);
		expect( rolling.container.textContent ).toContain( 'Last Gift Date: 30 days ago to today' );

		const forward = render( <div>{ segmentDescription( withRange( { end: { type: 'relative', days: 1 } } ) ) }</div> );
		expect( forward.container.textContent ).toContain( 'Last Gift Date: until 1 day from now' );

		const openEnded = render( <div>{ segmentDescription( withRange( { start: { type: 'absolute', date: '2026-01-01' } } ) ) }</div> );
		expect( openEnded.container.textContent ).toContain( 'Last Gift Date: from 2026-01-01' );
	} );

	it( 'leaves a min/max criterion value on the generic key: value rendering', () => {
		// The date-range formatter must not swallow the shipped range operator.
		const segment = {
			configuration: { is_disabled: false },
			criteria: [ { criteria_id: 'LAST_GIFT_DATE', value: { min: 1, max: 5 } } ],
		};
		const { container } = render( <div>{ segmentDescription( segment ) }</div> );
		expect( container.textContent ).toContain( 'min: 1, max: 5' );
	} );
} );
