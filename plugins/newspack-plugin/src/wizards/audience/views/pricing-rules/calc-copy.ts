/**
 * Wording for the calculation and value fields, shared by the flat model's inline
 * fields and the schedule's price drawer.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

export function calcTypeHelp( calcType: string, fallback: string ): string {
	if ( 'fixed_price' === calcType ) {
		return __( 'Readers pay this instead of the regular price.', 'newspack-plugin' );
	}
	if ( 'percent_of_base' === calcType ) {
		return __( 'Readers pay a share of the regular price.', 'newspack-plugin' );
	}
	if ( 'discount_fixed' === calcType ) {
		return __( 'A set amount comes off the regular price.', 'newspack-plugin' );
	}
	return fallback;
}

/**
 * The unit rides in the label so the field announces it before anything is typed.
 */
export function valueLabel( calcType: string, currencySymbol: string ): string {
	if ( 'percent_of_base' === calcType ) {
		return __( 'Value (%)', 'newspack-plugin' );
	}
	if ( 'fixed_price' === calcType || 'discount_fixed' === calcType ) {
		/* translators: %s: the store's currency symbol, for example $. */
		return sprintf( __( 'Value (%s)', 'newspack-plugin' ), currencySymbol );
	}
	// A type this build has no wording for: the unit is unknown, so claim none.
	return __( 'Value', 'newspack-plugin' );
}

/**
 * The engine computes base * value/100, so 80 leaves readers paying 80% rather
 * than taking 80% off.
 */
export function valueHelp( calcType: string ): string {
	if ( 'fixed_price' === calcType ) {
		return __( 'The price readers pay.', 'newspack-plugin' );
	}
	if ( 'percent_of_base' === calcType ) {
		return __( 'A percentage of the regular price. 80 means readers pay 80% of it.', 'newspack-plugin' );
	}
	if ( 'discount_fixed' === calcType ) {
		return __( 'Taken off the regular price.', 'newspack-plugin' );
	}
	return '';
}
