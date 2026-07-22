/**
 * Internal dependencies.
 */
import { CATEGORIES, getProductById } from './mock-catalog';
import {
	getAllRestrictions,
	getRestrictionById,
	saveRestriction,
	deleteRestriction,
	setRestrictionActive,
	getRestrictionSettings,
	saveRestrictionSettings,
} from './mock-restrictions';
import { coveredParentProductIds, productNamesForRule } from './targeting';
import { ALL_PLANS } from './mock-subscribers';
import { TEAM_PLANS } from './mock-groups';

const PLAN_NAMES = [ ...ALL_PLANS, ...TEAM_PLANS ].map( p => p.name );

beforeEach( () => window.localStorage.clear() );

describe( 'seeded restrictions', () => {
	it( 'reference real plans, products and categories', () => {
		getAllRestrictions().forEach( rule => {
			expect( rule.subscriptions.length ).toBeGreaterThan( 0 );
			rule.subscriptions.forEach( name => expect( PLAN_NAMES ).toContain( name ) );
			( rule.productIds || [] ).forEach( id => expect( getProductById( id ) ).toBeTruthy() );
			( rule.excludedIds || [] ).forEach( id => expect( getProductById( id ) ).toBeTruthy() );
			if ( rule.targeting === 'category' ) {
				expect( CATEGORIES ).toContain( rule.category );
			}
		} );
	} );

	it( 'cover every list state: product list, category, multi-subscription, paused, exclusion', () => {
		const rules = getAllRestrictions();
		expect( rules.some( r => r.targeting === 'products' && r.productIds.length > 1 ) ).toBe( true );
		expect( rules.some( r => r.targeting === 'category' ) ).toBe( true );
		expect( rules.some( r => r.subscriptions.length > 1 ) ).toBe( true );
		expect( rules.some( r => ! r.active ) ).toBe( true );
		expect( rules.some( r => ( r.excludedIds || [] ).length > 0 ) ).toBe( true );
	} );

	it( 'resolves product names for specific-products rules only', () => {
		const listRule = getAllRestrictions().find( r => r.targeting === 'products' && r.productIds.length > 1 );
		const names = productNamesForRule( listRule );
		expect( names ).toHaveLength( listRule.productIds.length );
		names.forEach( name => expect( typeof name ).toBe( 'string' ) );
		const categoryRule = getAllRestrictions().find( r => r.targeting === 'category' );
		expect( productNamesForRule( categoryRule ) ).toEqual( [] );
	} );

	it( 'covers products through category and all targeting, minus exclusions', () => {
		const categoryRule = getAllRestrictions().find( r => r.targeting === 'category' );
		const covered = coveredParentProductIds( categoryRule );
		expect( covered ).toContain( 'prod_course_journalism' );
		expect( covered ).not.toContain( 'prod_course_writing' );
		const all = coveredParentProductIds( { targeting: 'all', productIds: [], excludedIds: [] } );
		expect( all.filter( id => id === 'prod_album' ) ).toHaveLength( 1 );
	} );
} );

describe( 'storage-backed mutations', () => {
	it( 'persists save, toggle and delete via localStorage overrides', () => {
		const rule = saveRestriction( {
			subscriptions: [ ALL_PLANS[ 0 ].name ],
			targeting: 'all',
			productIds: [],
			category: null,
			excludedIds: [],
			active: true,
		} );
		expect( getRestrictionById( rule.id ) ).toBeTruthy();
		setRestrictionActive( rule.id, false );
		expect( getRestrictionById( rule.id ).active ).toBe( false );
		deleteRestriction( rule.id );
		expect( getRestrictionById( rule.id ) ).toBeFalsy();
	} );

	it( 'tombstones deleted seeds so they stay deleted after refresh', () => {
		const seeded = getAllRestrictions()[ 0 ];
		deleteRestriction( seeded.id );
		expect( getAllRestrictions().some( r => r.id === seeded.id ) ).toBe( false );
	} );

	it( 'round-trips settings', () => {
		expect( getRestrictionSettings() ).toEqual( { hideFromCatalog: false } );
		saveRestrictionSettings( { hideFromCatalog: true } );
		expect( getRestrictionSettings() ).toEqual( { hideFromCatalog: true } );
	} );
} );
