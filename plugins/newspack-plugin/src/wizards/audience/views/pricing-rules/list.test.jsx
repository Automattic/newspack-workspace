/**
 * The Pricing Rules list publishes its row count to the wizard header. A read that
 * never landed has no count to publish: "(0)" would assert the list is empty.
 */

/**
 * External dependencies
 */
import { render, act, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import PricingRulesList from './list';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/pricing-rules-list' } ) );

// Only the header count is under test, so DataViews renders nothing; the count
// itself comes from filterSortAndPaginate, which stays real.
jest.mock( '../../../../../packages/components/src', () => {
	const history = { push: jest.fn() };
	return {
		DataViews: () => null,
		Badge: () => null,
		WizardBanner: ( { children } ) => <>{ children }</>,
		Router: { useHistory: () => history },
	};
} );

jest.mock( './catalog-impact', () => () => null );

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

describe( 'the Pricing Rules list header count', () => {
	beforeEach( () => {
		headerCalls = [];
		notices = [];
		apiFetch.mockReset();
	} );

	it( 'publishes the count once the rules land', async () => {
		apiFetch.mockResolvedValue( response( [ rule( 1 ), rule( 2 ), rule( 3 ) ] ) );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( publishedSection().label ).toBe( 'Pricing Rules' );
		expect( publishedSection().count ).toBe( 3 );
		expect( publishedSection().countLabel ).toBe( '3 rules' );
	} );

	it( 'announces a single rule in the singular', async () => {
		apiFetch.mockResolvedValue( response( [ rule( 1 ) ] ) );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( publishedSection().countLabel ).toBe( '1 rule' );
	} );

	it( 'publishes no count while the read is in flight', async () => {
		let land;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				land = resolve;
			} )
		);
		render( <PricingRulesList /> );

		expect( publishedSection().label ).toBe( 'Pricing Rules' );
		expect( publishedSection().count ).toBeUndefined();

		await act( async () => {
			land( response( [ rule( 1 ) ] ) );
		} );

		expect( publishedSection().count ).toBe( 1 );
	} );

	it( 'publishes no count when the read fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <PricingRulesList /> );
		} );

		expect( publishedSection().label ).toBe( 'Pricing Rules' );
		expect( publishedSection().count ).toBeUndefined();
		expect( document.querySelector( '.components-notice.is-error' ) ).toHaveTextContent( 'Could not load pricing rules.' );
		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toBeInTheDocument();
	} );
} );
