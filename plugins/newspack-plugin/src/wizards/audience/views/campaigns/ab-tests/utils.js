/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';

export const DASH = '—';

/**
 * Where the tab's requests go: newspack-popups owns the tests and their routes.
 */
export const REST_BASE = '/newspack-popups/v1/ab-tests';

/**
 * Card order: running tests, then those needing attention, drafts, and
 * tests still missing a version.
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
 * A keeps its share and the other versions divide the rest evenly, which is
 * how readers are assigned.
 *
 * @param {number}   controlShare Percent of readers who see A.
 * @param {string[]} letters      The test's version letters.
 * @return {string} The split, such as "50% see A, 50% see B."
 */
export const splitSummary = ( controlShare, letters = [ 'a', 'b' ] ) => {
	const challengers = letters.filter( letter => letter !== 'a' ).sort();
	if ( challengers.length === 0 ) {
		challengers.push( 'b' );
	}
	if ( challengers.length === 1 ) {
		return sprintf(
			// Translators: %1$d is the share of readers who see version A, %2$d the share who see the other version, %3$s that version's letter such as "B".
			__( '%1$d%% see A, %2$d%% see %3$s.', 'newspack-plugin' ),
			controlShare,
			100 - controlShare,
			challengers[ 0 ].toUpperCase()
		);
	}
	// Rounding each share on its own can total 101; the last version takes the
	// remainder so the line always adds up to 100.
	const rest = 100 - controlShare;
	const challengerShare = Math.round( rest / challengers.length );
	const lastShare = rest - challengerShare * ( challengers.length - 1 );
	const parts = [ 'a', ...challengers ].map( ( letter, index ) => {
		let share = challengerShare;
		if ( letter === 'a' ) {
			share = controlShare;
		} else if ( index === challengers.length ) {
			share = lastShare;
		}
		return sprintf(
			// Translators: %1$d is a share of readers, %2$s a version letter such as "C".
			__( '%1$d%% see %2$s', 'newspack-plugin' ),
			share,
			letter.toUpperCase()
		);
	} );
	return sprintf(
		// Translators: %s is a list such as "60% see A, 20% see B and 20% see C".
		_x( '%s.', 'list of version shares', 'newspack-plugin' ),
		formatList( parts )
	);
};

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
