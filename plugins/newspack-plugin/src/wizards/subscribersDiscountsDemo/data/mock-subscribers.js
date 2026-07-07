/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise, no-nested-ternary */
/**
 * Mock subscriber data for the Subscribers Demo wizard.
 *
 * Designed to cover every state the UI needs to render:
 *   - Active, single digital subscription (happy path)
 *   - On hold with failed payment + alert
 *   - Active with digital + print add-on (multi-plan)
 *   - Cancelled, no payment method
 *
 * Plus ~40 seeded pseudo-random extras so DataViews has enough to
 * filter, sort and paginate through.
 */

/**
 * Internal dependencies.
 */
import { STORAGE_PREFIX, readStore, writeStore } from './storage';

export const DIGITAL_PLANS = [
	{ name: 'Monthly Digital', cadence: 'Monthly', amount: 12, access: 'Full digital access' },
	{ name: 'Yearly Digital', cadence: 'Yearly', amount: 120, access: 'Full digital access' },
	{ name: 'Student Monthly', cadence: 'Monthly', amount: 6, access: 'Student digital access' },
	{ name: 'Supporter Annual', cadence: 'Yearly', amount: 250, access: 'Full digital access + supporter perks' },
];

export const PRINT_PLANS = [
	{ name: 'Monthly Print', cadence: 'Monthly', amount: 15, access: 'Weekly print delivery' },
	{ name: 'Yearly Print', cadence: 'Yearly', amount: 150, access: 'Weekly print delivery' },
];

export const ALL_PLANS = [ ...DIGITAL_PLANS, ...PRINT_PLANS ];

export const KNOWN_TAGS = [ 'vip', 'valued-reader', 'met-in-person' ];

export const NEWSLETTERS = [
	{ id: 'daily', name: 'Daily Brief', description: 'Top stories every weekday morning.' },
	{ id: 'weekly', name: 'Weekend Read', description: 'Long reads delivered Saturday.' },
	{ id: 'arts', name: 'Arts & Culture', description: 'Reviews and what’s on, monthly.' },
	{ id: 'breaking', name: 'Breaking News', description: 'Real-time alerts on major stories.' },
];

// Tiny deterministic PRNG so the list is stable between reloads.
function mulberry32( seed ) {
	return function () {
		let t = ( seed += 0x6d2b79f5 );
		t = Math.imul( t ^ ( t >>> 15 ), t | 1 );
		t ^= t + Math.imul( t ^ ( t >>> 7 ), t | 61 );
		return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
	};
}
const rand = mulberry32( 42 );
const pick = arr => arr[ Math.floor( rand() * arr.length ) ];

const NAMES = [
	'James Carter',
	'Sofía Ramírez',
	'Marcus Johnson',
	'Wei Chen',
	'Arjun Sharma',
	'Omar Haddad',
	'Emily Thompson',
	'Diego Morales',
	'Aaliyah Robinson',
	'Grace Kim',
	'Neha Desai',
	'Layla Khalil',
	'Christopher Abernathy',
	'Camila Torres',
	'DeShawn Williams',
	'Kenji Tanaka',
	'Rohan Gupta',
	'Yusuf Rahman',
	'Olivia Bennett',
	'Luis Gutiérrez',
	'Imani Brooks',
	'Mei Lin',
	'Vivek Patel',
	'Fatima Aziz',
	'An Vo',
	'Valentina Cruz',
	'Malik Davis',
	'David Nguyen',
	'Priya Nair',
	'Karim Nasser',
	'Alexandra Kowalczyk',
	'Carlos Mendoza',
	'Jasmine Carter',
	'Hana Sato',
	'Aditya Rao',
	'Leila Farahani',
	'Nathan Brooks',
	'Isabella Vargas',
	'Andre Jackson',
	'Jun Park',
	'Meera Iyer',
	'Tariq Mansour',
	'Margaret Sinclair',
	'Javier Castillo',
	'Nia Coleman',
	'Lily Wong',
	'Sanjay Mehta',
	'Yasmin Saleh',
	'Eli Ross',
	'Lucía Romero',
	'Terrence Bell',
	'Minho Choi',
	'Divya Reddy',
	'Tobias Schmidt',
	'Genevieve Castellanos',
	'Ricardo Ibarra',
	'Destiny Howard',
	'Sophia Zhang',
	'Amara Okafor',
	'Sven Eriksson',
	'Katherine Vance',
	'Andrés Navarro',
	'Jamal Foster',
	'Kevin Liu',
	'Kwame Mensah',
	'Ingrid Larsen',
	'Maximilian Thornton',
	'Gabriela Fuentes',
	'Ayana Reed',
	'Bo Tran',
	'Anaya Singh',
	'Dmitri Volkov',
	'Claire Donovan',
	'Mateo Hernández',
	'Darius Greene',
	'Sophia Park',
	'Sanjana Bhatt',
	'Elena Bianchi',
	'Ty Cole',
	'Natalia Kowalski',
	'Daniel Foster',
	'Adriana Salazar',
	'Xavier Bell',
	'Freya Andersen',
	'Rahul Khanna',
	'Noor Hassan',
	'Bartholomew Higgins',
	'Renata Lopes',
	'Isaiah Brooks',
	'Mai Pham',
];

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

// One cadence after an ISO date, used to keep a subscription's next billing in
// step with its most recent payment.
export function plusCadenceIso( isoDate, cadence ) {
	const d = new Date( isoDate );
	if ( cadence === 'Monthly' ) {
		d.setMonth( d.getMonth() + 1 );
	} else {
		d.setFullYear( d.getFullYear() + 1 );
	}
	return d.toISOString().slice( 0, 10 );
}

// One cadence before an ISO date, used to place the successful payment that
// preceded an on-hold subscription's failed renewal.
export function minusCadenceIso( isoDate, cadence ) {
	const d = new Date( isoDate );
	if ( cadence === 'Monthly' ) {
		d.setMonth( d.getMonth() - 1 );
	} else {
		d.setFullYear( d.getFullYear() - 1 );
	}
	return d.toISOString().slice( 0, 10 );
}

function makeSub( plan, status = 'active' ) {
	const id = 'sub_' + Math.floor( rand() * 1e6 );
	// Start date derived from the id (no extra PRNG draw, so the seeded dataset
	// stays stable) and spread across the past few years so same-status
	// subscriptions can be ordered newest-first.
	const startDate = iso( 30 + ( parseInt( id.slice( 4 ), 10 ) % 1200 ) );
	return {
		id,
		plan: plan.name,
		status,
		access: plan.access,
		cadence: plan.cadence,
		startDate,
		nextBillingDate: status === 'active' ? futureIso( Math.floor( rand() * 30 ) + 1 ) : null,
		amount: plan.amount,
		// The card this subscription bills against. Builders set it to one of the
		// reader's cards; null falls back to the default card in the UI.
		paymentMethodId: null,
	};
}

// Four hand-crafted scenarios the design brief calls out.
const FIXTURES = [
	{
		id: '1',
		name: 'Matt Moore',
		email: 'matthew.moore@example.com',
		status: 'active',
		memberSince: '2025-09-15',
		lastPayment: iso( 10 ),
		lastSeen: iso( 1 ),
		// Owner of the Team Yearly group (grp_acme) with no separate individual
		// plan — the group subscription is the only thing he pays for.
		subscriptions: [],
		paymentMethods: [ { id: 'pm_1', type: 'Visa', last4: '4242', expiry: '08/27', isDefault: true } ],
		alerts: [],
		tags: [ 'valued-reader' ],
		newsletters: [ 'daily', 'weekly' ],
		// Orders are the group subscription's payments (he owns grp_acme).
		orders: [
			{ id: 'ord_1', date: iso( 10 ), amount: 1000.0, type: 'Subscription payment', subscriptionId: 'grp_acme' },
			{ id: 'ord_2', date: iso( 375 ), amount: 1000.0, type: 'Subscription payment', subscriptionId: 'grp_acme' },
		],
	},
	{
		id: '2',
		name: 'Jane Chen',
		email: 'jane.chen@example.com',
		status: 'on-hold',
		memberSince: '2021-04-12',
		lastPayment: iso( 45 ),
		lastSeen: iso( 52 ),
		subscriptions: [ { ...makeSub( DIGITAL_PLANS[ 1 ] ), id: 'sub_2', status: 'on-hold', nextBillingDate: null, paymentMethodId: 'pm_2' } ],
		// The card on file expired, so the latest renewal was declined and the
		// subscription dropped to on-hold.
		paymentMethods: [ { id: 'pm_2', type: 'Visa', last4: '6011', expiry: '04/26', isDefault: true } ],
		alerts: [
			{
				id: 'alert_pay',
				level: 'error',
				title: 'Payment failed',
				message: 'The last renewal payment was declined because the card on file has expired.',
			},
		],
		tags: [],
		newsletters: [ 'daily' ],
		orders: [
			{ id: 'ord_21', date: iso( 45 ), amount: 0, type: 'Failed renewal', subscriptionId: 'sub_2' },
			{ id: 'ord_22', date: iso( 410 ), amount: 120.0, type: 'Subscription payment', subscriptionId: 'sub_2' },
		],
	},
	{
		id: '3',
		name: 'Priya Patel',
		email: 'priya.patel@example.com',
		status: 'active',
		memberSince: '2023-01-05',
		lastPayment: iso( 3 ),
		lastSeen: iso( 0 ),
		subscriptions: [
			{ ...makeSub( DIGITAL_PLANS[ 1 ] ), id: 'sub_3a', paymentMethodId: 'pm_3a' },
			{ ...makeSub( PRINT_PLANS[ 0 ] ), id: 'sub_3b', paymentMethodId: 'pm_3b' },
		],
		paymentMethods: [
			{ id: 'pm_3a', type: 'Mastercard', last4: '1881', expiry: '02/28', isDefault: true },
			{ id: 'pm_3b', type: 'Visa', last4: '9933', expiry: '06/27', isDefault: false },
			{ id: 'pm_3c', type: 'Amex', last4: '0005', expiry: '03/24', isDefault: false },
		],
		alerts: [],
		tags: [ 'vip', 'valued-reader' ],
		newsletters: [ 'daily', 'weekly', 'arts' ],
		orders: [
			{ id: 'ord_31', date: iso( 3 ), amount: 15.0, type: 'Subscription payment', subscriptionId: 'sub_3b' },
			{ id: 'ord_32', date: iso( 20 ), amount: 120.0, type: 'Subscription payment', subscriptionId: 'sub_3a' },
		],
	},
	{
		id: '5',
		name: 'Aisha Khan',
		email: 'aisha.khan@example.com',
		status: 'active',
		memberSince: '2022-02-14',
		lastPayment: iso( 7 ),
		lastSeen: iso( 6 ),
		subscriptions: [
			{ ...makeSub( DIGITAL_PLANS[ 0 ] ), id: 'sub_5a', paymentMethodId: 'pm_5' },
			{ ...makeSub( PRINT_PLANS[ 1 ] ), id: 'sub_5b', status: 'cancelled', nextBillingDate: null, paymentMethodId: 'pm_5b' },
		],
		paymentMethods: [
			{ id: 'pm_5', type: 'Visa', last4: '0007', expiry: '11/26', isDefault: true },
			{ id: 'pm_5b', type: 'Mastercard', last4: '2244', expiry: '09/28', isDefault: false },
		],
		alerts: [],
		tags: [ 'met-in-person' ],
		newsletters: [ 'daily', 'breaking' ],
		orders: [
			{ id: 'ord_51', date: iso( 7 ), amount: 12.0, type: 'Subscription payment', subscriptionId: 'sub_5a' },
			{ id: 'ord_52', date: iso( 60 ), amount: 150.0, type: 'Subscription payment', subscriptionId: 'sub_5b' },
			{ id: 'ord_53', date: iso( 75 ), amount: 0, type: 'Cancellation', subscriptionId: 'sub_5b' },
		],
	},
	{
		id: '4',
		name: 'Oscar Rivera',
		email: 'oscar@example.com',
		status: 'cancelled',
		memberSince: '2020-06-18',
		lastPayment: iso( 220 ),
		lastSeen: null,
		subscriptions: [ { ...makeSub( DIGITAL_PLANS[ 0 ] ), id: 'sub_4', status: 'cancelled', nextBillingDate: null, paymentMethodId: 'pm_4' } ],
		// Renewals kept failing on an expired card until the subscription lapsed
		// to cancelled.
		paymentMethods: [ { id: 'pm_4', type: 'Mastercard', last4: '3007', expiry: '08/25', isDefault: true } ],
		alerts: [],
		tags: [],
		newsletters: [],
		orders: [
			{ id: 'ord_42', date: iso( 180 ), amount: 0, type: 'Cancellation', subscriptionId: 'sub_4' },
			{ id: 'ord_41', date: iso( 220 ), amount: 12.0, type: 'Subscription payment', subscriptionId: 'sub_4' },
		],
	},
	{
		id: '6',
		name: 'Liam Brooks',
		email: 'liam.brooks@example.com',
		status: 'active',
		memberSince: '2021-03-22',
		lastPayment: iso( 14 ),
		lastSeen: iso( 2 ),
		// Resubscribed on the same plan after an earlier cancellation, so two
		// "Supporter Annual" instances coexist. The order history folds each
		// one's start date to tell them apart.
		subscriptions: [
			{
				...makeSub( DIGITAL_PLANS[ 3 ] ),
				id: 'sub_6_old',
				startDate: '2021-03-22',
				status: 'cancelled',
				nextBillingDate: null,
				paymentMethodId: 'pm_6',
			},
			{ ...makeSub( DIGITAL_PLANS[ 3 ] ), id: 'sub_6_new', startDate: '2023-06-10', paymentMethodId: 'pm_6b' },
		],
		paymentMethods: [
			{ id: 'pm_6', type: 'Visa', last4: '4455', expiry: '05/28', isDefault: true },
			{ id: 'pm_6b', type: 'Amex', last4: '3782', expiry: '01/27', isDefault: false },
		],
		alerts: [],
		tags: [ 'valued-reader' ],
		newsletters: [ 'daily' ],
		orders: [
			{ id: 'ord_61', date: iso( 14 ), amount: 250.0, type: 'Subscription payment', subscriptionId: 'sub_6_new' },
			{ id: 'ord_62', date: iso( 400 ), amount: 250.0, type: 'Subscription payment', subscriptionId: 'sub_6_new' },
			{ id: 'ord_63', date: iso( 540 ), amount: 0, type: 'Cancellation', subscriptionId: 'sub_6_old' },
			{ id: 'ord_64', date: iso( 760 ), amount: 250.0, type: 'Subscription payment', subscriptionId: 'sub_6_old' },
		],
	},
];

// Strip accents/punctuation so display names with diacritics or apostrophes
// (García, O’Connell, Mei-Ling) still produce clean ASCII email addresses.
function emailSlug( value ) {
	return value
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /[^a-z]/g, '' );
}

const pad2 = n => String( n ).padStart( 2, '0' );

function futureExpiry() {
	return `${ pad2( Math.floor( rand() * 12 ) + 1 ) }/${ 27 + Math.floor( rand() * 3 ) }`;
}

function expiredExpiry() {
	return `${ pad2( Math.floor( rand() * 12 ) + 1 ) }/${ 24 + Math.floor( rand() * 2 ) }`;
}

function makeCard( i, n, isDefault, expired ) {
	const type = rand() < 0.55 ? 'Visa' : rand() < 0.85 ? 'Mastercard' : 'Amex';
	return {
		id: `pm_r${ i }_${ n }`,
		type,
		last4: String( Math.floor( rand() * 9000 ) + 1000 ),
		expiry: expired ? expiredExpiry() : futureExpiry(),
		isDefault,
	};
}

function makeCards( i, status ) {
	const hasCard = status === 'active' ? true : status === 'on-hold' ? rand() < 0.9 : rand() < 0.6;
	if ( ! hasCard ) {
		return [];
	}
	const expiredDefault = status === 'on-hold' && rand() < 0.55;
	const cards = [ makeCard( i, 0, true, expiredDefault ) ];
	if ( status === 'active' && rand() < 0.3 ) {
		cards.push( makeCard( i, 1, false, false ) );
	}
	return cards;
}

function makeRandom( i ) {
	const name = NAMES[ i % NAMES.length ];
	const [ first, ...rest ] = name.split( ' ' );
	const last = rest.join( ' ' ) || first;
	const email = `${ emailSlug( first ) }.${ emailSlug( last ) }${ i }@example.com`;
	const roll = rand();
	const status = roll < 0.45 ? 'active' : roll < 0.8 ? 'on-hold' : 'cancelled';
	const digital = pick( DIGITAL_PLANS );
	const withPrint = status === 'active' && rand() < 0.25;
	const subs = [ makeSub( digital, status === 'active' ? 'active' : status ) ];
	if ( withPrint ) {
		subs.push( makeSub( pick( PRINT_PLANS ) ) );
	}
	const memberSinceDays = Math.floor( rand() * 1500 ) + 30;
	// Payment and activity can never predate the join date, so clamp both to
	// the member-since window.
	const lastPaymentDays = Math.min( Math.floor( rand() * 60 ), memberSinceDays );
	// last_active mirror: engaged readers seen recently, on-hold/cancelled drift
	// off, and a slice never return after signup (null → rendered as "—").
	let seenDaysAgo = null;
	if ( status === 'active' ) {
		seenDaysAgo = rand() < 0.85 ? Math.floor( rand() * 21 ) : null;
	} else if ( status === 'on-hold' ) {
		seenDaysAgo = rand() < 0.7 ? Math.floor( rand() * 160 ) + 20 : null;
	} else if ( rand() < 0.4 ) {
		seenDaysAgo = Math.floor( rand() * 400 ) + 120;
	}
	const lastSeen = seenDaysAgo === null ? null : iso( Math.min( seenDaysAgo, memberSinceDays ) );
	const alerts =
		status === 'on-hold' && rand() < 0.6
			? [
					{
						id: 'alert_pay',
						level: 'warning',
						title: 'Payment needs attention',
						message: 'The last renewal payment failed.',
					},
			  ]
			: [];
	const tags = [];
	if ( rand() < 0.4 ) {
		const firstTag = KNOWN_TAGS[ Math.floor( rand() * KNOWN_TAGS.length ) ];
		tags.push( firstTag );
		if ( rand() < 0.3 ) {
			const secondTag = KNOWN_TAGS[ Math.floor( rand() * KNOWN_TAGS.length ) ];
			if ( secondTag !== firstTag ) {
				tags.push( secondTag );
			}
		}
	}
	const newsletters = [];
	if ( rand() < 0.7 ) {
		const count = Math.floor( rand() * 3 ) + 1;
		while ( newsletters.length < count ) {
			const candidate = NEWSLETTERS[ Math.floor( rand() * NEWSLETTERS.length ) ].id;
			if ( ! newsletters.includes( candidate ) ) {
				newsletters.push( candidate );
			}
		}
	}
	// Built last so the seeded PRNG sequence is unchanged; each subscription then
	// bills against a card by index (first → default), giving multi-card readers
	// some variety without a new random draw.
	const paymentMethods = makeCards( i, status );
	const defaultPm = paymentMethods.find( p => p.isDefault ) || paymentMethods[ 0 ];
	subs.forEach( ( sub, k ) => {
		sub.paymentMethodId = ( paymentMethods[ k ] || defaultPm )?.id || null;
	} );
	return {
		id: String( 100 + i ),
		name,
		email,
		status,
		memberSince: iso( memberSinceDays ),
		lastPayment: iso( lastPaymentDays ),
		lastSeen,
		subscriptions: subs,
		paymentMethods,
		alerts,
		tags,
		newsletters,
		orders: [
			// Cancelled readers carry a cancellation event (more recent than the
			// last payment) so the history shows when access ended.
			...( status === 'cancelled'
				? [
						{
							id: 'ord_r' + i + '_c',
							date: iso( Math.floor( rand() * lastPaymentDays ) ),
							amount: 0,
							type: 'Cancellation',
							subscriptionId: subs[ 0 ].id,
						},
				  ]
				: [] ),
			{
				id: 'ord_r' + i + '_1',
				date: iso( lastPaymentDays ),
				amount: digital.amount,
				type: status === 'on-hold' ? 'Failed renewal' : 'Subscription payment',
				subscriptionId: subs[ 0 ].id,
			},
			// The add-on (print) subscription carries its own payment line, so the
			// billing history reflects every subscription, not just the primary one.
			...( subs[ 1 ]
				? [
						{
							id: 'ord_r' + i + '_2',
							date: iso( Math.min( memberSinceDays, lastPaymentDays + 3 ) ),
							amount: subs[ 1 ].amount,
							type: 'Subscription payment',
							subscriptionId: subs[ 1 ].id,
						},
				  ]
				: [] ),
		],
	};
}

const EXTRAS = Array.from( { length: 80 }, ( _, i ) => makeRandom( i ) );

export const SUBSCRIBERS = [ ...FIXTURES, ...EXTRAS ];

// Keep each active subscription's next billing one cadence after its most recent
// payment, so the card's "Next billing" agrees with the billing history (a yearly
// plan billed last June is next billed the following June, not in a few weeks).
SUBSCRIBERS.forEach( subscriber => {
	( subscriber.subscriptions || [] ).forEach( sub => {
		if ( sub.status !== 'active' ) {
			return;
		}
		const lastPayment = ( subscriber.orders || [] )
			.filter( order => order.subscriptionId === sub.id && order.type === 'Subscription payment' )
			.map( order => order.date )
			.sort()
			.pop();
		if ( lastPayment ) {
			sub.nextBillingDate = plusCadenceIso( lastPayment, sub.cadence );
		}
	} );
} );

// On-hold cleanup: mirror the active alignment for failed renewals. An on-hold
// subscription's failed renewal sits exactly one cadence after its last
// successful payment, every on-hold sub carries that payment (randoms only had
// the failed attempt), and the reader's last payment reflects the successful
// charge rather than the failed one — so the on-hold notice and the list column
// stay truthful.
const TODAY = iso( 0 );
SUBSCRIBERS.forEach( subscriber => {
	if ( subscriber.status !== 'on-hold' ) {
		return;
	}
	subscriber.orders = subscriber.orders || [];
	( subscriber.subscriptions || [] ).forEach( sub => {
		if ( sub.status !== 'on-hold' ) {
			return;
		}
		const failed = subscriber.orders.find( order => order.subscriptionId === sub.id && order.type === 'Failed renewal' );
		let success = subscriber.orders
			.filter( order => order.subscriptionId === sub.id && order.type === 'Subscription payment' )
			.sort( ( a, b ) => a.date.localeCompare( b.date ) )
			.pop();
		if ( ! success && failed ) {
			// Randoms carry only the failed attempt, so synthesize the charge that
			// preceded it — one cadence earlier, but never before the join date (a
			// younger membership simply failed its first renewal).
			const prior = minusCadenceIso( failed.date, sub.cadence );
			success = {
				id: `ord_${ sub.id }_paid`,
				date: prior < subscriber.memberSince ? subscriber.memberSince : prior,
				amount: sub.amount,
				type: 'Subscription payment',
				subscriptionId: sub.id,
			};
			subscriber.orders.push( success );
		}
		if ( success && failed ) {
			// Keep the failure exactly one cadence after the payment while that
			// still lands in the past; otherwise leave the seeded recent failure
			// (the membership is younger than a full cycle).
			const aligned = plusCadenceIso( success.date, sub.cadence );
			if ( aligned <= TODAY ) {
				failed.date = aligned;
			}
		}
		if ( success ) {
			subscriber.lastPayment = success.date;
		}
	} );
} );

// PROTOTYPE ONLY: tag/newsletter changes made in L1 are persisted to localStorage but the
// in-memory SUBSCRIBERS array isn't mutated. As a result the L0 list and the ALL_TAGS
// filter elements only reflect the seeded values. Acceptable for a prototype.
export const ALL_TAGS = [ ...new Set( SUBSCRIBERS.flatMap( s => s.tags || [] ) ) ].sort();

export function getSubscriberById( id ) {
	return SUBSCRIBERS.find( s => s.id === id );
}

export function getSubscriberByEmail( email ) {
	const needle = String( email || '' )
		.trim()
		.toLowerCase();
	if ( ! needle ) {
		return undefined;
	}
	return SUBSCRIBERS.find( s => ( s.email || '' ).toLowerCase() === needle );
}

// PROTOTYPE ONLY: notes/tags/newsletters are persisted to the current admin's localStorage
// so they survive a refresh during a demo. In production these need to live server-side
// (REST endpoint + user/post meta or an option) so they're shared across every admin
// viewing the same subscriber.
const NOTES_STORAGE_KEY = STORAGE_PREFIX + 'notes';
const TAGS_STORAGE_KEY = STORAGE_PREFIX + 'tags';
const NEWSLETTERS_STORAGE_KEY = STORAGE_PREFIX + 'newsletters';

export function getStoredNotes( id ) {
	return readStore( NOTES_STORAGE_KEY )[ id ] || [];
}

export function setStoredNotes( id, notes ) {
	const store = readStore( NOTES_STORAGE_KEY );
	if ( notes && notes.length ) {
		store[ id ] = notes;
	} else {
		delete store[ id ];
	}
	writeStore( NOTES_STORAGE_KEY, store );
}

// Returns the stored array if an entry exists, or null when there's no entry yet
// (so callers can fall back to the seeded fixture value). An empty array still counts
// as a real entry — the user may have intentionally cleared all tags/newsletters.
export function getStoredTags( id ) {
	const store = readStore( TAGS_STORAGE_KEY );
	return Object.prototype.hasOwnProperty.call( store, id ) ? store[ id ] : null;
}

export function setStoredTags( id, tags ) {
	const store = readStore( TAGS_STORAGE_KEY );
	store[ id ] = tags || [];
	writeStore( TAGS_STORAGE_KEY, store );
}

export function getStoredNewsletters( id ) {
	const store = readStore( NEWSLETTERS_STORAGE_KEY );
	return Object.prototype.hasOwnProperty.call( store, id ) ? store[ id ] : null;
}

export function setStoredNewsletters( id, ids ) {
	const store = readStore( NEWSLETTERS_STORAGE_KEY );
	store[ id ] = ids || [];
	writeStore( NEWSLETTERS_STORAGE_KEY, store );
}

// Full-subscriber override: once a subscriber profile is mutated (refund, plan
// change, resubscribe, payment method, etc.) the whole record is stored so the
// change survives a reload or route remount. The seeded SUBSCRIBERS array stays
// immutable; reads in the profile layer the stored override on top.
const SUBSCRIBER_STORAGE_KEY = STORAGE_PREFIX + 'subscribers';

export function getStoredSubscriber( id ) {
	const store = readStore( SUBSCRIBER_STORAGE_KEY );
	return Object.prototype.hasOwnProperty.call( store, id ) ? store[ id ] : null;
}

export function setStoredSubscriber( id, subscriber ) {
	const store = readStore( SUBSCRIBER_STORAGE_KEY );
	store[ id ] = subscriber;
	writeStore( SUBSCRIBER_STORAGE_KEY, store );
}
