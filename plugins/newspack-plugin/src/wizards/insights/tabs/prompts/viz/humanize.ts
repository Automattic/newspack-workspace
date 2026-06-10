/**
 * humanizeTerm — display helper for raw event-param enum values.
 *
 * Turns snake_case / lowercase event values (`newsletters_subscription`,
 * `above-header`) into title-cased display strings
 * (`Newsletters Subscription`, `Above Header`) for the Performance
 * breakdown tables. Phase 1 tables have no rows, but the per-cell
 * renderers carry this through to Phase 2 unchanged.
 */

export const humanizeTerm = ( value: string ): string =>
	value
		.split( /[_-]+/ )
		.filter( Boolean )
		.map( word => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );
