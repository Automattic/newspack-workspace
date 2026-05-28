<?php
/**
 * Audience Subscriptions Wizard
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Subscriptions Wizard.
 */
class Audience_Subscriptions extends Wizard {
	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience-subscriptions';

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
		return esc_html__( 'Audience Management / Subscriptions', 'newspack-plugin' );
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/primary-product',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_primary_product' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'primary_product' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Update the primary product.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error The response object or error.
	 */
	public function api_update_primary_product( $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'woocommerce_not_active', __( 'WooCommerce is not active.', 'newspack-plugin' ) );
		}
		$primary_product = $request->get_param( 'primary_product' );
		if ( empty( $primary_product ) ) {
			Subscriptions_Tiers::set_primary_subscription_tier_product( null );
			return rest_ensure_response( [ 'success' => true ] );
		}

		$product = wc_get_product( $primary_product );
		if ( ! $product ) {
			return new \WP_Error( 'invalid_product', __( 'Invalid product.', 'newspack-plugin' ) );
		}
		Subscriptions_Tiers::set_primary_subscription_tier_product( $product );
		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Add Subscriptions page.
	 */
	public function add_page() {
		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Subscriptions', 'newspack-plugin' ),
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

		$primary_product = Subscriptions_Tiers::get_primary_subscription_tier_product();

		parent::enqueue_scripts_and_styles();
		wp_enqueue_script( 'newspack-wizards' );
		wp_localize_script(
			'newspack-wizards',
			'newspackAudienceSubscriptions',
			[
				'memberships_url'          => admin_url( 'edit.php?post_type=wc_membership_plan' ),
				'primary_product'          => $primary_product ? $primary_product->get_id() : '',
				'eligible_products'        => array_map(
					function( $product ) {
						return [
							'id'    => $product->get_id(),
							'title' => $product->get_title(),
						];
					},
					Subscriptions_Tiers::get_tier_eligible_products()
				),
				'upgrade_subscription_url' => Subscriptions_Tiers::get_upgrade_subscription_url(),
			]
		);
	}
}
