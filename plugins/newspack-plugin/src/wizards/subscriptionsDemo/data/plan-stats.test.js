/**
 * Internal dependencies.
 */
import { getAllPlans, getPlanById, getPlanStats, getSubscribersForPlan, getGroupsForPlan } from './plan-stats';
import { SUBSCRIBERS, DIGITAL_PLANS, PRINT_PLANS } from './mock-subscribers';
import { TEAM_PLANS, getAllGroups, seatsUsed } from './mock-groups';
import { getAllDiscounts } from './mock-discounts';

const LIVE_STATUSES = [ 'active', 'on-hold' ];

beforeEach( () => window.localStorage.clear() );

describe( 'getAllPlans', () => {
	it( 'includes every plan from the three family arrays exactly once, with the right family', () => {
		const all = getAllPlans();
		expect( all ).toHaveLength( DIGITAL_PLANS.length + PRINT_PLANS.length + TEAM_PLANS.length );

		DIGITAL_PLANS.forEach( p => {
			const matches = all.filter( e => e.name === p.name );
			expect( matches ).toHaveLength( 1 );
			expect( matches[ 0 ].family ).toBe( 'digital' );
		} );
		PRINT_PLANS.forEach( p => {
			const matches = all.filter( e => e.name === p.name );
			expect( matches ).toHaveLength( 1 );
			expect( matches[ 0 ].family ).toBe( 'print' );
		} );
		TEAM_PLANS.forEach( p => {
			const matches = all.filter( e => e.name === p.name );
			expect( matches ).toHaveLength( 1 );
			expect( matches[ 0 ].family ).toBe( 'team' );
		} );
	} );

	it( 'has unique, slugified ids (lowercase, non-alphanumerics collapsed to a single hyphen, trimmed)', () => {
		const all = getAllPlans();
		const ids = all.map( p => p.id );
		expect( new Set( ids ).size ).toBe( ids.length );
		all.forEach( p => {
			expect( p.id ).toBe( p.id.toLowerCase() );
			expect( p.id ).not.toMatch( /[^a-z0-9-]/ );
			expect( p.id ).not.toMatch( /^-|-$/ );
			expect( p.id ).not.toMatch( /--/ );
		} );
	} );
} );

describe( 'getPlanById', () => {
	it( 'resolves a plan by its slugified id', () => {
		const plan = getAllPlans()[ 0 ];
		expect( getPlanById( plan.id ) ).toEqual( plan );
	} );

	it( 'returns null for an unknown id', () => {
		expect( getPlanById( 'not-a-real-plan' ) ).toBeNull();
	} );
} );

describe( 'getPlanStats for a digital plan', () => {
	const planName = 'Supporter Annual';

	it( 'counts live holders derived from the subscribers fixture, with groups/members null', () => {
		const expectedIndividuals = SUBSCRIBERS.filter( s =>
			( s.subscriptions || [] ).some( sub => sub.plan === planName && LIVE_STATUSES.includes( sub.status ) )
		).length;

		const stats = getPlanStats( planName );
		expect( stats.individuals ).toBe( expectedIndividuals );
		expect( stats.groups ).toBeNull();
		expect( stats.members ).toBeNull();
	} );

	it( 'counts active seeded discount rules targeting the plan', () => {
		const expectedDiscounts = getAllDiscounts().filter( rule => rule.active && rule.audience === planName ).length;
		// Structurally guaranteed by the seed (disc_books and disc_all both target
		// Supporter Annual and are active).
		expect( expectedDiscounts ).toBeGreaterThanOrEqual( 1 );
		expect( getPlanStats( planName ).discounts ).toBe( expectedDiscounts );
	} );
} );

describe( 'getPlanStats for a team plan', () => {
	const planName = 'Team Monthly';

	it( 'returns null individuals and groups/members agreeing with getAllGroups()', () => {
		const liveGroups = getAllGroups().filter( g => g.plan === planName && LIVE_STATUSES.includes( g.status ) );
		const expectedMembers = liveGroups.reduce( ( sum, g ) => sum + seatsUsed( g ), 0 );

		const stats = getPlanStats( planName );
		expect( stats.individuals ).toBeNull();
		expect( stats.groups ).toBe( liveGroups.length );
		expect( stats.members ).toBe( expectedMembers );
	} );
} );

describe( 'getSubscribersForPlan', () => {
	const planName = 'Monthly Digital';

	it( 'returns only live holders, each actually holding the plan, newest-first by that subscription startDate', () => {
		const result = getSubscribersForPlan( planName );
		expect( result.length ).toBeGreaterThan( 0 );

		const latestLiveStart = subscriber => {
			const liveSubs = ( subscriber.subscriptions || [] ).filter( sub => sub.plan === planName && LIVE_STATUSES.includes( sub.status ) );
			expect( liveSubs.length ).toBeGreaterThan( 0 );
			return liveSubs
				.map( sub => sub.startDate )
				.sort()
				.pop();
		};

		const starts = result.map( latestLiveStart );
		const sortedDesc = [ ...starts ].sort( ( a, b ) => b.localeCompare( a ) );
		expect( starts ).toEqual( sortedDesc );

		expect( result ).toHaveLength( getPlanStats( planName ).individuals );
	} );

	it( 'excludes a subscriber whose only subscription on the plan is cancelled', () => {
		// Oscar Rivera (id '4') holds a cancelled Monthly Digital subscription only.
		const oscar = SUBSCRIBERS.find( s => s.id === '4' );
		expect( oscar.subscriptions.some( sub => sub.plan === planName && sub.status === 'cancelled' ) ).toBe( true );
		expect( getSubscribersForPlan( planName ).some( s => s.id === '4' ) ).toBe( false );
	} );
} );

describe( 'getGroupsForPlan', () => {
	it( "returns only that plan's live groups, matching getAllGroups()", () => {
		const planName = 'Team Yearly';
		const expected = getAllGroups().filter( g => g.plan === planName && LIVE_STATUSES.includes( g.status ) );

		const result = getGroupsForPlan( planName );
		result.forEach( g => {
			expect( g.plan ).toBe( planName );
			expect( LIVE_STATUSES ).toContain( g.status );
		} );
		expect( result.map( g => g.id ).sort() ).toEqual( expected.map( g => g.id ).sort() );
	} );

	it( 'excludes a cancelled group on the plan', () => {
		// grp_oldpress (Jane Chen) is a cancelled Team Monthly group.
		const cancelled = getAllGroups().find( g => g.id === 'grp_oldpress' );
		expect( cancelled.status ).toBe( 'cancelled' );
		expect( getGroupsForPlan( 'Team Monthly' ).some( g => g.id === 'grp_oldpress' ) ).toBe( false );
	} );
} );
