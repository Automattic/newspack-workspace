/**
 * The published-age content rule, shared by the Access control wizard and the post
 * sidebar. Mirrors `Newspack\Content_Rules::post_matches_published_age()`, so the
 * sidebar names the gate the server will apply.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

export const PUBLISHED_AGE_RULE = 'published_age';

// Mirrors Content_Rules::AGE_OVERRIDE_META_KEY.
export const AGE_OVERRIDE_META_KEY = 'newspack_content_gate_age_override';

export const AGE_UNITS = [ 'days', 'months', 'years' ] as const;

// Mirrors Content_Rules::AGE_MAX_COUNT.
export const AGE_MAX_COUNT = 1000;

export type AgeUnit = ( typeof AGE_UNITS )[ number ];

export type AgeDuration = {
	duration_value: number;
	// '' marks a missing or unknown unit, which the server refuses to save.
	duration_unit: AgeUnit | '';
};

export type PublishedAgeValue = AgeDuration & {
	comparison: 'more_than' | 'less_than';
};

const isAgeUnit = ( unit: unknown ): unit is AgeUnit => ( AGE_UNITS as readonly unknown[] ).includes( unit );

const asObject = ( value: unknown ): Record< string, unknown > =>
	value && typeof value === 'object' && ! Array.isArray( value ) ? ( value as Record< string, unknown > ) : {};

/**
 * Read a stored duration into the shape the controls edit, keeping an unusable part visible
 * rather than coercing it into a valid one.
 *
 * @param value A stored duration, or the post meta the editor returns (`[]` when unset).
 */
export const normalizeAgeDuration = ( value: unknown ): AgeDuration => {
	const raw = asObject( value );
	return {
		duration_value: Number( raw.duration_value ) || 0,
		duration_unit: isAgeUnit( raw.duration_unit ) ? raw.duration_unit : '',
	};
};

/**
 * Whether a duration is one the server accepts: a whole number from 1 to AGE_MAX_COUNT, in a known unit.
 *
 * @param duration The duration.
 */
export const isUsableAgeDuration = ( duration: AgeDuration ) =>
	Number.isInteger( duration.duration_value ) &&
	duration.duration_value >= 1 &&
	duration.duration_value <= AGE_MAX_COUNT &&
	'' !== duration.duration_unit;

/**
 * Read a stored rule value into the shape the controls edit.
 *
 * @param value The rule's stored value.
 */
export const normalizePublishedAgeValue = ( value: unknown ): PublishedAgeValue => ( {
	comparison: 'less_than' === asObject( value ).comparison ? 'less_than' : 'more_than',
	...normalizeAgeDuration( value ),
} );

/**
 * A duration in words, such as "7 days".
 *
 * @param duration The duration.
 */
export const formatAgeDuration = ( duration: AgeDuration ): string => {
	const { duration_value: count, duration_unit: unit } = duration;
	if ( 'days' === unit ) {
		// translators: %d: number of days.
		return sprintf( _n( '%d day', '%d days', count, 'newspack-plugin' ), count );
	}
	if ( 'months' === unit ) {
		// translators: %d: number of months.
		return sprintf( _n( '%d month', '%d months', count, 'newspack-plugin' ), count );
	}
	if ( 'years' === unit ) {
		// translators: %d: number of years.
		return sprintf( _n( '%d year', '%d years', count, 'newspack-plugin' ), count );
	}
	return __( 'an invalid duration', 'newspack-plugin' );
};

/**
 * A rule value in words, such as "More than 7 days ago".
 *
 * @param value The rule's stored value.
 */
export const formatPublishedAge = ( value: unknown ): string => {
	const age = normalizePublishedAgeValue( value );
	return 'more_than' === age.comparison
		? sprintf(
				// translators: %s: a duration, such as "7 days".
				__( 'More than %s ago', 'newspack-plugin' ),
				formatAgeDuration( age )
		  )
		: sprintf(
				// translators: %s: a duration, such as "7 days".
				__( 'Less than %s ago', 'newspack-plugin' ),
				formatAgeDuration( age )
		  );
};

/**
 * Whether a published-age rule matches a post.
 *
 * @param value     The rule's stored value.
 * @param published When the post was published, or null when it is not published yet (zero age).
 * @param override  The post's own time window, from its override meta. Replaces the rule's duration.
 * @param now       The time to judge against.
 */
export const postMatchesPublishedAge = ( value: unknown, published: Date | null, override: unknown = null, now = new Date() ): boolean => {
	const age = normalizePublishedAgeValue( value );
	// As on the server, a rule that can't be read never matches, whatever the post's own window.
	const comparison = asObject( value ).comparison;
	if ( ! isUsableAgeDuration( age ) || ( 'more_than' !== comparison && 'less_than' !== comparison ) ) {
		return false;
	}
	const ownAge = normalizeAgeDuration( override );
	const duration = isUsableAgeDuration( ownAge ) ? ownAge : age;
	// Calendar units, as PHP's DateTime::modify() counts them: one month after Jan 31 overflows into March.
	const line = new Date( ( published ?? now ).getTime() );
	if ( 'days' === duration.duration_unit ) {
		line.setUTCDate( line.getUTCDate() + duration.duration_value );
	} else if ( 'months' === duration.duration_unit ) {
		line.setUTCMonth( line.getUTCMonth() + duration.duration_value );
	} else {
		line.setUTCFullYear( line.getUTCFullYear() + duration.duration_value );
	}
	const isOlderThan = now.getTime() >= line.getTime();
	return 'more_than' === age.comparison ? isOlderThan : ! isOlderThan;
};
