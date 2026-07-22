/**
 * Mock subscriber-only product restrictions for the Subscriptions Demo.
 *
 * A restriction says "these store products are purchasable only by subscribers
 * of ANY of these subscriptions". Mirrors the storage pattern of
 * mock-discounts: a static seed plus a localStorage override store, so demo
 * mutations survive a refresh.
 */

/**
 * Internal dependencies.
 */
import { STORAGE_PREFIX, readStore, writeStore } from './storage';
import { TEAM_PLANS } from './mock-groups';

const RESTRICTIONS_KEY = STORAGE_PREFIX + 'restrictions';
const SETTINGS_KEY = STORAGE_PREFIX + 'restriction-settings';

// Seeded to exercise every list state: a hand-picked product list unlocked by
// two subscriptions, a category rule with an exclusion, a group-plan rule, and
// a paused rule.
const SEED = [
	{
		id: 'rest_books',
		subscriptions: [ 'Supporter Annual', 'Yearly Digital' ],
		targeting: 'products',
		productIds: [ 'prod_book_v1', 'prod_book_v2', 'prod_book_v3' ],
		category: null,
		excludedIds: [],
		active: true,
		createdAt: '2026-05-06',
	},
	{
		id: 'rest_courses',
		subscriptions: [ 'Supporter Annual' ],
		targeting: 'category',
		productIds: [],
		category: 'Courses',
		excludedIds: [ 'prod_course_writing' ],
		active: true,
		createdAt: '2026-05-24',
	},
	{
		id: 'rest_gala',
		subscriptions: [ TEAM_PLANS[ 0 ].name, 'Monthly Digital' ],
		targeting: 'products',
		productIds: [ 'prod_gala' ],
		category: null,
		excludedIds: [],
		active: true,
		createdAt: '2026-06-14',
	},
	{
		id: 'rest_walk',
		subscriptions: [ 'Monthly Digital' ],
		targeting: 'products',
		productIds: [ 'prod_photo_walk' ],
		category: null,
		excludedIds: [],
		active: false,
		createdAt: '2026-06-28',
	},
];

// ---- Storage: seed + overrides (mirrors mock-discounts). ----

function readOverrides() {
	return readStore( RESTRICTIONS_KEY );
}

export function getAllRestrictions() {
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

export const getRestrictionById = id => getAllRestrictions().find( rule => rule.id === id ) || null;

export function saveRestriction( rule ) {
	const overrides = readOverrides();
	const saved = {
		subscriptions: [],
		productIds: [],
		category: null,
		excludedIds: [],
		active: true,
		...rule,
		id: rule.id || 'rest_new_' + Date.now(),
		createdAt: rule.createdAt || new Date().toISOString().slice( 0, 10 ),
	};
	overrides[ saved.id ] = saved;
	writeStore( RESTRICTIONS_KEY, overrides );
	return saved;
}

export function deleteRestriction( id ) {
	const overrides = readOverrides();
	if ( SEED.some( rule => rule.id === id ) ) {
		overrides[ id ] = 'deleted';
	} else {
		delete overrides[ id ];
	}
	writeStore( RESTRICTIONS_KEY, overrides );
}

export function setRestrictionActive( id, active ) {
	const rule = getRestrictionById( id );
	if ( rule ) {
		saveRestriction( { ...rule, active } );
	}
}

// ---- Global settings. ----

const DEFAULT_SETTINGS = { hideFromCatalog: false };

export const getRestrictionSettings = () => ( { ...DEFAULT_SETTINGS, ...readStore( SETTINGS_KEY ) } );

export const saveRestrictionSettings = settings => writeStore( SETTINGS_KEY, { ...getRestrictionSettings(), ...settings } );
