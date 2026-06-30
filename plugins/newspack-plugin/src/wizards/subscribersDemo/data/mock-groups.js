/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise, no-nested-ternary */
/**
 * Mock group/team subscription data for the Subscribers Demo wizard.
 *
 * The admin-facing counterpart to the owner-facing My Account group flows.
 * Covers every state the UI needs to render:
 *   - Active with open seats (happy path, owner is a fixture subscriber)
 *   - Active and full (no seats left)
 *   - Multi-group owner (one subscriber owns two groups)
 *   - Pending email invite + active invite link
 *   - On-hold (cleanup only, invites suppressed)
 *   - Cancelled (read-only)
 *
 * Plus a handful of seeded pseudo-random extras so the Groups DataViews list
 * has enough to filter, sort and paginate through. Members and owners are drawn
 * from the existing SUBSCRIBERS array so profile <-> group cross-links resolve.
 * Group subscriptions are uncommon, so the bulk of subscribers belong to no
 * group at all.
 */

import { SUBSCRIBERS, getSubscriberById, getSubscriberByEmail, DIGITAL_PLANS, PRINT_PLANS, plusCadenceIso } from './mock-subscribers';
import { STORAGE_PREFIX, readStore, writeStore } from './storage';

// Each group product carries its own seat cap, so changing a group's plan
// changes its capacity (and a downgrade below the current member count is
// blocked in the plan-change flow).
export const TEAM_PLANS = [
	{ name: 'Team Monthly', cadence: 'Monthly', amount: 99, seats: 5 },
	{ name: 'Team Yearly', cadence: 'Yearly', amount: 1000, seats: 10 },
	{ name: 'Education Annual', cadence: 'Yearly', amount: 2500, seats: 25 },
];

// Plans an item can switch to, staying within its own product family: a group
// changes among team products, an individual stays within digital or print.
// Empty when there's no alternative, so callers can hide the change action.
export function getPlanOptions( item, isGroup ) {
	let pool;
	if ( isGroup ) {
		pool = TEAM_PLANS;
	} else {
		pool = DIGITAL_PLANS.some( p => p.name === item.plan ) ? DIGITAL_PLANS : PRINT_PLANS;
	}
	return pool.filter( p => p.name !== item.plan );
}

export const GROUP_STATUS_LABELS = {
	active: 'Active',
	'on-hold': 'On hold',
	cancelled: 'Cancelled',
};

export const GROUP_STATUS_BADGE_LEVEL = {
	active: 'success',
	'on-hold': 'warning',
	cancelled: 'error',
};

// Tiny deterministic PRNG (distinct seed from mock-subscribers) so the list is
// stable between reloads.
function mulberry32( seed ) {
	return function () {
		let t = ( seed += 0x6d2b79f5 );
		t = Math.imul( t ^ ( t >>> 15 ), t | 1 );
		t ^= t + Math.imul( t ^ ( t >>> 7 ), t | 61 );
		return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
	};
}
const rand = mulberry32( 99 );
const pick = arr => arr[ Math.floor( rand() * arr.length ) ];

function iso( daysAgo ) {
	const d = new Date();
	d.setDate( d.getDate() - daysAgo );
	return d.toISOString().slice( 0, 10 );
}

function futureIso( daysAhead ) {
	const d = new Date();
	d.setDate( d.getDate() + daysAhead );
	return d.toISOString().slice( 0, 10 );
}

const ALL_SUBSCRIBER_IDS = SUBSCRIBERS.map( s => s.id );

function member( subscriberId, joinedAt, role = 'member' ) {
	return { subscriberId, joinedAt, role };
}

// Realistic membership density: a subscriber belongs to exactly one group.
// Belonging to multiple is extremely rare in practice — the only such case is
// the curated multi-group owner below (Priya), who is added as an owner
// directly and so isn't subject to this cap. Track per-subscriber counts during
// generation and skip anyone already in a group.
const MAX_MEMBERSHIPS = 1;
const membershipCount = {};
const canJoin = id => ( membershipCount[ id ] || 0 ) < MAX_MEMBERSHIPS;
const reserve = id => {
	membershipCount[ id ] = ( membershipCount[ id ] || 0 ) + 1;
};

// Curated fixture subscribers, excluded from every group's random membership so
// their hand-authored scenarios stay intact. Owners (1-5) appear only in the
// groups they own; Liam (6) keeps his two same-plan individual subscriptions,
// which the covered-member rule below would otherwise clear if he were enrolled.
const CURATED_OWNER_IDS = new Set( [ '1', '2', '3', '4', '5', '6' ] );

// Yuki Okafor is a curated "two active subscriptions" example, so keep her out of
// random group membership — joining an on-hold group would otherwise show her as
// part-on-hold instead of cleanly active.
const yukiOkafor = SUBSCRIBERS.find( s => s.email === 'yuki.okafor65@example.com' );
if ( yukiOkafor ) {
	CURATED_OWNER_IDS.add( yukiOkafor.id );
}

/**
 * Build a members array: the owner first, then up to `count - 1` other
 * subscribers who aren't already at their membership cap.
 */
function buildMembers( ownerId, count ) {
	reserve( ownerId );
	const members = [ member( ownerId, iso( 400 ), 'owner' ) ];
	const taken = new Set( [ ownerId ] );
	let guard = 0;
	while ( members.length < count && guard < 800 ) {
		guard++;
		const candidate = pick( ALL_SUBSCRIBER_IDS );
		if ( taken.has( candidate ) || ! canJoin( candidate ) || CURATED_OWNER_IDS.has( candidate ) ) {
			continue;
		}
		taken.add( candidate );
		reserve( candidate );
		members.push( member( candidate, iso( Math.floor( rand() * 300 ) + 5 ) ) );
	}
	return members;
}

// Pick a group owner who isn't already at their membership cap.
function pickOwner() {
	let candidate = pick( ALL_SUBSCRIBER_IDS );
	let guard = 0;
	while ( ( ! canJoin( candidate ) || CURATED_OWNER_IDS.has( candidate ) ) && guard < 200 ) {
		guard++;
		candidate = pick( ALL_SUBSCRIBER_IDS );
	}
	return candidate;
}

// Curated fixtures, cross-linked to existing fixture subscribers.
const FIXTURES = [
	{
		id: 'grp_acme',
		ownerId: '1', // Matt Moore
		plan: 'Team Yearly',
		cadence: 'Yearly',
		amount: 1000,
		status: 'active',
		seatLimit: 10,
		createdAt: '2022-10-01',
		members: buildMembers( '1', 5 ),
		invites: [
			{
				id: 'inv_acme_1',
				type: 'email',
				email: 'sofia.rossi@example.com',
				status: 'pending',
				sentAt: iso( 4 ),
			},
			{
				id: 'inv_acme_2',
				type: 'link',
				status: 'active',
				createdAt: iso( 12 ),
			},
		],
	},
	{
		id: 'grp_riverside',
		ownerId: '3', // Priya Patel (multi-group owner)
		plan: 'Education Annual',
		cadence: 'Yearly',
		amount: 2500,
		status: 'active',
		seatLimit: 25,
		createdAt: '2023-08-20',
		members: buildMembers( '3', 6 ),
		invites: [
			{
				id: 'inv_river_1',
				type: 'email',
				email: 'newteacher@example.com',
				status: 'pending',
				sentAt: iso( 2 ),
			},
			{
				id: 'inv_river_2',
				type: 'email',
				email: 'lapsed.coach@example.com',
				status: 'pending',
				sentAt: iso( 45 ),
			},
		],
	},
	{
		id: 'grp_patel',
		ownerId: '3', // Priya Patel's second group
		plan: 'Team Monthly',
		cadence: 'Monthly',
		amount: 99,
		status: 'active',
		seatLimit: 5,
		createdAt: '2024-01-15',
		members: buildMembers( '3', 3 ),
		invites: [],
	},
	{
		id: 'grp_bauer',
		ownerId: '5', // Aisha Khan — full group, no seats left
		plan: 'Team Monthly',
		cadence: 'Monthly',
		amount: 99,
		status: 'active',
		seatLimit: 5,
		createdAt: '2023-11-30',
		members: buildMembers( '5', 5 ),
		invites: [],
	},
	{
		id: 'grp_northside',
		ownerId: '4', // Oscar Rivera — on hold
		plan: 'Team Yearly',
		cadence: 'Yearly',
		amount: 1000,
		status: 'on-hold',
		seatLimit: 10,
		createdAt: '2021-07-12',
		members: buildMembers( '4', 3 ),
		invites: [
			{
				id: 'inv_north_1',
				type: 'email',
				email: 'stale.invite@example.com',
				status: 'pending',
				sentAt: iso( 90 ),
			},
		],
	},
	{
		id: 'grp_oldpress',
		ownerId: '2', // Jane Chen — cancelled
		plan: 'Team Monthly',
		cadence: 'Monthly',
		amount: 99,
		status: 'cancelled',
		seatLimit: 5,
		createdAt: '2020-03-08',
		members: buildMembers( '2', 2 ),
		invites: [],
	},
];

function makeRandom( i ) {
	const ownerId = pickOwner();
	const plan = pick( TEAM_PLANS );
	const roll = rand();
	const status = roll < 0.75 ? 'active' : roll < 0.9 ? 'on-hold' : 'cancelled';
	// Capacity is a property of the product, so the seat cap comes from the plan.
	const seatLimit = plan.seats;
	// Small groups so the bulk of subscribers stay ungrouped individuals.
	const memberCount = Math.min( seatLimit - 1, Math.floor( rand() * 3 ) + 1 );
	const members = buildMembers( ownerId, memberCount );
	// Email invites and the invite link are independent: a group may have a
	// pending email invite, an active link, both, or neither. Email invites count
	// against capacity; the link reserves nothing.
	const invites = [];
	if ( status === 'active' && rand() < 0.4 ) {
		invites.push( {
			id: `inv_r${ i }`,
			type: 'email',
			email: `pending${ i }@example.com`,
			status: 'pending',
			sentAt: iso( Math.floor( rand() * 30 ) + 1 ),
		} );
	}
	if ( status === 'active' && rand() < 0.3 ) {
		invites.push( {
			id: `inv_r${ i }_link`,
			type: 'link',
			status: 'active',
			createdAt: iso( Math.floor( rand() * 60 ) + 1 ),
		} );
	}
	return {
		id: `grp_${ 200 + i }`,
		ownerId,
		plan: plan.name,
		cadence: plan.cadence,
		amount: plan.amount,
		status,
		seatLimit,
		createdAt: iso( Math.floor( rand() * 1500 ) + 30 ),
		members,
		invites,
	};
}

const EXTRAS = Array.from( { length: 8 }, ( _, i ) => makeRandom( i ) );

export const GROUPS = [ ...FIXTURES, ...EXTRAS ];

// Active groups are recurring subscriptions, so give each a future next-billing
// date the profile can surface like an individual subscription. On-hold and
// cancelled groups have no upcoming charge.
GROUPS.forEach( group => {
	group.nextBillingDate = group.status === 'active' ? futureIso( Math.floor( rand() * 30 ) + 1 ) : null;
} );

// Curated cross-membership: Ines Rivera already belongs to a Team Yearly cohort,
// so add her to Yuki Okafor's Education Annual too — a subscriber in two cohorts,
// exercising the multi-cohort case in the list. Added explicitly so it isn't
// subject to the one-group membership cap above.
( () => {
	const ines = SUBSCRIBERS.find( s => s.email === 'ines.rivera67@example.com' );
	const eduGroup = GROUPS.find( g => g.plan === 'Education Annual' && getSubscriberById( g.ownerId )?.name === 'Yuki Okafor' );
	if ( ines && eduGroup && ! eduGroup.members.some( m => m.subscriberId === ines.id ) ) {
		eduGroup.members.push( member( ines.id, iso( 30 ) ) );
	}
} )();

// Members benefit from the owner's plan (per PR #148): they have no individual
// subscription, payment method, or orders of their own. Clear those on the
// shared subscriber records so the L0 list and the profile agree. Owners keep
// their own billing data — they're the ones paying for the group.
( () => {
	const ownerIds = new Set( GROUPS.map( g => g.ownerId ) );
	const coveredMemberIds = new Set();
	GROUPS.forEach( group => {
		if ( group.status !== 'active' ) {
			return;
		}
		( group.members || [] ).forEach( m => {
			if ( m.subscriberId !== group.ownerId && ! ownerIds.has( m.subscriberId ) ) {
				coveredMemberIds.add( m.subscriberId );
			}
		} );
	} );
	coveredMemberIds.forEach( id => {
		const sub = SUBSCRIBERS.find( s => s.id === id );
		if ( ! sub ) {
			return;
		}
		sub.subscriptions = [];
		sub.paymentMethods = [];
		sub.orders = [];
		sub.lastPayment = null;
		sub.status = 'active';
	} );
} )();

// A group is a subscription the owner pays for, so its payments belong in the
// owner's order history alongside any individual subscriptions. Generate a small
// per-group payment history keyed to the group via subscriptionId, unless the
// owner already carries hand-authored orders for it (e.g. Matt's Team Yearly).
( () => {
	GROUPS.forEach( group => {
		const owner = getSubscriberById( group.ownerId );
		if ( ! owner ) {
			return;
		}
		owner.orders = owner.orders || [];
		if ( owner.orders.some( o => o.subscriptionId === group.id ) ) {
			return;
		}
		const ageDays = Math.max( 1, Math.round( ( Date.now() - new Date( group.createdAt ).getTime() ) / 86400000 ) );
		const interval = group.cadence === 'Monthly' ? 30 : 365;
		const rows = [];
		// Lead transaction reflects the group's current state.
		if ( group.status === 'cancelled' ) {
			rows.push( { tag: 'c', daysAgo: Math.min( ageDays, Math.floor( rand() * 90 ) + 10 ), amount: 0, type: 'Cancellation' } );
		} else if ( group.status === 'on-hold' ) {
			rows.push( { tag: 'h', daysAgo: Math.min( ageDays, Math.floor( rand() * 30 ) + 5 ), amount: 0, type: 'Failed renewal' } );
		}
		// One subscription payment per billing cycle back to creation, capped.
		const firstOffset = Math.min( ageDays, group.status === 'active' ? Math.floor( rand() * 20 ) + 1 : interval );
		for ( let d = firstOffset, n = 0; d <= ageDays && n < 4; d += interval, n++ ) {
			rows.push( { tag: 'p' + n, daysAgo: d, amount: group.amount, type: 'Subscription payment' } );
		}
		rows.forEach( r => {
			owner.orders.push( {
				id: `ord_${ group.id }_${ r.tag }`,
				date: iso( r.daysAgo ),
				amount: r.amount,
				type: r.type,
				subscriptionId: group.id,
			} );
		} );
	} );
} )();

// Same alignment as individual subscriptions: an active group's next billing is
// one cadence after the owner's most recent group payment.
GROUPS.forEach( group => {
	if ( group.status !== 'active' ) {
		return;
	}
	const owner = getSubscriberById( group.ownerId );
	const lastPayment = ( owner?.orders || [] )
		.filter( order => order.subscriptionId === group.id && order.type === 'Subscription payment' )
		.map( order => order.date )
		.sort()
		.pop();
	if ( lastPayment ) {
		group.nextBillingDate = plusCadenceIso( lastPayment, group.cadence );
	}
} );

// On-hold groups follow the same cadence rule: the failed renewal in the owner's
// history sits one cadence after the group's last successful payment, as long as
// that still lands in the past (a younger group keeps its seeded recent failure).
( () => {
	const today = iso( 0 );
	GROUPS.forEach( group => {
		if ( group.status !== 'on-hold' ) {
			return;
		}
		const owner = getSubscriberById( group.ownerId );
		if ( ! owner ) {
			return;
		}
		const lastPayment = ( owner.orders || [] )
			.filter( order => order.subscriptionId === group.id && order.type === 'Subscription payment' )
			.map( order => order.date )
			.sort()
			.pop();
		const failed = ( owner.orders || [] ).find( order => order.subscriptionId === group.id && order.type === 'Failed renewal' );
		if ( lastPayment && failed ) {
			const aligned = plusCadenceIso( lastPayment, group.cadence );
			if ( aligned <= today ) {
				failed.date = aligned;
			}
		}
	} );
} )();

export const ALL_GROUP_PLAN_NAMES = [ ...new Set( GROUPS.map( g => g.plan ) ) ];

// Seat helpers.
export function seatsUsed( group ) {
	return ( group.members || [] ).length;
}

export function seatsAvailable( group ) {
	return Math.max( 0, group.seatLimit - seatsUsed( group ) );
}

export function isGroupFull( group ) {
	return seatsAvailable( group ) <= 0;
}

// Seats reserved by members plus outstanding obligations (pending email
// invites). The invite link reserves nothing — it's a single reusable link
// bounded by the seat limit only at the moment someone joins. Inviting and
// seat-limit changes are gated on this so the limit can't be undercut by
// obligations already made.
export function reservedSeats( group ) {
	const invites = group.invites || [];
	const pendingEmails = invites.filter( inv => inv.type === 'email' && inv.status === 'pending' ).length;
	return seatsUsed( group ) + pendingEmails;
}

// Email invites expire after 30 days, mirroring the owner-facing flow. The mock
// data carries only sentAt, so derive expiry from it.
const INVITE_EXPIRY_DAYS = 30;
export function isInviteExpired( invite ) {
	if ( ! invite || invite.type !== 'email' || ! invite.sentAt ) {
		return false;
	}
	const sentAt = new Date( invite.sentAt ).getTime();
	return Date.now() > sentAt + INVITE_EXPIRY_DAYS * 24 * 60 * 60 * 1000;
}

// A group has one persistent reusable invite link per owner. The prototype has a
// single owner per group, so an active link entry stands in for it.
export function hasActiveInviteLink( group ) {
	return ( group.invites || [] ).some( inv => inv.type === 'link' && inv.status === 'active' );
}

// Capacity available for new invites (limit minus everything already reserved).
export function inviteCapacity( group ) {
	return Math.max( 0, group.seatLimit - reservedSeats( group ) );
}

// A group is manageable (cleanup allowed) unless cancelled; invites/seat
// increases require an active group. Mirrors the owner-facing logic from #148.
export function isGroupActive( group ) {
	return group.status === 'active';
}

export function isGroupManageable( group ) {
	return group.status !== 'cancelled';
}

// PROTOTYPE ONLY: group mutations are persisted to the current admin's
// localStorage so they survive a refresh during a demo. The seeded GROUPS array
// stays immutable; reads layer the stored override on top. In production this
// needs to live server-side (REST + WooCommerce subscription meta) so it's
// shared across every admin viewing the same group.
const GROUPS_STORAGE_KEY = STORAGE_PREFIX + 'groups';

// Returns the stored (mutated) group if an override exists, else null.
export function getStoredGroup( id ) {
	const store = readStore( GROUPS_STORAGE_KEY );
	return Object.prototype.hasOwnProperty.call( store, id ) ? store[ id ] : null;
}

export function setStoredGroup( id, group ) {
	const store = readStore( GROUPS_STORAGE_KEY );
	store[ id ] = group;
	writeStore( GROUPS_STORAGE_KEY, store );
}

// Resolve a group by id, applying any stored override.
export function getGroupById( id ) {
	const stored = getStoredGroup( id );
	if ( stored ) {
		return stored;
	}
	return GROUPS.find( g => g.id === id ) || null;
}

// All groups with stored overrides applied — drives the Groups list. Reads the
// override store once (not once per group) so the localStorage parse doesn't
// fan out across the whole list. Also surfaces runtime-created groups that exist
// only in the store (not in the seeded GROUPS array).
export function getAllGroups() {
	const store = readStore( GROUPS_STORAGE_KEY );
	const seeded = GROUPS.map( g => ( Object.prototype.hasOwnProperty.call( store, g.id ) ? store[ g.id ] : g ) );
	const seededIds = new Set( GROUPS.map( g => g.id ) );
	const created = Object.keys( store )
		.filter( id => ! seededIds.has( id ) )
		.map( id => store[ id ] );
	return [ ...seeded, ...created ];
}

// Build a new group record owned by `ownerId` from a team plan. Persistence is
// the caller's job (setStoredGroup), matching how the rest of the seam works.
export function createGroup( { ownerId, plan, cadence, amount, seatLimit } ) {
	const createdAt = new Date().toISOString().slice( 0, 10 );
	return {
		id: 'grp_new_' + Date.now(),
		ownerId,
		plan,
		cadence,
		amount,
		status: 'active',
		seatLimit,
		createdAt,
		nextBillingDate: new Date( Date.now() + 30 * 86400000 ).toISOString().slice( 0, 10 ),
		members: [ { subscriberId: ownerId, joinedAt: createdAt, role: 'owner' } ],
		invites: [],
	};
}

/**
 * Groups a subscriber owns and/or belongs to, with the relationship flagged.
 * Returns [ { group, isOwner, isMember } ].
 */
export function getGroupsForSubscriber( subscriberId ) {
	return getAllGroups()
		.map( group => {
			const isOwner = group.ownerId === subscriberId;
			const isMember = ( group.members || [] ).some( m => m.subscriberId === subscriberId );
			return { group, isOwner, isMember };
		} )
		.filter( entry => entry.isOwner || entry.isMember );
}

// Subscribers eligible to be invited to a group (not already members).
export function getInvitableSubscribers( group ) {
	const memberIds = new Set( ( group.members || [] ).map( m => m.subscriberId ) );
	return SUBSCRIBERS.filter( s => ! memberIds.has( s.id ) );
}

// Convenience: resolve a member entry to its subscriber record.
export function getMemberSubscriber( subscriberId ) {
	return getSubscriberById( subscriberId );
}

// Build a member entry for an email. An existing account resolves by email; an
// unknown email mints a stub account carried on the entry itself — the seeded
// SUBSCRIBERS array stays immutable at runtime, so the row renders from these
// fields and falls back to a default avatar.
let stubMemberCount = 0;
function memberFromEmail( email, joinedAt ) {
	const existing = getSubscriberByEmail( email );
	if ( existing ) {
		return member( existing.id, joinedAt );
	}
	stubMemberCount += 1;
	const localPart = String( email ).split( '@' )[ 0 ];
	const name = localPart.replace( /[._-]+/g, ' ' ).replace( /\b\w/g, c => c.toUpperCase() );
	return {
		subscriberId: `new_${ Date.now() }_${ stubMemberCount }`,
		email,
		name,
		joinedAt,
		role: 'member',
		createdAccount: true,
	};
}

// Add members to a group by email (admin superpower beyond owner parity, #148).
// Skips anyone already a member; drops a pending email invite when its address is
// being added (the seat it reserved becomes the membership, so capacity is
// unchanged). Additive — never touches a person's individual subscription.
export function addMembersByEmail( group, emails, joinedAt ) {
	const memberEmails = new Set(
		( group.members || [] ).map( m => ( getSubscriberById( m.subscriberId )?.email || m.email || '' ).toLowerCase() ).filter( Boolean )
	);
	const clean = [ ...new Set( ( emails || [] ).map( e => String( e ).trim() ).filter( Boolean ) ) ].filter(
		e => ! memberEmails.has( e.toLowerCase() )
	);
	const newMembers = clean.map( e => memberFromEmail( e, joinedAt ) );
	const acceptedLc = new Set( clean.map( e => e.toLowerCase() ) );
	const invites = ( group.invites || [] ).filter(
		inv => ! ( inv.type === 'email' && inv.status === 'pending' && acceptedLc.has( ( inv.email || '' ).toLowerCase() ) )
	);
	return { ...group, members: [ ...( group.members || [] ), ...newMembers ], invites };
}

// Cohorts aren't named — they're an owner's group subscription (per PR #148).
export function getGroupOwnerName( group ) {
	return getSubscriberById( group.ownerId )?.name || '';
}

// Display label for a cohort: owner name qualified by plan (disambiguates a
// multi-group owner). Falls back to the plan alone if the owner can't resolve.
export function getGroupLabel( group ) {
	const ownerName = getGroupOwnerName( group );
	return ownerName ? `${ ownerName } · ${ group.plan }` : group.plan;
}
