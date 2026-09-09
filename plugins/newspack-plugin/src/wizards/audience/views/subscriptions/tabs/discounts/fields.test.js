/**
 * Tests what the Subscriber discounts list searches and filters on.
 */

/**
 * WordPress dependencies.
 */
import { filterSortAndPaginate } from '@wordpress/dataviews';

/**
 * Internal dependencies.
 */
import { DEFAULT_CURRENCY } from './discount';
import { discountFields } from './fields';

// Only the fields' values are under test here, never their rendering, so the
// component library is stubbed rather than pulled in whole.
jest.mock( '../../../../../../../packages/components/src', () => ( {
	StatusIndicator: ( { children } ) => children,
} ) );

const SUBSCRIPTIONS = [
	{ id: 10, name: 'Digital Monthly' },
	{ id: 11, name: 'Print Weekend' },
	// Two products, one name: a catalogue migrated from another plugin routinely
	// has these, and they must not collapse into one filter option.
	{ id: 12, name: 'Founding Member' },
	{ id: 13, name: 'Founding Member' },
];

const rule = ( id, subscriptionIds ) => ( {
	id,
	subscription_product_ids: subscriptionIds,
	targeting: 'all',
	product_ids: [],
	category_ids: [],
	excluded_product_ids: [],
	discount_type: 'percent',
	amount: 20,
	active: true,
	created_at: '2026-01-01',
} );

const RULES = [ rule( 'a', [ 10 ] ), rule( 'b', [ 11 ] ), rule( 'c', [ 12 ] ), rule( 'd', [ 13 ] ) ];

const fields = discountFields( DEFAULT_CURRENCY, SUBSCRIPTIONS );
const view = { type: 'table', page: 1, perPage: 25, sort: { field: 'created_at', direction: 'desc' }, search: '', filters: [], fields: [] };
const idsFor = partialView => filterSortAndPaginate( RULES, { ...view, ...partialView }, fields ).data.map( item => item.id );

describe( 'discountFields', () => {
	// Search matched nothing at all until a field opted in, and an empty result
	// looks the same as no matches, so nothing surfaced the failure.
	it( 'matches a search against subscription names', () => {
		expect( idsFor( { search: 'Digital' } ) ).toEqual( [ 'a' ] );
		expect( idsFor( { search: 'Founding' } ) ).toEqual( [ 'c', 'd' ] );
		expect( idsFor( { search: 'nothing here' } ) ).toEqual( [] );
	} );

	it( 'matches a search against the other columns a publisher can read', () => {
		expect( idsFor( { search: '20%' } ) ).toHaveLength( RULES.length );
		expect( idsFor( { search: 'All products' } ) ).toHaveLength( RULES.length );
	} );

	// Search and filter have to stay on separate fields. Moving the search onto
	// the Subscription field would key its filter on names, merging the two
	// Founding Member products into one option that matches both rules — and the
	// field carrying the search only stays invisible because it opts out of
	// hiding, which is what keeps it from becoming a column.
	it( 'keeps subscriptions sharing a name apart in the filter', () => {
		expect( idsFor( { filters: [ { field: 'subscription', operator: 'isAny', value: [ 12 ] } ] } ) ).toEqual( [ 'c' ] );
		expect( fields.find( field => field.id === 'subscription_names' ).enableHiding ).toBe( false );
	} );
} );
