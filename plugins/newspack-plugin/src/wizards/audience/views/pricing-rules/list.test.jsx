/**
 * The Pricing Rules list screen: what it publishes to the wizard header, how it
 * gates on both reads, and the order it renders the table and the card in.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import PricingRulesList, { ACTIVE_STATE_STATUS } from './list';
import { statusGlyph } from '../../../../../packages/components/src/status-indicator';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/pricing-rules-list' } ) );

// Exposes a filter-to-nothing trigger and the trash action's confirm step, neither
// of which the real DataViews makes reachable from a test.
jest.mock( '../../../../../packages/components/src', () => {
	const history = { push: jest.fn() };
	return {
		DataViews: ( { view, onChangeView, actions, data } ) => {
			const trash = ( actions || [] ).find( action => action.id === 'trash' );
			return (
				<>
					<button onClick={ () => onChangeView( { ...view, search: 'no-such-rule' } ) }>filter to nothing</button>
					{ trash && data.length > 0 && <trash.RenderModal items={ [ data[ 0 ] ] } closeModal={ () => {} } /> }
				</>
			);
		},
		WizardBanner: ( { children } ) => <>{ children }</>,
		Router: { useHistory: () => history },
	};
} );

jest.mock( './catalog-impact', () => () => <div data-testid="catalog-impact" /> );
jest.mock( './onboarding', () => () => <div data-testid="onboarding" /> );

let headerCalls = [];
let notices = [];

register(
	createReduxStore( 'test/pricing-rules-list', {
		reducer: ( state = {} ) => state,
		actions: {
			setHeaderData: data => {
				headerCalls.push( data );
				return { type: 'NOOP' };
			},
			addNotice: notice => {
				notices.push( notice );
				return { type: 'NOOP' };
			},
		},
	} )
);

/** The leaf of the last header payload that named the section. */
const publishedSection = () => {
	const named = headerCalls.filter( data => data.sectionName );
	return named[ named.length - 1 ].sectionName[ 0 ];
};

const publishedActions = () => {
	const withActions = headerCalls.filter( data => data.actions );
	return withActions[ withActions.length - 1 ].actions;
};

const rule = id => ( {
	id,
	deal_key: `deal-${ id }`,
	title: `Rule ${ id }`,
	status: 'publish',
	status_label: 'Published',
	strategy_id: 'simple',
	strategy_label: 'Simple',
	scope_type: 'all',
	scope_label: 'All products',
	scope_ids: [],
	priority: 0,
	compose_mode: 'min',
	application: 'current',
	publicize: false,
	active_from: null,
	active_until: null,
	active_state: 'active',
	published_at: null,
	intent: 'custom',
	intent_note: '',
	cycle_anchor: 'subscription_start',
	is_stepped: false,
	has_conditions: false,
	conditions: {},
	simple: null,
	steps: null,
	edit_link: '',
} );

const response = rules => ( {
	rules,
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	strategies: [],
	scopes: [],
	calc_types: [],
	conditions: [],
} );

const catalogStats = ( over = {} ) => ( {
	supported: true,
	total_matching: 33,
	count_limited: false,
	preview_limited: true,
	sample_count: 1,
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	sample: [],
	segment_groups: [],
	...over,
} );

const isCatalogPath = path => path.startsWith( '/wc-dynamic-pricing/v1/impact-preview' );

/** Route each request by path, so the catalogue call never receives the rules payload. */
const serve = ( { rules = [], stats = catalogStats(), rulesFail = false, statsFail = false } = {} ) => {
	apiFetch.mockImplementation( ( { path } ) => {
		if ( isCatalogPath( path ) ) {
			return statsFail ? Promise.reject( new Error( 'nope' ) ) : Promise.resolve( stats );
		}
		return rulesFail ? Promise.reject( new Error( 'nope' ) ) : Promise.resolve( response( rules ) );
	} );
};

describe( 'the Pricing Rules list', () => {
	beforeEach( () => {
		headerCalls = [];
		notices = [];
		apiFetch.mockReset();
	} );

	it( 'publishes the count once the rules land', async () => {
		serve( { rules: [ rule( 1 ), rule( 2 ), rule( 3 ) ] } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( publishedSection().label ).toBe( 'Pricing Rules' );
		expect( publishedSection().count ).toBe( 3 );
		expect( publishedSection().countLabel ).toBe( '3 rules' );
	} );

	it( 'announces a single rule in the singular', async () => {
		serve( { rules: [ rule( 1 ) ] } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( publishedSection().countLabel ).toBe( '1 rule' );
	} );

	it( 'publishes no count while the read is in flight', async () => {
		let land;
		apiFetch.mockImplementation( ( { path } ) => {
			if ( isCatalogPath( path ) ) {
				return Promise.resolve( catalogStats() );
			}
			return new Promise( resolve => {
				land = resolve;
			} );
		} );
		// Left unwrapped, the catalogue promise resolves in a microtask after render
		// and React warns about the update.
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( publishedSection().label ).toBe( 'Pricing Rules' );
		expect( publishedSection().count ).toBeUndefined();

		await act( async () => {
			land( response( [ rule( 1 ) ] ) );
		} );

		expect( publishedSection().count ).toBe( 1 );
	} );

	it( 'publishes no count when the read fails', async () => {
		serve( { rulesFail: true } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( publishedSection().label ).toBe( 'Pricing Rules' );
		expect( publishedSection().count ).toBeUndefined();
		expect( document.querySelector( '.components-notice.is-error' ) ).toHaveTextContent( 'Could not load pricing rules.' );
		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toBeInTheDocument();
	} );

	it( 'holds a page spinner until both reads settle', async () => {
		let landStats;
		apiFetch.mockImplementation( ( { path } ) => {
			if ( isCatalogPath( path ) ) {
				return new Promise( resolve => {
					landStats = resolve;
				} );
			}
			return Promise.resolve( response( [ rule( 1 ) ] ) );
		} );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( document.querySelector( '.components-spinner' ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'catalog-impact' ) ).not.toBeInTheDocument();

		await act( async () => {
			landStats( catalogStats() );
		} );

		expect( document.querySelector( '.components-spinner' ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'catalog-impact' ) ).toBeInTheDocument();
	} );

	it( 'asks the catalogue for a single row', async () => {
		serve( { rules: [ rule( 1 ) ] } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( apiFetch ).toHaveBeenCalledWith( { path: '/wc-dynamic-pricing/v1/impact-preview?limit=1' } );
	} );

	it( 'renders the catalogue card after the rules table', async () => {
		serve( { rules: [ rule( 1 ) ] } );
		let container;
		await act( async () => {
			( { container } = render( <PricingRulesList /> ) );
		} );

		const order = Array.from( container.querySelectorAll( '*' ) );
		const table = screen.getByRole( 'button', { name: 'filter to nothing' } );
		const card = screen.getByTestId( 'catalog-impact' );

		// Without this, a stand-in outside the container indexes at -1 and every
		// card position passes.
		expect( order ).toContain( table );
		expect( order.indexOf( card ) ).toBeGreaterThan( order.indexOf( table ) );
	} );

	it( 'sets no header action while the screen is still loading', async () => {
		let landStats;
		apiFetch.mockImplementation( ( { path } ) => {
			if ( isCatalogPath( path ) ) {
				return new Promise( resolve => {
					landStats = resolve;
				} );
			}
			return Promise.resolve( response( [ rule( 1 ) ] ) );
		} );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( publishedActions() ).toEqual( [] );

		await act( async () => {
			landStats( catalogStats() );
		} );

		expect( publishedActions() ).toHaveLength( 1 );
		expect( publishedActions()[ 0 ].label ).toBe( 'Add Rule' );
	} );

	it( 'shows the empty state and withholds the header action when there are no rules', async () => {
		serve( { rules: [] } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( screen.getByTestId( 'onboarding' ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'catalog-impact' ) ).not.toBeInTheDocument();
		expect( publishedActions() ).toEqual( [] );
		expect( publishedSection().count ).toBeUndefined();
	} );

	it( 'keeps the table when a filter leaves no matches', async () => {
		serve( { rules: [ rule( 1 ), rule( 2 ) ] } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'filter to nothing' } ) );
		} );

		expect( screen.queryByTestId( 'onboarding' ) ).not.toBeInTheDocument();
		expect( publishedSection().count ).toBe( 0 );
	} );

	it( 'keeps the table rather than the empty state when the read fails', async () => {
		serve( { rulesFail: true } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( screen.queryByTestId( 'onboarding' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'filter to nothing' } ) ).toBeInTheDocument();
	} );

	it( 'renders the screen without a headline when the catalogue read fails', async () => {
		serve( { rules: [ rule( 1 ) ], statsFail: true } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( screen.queryByTestId( 'catalog-impact' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'filter to nothing' } ) ).toBeInTheDocument();
		expect( notices ).toEqual( [] );
	} );

	it( 'renders the table but no card when the catalogue is unsupported', async () => {
		serve( { rules: [ rule( 1 ) ], stats: { supported: false } } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( screen.queryByTestId( 'catalog-impact' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'filter to nothing' } ) ).toBeInTheDocument();
	} );

	it( 'releases the page once the catalogue read outstays its welcome', async () => {
		jest.useFakeTimers();
		apiFetch.mockImplementation( ( { path } ) => {
			if ( isCatalogPath( path ) ) {
				return new Promise( () => {} );
			}
			return Promise.resolve( response( [ rule( 1 ) ] ) );
		} );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( document.querySelector( '.components-spinner' ) ).toBeInTheDocument();

		await act( async () => {
			jest.advanceTimersByTime( 8000 );
		} );

		expect( document.querySelector( '.components-spinner' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'filter to nothing' } ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'catalog-impact' ) ).not.toBeInTheDocument();
		jest.useRealTimers();
	} );

	it( 'refetches the catalogue after a rule is trashed', async () => {
		serve( { rules: [ rule( 1 ), rule( 2 ) ] } );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		const catalogCalls = () => apiFetch.mock.calls.filter( ( [ { path } ] ) => isCatalogPath( path ) ).length;
		expect( catalogCalls() ).toBe( 1 );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Move to Trash' } ) );
		} );

		expect( apiFetch ).toHaveBeenCalledWith( { path: '/wc-dynamic-pricing/v1/rules/1', method: 'DELETE' } );
		expect( catalogCalls() ).toBe( 2 );
	} );

	it( 'announces the page loader for assistive technology', async () => {
		apiFetch.mockImplementation( ( { path } ) => {
			if ( isCatalogPath( path ) ) {
				return new Promise( () => {} );
			}
			return Promise.resolve( response( [ rule( 1 ) ] ) );
		} );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Loading pricing rules' );
	} );
} );

describe( 'ACTIVE_STATE_STATUS', () => {
	it( 'names every window state a rule can be in', () => {
		expect( Object.keys( ACTIVE_STATE_STATUS ).sort() ).toEqual( [ 'active', 'ended', 'scheduled' ] );
	} );

	// The column offers the three as separate filters, and the glyph is the only thing
	// telling them apart: nothing here is tinted.
	it( 'gives no two states the same mark', () => {
		const glyphs = Object.values( ACTIVE_STATE_STATUS ).map( name => statusGlyph( name ) );
		expect( new Set( glyphs ).size ).toBe( glyphs.length );
	} );
} );
