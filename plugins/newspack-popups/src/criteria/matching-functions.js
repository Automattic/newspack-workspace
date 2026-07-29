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

// Month and day ranges are bounded (01-12, 01-31) so a digit-shaped but impossible
// date like '2026-13-45' is rejected rather than sorting above every real date.
// Shape only — isCalendarDate() below rejects a day that doesn't exist in its month.
const ISO_DATE = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/;

/**
 * Whether a shape-valid `YYYY-MM-DD` names a day that actually exists.
 *
 * The bounded regex still admits `2026-02-30`, which sorts perfectly well between
 * real dates — so as a stored value it would match a window it isn't in, and as a
 * saved bound it would silently shift one edge of that window. Applied to both
 * sides, since a criterion value can be saved through paths that don't validate
 * against the segment schema.
 *
 * @param {string} candidate A `YYYY-MM-DD` string that already passed ISO_DATE.
 * @return {boolean} Whether the date exists.
 */
const isCalendarDate = candidate => {
	const [ year, month, day ] = candidate.split( '-' ).map( Number );
	const date = new Date( year, month - 1, day );
	return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
};

/**
 * Reduces a stored date value to a calendar date, or null when it isn't one.
 *
 * Takes the date part as written — `2026-01-15T23:30:00-06:00` is Jan 15 for every
 * reader regardless of their timezone, which is what the publisher sees in the ESP.
 * The ISO check is load-bearing, not defensive: a legacy un-normalized value like
 * `03/04/2026` slices to a perfectly comparable string that sorts below every ISO
 * date, so skipping validation produces confident wrong matches rather than none.
 *
 * @param {*} value The stored reader value.
 * @return {?string} A `YYYY-MM-DD` string, or null.
 */
const toCalendarDate = value => {
	if ( typeof value !== 'string' ) {
		return null;
	}
	const candidate = value.slice( 0, 10 );
	return ISO_DATE.test( candidate ) && isCalendarDate( candidate ) ? candidate : null;
};

/**
 * Resolves one end of a date range to a calendar date.
 *
 * Relative bounds resolve against the browser's today, which is what makes a
 * rolling window ("last 30 days") stay current without the segment being edited.
 *
 * @param {*} bound `{ type: 'absolute', date }` or `{ type: 'relative', days }`.
 * @return {?string} A `YYYY-MM-DD` string, or null when the bound is unusable.
 */
const resolveDateBound = bound => {
	if ( ! bound || typeof bound !== 'object' ) {
		return null;
	}
	if ( 'absolute' === bound.type ) {
		return toCalendarDate( bound.date );
	}
	if ( 'relative' === bound.type && Number.isInteger( bound.days ) ) {
		const date = new Date();
		date.setDate( date.getDate() + bound.days );
		// An offset past the Date range yields an Invalid Date, whose components
		// format to the truthy string 'NaN-NaN-NaN'. As an end bound that compares
		// as satisfied, widening the window to everyone — the opposite of the
		// fail-closed contract below. Return null so the caller rejects it.
		if ( Number.isNaN( date.getTime() ) ) {
			return null;
		}
		// Built from local components on purpose: toISOString() converts to UTC and
		// would land on the wrong day for anyone west of Greenwich.
		const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
		const day = String( date.getDate() ).padStart( 2, '0' );
		return `${ date.getFullYear() }-${ month }-${ day }`;
	}
	return null;
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
		// Treat only genuinely-absent bounds ( undefined / null / '' ) as unbounded, so a
		// min or max of 0 is still enforced. This matches the server's (float) compare only
		// while a bound is present: the server floors an absent min at 0 and caps an absent
		// max at PHP_INT_MAX ( class-promoted-fields.php ), whereas an absent bound here is
		// fully unbounded, so the two diverge for a negative reader value with no lower
		// bound. The isNaN guard above also fails closed where the server coerces a
		// non-numeric reader value to 0.0. ESP numeric fields are typically non-negative,
		// so the divergence is narrow in practice.
		const hasBound = value => undefined !== value && null !== value && '' !== value;
		if ( ( hasBound( min ) && criteria.value < min ) || ( hasBound( max ) && criteria.value > max ) ) {
			return false;
		}
		return true;
	},
	/**
	 * Matches an ESP date value against a { start, end } window where each bound is
	 * either an absolute calendar date or an offset in days from today. An absent
	 * bound is unbounded; both bounds are inclusive.
	 *
	 * Comparison is lexicographic on validated `YYYY-MM-DD` strings, which sort
	 * chronologically, so no date arithmetic is involved in the compare itself.
	 */
	date_range: ( criteria, config ) => {
		const value = toCalendarDate( criteria.value );
		if ( ! value ) {
			return false;
		}
		const bounds = config.value;
		if ( ! bounds || typeof bounds !== 'object' || Array.isArray( bounds ) ) {
			return false;
		}
		// A bound that is present but unusable fails closed. Silently dropping it
		// would widen the segment to readers the publisher never asked for.
		if ( bounds.start ) {
			const start = resolveDateBound( bounds.start );
			if ( ! start || value < start ) {
				return false;
			}
		}
		if ( bounds.end ) {
			const end = resolveDateBound( bounds.end );
			if ( ! end || value > end ) {
				return false;
			}
		}
		return true;
	},
};
