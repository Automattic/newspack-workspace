/* eslint-disable eqeqeq */

import type { Criteria, SegmentConfig } from './utils';

/**
 * A built-in matching function, referenced by name from a criteria's
 * `matchingFunction` config (see `registerCriteria()` in `./utils`). Bound to
 * the owning `Criteria` before being called via `criteria.matches()`.
 */
type BuiltinMatchingFunction = ( criteria: Criteria, config: SegmentConfig ) => boolean;

/**
 * Common matching functions that can be used by criteria.
 */
const matchingFunctions: Record< string, BuiltinMatchingFunction > = {
	/**
	 * Matches the exact value of the criteria against the segment config.
	 */
	default: ( criteria, config ) => criteria.value === config.value,
	/**
	 * Matches the criteria value against a list provided by the segment config,
	 * returns true if the value is on the list.
	 *
	 * If the criteria value is an array, it returns true if any of the values
	 * are on the list.
	 */
	list__in: ( criteria, config ) => {
		let list: unknown = config.value;
		if ( typeof list === 'string' ) {
			list = list.split( ',' ).map( item => item.trim() );
		}
		if ( ! Array.isArray( list ) ) {
			return false;
		}
		if ( Array.isArray( criteria.value ) ) {
			return criteria.value.some( value => list.some( configValue => configValue == value ) );
		}
		if ( ! criteria.value || ! list.some( configValue => configValue == criteria.value ) ) {
			return false;
		}
		return true;
	},
	/**
	 * Matches the criteria value against a list provided by the segment config,
	 * returns true if the value is empty or not on the list.
	 *
	 * If the criteria value is an array, it returns true if none of the values
	 * are on the list.
	 */
	list__not_in: ( criteria, config ) => {
		let list: unknown = config.value;
		if ( typeof list === 'string' ) {
			list = list.split( ',' ).map( item => item.trim() );
		}
		if ( ! Array.isArray( list ) ) {
			return true;
		}
		if ( Array.isArray( criteria.value ) ) {
			return ! criteria.value.some( value => list.some( configValue => configValue == value ) );
		}
		if ( ! criteria.value || ! list.some( configValue => configValue == criteria.value ) ) {
			return true;
		}
		return false;
	},
	/**
	 * Matches the criteria value against a range of 'min' and 'max' provided by
	 * the segment config.
	 */
	range: ( criteria, config ) => {
		if ( isNaN( Number( criteria.value ) ) ) {
			return false;
		}
		// `config.value` is authored via the segment builder's range control, which
		// always stores `{ min, max }` for a 'range' criteria -- not otherwise
		// representable in `SegmentConfig[ 'value' ]`'s general `unknown` shape.
		const { min, max } = config.value as { min?: number; max?: number };
		const numericValue = Number( criteria.value );
		if ( ( min && numericValue < min ) || ( max && numericValue > max ) ) {
			return false;
		}
		return true;
	},
};

export default matchingFunctions;
