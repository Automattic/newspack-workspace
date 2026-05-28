<?php
/**
 * Enable Woos block email editor.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request, WP_REST_Response, WP_REST_Server;

/**
 * WooCommerce Emails class.
 */
class WooCommerce_Emails {
	/**
	 * Option to track if the feature is enabled.
	 *
	 * @var string
	 */
	const WOOCOMMERCE_EMAIL_EDITOR_OPTION = 'newspack_woocommerce_feature_block_email_editor_enabled';

	/**
	 * Option to determine whether email templates have been updated.
	 *
	 * @var string
	 */
	const WOOCOMMERCE_EMAILS_UPDATED_OPTION = 'newspack_woocommerce_block_editor_emails_updated_to_latest';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'option_woocommerce_feature_block_email_editor_enabled', [ __CLASS__, 'override_woocommerce_email_editor_option' ], 10, 2 );
		add_action( 'admin_init', [ __CLASS__, 'update_woocommerce_emails_to_latest' ] );
	}

	/**
	 * Force enable WooCommerce email editor.
	 *
	 * @param mixed  $value  Current value.
	 * @param string $option Option name.
	 */
	public static function override_woocommerce_email_editor_option( $value, $option ) {
		if ( ! self::is_active() ) {
			return $value;
		}
		return self::is_enabled();
	}

	/**
	 * Update the option to enable WooCommerce block email editor.
	 *
	 * @param bool $enable Whether to enable the feature.
	 */
	public static function set_enabled( $enable ) {
		update_option( self::WOOCOMMERCE_EMAIL_EDITOR_OPTION, $enable ? 'yes' : 'no' );
	}

	/**
	 * Check if WooCommerce block email editor is enabled. Default to enabled.
	 *
	 * @return string 'yes' if enabled, 'no' if not.
	 */
	public static function is_enabled() {
		return get_option( self::WOOCOMMERCE_EMAIL_EDITOR_OPTION, 'yes' );
	}

	/**
	 * Update any existing woocommerce block emails to the latest content if they haven't been customized.
	 */
	public static function update_woocommerce_emails_to_latest() {
		if ( ! self::is_active() || ! class_exists( '\Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmails' ) ) {
			return;
		}
		if ( 'yes' === self::is_enabled() && 'v1' !== get_option( self::WOOCOMMERCE_EMAILS_UPDATED_OPTION, '' ) ) {
			$email_ids              = \Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmails::get_transactional_emails();
			$email_template_manager = \Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmailPostsManager::get_instance();
			foreach ( $email_ids as $email_id ) {
				$template_id = $email_template_manager->get_email_template_post_id( $email_id );
				if ( ! $template_id ) {
					continue;
				}
				$publish_date       = get_the_date( 'Y-m-d H:i:s', $template_id );
				$last_modified_date = get_the_modified_date( 'Y-m-d H:i:s', $template_id );
				// Template has not been modified, so delete the post so we can regenerate the template.
				if ( $publish_date === $last_modified_date ) {
					wp_delete_post( $template_id, true );
				}
			}
			delete_transient( 'wc_email_editor_initial_templates_generated' );
			$email_template_generator = new \Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmailPostsGenerator();
			$email_template_generator->initialize();
			update_option( self::WOOCOMMERCE_EMAILS_UPDATED_OPTION, 'v1' );
		}
	}

	/**
	 * Whether email enhancements are active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		/**
		 * Enables Newspack WooCommerce email enhancements including
		 * improved templates and transactional email customizations.
		 *
		 * @constant NEWSPACK_EMAIL_ENHANCEMENTS
		 * @type     bool
		 * @default  Email enhancements disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_EMAIL_ENHANCEMENTS', true );
		 */
		return defined( 'NEWSPACK_EMAIL_ENHANCEMENTS' ) && NEWSPACK_EMAIL_ENHANCEMENTS;
	}
}
WooCommerce_Emails::init();
