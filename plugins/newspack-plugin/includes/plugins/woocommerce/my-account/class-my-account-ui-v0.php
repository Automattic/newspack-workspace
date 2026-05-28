<?php
/**
 * Connection with WooCommerce's features.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use Newspack\WooCommerce_My_Account;
use Newspack\Reader_Activation;

/**
 * Connection with WooCommerce's "My Account" page.
 */
class My_Account_UI_V0 {
	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		\add_filter( 'wc_get_template', [ __CLASS__, 'wc_get_template' ], 10, 5 );
		\add_action( 'template_redirect', [ __CLASS__, 'handle_delete_account_request' ] );
		\add_action( 'newspack_after_delete_account', [ __CLASS__, 'handle_after_delete_account' ] );
	}

	/**
	 * Enqueue assets.
	 */
	public static function enqueue_assets() {
		if ( function_exists( 'is_account_page' ) && \is_account_page() ) {
			\wp_enqueue_style(
				'newspack-my-account-v0',
				\Newspack\Newspack::plugin_url() . '/dist/my-account-v0.css',
				[],
				NEWSPACK_PLUGIN_VERSION
			);
		}
	}

	/**
	 * WC's page templates hijacking.
	 *
	 * @param string $template      Template path.
	 * @param string $template_name Template name.
	 */
	public static function wc_get_template( $template, $template_name ) {
		switch ( $template_name ) {
			case 'myaccount/form-login.php':
				if ( isset( $_GET[ WooCommerce_My_Account::AFTER_ACCOUNT_DELETION_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					return __DIR__ . '/templates/after-delete-account.php';
				}
				return $template;
			case 'myaccount/form-edit-account.php':
				if ( isset( $_GET[ WooCommerce_My_Account::DELETE_ACCOUNT_FORM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					return __DIR__ . '/templates/delete-account.php';
				}
				return __DIR__ . '/templates/v0/edit-account.php';
			default:
				return $template;
		}
	}

	/**
	 * Handle delete account request.
	 *
	 * @param bool $return Whether to return the response or not.
	 * @return void|bool
	 */
	public static function handle_delete_account_request( $return = false ) {
		if ( ! \is_user_logged_in() ) {
			return;
		}

		$user = \wp_get_current_user();
		if ( ! Reader_Activation::is_user_reader( $user ) ) {
			return;
		}

		$nonce = filter_input( INPUT_GET, WooCommerce_My_Account::DELETE_ACCOUNT_URL_PARAM, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! $nonce || ! \wp_verify_nonce( $nonce, WooCommerce_My_Account::DELETE_ACCOUNT_URL_PARAM ) ) {
			return;
		}

		$sent    = WooCommerce_My_Account::send_delete_account_email( $user );
		$message = $sent ? __( 'Please check your email inbox for instructions on how to delete your account.', 'newspack-plugin' ) : __( 'Something went wrong.', 'newspack-plugin' );
		if ( \is_wp_error( $sent ) ) {
			$message = \wp_strip_all_tags( $sent->get_error_message() );
		}
		\wp_safe_redirect(
			\add_query_arg(
				[
					'message'  => \wp_strip_all_tags( \wp_unslash( $message ) ),
					'is_error' => ! $sent || \is_wp_error( $sent ),
				],
				\remove_query_arg( WooCommerce_My_Account::DELETE_ACCOUNT_URL_PARAM )
			)
		);
		exit;
	}

	/**
	 * Handle after delete account.
	 *
	 * @param int $user_id The user ID.
	 */
	public static function handle_after_delete_account( $user_id ) {
		\wp_safe_redirect(
			\add_query_arg(
				WooCommerce_My_Account::AFTER_ACCOUNT_DELETION_PARAM,
				1,
				\wc_get_account_endpoint_url( 'edit-account' )
			)
		);
		exit;
	}
}

My_Account_UI_V0::init();
