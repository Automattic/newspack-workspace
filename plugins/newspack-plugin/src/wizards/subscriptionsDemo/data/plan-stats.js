/**
 * Plan-stats: pure derivations over the existing mock stores.
 *
 * Rolls up the subscribers, groups and discounts stores into per-plan figures
 * for the plan-centric views. No new storage — every number here is computed
 * from the same seeded/overridden data the list screens already read.
 */

/**
 * Internal dependencies.
 */
import { SUBSCRIBERS, DIGITAL_PLANS, PRINT_PLANS } from './mock-subscribers';
import { TEAM_PLANS, getAllGroups, seatsUsed } from './mock-groups';
import { getAllDiscounts } from './mock-discounts';

// A subscription/group still counts toward a plan's live figures while active
// or on-hold; cancelled is churned and drops out.
const LIVE_STATUSES = [ 'active', 'on-hold' ];

function slugify( name ) {
	return String( name )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

function planEntry( plan, family ) {
	return {
		id: slugify( plan.name ),
		name: plan.name,
		family,
		cadence: plan.cadence,
		amount: plan.amount,
		access: plan.access ?? null,
		status: plan.status ?? 'active',
		totalSales: plan.totalSales ?? 0,
		totalRevenue: plan.totalRevenue ?? 0,
	};
}

// Stable base order within each family: monthly before yearly (unknowns last),
// then cheapest-first. The list screen re-sorts by the active column, so this
// is just a sensible default when subscriptions share a sort key.
const CADENCE_RANK = { monthly: 0, yearly: 1, annual: 1 };
const byCadenceThenPrice = ( a, b ) => {
	const ra = CADENCE_RANK[ String( a.cadence ).toLowerCase() ] ?? 2;
	const rb = CADENCE_RANK[ String( b.cadence ).toLowerCase() ] ?? 2;
	return ra !== rb ? ra - rb : ( a.amount || 0 ) - ( b.amount || 0 );
};

export function getAllPlans() {
	return [
		...DIGITAL_PLANS.map( p => planEntry( p, 'digital' ) ).sort( byCadenceThenPrice ),
		...PRINT_PLANS.map( p => planEntry( p, 'print' ) ).sort( byCadenceThenPrice ),
		...TEAM_PLANS.map( p => planEntry( p, 'team' ) ).sort( byCadenceThenPrice ),
	];
}

export function getPlanById( id ) {
	return getAllPlans().find( p => p.id === id ) || null;
}

// Live subscriptions a subscriber holds on the given plan (usually zero or one,
// but a resubscribe can leave more than one row for the same plan name).
function liveSubscriptionsForPlan( subscriber, planName ) {
	return ( subscriber.subscriptions || [] ).filter( sub => sub.plan === planName && LIVE_STATUSES.includes( sub.status ) );
}

// Live groups on the given team plan.
function liveGroupsForPlan( planName ) {
	return getAllGroups().filter( group => group.plan === planName && LIVE_STATUSES.includes( group.status ) );
}

function activeDiscountsForPlan( planName ) {
	return getAllDiscounts().filter( rule => rule.active && rule.audience === planName ).length;
}

/**
 * Live individual holders of a digital/print plan, newest-first by that
 * subscription's startDate (the latest, if a subscriber somehow carries more
 * than one live row for the same plan).
 *
 * @param {string} planName Plan name.
 * @return {Object[]} Subscriber records.
 */
export function getSubscribersForPlan( planName ) {
	return SUBSCRIBERS.map( subscriber => {
		const liveSubs = liveSubscriptionsForPlan( subscriber, planName );
		if ( ! liveSubs.length ) {
			return null;
		}
		const latestStart = liveSubs
			.map( sub => sub.startDate )
			.sort()
			.pop();
		return { subscriber, latestStart };
	} )
		.filter( Boolean )
		.sort( ( a, b ) => b.latestStart.localeCompare( a.latestStart ) )
		.map( entry => entry.subscriber );
}

/**
 * Live groups on a team plan.
 *
 * @param {string} planName Plan name.
 * @return {Object[]} Group records.
 */
export function getGroupsForPlan( planName ) {
	return liveGroupsForPlan( planName );
}

/**
 * Roll-up stats for a single plan by name.
 *
 * @param {string} planName Plan name.
 * @return {{individuals: (number|null), groups: (number|null), members: (number|null), discounts: number}} Stats.
 */
export function getPlanStats( planName ) {
	const isTeamPlan = TEAM_PLANS.some( p => p.name === planName );

	if ( isTeamPlan ) {
		const groups = liveGroupsForPlan( planName );
		return {
			individuals: null,
			groups: groups.length,
			members: groups.reduce( ( sum, group ) => sum + seatsUsed( group ), 0 ),
			discounts: activeDiscountsForPlan( planName ),
		};
	}

	return {
		individuals: getSubscribersForPlan( planName ).length,
		groups: null,
		members: null,
		discounts: activeDiscountsForPlan( planName ),
	};
}
