/**
 * Newspack Settings Connections Constants.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Error messages.
 */
export const ERROR_MESSAGES = {
	RECAPTCHA: {
		SITE_KEY_EMPTY: __( 'Site Key cannot be empty!', 'newspack-plugin' ),
		SITE_SECRET_EMPTY: __( 'Site Secret cannot be empty', 'newspack-plugin' ),
		THRESHOLD_INVALID_MIN: __( 'Threshold cannot be less than 0.1', 'newspack-plugin' ),
		THRESHOLD_INVALID_MAX: __( 'Threshold cannot be greater than 1', 'newspack-plugin' ),
		VERSION_CHANGE: __( 'Your site key and secret must match the selected reCAPTCHA version. Please enter new credentials.', 'newspack-plugin' ),
	},
	CUSTOM_EVENTS: {
		INVALID_MEASUREMENT_ID: __( 'You need a valid Measurement ID (e.g. "G-ABCDE12345") to activate Newspack Custom Events.', 'newspack-plugin' ),
		INVALID_MEASUREMENT_PROTOCOL_SECRET: __( 'You need a valid Measurement API Secret to activate Newspack Custom Events.', 'newspack-plugin' ),
	},
};
