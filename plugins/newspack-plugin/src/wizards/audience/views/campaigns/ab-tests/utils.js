/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

export const DASH = '—';

/**
 * Where the tab's requests go: newspack-popups owns the tests and their routes.
 */
export const REST_BASE = '/newspack-popups/v1/ab-tests';

/**
 * Cards that need someone's attention come first, as on the retired page,
 * until the tab sorts by what needs doing.
 */
export const STATUS_ORDER = [ 'running', 'needs-attention', 'draft', 'incomplete' ];

/**
 * A test's state from its variants' post statuses.
 *
 * @param {Object} test A test from the list route.
 * @return {string} 'running', 'needs-attention', 'draft' or 'incomplete'.
 */
export const getTestStatus = test => {
	const variants = ( test && test.variants ) || {};
	const letters = Object.keys( variants );
	const challengers = letters.filter( letter => letter !== 'a' );
	if ( ! variants.a || challengers.length === 0 ) {
		return 'incomplete';
	}
	if ( letters.some( letter => false === variants[ letter ].eligible ) ) {
		return 'needs-attention';
	}
	return letters.every( letter => variants[ letter ].status === 'publish' ) ? 'running' : 'draft';
};

/**
 * @param {Object[]} tests Tests from the list route.
 * @return {Object[]} The tests in card order.
 */
export const sortTests = tests =>
	[ ...tests ].sort( ( a, b ) => STATUS_ORDER.indexOf( getTestStatus( a ) ) - STATUS_ORDER.indexOf( getTestStatus( b ) ) );

/**
 * @param {string} status From getTestStatus().
 * @return {string} Label.
 */
export const getStatusLabel = status => {
	switch ( status ) {
		case 'running':
			return __( 'Running', 'newspack-plugin' );
		case 'needs-attention':
			return __( 'Needs attention', 'newspack-plugin' );
		case 'draft':
			return __( 'Draft', 'newspack-plugin' );
		default:
			return __( 'Incomplete', 'newspack-plugin' );
	}
};

/**
 * @param {string} status From getTestStatus().
 * @return {string} A `Badge` intent.
 */
export const getStatusIntent = status => {
	switch ( status ) {
		case 'running':
			return 'stable';
		case 'needs-attention':
			return 'high';
		case 'draft':
			return 'draft';
		default:
			return 'low';
	}
};

/**
 * A rate as the table shows it, so every figure derived from it agrees.
 *
 * @param {?number} rate Conversions per hundred readers.
 * @return {?number} Rounded to two places, or null.
 */
export const roundRate = rate => ( rate === null || rate === undefined ? null : Math.round( Number( rate ) * 100 ) / 100 );

/**
 * @param {?number} rate Conversions per hundred readers.
 * @return {string} Formatted.
 */
export const formatRate = rate => ( rate === null || rate === undefined ? DASH : `${ Number( rate ).toFixed( 2 ) }%` );

/**
 * Lift of a version over A, from the rates as shown rather than the raw ones,
 * so a reader checking the table by hand gets the same figure.
 *
 * @param {?number} controlRate A's rate.
 * @param {?number} rate        The version's rate.
 * @return {?number} Percent change, or null when there is nothing to compare.
 */
export const computeLift = ( controlRate, rate ) => {
	const base = roundRate( controlRate );
	const value = roundRate( rate );
	if ( base === null || value === null || base === 0 ) {
		return null;
	}
	return ( ( value - base ) / base ) * 100;
};

/**
 * @param {?number} lift Percent change against A.
 * @return {string} Formatted, always signed.
 */
export const formatLift = lift => {
	if ( lift === null || lift === undefined ) {
		return DASH;
	}
	const value = Number( lift );
	return `${ value < 0 ? '-' : '+' }${ Math.abs( value ).toFixed( 1 ) }%`;
};

/**
 * Confidence as text: the percentage then the label, or the label alone.
 *
 * @param {?Object} comparison A comparison from the stats route.
 * @param {Object}  labels     Label key => text.
 * @return {string} Formatted.
 */
export const formatConfidence = ( comparison, labels = {} ) => {
	if ( ! comparison ) {
		return DASH;
	}
	const label = labels[ comparison.label ] || '';
	if ( comparison.confidence === null || comparison.confidence === undefined ) {
		return label || DASH;
	}
	return `${ Number( comparison.confidence ).toFixed( 1 ) }% ${ label }`.trim();
};

/**
 * @param {string} unit 'readers' or 'events'.
 * @return {string} The count column's heading.
 */
export const getCountLabel = unit => ( unit === 'events' ? __( 'Views', 'newspack-plugin' ) : __( 'Readers', 'newspack-plugin' ) );

/**
 * @param {number} controlShare Percent of readers who see A.
 * @return {string} The split, such as "50% see A, 50% see B."
 */
export const splitSummary = controlShare =>
	sprintf(
		// Translators: %1$d is the share of readers who see version A, %2$d the share who see version B.
		__( '%1$d%% see A, %2$d%% see B.', 'newspack-plugin' ),
		controlShare,
		100 - controlShare
	);

/**
 * A list in the page's language, such as "A and B".
 *
 * @param {string[]} items Items.
 * @return {string} Joined list.
 */
export const formatList = items => {
	// WordPress locales such as pt_PT_ao90 become tags Intl rejects, and a throw
	// here would unmount the tab.
	try {
		return new Intl.ListFormat( document.documentElement.lang || 'en', { type: 'conjunction' } ).format( items );
	} catch ( e ) {
		return items.join( ', ' );
	}
};
