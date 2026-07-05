/**
 * Utility functions for contribution meter calculations and formatting.
 */

/**
 * Format currency value using WordPress settings.
 *
 * @param {number} amount Amount to format.
 * @return {string} Formatted currency string.
 */
export const formatCurrency = amount => {
	// Get currency settings from WordPress/WooCommerce if available.
	const currencySymbol = window.newspack_contribution_meter_data?.currencySymbol || '$';
	const currencyPosition = window.newspack_contribution_meter_data?.currencyPosition || 'left';
	const thousandSeparator = window.newspack_contribution_meter_data?.thousandSeparator || ',';

	// Format the number with no decimals.
	const formatted = Math.round( amount )
		.toString()
		.replace( /\B(?=(\d{3})+(?!\d))/g, thousandSeparator );

	// Apply currency symbol position.
	switch ( currencyPosition ) {
		case 'left':
			return currencySymbol + formatted;
		case 'right':
			return formatted + currencySymbol;
		case 'left_space':
			return currencySymbol + ' ' + formatted;
		case 'right_space':
			return formatted + ' ' + currencySymbol;
		default:
			return currencySymbol + formatted;
	}
};

/**
 * Get default start date (today in YYYY-MM-DD format).
 *
 * @return {string} Today's date in YYYY-MM-DD format.
 */
export const getDefaultStartDate = ( d = new Date() ) =>
	`${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( d.getDate() ).padStart( 2, '0' ) }`;
