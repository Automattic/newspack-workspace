<?php
/**
 * Newspack Emails Section.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\Wizards\Wizard_Section;
use Newspack\WooCommerce_Emails;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Emails Section Class.
 */
class Emails_Section extends Wizard_Section {
	/**
	 * Containing wizard slug.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_rest_routes() {
		if ( ! WooCommerce_Emails::is_active() ) {
			return;
		}
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'wizard/' . $this->wizard_slug . '/emails',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_email_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'wizard/' . $this->wizard_slug . '/emails',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_update_email_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'enable_woocommerce_email_editor' => [
						'type'              => 'boolean',
						'required'          => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],

			]
		);
	}

	/**
	 * Get email settings.
	 *
	 * @return array
	 */
	public static function api_get_email_settings(): array {
		$settings = [];
		if ( class_exists( 'WooCommerce' ) ) {
			$settings['admin_url']                       = admin_url( 'admin.php?page=wc-settings&tab=email' );
			$settings['enable_woocommerce_email_editor'] = 'yes' === WooCommerce_Emails::is_enabled();
		}
		return $settings;
	}

	/**
	 * API callback to update woocommerce email settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response Response.
	 */
	public static function api_update_email_settings( $request ) {
		if ( $request->has_param( 'enable_woocommerce_email_editor' ) ) {
			$enable = filter_var( $request->get_param( 'enable_woocommerce_email_editor' ), FILTER_VALIDATE_BOOLEAN );
			WooCommerce_Emails::set_enabled( $enable );
		}
		return rest_ensure_response( self::api_get_email_settings() );
	}
}
