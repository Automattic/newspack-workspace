/**
 * Internal dependencies.
 */
import { CATEGORIES, PRODUCTS, getProductById } from './mock-catalog';
import {
	getAllDiscounts,
	getDiscountById,
	saveDiscount,
	deleteDiscount,
	setDiscountActive,
	getDiscountSettings,
	saveDiscountSettings,
	subscriberPrice,
	discountLabel,
	targetingLabel,
	productsForRule,
	benefitsForPlans,
} from './mock-discounts';
import { ALL_PLANS } from './mock-subscribers';
import { TEAM_PLANS } from './mock-groups';

const PLAN_NAMES = [ ...ALL_PLANS, ...TEAM_PLANS ].map( p => p.name );

beforeEach( () => window.localStorage.clear() );

describe( 'mock catalog', () => {
	it( 'has valid categories and at least one variable product', () => {
		PRODUCTS.forEach( p => expect( CATEGORIES ).toContain( p.category ) );
		expect( PRODUCTS.some( p => ( p.variations || [] ).length > 1 ) ).toBe( true );
	} );
} );

describe( 'seeded discount rules', () => {
	it( 'reference real plans, products and categories', () => {
		getAllDiscounts().forEach( rule => {
			expect( PLAN_NAMES ).toContain( rule.audience );
			( rule.productIds || [] ).forEach( id => expect( getProductById( id ) ).toBeTruthy() );
			( rule.excludedIds || [] ).forEach( id => expect( getProductById( id ) ).toBeTruthy() );
			if ( rule.targeting === 'category' ) {
				expect( CATEGORIES ).toContain( rule.category );
			}
		} );
	} );

	it( 'cover every list state: yucatan-style, category, all-store, paused, exclusion', () => {
		const rules = getAllDiscounts();
		expect( rules.some( r => r.type === 'fixed' && r.targeting === 'products' && r.productIds.length >= 5 ) ).toBe( true );
		expect( rules.some( r => r.targeting === 'category' ) ).toBe( true );
		expect( rules.some( r => r.targeting === 'all' ) ).toBe( true );
		expect( rules.some( r => ! r.active ) ).toBe( true );
		expect( rules.some( r => ( r.excludedIds || [] ).length > 0 ) ).toBe( true );
	} );
} );

describe( 'pricing helpers', () => {
	it( 'computes fixed and percent subscriber prices, never negative', () => {
		expect( subscriberPrice( 450, { type: 'fixed', amount: 51 } ) ).toBe( 399 );
		expect( subscriberPrice( 100, { type: 'percent', amount: 10 } ) ).toBe( 90 );
		expect( subscriberPrice( 30, { type: 'fixed', amount: 51 } ) ).toBe( 0 );
	} );

	it( 'expands variations and removes exclusions in productsForRule', () => {
		const variable = PRODUCTS.find( p => ( p.variations || [] ).length > 1 );
		const all = productsForRule( { targeting: 'all', excludedIds: [], productIds: [] } );
		expect( all.some( e => e.id === variable.variations[ 0 ].id ) ).toBe( true );
		expect( all.some( e => e.id === variable.id ) ).toBe( false );
		const excluded = productsForRule( { targeting: 'all', excludedIds: [ PRODUCTS[ 0 ].id ], productIds: [] } );
		expect( excluded.some( e => e.id === PRODUCTS[ 0 ].id ) ).toBe( false );
	} );

	it( 'labels discounts and targeting', () => {
		expect( discountLabel( { type: 'percent', amount: 10 } ) ).toBe( '10%' );
		expect( targetingLabel( { targeting: 'all', excludedIds: [], productIds: [] } ) ).toBe( 'All products' );
		expect( targetingLabel( { targeting: 'products', productIds: [ 'a', 'b' ], excludedIds: [] } ) ).toBe( '2 products' );
	} );
} );

describe( 'storage-backed mutations', () => {
	it( 'persists save, toggle and delete via localStorage overrides', () => {
		const rule = saveDiscount( {
			audience: ALL_PLANS[ 0 ].name,
			type: 'fixed',
			amount: 5,
			targeting: 'all',
			productIds: [],
			category: null,
			excludedIds: [],
			active: true,
		} );
		expect( getDiscountById( rule.id ) ).toBeTruthy();
		setDiscountActive( rule.id, false );
		expect( getDiscountById( rule.id ).active ).toBe( false );
		deleteDiscount( rule.id );
		expect( getDiscountById( rule.id ) ).toBeFalsy();
	} );

	it( 'round-trips settings', () => {
		expect( getDiscountSettings() ).toEqual( { applyOnSale: false, overlap: 'best' } );
		saveDiscountSettings( { applyOnSale: true, overlap: 'combine' } );
		expect( getDiscountSettings() ).toEqual( { applyOnSale: true, overlap: 'combine' } );
	} );
} );

describe( 'benefits', () => {
	it( 'returns only active rules matching the given plan names', () => {
		const teamRule = getAllDiscounts().find( r => TEAM_PLANS.some( p => p.name === r.audience ) );
		expect( teamRule ).toBeTruthy();
		const benefits = benefitsForPlans( [ teamRule.audience ] );
		expect( benefits ).toContainEqual( expect.objectContaining( { id: teamRule.id } ) );
		benefits.forEach( r => expect( r.active ).toBe( true ) );
	} );
} );
