<?php
/**
 * Audience Donations Wizard
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Donations Wizard.
 */
class Audience_Donations extends Wizard {
	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience-donations';

	/**
	 * Parent slug.
	 *
	 * @var string
	 */
	protected $parent_slug = 'newspack-audience';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Audience Management / Donations', 'newspack-plugin' );
	}

	/**
	 * Add Donations page.
	 */
	public function add_page() {
		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Donations', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}

		parent::enqueue_scripts_and_styles();

		wp_enqueue_script( 'newspack-wizards' );

		\wp_localize_script(
			'newspack-wizards',
			'newspackAudienceDonations',
			[
				'can_use_name_your_price' => Donations::can_use_name_your_price(),
				'revenue_link'            => admin_url( 'admin.php?page=wc-reports' ),
			]
		);
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		// Get donations settings.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_donation_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		// Update donations settings.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug,
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_donation_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'amounts'             => [
						'required' => false,
					],
					'tiered'              => [
						'required'          => false,
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
					'disabledFrequencies' => [
						'required' => false,
					],
					'platform'            => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/emails/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'api_reset_donation_email' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
	}

	/**
	 * API endpoint for setting the donation settings.
	 *
	 * @param WP_REST_Request $request Request containing settings.
	 * @return WP_REST_Response with the latest settings.
	 */
	public function api_update_donation_settings( $request ) {
		return $this->update_donation_settings( $request->get_params() );
	}

	/**
	 * Handler for setting the donation settings.
	 *
	 * @param object $settings Donation settings.
	 * @return WP_REST_Response with the latest settings.
	 */
	public function update_donation_settings( $settings ) {
		$donations_response = Donations::set_donation_settings( $settings );
		if ( is_wp_error( $donations_response ) ) {
			return rest_ensure_response( $donations_response );
		}
		return \rest_ensure_response( $this->fetch_all_data() );
	}

	/**
	 * Fetch all data needed to render the Wizard
	 *
	 * @return Array
	 */
	public function fetch_all_data() {
		$platform = Donations::get_platform_slug();

		$args = [
			'platform_data'      => [
				'platform' => $platform,
			],
			'donation_data'      => Donations::get_donation_settings(),
			'donation_page'      => Donations::get_donation_page_info(),
			'product_validation' => $this->validate_donation_products(),
		];
		if ( 'wc' === $platform ) {
			$plugin_status    = true;
			$managed_plugins  = Plugin_Manager::get_managed_plugins();
			$required_plugins = [
				'woocommerce',
				'woocommerce-subscriptions',
			];
			foreach ( $required_plugins as $required_plugin ) {
				if ( 'active' !== $managed_plugins[ $required_plugin ]['Status'] ) {
					$plugin_status = false;
				}
			}
			$args = wp_parse_args(
				[
					'plugin_status' => $plugin_status,
				],
				$args
			);
		} elseif ( Donations::is_platform_nrh() ) {
			$nrh_config            = NRH::get_settings();
			$args['platform_data'] = wp_parse_args( $nrh_config, $args['platform_data'] );
		}
		return $args;
	}

	/**
	 * API endpoint for getting donation settings.
	 *
	 * @return WP_REST_Response containing info.
	 */
	public function api_get_donation_settings() {
		if ( Donations::is_platform_wc() ) {
			$required_plugins_installed = $this->check_required_plugins_installed();
			if ( is_wp_error( $required_plugins_installed ) ) {
				return rest_ensure_response( $required_plugins_installed );
			}
		}

		return rest_ensure_response( $this->fetch_all_data() );
	}

	/**
	 * Reset donation email template.
	 * We acheive this by trashing the email template post.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function api_reset_donation_email( $request ) {
		$params = $request->get_params();
		$id     = $params['id'];
		$email  = get_post( $id );

		if ( $email === null || $email->post_type !== Emails::POST_TYPE ) {
			return new WP_Error(
				'newspack_reset_donation_email_invalid_arg',
				esc_html__( 'Invalid argument: no email template matches the provided id.', 'newspack-plugin' ),
				[
					'status' => 400,
					'level'  => 'notice',
				]
			);
		}

		if ( ! wp_trash_post( $id ) ) {
			return new WP_Error(
				'newspack_reset_donation_email_reset_failed',
				esc_html__( 'Reset failed: unable to reset email template.', 'newspack-plugin' ),
				[
					'status' => 400,
					'level'  => 'notice',
				]
			);
		}

		return rest_ensure_response(
			Emails::get_emails( Reader_Activation::is_enabled() ? [] : array_values( Reader_Revenue_Emails::EMAIL_TYPES ), false )
		);
	}

	/**
	 * Check whether WooCommerce is installed and active.
	 *
	 * @return bool | WP_Error True on success, WP_Error on failure.
	 */
	protected function check_required_plugins_installed() {
		if ( ! function_exists( 'WC' ) ) {
			return new WP_Error(
				'newspack_missing_required_plugin',
				esc_html__( 'The WooCommerce plugin is not installed and activated. Install and/or activate it to access this feature.', 'newspack' ),
				[
					'status' => 400,
					'level'  => 'fatal',
				]
			);
		}

		return true;
	}

	/**
	 * Validate donation products for purchasability and restrictions.
	 *
	 * @return array Validation results for donation products.
	 */
	protected function validate_donation_products() {
		$validation_results = [];

		if ( ! Donations::is_platform_wc() ) {
			return $validation_results;
		}

		// Check if WooCommerce is active.
		if ( ! class_exists( '\Newspack\WooCommerce_Product_Validator' ) ) {
			return $validation_results;
		}

		$donation_product_ids = Donations::get_donation_product_child_products_ids( null );
		// Check if we have donation products configured.
		if ( empty( array_filter( $donation_product_ids ) ) ) {
			return $validation_results;
		}

		// Validate each donation product.
		foreach ( $donation_product_ids as $frequency => $product_id ) {
			if ( empty( $product_id ) ) {
				continue;
			}

			$validation = WooCommerce_Product_Validator::validate_product_purchasability( $product_id );

			if ( is_wp_error( $validation ) ) {
				$validation_results[ $frequency ] = [
					'product_id' => $product_id,
					'frequency'  => $frequency,
					'issues'     => [ $validation->get_error_message() ],
				];
			} else {
				$validation_results[ $frequency ] = [
					'product_id'   => $product_id,
					'product_name' => $validation['product_name'],
					'frequency'    => $frequency,
					'issues'       => $validation['issues'],
				];
			}
		}

		return $validation_results;
	}
}
