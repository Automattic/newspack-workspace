/**
 * Subscriptions demo — subscriber & plan data.
 *
 * People and plan definitions have a single source of truth: the Subscribers
 * demo. This module re-exports that dataset verbatim and layers on the
 * plan-level commercial extras the subscription views need (status, total
 * sales, total revenue). One shared dataset means a subscription's subscriber
 * count here matches the Subscribers demo list the "View subscribers" link
 * deep-links into.
 */

/**
 * Internal dependencies.
 */
import { DIGITAL_PLANS as BASE_DIGITAL_PLANS, PRINT_PLANS as BASE_PRINT_PLANS } from '../../subscribersDemo/data/mock-subscribers';

export {
	SUBSCRIBERS,
	ALL_TAGS,
	KNOWN_TAGS,
	NEWSLETTERS,
	getSubscriberById,
	getSubscriberByEmail,
	getStoredNotes,
	setStoredNotes,
	getStoredTags,
	setStoredTags,
	getStoredNewsletters,
	setStoredNewsletters,
	getStoredSubscriber,
	setStoredSubscriber,
	plusCadenceIso,
	minusCadenceIso,
} from '../../subscribersDemo/data/mock-subscribers';

// Per-subscription commercial extras keyed by name, merged onto the shared plan
// definitions. Defaults keep any unlisted plan active with zero sales.
const PLAN_EXTRAS = {
	'Monthly Digital': { status: 'active', totalSales: 320, totalRevenue: 18400 },
	'Yearly Digital': { status: 'active', totalSales: 145, totalRevenue: 21200 },
	'Student Monthly': { status: 'active', totalSales: 210, totalRevenue: 4900 },
	'Supporter Annual': { status: 'active', totalSales: 60, totalRevenue: 22500 },
	'Monthly Print': { status: 'active', totalSales: 95, totalRevenue: 8600 },
	'Yearly Print': { status: 'retired', totalSales: 30, totalRevenue: 6300 },
};

const withExtras = plan => ( { status: 'active', totalSales: 0, totalRevenue: 0, ...plan, ...PLAN_EXTRAS[ plan.name ] } );

export const DIGITAL_PLANS = BASE_DIGITAL_PLANS.map( withExtras );
export const PRINT_PLANS = BASE_PRINT_PLANS.map( withExtras );
export const ALL_PLANS = [ ...DIGITAL_PLANS, ...PRINT_PLANS ];
