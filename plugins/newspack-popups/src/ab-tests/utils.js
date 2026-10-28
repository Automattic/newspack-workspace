/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

const DASH = '—';

/**
 * A test's state from its variants' post statuses.
 *
 * @param {Object} test A test from the boot data.
 * @return {string} 'running', 'draft' or 'incomplete'.
 */
export const getTestStatus = test => {
	const variants = ( test && test.variants ) || {};
	const letters = Object.keys( variants );
	const challengers = letters.filter( letter => letter !== 'a' );
	if ( ! variants.a || challengers.length === 0 ) {
		return 'incomplete';
	}
	return letters.every( letter => variants[ letter ].status === 'publish' ) ? 'running' : 'draft';
};

/**
 * Human label for a test status.
 *
 * @param {string} status From getTestStatus().
 * @return {string} Label.
 */
export const getStatusLabel = status => {
	switch ( status ) {
		case 'running':
			return __( 'Running', 'newspack-popups' );
		case 'draft':
			return __( 'Draft', 'newspack-popups' );
		default:
			return __( 'Incomplete', 'newspack-popups' );
	}
};

/**
 * @param {?number} rate Conversions per hundred readers.
 * @return {string} Formatted.
 */
export const formatRate = rate => ( rate === null || rate === undefined ? DASH : `${ Number( rate ).toFixed( 2 ) }%` );

/**
 * @param {?number} lift Percent change against control.
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
 * @param {?number} confidence Percent.
 * @return {string} Formatted.
 */
export const formatConfidence = confidence => ( confidence === null || confidence === undefined ? DASH : `${ Number( confidence ).toFixed( 1 ) }%` );

/**
 * Column heading for the counting unit the stats source used.
 *
 * @param {string} unit 'readers' or 'events'.
 * @return {string} Label.
 */
export const getCountLabel = unit => ( unit === 'events' ? __( 'Views', 'newspack-popups' ) : __( 'Readers', 'newspack-popups' ) );

/**
 * @param {string} letter Variant letter.
 * @return {string} Label naming the variant's role.
 */
export const getVariantLabel = letter =>
	letter === 'a'
		? // Translators: %s is the variant letter.
		  sprintf( __( '%s (control)', 'newspack-popups' ), letter.toUpperCase() )
		: // Translators: %s is the variant letter.
		  sprintf( __( '%s (challenger)', 'newspack-popups' ), letter.toUpperCase() );

/**
 * Badge intent for a test status.
 *
 * @param {string} status From getTestStatus().
 * @return {string} A `Badge` intent.
 */
export const getStatusIntent = status => {
	switch ( status ) {
		case 'running':
			return 'stable';
		case 'draft':
			return 'draft';
		default:
			return 'high';
	}
};

/**
 * Badge intent for a confidence label from the stats route.
 *
 * @param {string} label 'reliable', 'directional' or 'collecting'.
 * @return {string} A `Badge` intent.
 */
export const getLabelIntent = label => {
	switch ( label ) {
		case 'reliable':
			return 'stable';
		case 'directional':
			return 'medium';
		default:
			return 'low';
	}
};
