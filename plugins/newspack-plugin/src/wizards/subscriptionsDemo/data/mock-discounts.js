/**
 * Mock subscriber product-discount rules for the Subscriptions Demo.
 *
 * Mirrors the storage pattern of mock-groups: a static seed plus a
 * localStorage override store, so demo mutations survive a refresh.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { STORAGE_PREFIX, readStore, writeStore } from './storage';
import { TEAM_PLANS } from './mock-groups';
import { fmtCurrency } from '../format';

export { productsForRule, targetingBaseLabel, excludedLabel, targetingLabel } from './targeting';

const DISCOUNTS_KEY = STORAGE_PREFIX + 'discounts';
const SETTINGS_KEY = STORAGE_PREFIX + 'discount-settings';

// Seeded to exercise every list state: Yucatan-style fixed amount on a
// hand-picked product list, a category rule, an all-store rule, a paused
// rule, and a rule with an exclusion.
const SEED = [
	{
		id: 'disc_books',
		audience: 'Supporter Annual',
		type: 'fixed',
		amount: 51,
		targeting: 'products',
		productIds: [ 'prod_book_v1', 'prod_book_v2', 'prod_book_v3', 'prod_book_city', 'prod_book_recipes' ],
		category: null,
		excludedIds: [],
		active: true,
		createdAt: '2026-04-02',
	},
	{
		id: 'disc_books_cat',
		audience: 'Yearly Digital',
		type: 'percent',
		amount: 10,
		targeting: 'category',
		productIds: [],
		category: 'Books',
		excludedIds: [],
		active: true,
		createdAt: '2026-04-18',
	},
	{
		id: 'disc_all',
		audience: 'Supporter Annual',
		type: 'percent',
		amount: 5,
		targeting: 'all',
		productIds: [],
		category: null,
		excludedIds: [],
		active: true,
		createdAt: '2026-05-11',
	},
	{
		id: 'disc_gala',
		audience: 'Monthly Digital',
		type: 'fixed',
		amount: 20,
		targeting: 'products',
		productIds: [ 'prod_gala' ],
		category: null,
		excludedIds: [],
		active: false,
		createdAt: '2026-05-30',
	},
	{
		id: 'disc_team_courses',
		audience: TEAM_PLANS[ 0 ].name,
		type: 'percent',
		amount: 15,
		targeting: 'category',
		productIds: [],
		category: 'Courses',
		excludedIds: [ 'prod_course_photo' ],
		active: true,
		createdAt: '2026-06-09',
	},
];

// ---- Storage: seed + overrides (mirrors mock-groups). ----

function readOverrides() {
	return readStore( DISCOUNTS_KEY );
}

export function getAllDiscounts() {
	const overrides = readOverrides();
	const rules = SEED.map( rule => ( overrides[ rule.id ] === 'deleted' ? null : { ...rule, ...( overrides[ rule.id ] || {} ) } ) ).filter(
		Boolean
	);
	Object.keys( overrides ).forEach( id => {
		if ( ! SEED.some( rule => rule.id === id ) && overrides[ id ] !== 'deleted' ) {
			rules.push( overrides[ id ] );
		}
	} );
	return rules.sort( ( a, b ) => ( b.createdAt || '' ).localeCompare( a.createdAt || '' ) );
}

export const getDiscountById = id => getAllDiscounts().find( rule => rule.id === id ) || null;

export function saveDiscount( rule ) {
	const overrides = readOverrides();
	const saved = {
		productIds: [],
		category: null,
		excludedIds: [],
		active: true,
		...rule,
		id: rule.id || 'disc_new_' + Date.now(),
		createdAt: rule.createdAt || new Date().toISOString().slice( 0, 10 ),
	};
	overrides[ saved.id ] = saved;
	writeStore( DISCOUNTS_KEY, overrides );
	return saved;
}

export function deleteDiscount( id ) {
	const overrides = readOverrides();
	if ( SEED.some( rule => rule.id === id ) ) {
		overrides[ id ] = 'deleted';
	} else {
		delete overrides[ id ];
	}
	writeStore( DISCOUNTS_KEY, overrides );
}

export function setDiscountActive( id, active ) {
	const rule = getDiscountById( id );
	if ( rule ) {
		saveDiscount( { ...rule, active } );
	}
}

// ---- Global settings. ----

const DEFAULT_SETTINGS = { applyOnSale: false, applyAtCheckout: false, overlap: 'best' };

export const getDiscountSettings = () => ( { ...DEFAULT_SETTINGS, ...readStore( SETTINGS_KEY ) } );

export const saveDiscountSettings = settings => writeStore( SETTINGS_KEY, { ...getDiscountSettings(), ...settings } );

// ---- Presentation helpers. ----

export function subscriberPrice( price, rule ) {
	const value = rule.type === 'percent' ? price * ( 1 - rule.amount / 100 ) : price - rule.amount;
	return Math.max( 0, Math.round( value * 100 ) / 100 );
}

export function discountLabel( rule ) {
	if ( rule.type === 'percent' ) {
		// translators: %s: percentage number.
		return sprintf( __( '%s%%', 'newspack-plugin' ), rule.amount );
	}
	return fmtCurrency( rule.amount );
}

export const benefitsForPlans = planNames => getAllDiscounts().filter( rule => rule.active && ( planNames || [] ).includes( rule.audience ) );
