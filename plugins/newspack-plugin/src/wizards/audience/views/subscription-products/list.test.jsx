/**
 * The Plans list publishes its row count to the wizard header. A read that never
 * landed has no count to publish: "(0)" would assert the scope is empty.
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
import SubscriptionProductsList from './list';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/plans-list' } ) );

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

jest.mock( './policy-cells', () => ( { PolicyChips: () => null, EffectivePrice: () => null } ) );

let headerCalls = [];
let notices = [];

register(
	createReduxStore( 'test/plans-list', {
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

const product = id => ( {
	id,
	name: `Plan ${ id }`,
	type: 'subscription',
	type_label: 'Simple subscription',
	is_donation: false,
	is_group_subscription: false,
	group_member_label: '',
	base_price: 10,
	price_label: '$10',
	price_range_label: '',
	active_subscriptions: 3,
	categories: [],
	category_ids: [],
	category_label: '',
	availability: 'public',
	availability_label: 'Public',
	bundled_products: [],
	unlocks: [],
	unlocks_label: '',
	status: 'publish',
	status_label: 'Published',
} );

/** The leaf of the last header payload that named the section. */
const publishedSection = () => {
	const named = headerCalls.filter( data => data.sectionName );
	return named[ named.length - 1 ].sectionName[ 0 ];
};

const renderList = async ( scope = 'subscriptions' ) => {
	await act( async () => {
		render( <SubscriptionProductsList scope={ scope } /> );
	} );
};

describe( 'the Plans list header count', () => {
	beforeEach( () => {
		headerCalls = [];
		notices = [];
		apiFetch.mockReset();
	} );

	// The label is the breadcrumb leaf, and so the surface's only h1: each scope has to
	// name the tab the publisher activated. Each row carries a product from another
	// scope, so the count can only be right if it is the scoped one.
	it.each( [
		[ 'subscriptions', 'Subscriptions', '2 subscriptions', {}, { type: 'grouped' } ],
		[ 'donations', 'Donations', '2 donations', { is_donation: true }, { type: 'grouped' } ],
		[ 'groups', 'Plan bundles', '2 plan bundles', { type: 'grouped' }, { is_donation: true } ],
	] )( 'publishes the %s count once the products land', async ( scope, label, countLabel, shape, outsider ) => {
		apiFetch.mockResolvedValue( {
			products: [
				{ ...product( 1 ), ...shape },
				{ ...product( 2 ), ...shape },
				{ ...product( 3 ), ...outsider },
			],
		} );
		await renderList( scope );

		expect( publishedSection() ).toEqual( { label, count: 2, countLabel } );
	} );

	// Each scope names what it counts, rather than falling back to the generic "N items".
	it( 'announces a single product in the singular, naming the scope', async () => {
		apiFetch.mockResolvedValue( { products: [ { ...product( 1 ), is_donation: true } ] } );
		await renderList( 'donations' );

		expect( publishedSection().countLabel ).toBe( '1 donation' );
	} );

	it( 'publishes no count while the read is in flight', async () => {
		let land;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				land = resolve;
			} )
		);
		render( <SubscriptionProductsList scope="subscriptions" /> );

		expect( publishedSection().label ).toBe( 'Subscriptions' );
		expect( publishedSection().count ).toBeUndefined();

		await act( async () => {
			land( { products: [ product( 1 ) ] } );
		} );

		expect( publishedSection().count ).toBe( 1 );
	} );

	it( 'publishes no count when the read fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await renderList();

		expect( publishedSection().count ).toBeUndefined();
		expect( publishedSection().label ).toBe( 'Subscriptions' );
		expect( document.querySelector( '.components-notice.is-error' ) ).toHaveTextContent( 'Could not load subscription products.' );
		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toBeInTheDocument();
	} );
} );
