<?php
/**
 * Test double for the modal checkout class.
 *
 * @package Newspack\Tests
 */

/**
 * Test double for the modal checkout class when newspack-blocks is not loaded.
 */
class Newspack_Test_Modal_Checkout {
	/**
	 * Whether the current request is modal checkout.
	 *
	 * @return bool
	 */
	public static function is_modal_checkout() {
		if ( isset( $_REQUEST['modal_checkout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		return isset( $_REQUEST['post_data'] ) && is_string( $_REQUEST['post_data'] ) && false !== strpos( $_REQUEST['post_data'], 'modal_checkout=1' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Get user from email.
	 *
	 * Keep this fallback aligned with Newspack_Blocks\Modal_Checkout::get_user_id_from_email().
	 *
	 * @return false|int User ID if found by email address, false otherwise.
	 */
	public static function get_user_id_from_email() {
		$billing_email = '';
		if ( isset( $_POST['billing_email'] ) && is_string( $_POST['billing_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$billing_email = sanitize_email( wp_unslash( $_POST['billing_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( ! $billing_email && isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_parse_str( wp_unslash( $_POST['post_data'] ), $parsed_post_data ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( isset( $parsed_post_data['billing_email'] ) && is_string( $parsed_post_data['billing_email'] ) ) {
				$billing_email = sanitize_email( $parsed_post_data['billing_email'] );
			}
		}

		if ( $billing_email ) {
			$customer = get_user_by( 'email', $billing_email );
			if ( $customer ) {
				return $customer->ID;
			}
		}

		return false;
	}
}
