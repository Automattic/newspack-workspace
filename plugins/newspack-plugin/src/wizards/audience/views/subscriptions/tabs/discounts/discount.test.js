/**
 * Tests the Subscriber discounts tab's pure helpers.
 */

/**
 * Internal dependencies.
 */
import { discountLabel, formatCurrency, isValidRule, subscriberPrice, subscriptionsSummary, targetingLabel } from './discount';

const GBP = {
	code: 'GBP',
	symbol: '£',
	decimals: 2,
	decimal_separator: '.',
	thousand_separator: ',',
	position: 'left',
};

describe( 'formatCurrency', () => {
	it( 'groups thousands and places the symbol as the store does', () => {
		expect( formatCurrency( 1450.5, GBP ) ).toBe( '£1,450.50' );
		expect( formatCurrency( 1450.5, { ...GBP, position: 'right_space' } ) ).toBe( '1,450.50 £' );
	} );

	it( 'honours a currency with no decimals', () => {
		expect( formatCurrency( 1450, { ...GBP, decimals: 0 } ) ).toBe( '£1,450' );
	} );
} );

describe( 'subscriberPrice', () => {
	// The preview is what a publisher tunes a fixed amount against, so these
	// must match the server's arithmetic exactly.
	it( 'takes a percentage off', () => {
		expect( subscriberPrice( 520, { discount_type: 'percent', amount: 15 } ) ).toBe( 442 );
	} );

	it( 'takes a fixed amount off', () => {
		expect( subscriberPrice( 1450, { discount_type: 'fixed', amount: 151 } ) ).toBe( 1299 );
	} );

	it( 'rounds half down to the currency precision', () => {
		expect( subscriberPrice( 9.99, { discount_type: 'percent', amount: 10 } ) ).toBe( 8.99 );
	} );

	it( 'floors at zero rather than going negative', () => {
		expect( subscriberPrice( 5, { discount_type: 'fixed', amount: 20 } ) ).toBe( 0 );
	} );

	it( 'reports no discount when the price cannot drop', () => {
		expect( subscriberPrice( 0, { discount_type: 'fixed', amount: 5 } ) ).toBeNull();
		expect( subscriberPrice( 10, { discount_type: 'fixed', amount: 0 } ) ).toBeNull();
	} );
} );

describe( 'discountLabel', () => {
	it( 'shows a percentage as a percentage and an amount as money', () => {
		expect( discountLabel( { discount_type: 'percent', amount: 15 }, GBP ) ).toBe( '15%' );
		expect( discountLabel( { discount_type: 'fixed', amount: 51 }, GBP ) ).toBe( '£51.00' );
	} );
} );

describe( 'subscriptionsSummary', () => {
	const options = [
		{ id: 10, name: 'Digital Monthly' },
		{ id: 11, name: 'Print &amp; Digital' },
	];

	it( 'names the subscriptions, decoded, in rule order', () => {
		expect( subscriptionsSummary( [ 10 ], options ) ).toEqual( { named: 'Digital Monthly', more: '' } );
		expect( subscriptionsSummary( [ 11, 10 ], options ) ).toEqual( { named: 'Print & Digital, Digital Monthly', more: '' } );
	} );

	it( 'falls back to a count when no name is known', () => {
		expect( subscriptionsSummary( [ 99 ], options ) ).toEqual( { named: '1 subscription', more: '' } );
		expect( subscriptionsSummary( [ 98, 99 ], [] ) ).toEqual( { named: '2 subscriptions', more: '' } );
	} );

	it( 'counts the ids it cannot name alongside the ones it can', () => {
		expect( subscriptionsSummary( [ 10, 98, 99 ], options ) ).toEqual( { named: 'Digital Monthly', more: '+2 more' } );
	} );

	it( 'names at most two subscriptions, however many the rule covers', () => {
		const many = Array.from( { length: 49 }, ( _, index ) => ( { id: index + 1, name: `Subscription ${ index + 1 }` } ) );
		expect(
			subscriptionsSummary(
				many.map( option => option.id ),
				many
			)
		).toEqual( { named: 'Subscription 1, Subscription 2', more: '+47 more' } );
	} );

	// The count is rendered in its own element, so it has to be one number
	// covering both causes rather than the sum of two separate claims.
	it( 'reports one count for the names it withheld and the ids it could not resolve, never two', () => {
		const known = [
			{ id: 1, name: 'Digital Monthly' },
			{ id: 2, name: 'Digital Annual' },
			{ id: 3, name: 'Print Weekend' },
			{ id: 4, name: 'Print Daily' },
		];
		expect( subscriptionsSummary( [ 1, 2, 3, 4, 98, 99 ], known ) ).toEqual( { named: 'Digital Monthly, Digital Annual', more: '+4 more' } );
	} );
} );

describe( 'targetingLabel', () => {
	const rule = { targeting: 'products', product_ids: [ 1, 2 ], category_ids: [], excluded_product_ids: [] };

	it( 'counts what the rule covers', () => {
		expect( targetingLabel( rule ) ).toBe( '2 products' );
		expect( targetingLabel( { ...rule, targeting: 'category', product_ids: [], category_ids: [ 9 ] } ) ).toBe( '1 category' );
		expect( targetingLabel( { ...rule, targeting: 'all', product_ids: [] } ) ).toBe( 'All products' );
	} );

	it( 'mentions exclusions only where they can apply', () => {
		expect( targetingLabel( { ...rule, targeting: 'all', product_ids: [], excluded_product_ids: [ 7 ] } ) ).toBe( 'All products · 1 excluded' );
		// A hand-picked list is its own exclusion, so a stale exclusion left on
		// such a rule must not be advertised.
		expect( targetingLabel( { ...rule, excluded_product_ids: [ 7 ] } ) ).toBe( '2 products' );
	} );
} );

describe( 'isValidRule', () => {
	const valid = {
		subscription_product_ids: [ 10 ],
		targeting: 'products',
		product_ids: [ 200 ],
		category_ids: [],
		discount_type: 'fixed',
		amount: 5,
	};

	it( 'accepts a complete rule', () => {
		expect( isValidRule( valid ) ).toBe( true );
	} );

	it( 'rejects what the server would reject, so Save is disabled instead of erroring', () => {
		expect( isValidRule( { ...valid, subscription_product_ids: [] } ) ).toBe( false );
		expect( isValidRule( { ...valid, amount: 0 } ) ).toBe( false );
		expect( isValidRule( { ...valid, discount_type: 'percent', amount: 101 } ) ).toBe( false );
		expect( isValidRule( { ...valid, product_ids: [] } ) ).toBe( false );
		expect( isValidRule( { ...valid, targeting: 'category' } ) ).toBe( false );
	} );

	it( 'accepts an all-products rule with no selection', () => {
		expect( isValidRule( { ...valid, targeting: 'all', product_ids: [] } ) ).toBe( true );
	} );
} );
