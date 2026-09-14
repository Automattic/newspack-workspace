/**
 * Tests for the campaigns wizard utils.
 *
 * Regression coverage for NPPD-1852: a segment summary rendered the literal
 * string "[object Object]" when a criteria message resolved to a React element
 * (e.g. the async-resolved "Not subscribed to: <list>" label). `segmentDescription`
 * must return renderable React nodes, not a `.join( ' | ' )`-ed string that would
 * coerce those elements to "[object Object]".
 *
 * Regression coverage for NPPM-2729: the Campaigns admin page rendered blank
 * because a prompt's taxonomy arrays reached `promptDescription` holding a null
 * entry — a term deleted out from under a stale object cache — and `.map()` threw
 * on `.name`. `warningForPopup` walks the same category arrays and throws the same
 * way. Both must tolerate null entries and a non-array value, since PHP's
 * `get_the_tags()` / `get_the_terms()` return `false` when a post has no terms.
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

const PLACEMENT = 'center';

let segmentDescription, promptDescription, warningForPopup, isOverlay;

beforeAll( async () => {
	window.newspackAudienceCampaigns = {
		api: '/newspack/v1/wizard/newspack-audience-campaigns',
		criteria: CRITERIA,
		overlay_placements: [ PLACEMENT, 'top', 'bottom' ],
		custom_placements: {},
	};
	( { segmentDescription, promptDescription, warningForPopup, isOverlay } = await import( './utils' ) );
} );

describe( 'segmentDescription', () => {
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

const overlay = ( overrides = {} ) => ( {
	id: 100,
	status: 'publish',
	title: 'Test prompt',
	segments: [],
	categories: [],
	tags: [],
	campaign_groups: [],
	options: { placement: PLACEMENT, frequency: 'daily' },
	...overrides,
} );

const realTag = { term_id: 58, name: 'real-tag' };
const realCategory = { term_id: 5, name: 'Sports' };
const otherCategory = { term_id: 6, name: 'News' };

describe( 'promptDescription', () => {
	it( 'renders a description from valid taxonomy arrays', () => {
		const result = promptDescription(
			overlay( {
				categories: [ realCategory ],
				tags: [ realTag ],
				campaign_groups: [ { name: 'Group A' } ],
			} )
		);
		expect( result ).toContain( 'Categories:' );
		expect( result ).toContain( 'Sports' );
		expect( result ).toContain( 'Tags:' );
		expect( result ).toContain( 'real-tag' );
		expect( result ).toContain( 'Campaign:' );
		expect( result ).toContain( 'Group A' );
	} );

	// The reported crash: a null entry alongside a real term must not take the
	// real term's name down with it.
	it( 'tolerates a null entry in tags', () => {
		const result = promptDescription( overlay( { tags: [ realTag, null ] } ) );
		expect( result ).toContain( 'Tags:' );
		expect( result ).toContain( 'real-tag' );
	} );

	it( 'tolerates a null entry in categories', () => {
		const result = promptDescription( overlay( { categories: [ realCategory, null ] } ) );
		expect( result ).toContain( 'Categories:' );
		expect( result ).toContain( 'Sports' );
	} );

	it( 'tolerates a null entry in campaign_groups', () => {
		const result = promptDescription( overlay( { campaign_groups: [ { name: 'Group A' }, null ] } ) );
		expect( result ).toContain( 'Campaign:' );
		expect( result ).toContain( 'Group A' );
	} );

	it( 'tolerates tags === null', () => {
		expect( () => promptDescription( overlay( { tags: null } ) ) ).not.toThrow();
	} );

	it( 'tolerates categories === null', () => {
		expect( () => promptDescription( overlay( { categories: null } ) ) ).not.toThrow();
	} );

	it( 'tolerates campaign_groups === null', () => {
		expect( () => promptDescription( overlay( { campaign_groups: null } ) ) ).not.toThrow();
	} );
} );

describe( 'warningForPopup', () => {
	it( 'test fixture is correctly set up as an overlay', () => {
		expect( isOverlay( overlay() ) ).toBe( true );
	} );

	it( 'returns null when no conflicting prompts share segments and categories', () => {
		const prompt = overlay( { categories: [ realCategory ] } );
		const conflict = overlay( { id: 200, categories: [ otherCategory ] } );
		expect( warningForPopup( [ prompt, conflict ], prompt ) ).toBeNull();
	} );

	// The conflict carries a non-matching category so the outer `.some()` iterates
	// past the real category and reaches the null.
	it( 'tolerates a null entry in prompt categories', () => {
		const prompt = overlay( { categories: [ realCategory, null ] } );
		const conflict = overlay( { id: 200, categories: [ otherCategory ] } );
		expect( () => warningForPopup( [ prompt, conflict ], prompt ) ).not.toThrow();
	} );

	// Null goes first so the inner `.some()` hits it on its first iteration.
	it( 'tolerates a null entry in a conflicting prompt categories', () => {
		const prompt = overlay( { categories: [ realCategory ] } );
		const conflict = overlay( { id: 200, categories: [ null, otherCategory ] } );
		expect( () => warningForPopup( [ prompt, conflict ], prompt ) ).not.toThrow();
	} );

	it( 'tolerates categories === null on the prompt', () => {
		const prompt = overlay( { categories: null } );
		const conflict = overlay( { id: 200, categories: [ realCategory ] } );
		expect( () => warningForPopup( [ prompt, conflict ], prompt ) ).not.toThrow();
	} );

	it( 'tolerates categories === null on a conflicting prompt', () => {
		const prompt = overlay( { categories: [ realCategory ] } );
		const conflict = overlay( { id: 200, categories: null } );
		expect( () => warningForPopup( [ prompt, conflict ], prompt ) ).not.toThrow();
	} );
} );
