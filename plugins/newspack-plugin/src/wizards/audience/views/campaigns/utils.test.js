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
		id: 'active_subscriptions',
		category: 'reader_revenue',
		name: 'Has active subscription(s)',
	},
	{
		id: 'LAST_GIFT_DATE',
		category: 'integrations',
		name: 'Last Gift Date',
		matching_function: 'date_range',
	},
];

const PLACEMENT = 'center';

let segmentDescription, splitCriteriaMessage, promptDetails, promptStatus, warningForPopup, isOverlay, placementForPopup;

beforeAll( async () => {
	window.newspackAudienceCampaigns = {
		api: '/newspack/v1/wizard/newspack-audience-campaigns',
		criteria: CRITERIA,
		overlay_placements: [ PLACEMENT, 'top', 'bottom' ],
		custom_placements: {},
	};
	( { segmentDescription, splitCriteriaMessage, promptDetails, promptStatus, warningForPopup, isOverlay, placementForPopup } = await import(
		'./utils'
	) );
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
		// Names come from the unfiltered config, so a list a gate hides from readers keeps its name.
		expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-newsletters/v1/lists_config?context=edit' } );
	} );

	it( 'names saved subscription products as deleted when none of them exist any more', async () => {
		// The lookup asks only for the saved IDs, so an empty answer means every one is gone.
		apiFetch.mockResolvedValue( [] );

		const segment = {
			configuration: { is_disabled: false },
			criteria: [ { criteria_id: 'active_subscriptions', value: [ 999 ] } ],
		};

		const { container } = render( <div>{ segmentDescription( segment ) }</div> );

		await waitFor( () => expect( container.textContent ).toContain( 'Has active subscription(s): Deleted subscription' ) );
		// Names come from a lookup of the saved IDs, so products the picker no longer lists keep theirs.
		expect( apiFetch ).toHaveBeenCalledWith( { path: expect.stringContaining( 'include' ) } );
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

const describePrompt = prompt =>
	promptDetails( prompt )
		.map( ( { label, value } ) => `${ label } ${ value }` )
		.join( ' | ' );

describe( 'splitCriteriaMessage', () => {
	it( 'splits a plain message at its first separator', () => {
		expect( splitCriteriaMessage( 'Articles read: min: 5' ) ).toEqual( { label: 'Articles read:', value: 'min: 5' } );
	} );

	it( 'leaves a message with no label as the value', () => {
		expect( splitCriteriaMessage( 'Segment disabled' ) ).toEqual( { value: 'Segment disabled' } );
	} );

	it( 'leaves an element-valued message alone', () => {
		const element = <span>Subscribed to: Weekly Digest</span>;
		expect( splitCriteriaMessage( element ) ).toEqual( { value: element } );
	} );
} );

describe( 'promptDetails', () => {
	it( 'names the A/B test and the variant when the prompt is in one', () => {
		expect( describePrompt( overlay( { ab_test_id: 'hero', ab_variant: 'b' } ) ) ).toContain( 'A/B test: hero (variant B)' );
	} );

	it( 'leads with the placement and the frequency', () => {
		expect( promptDetails( overlay() ).map( ( { label } ) => label ) ).toEqual( [ 'Placement:', 'Frequency:' ] );
	} );

	it( 'names a custom frequency without the editor hint', () => {
		const prompt = overlay();
		const details = promptDetails( { ...prompt, options: { ...prompt.options, frequency: 'custom' } } );
		expect( details.find( ( { label } ) => 'Frequency:' === label ).value ).toBe( 'Custom' );
	} );

	it( 'says nothing about A/B tests when the prompt is in none', () => {
		expect( describePrompt( overlay() ) ).not.toContain( 'A/B test' );
	} );

	it( 'renders a description from valid taxonomy arrays', () => {
		const result = describePrompt(
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
		const result = describePrompt( overlay( { tags: [ realTag, null ] } ) );
		expect( result ).toContain( 'Tags:' );
		expect( result ).toContain( 'real-tag' );
	} );

	it( 'tolerates a null entry in categories', () => {
		const result = describePrompt( overlay( { categories: [ realCategory, null ] } ) );
		expect( result ).toContain( 'Categories:' );
		expect( result ).toContain( 'Sports' );
	} );

	it( 'tolerates a null entry in campaign_groups', () => {
		const result = describePrompt( overlay( { campaign_groups: [ { name: 'Group A' }, null ] } ) );
		expect( result ).toContain( 'Campaign:' );
		expect( result ).toContain( 'Group A' );
	} );

	it( 'tolerates tags === null', () => {
		expect( () => describePrompt( overlay( { tags: null } ) ) ).not.toThrow();
	} );

	it( 'tolerates categories === null', () => {
		expect( () => describePrompt( overlay( { categories: null } ) ) ).not.toThrow();
	} );

	it( 'tolerates campaign_groups === null', () => {
		expect( () => describePrompt( overlay( { campaign_groups: null } ) ) ).not.toThrow();
	} );
} );

describe( 'promptStatus', () => {
	it.each( [
		[ 'publish', 'Active', 'stable' ],
		[ 'draft', 'Inactive', 'draft' ],
		[ 'pending', 'Pending Review', 'low' ],
		[ 'future', 'Scheduled', 'informational' ],
		[ 'trash', 'Trash', 'draft' ],
	] )( 'labels a %s prompt', ( status, label, intent ) => {
		expect( promptStatus( { status } ) ).toEqual( { label, intent } );
	} );

	it( 'says when a published prompt is held back by its segment', () => {
		expect( promptStatus( { status: 'publish' }, true ).label ).toBe( 'Segment Disabled' );
	} );

	it( 'keeps the status of an unpublished prompt in a disabled segment', () => {
		expect( promptStatus( { status: 'draft' }, true ).label ).toBe( 'Inactive' );
	} );
} );

describe( 'warningForPopup', () => {
	it( 'test fixture is correctly set up as an overlay', () => {
		expect( isOverlay( overlay() ) ).toBe( true );
	} );

	it( 'reports a published prompt in the same placement with no categories on either', () => {
		const prompt = overlay( { id: 100 } );
		const conflict = overlay( { id: 200, title: 'Other prompt' } );
		const { container } = render( warningForPopup( [ prompt, conflict ], prompt ) );
		expect( container.textContent ).toContain( 'Other prompt' );
	} );

	// Two prompts in one A/B test target the same readers in the same placement
	// on purpose, and the view engine shows each reader one of them. `ab_test_id`
	// is only on the payload while the test is valid and the feature is on.
	it( 'does not report the other arm of the same A/B test as a conflict', () => {
		const prompt = overlay( { id: 100, ab_test_id: 'hero', ab_variant: 'a' } );
		const pair = overlay( { id: 200, title: 'Variant B', ab_test_id: 'hero', ab_variant: 'b' } );
		expect( warningForPopup( [ prompt, pair ], prompt ) ).toBeNull();
	} );

	it( 'reports a prompt on the same arm of the same A/B test', () => {
		const prompt = overlay( { id: 100, ab_test_id: 'hero', ab_variant: 'b' } );
		const twin = overlay( { id: 200, title: 'Second B', ab_test_id: 'hero', ab_variant: 'b' } );
		const { container } = render( warningForPopup( [ prompt, twin ], prompt ) );
		expect( container.textContent ).toContain( 'Second B' );
	} );

	it( 'still reports a prompt outside the test', () => {
		const prompt = overlay( { id: 100, ab_test_id: 'hero', ab_variant: 'a' } );
		const pair = overlay( { id: 200, title: 'Variant B', ab_test_id: 'hero', ab_variant: 'b' } );
		const third = overlay( { id: 300, title: 'Unrelated prompt' } );
		const { container } = render( warningForPopup( [ prompt, pair, third ], prompt ) );
		expect( container.textContent ).toContain( 'Unrelated prompt' );
		expect( container.textContent ).not.toContain( 'Variant B' );
	} );

	it( 'reports a prompt in a different A/B test', () => {
		const prompt = overlay( { id: 100, ab_test_id: 'hero', ab_variant: 'a' } );
		const other = overlay( { id: 200, title: 'Other test', ab_test_id: 'footer', ab_variant: 'a' } );
		const { container } = render( warningForPopup( [ prompt, other ], prompt ) );
		expect( container.textContent ).toContain( 'Other test' );
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

describe( 'placementForPopup', () => {
	it( 'labels a manual-only overlay apart from an overlay inserted automatically', () => {
		expect( placementForPopup( { options: { placement: 'center', manual_only: false } } ) ).toBe( 'Center Overlay' );
		expect( placementForPopup( { options: { placement: 'center', manual_only: true } } ) ).toBe( 'Center Overlay (Manual Only)' );
	} );
} );
