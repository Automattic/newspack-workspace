/* eslint-disable eqeqeq */

/**
 * Normalizes a reader value into an array for list matching. Mirrors the server
 * `Newspack\Reader_Activation\Promoted_Fields::parse_list_value()`: ActiveCampaign
 * wraps multi-select values with leading/trailing `||` (`||A||B||`); require both
 * ends so a normal string containing `||` mid-value is left intact.
 *
 * @param {*} value The reader value.
 * @return {*} An array of values when pipe-delimited, otherwise the value unchanged.
 */
const parseReaderListValue = value => {
	if ( typeof value === 'string' && value.startsWith( '||' ) && value.endsWith( '||' ) ) {
		return value
			.split( '||' )
			.map( item => item.trim() )
			.filter( item => '' !== item );
	}
	return value;
};

/**
 * Common matching functions that can be used by criteria.
 */
export default {
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
		let list = config.value;
		if ( typeof list === 'string' ) {
			list = config.value.split( ',' ).map( item => item.trim() );
		}
		if ( ! Array.isArray( list ) ) {
			return false;
		}
		const readerValue = parseReaderListValue( criteria.value );
		if ( Array.isArray( readerValue ) ) {
			return readerValue.some( value => list.some( configValue => configValue == value ) );
		}
		if ( ! readerValue || ! list.some( configValue => configValue == readerValue ) ) {
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
		let list = config.value;
		if ( typeof list === 'string' ) {
			list = config.value.split( ',' ).map( item => item.trim() );
		}
		if ( ! Array.isArray( list ) ) {
			return true;
		}
		const readerValue = parseReaderListValue( criteria.value );
		if ( Array.isArray( readerValue ) ) {
			return ! readerValue.some( value => list.some( configValue => configValue == value ) );
		}
		if ( ! readerValue || ! list.some( configValue => configValue == readerValue ) ) {
			return true;
		}
		return false;
	},
	/**
	 * Matches the criteria value against a range of 'min' and 'max' provided by
	 * the segment config.
	 */
	range: ( criteria, config ) => {
		if ( isNaN( criteria.value ) ) {
			return false;
		}
		const { min, max } = config.value;
		// A bound of 0 is "no bound", the same as an absent one. That is the contract the
		// segment editor's Min/Max control writes (unchecking or clearing a bound stores 0),
		// and what the pre-criteria migration stored for every "at least N" segment, so a
		// stored 0 never means "at most zero" (NPPM-3389). It follows that a range cannot
		// exclude negative reader values or pin a value to exactly 0; the editor cannot
		// express either. This diverges from the server-side access-rule compare in
		// class-promoted-fields.php, which enforces a present 0, but that path has no
		// range editor. The isNaN guard above fails closed where the server coerces a
		// non-numeric reader value to 0.0.
		const hasBound = value => undefined !== value && null !== value && '' !== value && 0 !== Number( value );
		if ( ( hasBound( min ) && criteria.value < min ) || ( hasBound( max ) && criteria.value > max ) ) {
			return false;
		}
		return true;
	},
};
