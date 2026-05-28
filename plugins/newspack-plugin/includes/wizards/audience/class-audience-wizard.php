<?php
/**
 * Audience Wizard
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\{
	Memberships,
	Newsletters,
	Reader_Activation
};
use Newspack_Newsletters_Subscription;
use WP_Error, WP_REST_Request, WP_REST_Response, WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Wizard.
 */
class Audience_Wizard extends Wizard {

	/**
	 * Option to skip campaign setup.
	 *
	 * @var string
	 */
	const SKIP_CAMPAIGN_SETUP_OPTION = '_newspack_ras_skip_campaign_setup';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience';

	/**
	 * The parent menu item name.
	 *
	 * @var string
	 */
	public $parent_menu = 'newspack-audience';

	/**
	 * Parent menu order relative to the Newspack Dashboard menu item.
	 *
	 * @var int
	 */
	public $parent_menu_order = 1;

	/**
	 * Audience Configuration Constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );

		// Determine active menu items.
		add_filter( 'parent_file', [ $this, 'parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'submenu_file' ] );
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return Reader_Activation::is_enabled() ?
			esc_html__( 'Audience Management / Configuration', 'newspack-plugin' ) :
			esc_html__( 'Audience Management / Setup', 'newspack-plugin' );
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}
		parent::enqueue_scripts_and_styles();
		$salesforce_settings = Salesforce::get_salesforce_settings();
		$data = [
			'has_memberships'         => Memberships::is_active(),
			'reader_activation_url'   => admin_url( 'admin.php?page=newspack-audience#/' ),
			'esp_metadata_fields'     => Reader_Activation\Sync\Metadata::get_default_fields(),
			'can_use_salesforce'      => ! empty( $salesforce_settings['client_id'] ),
			'salesforce_redirect_url' => Salesforce::get_redirect_url(),
			'available_products'      => Content_Gate::get_purchasable_product_options(),
		];

		if ( method_exists( 'Newspack\Newsletters\Subscription_Lists', 'get_add_new_url' ) ) {
			$data['new_subscription_lists_url'] = Newsletters\Subscription_Lists::get_add_new_url();
		}

		if ( method_exists( 'Newspack_Newsletters_Subscription', 'get_lists' ) ) {
			$data['available_newsletter_lists'] = Newspack_Newsletters_Subscription::get_lists();
		}

		$newspack_popups = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-popups' );

		if ( $newspack_popups->is_configured() ) {
			$data['preview_query_keys'] = $newspack_popups->preview_query_keys();
			$data['preview_post']       = $newspack_popups->preview_post();
			$data['preview_archive']    = $newspack_popups->preview_archive();
		}

		$data['is_skipped_campaign_setup'] = Reader_Activation::is_skipped( 'ras_campaign' );

		$data['content_gifting'] = [
			'can_use_gifting' => Content_Gifting::can_use_gifting( true ),
			'has_metering'    => Content_Gate::is_metering_enabled( Memberships::GATE_CPT ),
		];

		wp_enqueue_script( 'newspack-wizards' );

		wp_localize_script(
			'newspack-wizards',
			'newspackAudience',
			$data
		);

		// Enqueue content banner CSS for previews.
		wp_enqueue_style( 'newspack-content-banner', Newspack::plugin_url() . '/dist/content-banner.css', [], NEWSPACK_PLUGIN_VERSION );
	}

	/**
	 * Add Audience top-level and Configuration subpage to the /wp-admin menu.
	 */
	public function add_page() {
		$icon = sprintf(
			'data:image/svg+xml;base64,%s',
			base64_encode( Newspack_UI_Icons::get_svg( 'people' ) )
		);

		add_menu_page(
			$this->get_name(),
			__( 'Audience', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ],
			$icon
		);
		add_submenu_page(
			$this->slug,
			$this->get_name(),
			Reader_Activation::is_enabled() ?
				__( 'Configuration', 'newspack-plugin' ) :
				__( 'Setup', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/audience-management',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_reader_activation_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/audience-management',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_reader_activation_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/audience-management/activate',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_activate_reader_activation' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/audience-management/emails/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'api_reset_reader_activation_email' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/audience-management/skip',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_skip_prerequisite' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'prerequisite' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'skip'         => [
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
				],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/content-gating',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_content_gating_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/content-gating',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_content_gating_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		// Get Salesforce settings.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/salesforce',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_salesforce_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		// Save Salesforce settings.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/salesforce',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_salesforce_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'client_id'     => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'client_secret' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'access_token'  => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'refresh_token' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Get payment settings data.
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/payment',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_payment_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		// Save basic data about reader revenue platform.
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/payment',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_payment_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'platform'                   => [
						'sanitize_callback' => 'Newspack\newspack_clean',
						'validate_callback' => [ $this, 'api_validate_platform' ],
					],
					'nrh_organization_id'        => [
						'sanitize_callback' => 'Newspack\newspack_clean',
						'validate_callback' => [ $this, 'api_validate_not_empty' ],
					],
					'nrh_custom_domain'          => [
						'sanitize_callback' => 'Newspack\newspack_clean',
					],
					'nrh_salesforce_campaign_id' => [
						'sanitize_callback' => 'Newspack\newspack_clean',
					],
					'donor_landing_page'         => [
						'sanitize_callback' => 'Newspack\newspack_clean',
					],
				],
			]
		);

		// Get billing fields info.
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/billing-fields',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_billing_fields' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		// Update billing fields info.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/billing-fields',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_billing_fields' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'billing_fields' => [
						'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						'validate_callback' => [ $this, 'api_validate_not_empty' ],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/checkout-configuration',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ Reader_Activation::class, 'get_checkout_configuration' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/checkout-configuration',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_checkout_configuration' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'billing_fields' => [
						'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						'validate_callback' => [ $this, 'api_validate_not_empty' ],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/subscription-settings',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_subscription_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/subscription-settings',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_subscription_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		// Cover fees settings.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/cover-fees',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_cover_fees_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/cover-fees',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_cover_fees_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'fee_multiplier'                     => [
						'sanitize_callback' => 'Newspack\newspack_clean',
						'validate_callback' => function ( $value ) {
							if ( (float) $value > 10 ) {
								return new WP_Error(
									'newspack_invalid_param',
									__( 'Fee multiplier must be smaller than 10.', 'newspack' )
								);
							}
							return true;
						},
					],
					'fee_static'                         => [
						'sanitize_callback' => 'Newspack\newspack_clean',
					],
					'allow_covering_fees'                => [
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
					'allow_covering_fees_default'        => [
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
					'allow_covering_fees_label'          => [
						'sanitize_callback' => 'Newspack\newspack_clean',
					],
					'allow_covering_fees_donations_only' => [
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
				],
			]
		);

		// Save Stripe info.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/payment/stripe/',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_stripe_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'activate'      => [
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
					'enabled'       => [
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
					'location_code' => [
						'sanitize_callback' => 'Newspack\newspack_clean',
					],
				],
			]
		);

		// Save payment gatway info.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/payment/gateway/',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_gateway_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'activate' => [
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
					'enabled'  => [
						'sanitize_callback' => 'Newspack\newspack_string_to_bool',
					],
					'slug'     => [
						'sanitize_callback' => 'Newspack\newspack_clean',
					],
				],
			]
		);
	}

	/**
	 * Get reader activation settings.
	 *
	 * @return WP_REST_Response
	 */
	public function api_get_reader_activation_settings() {
		return rest_ensure_response(
			[
				'config'               => Reader_Activation::get_settings(),
				'prerequisites_status' => Reader_Activation::get_prerequisites_status(),
				'memberships'          => self::get_memberships_settings(),
				'can_esp_sync'         => Reader_Activation\Contact_Sync::has_one_syncable_integration( true ),
			]
		);
	}

	/**
	 * Update reader activation settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function api_update_reader_activation_settings( $request ) {
		$args = $request->get_params();
		foreach ( $args as $key => $value ) {
			Reader_Activation::update_setting( $key, $value );
		}

		return rest_ensure_response(
			[
				'config'               => Reader_Activation::get_settings(),
				'prerequisites_status' => Reader_Activation::get_prerequisites_status(),
				'memberships'          => self::get_memberships_settings(),
				'can_esp_sync'         => Reader_Activation\Contact_Sync::has_one_syncable_integration( true ),
			]
		);
	}

	/**
	 * Reset reader activation email template.
	 * We acheive this by trashing the email template post.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function api_reset_reader_activation_email( $request ) {
		$params = $request->get_params();
		$id     = $params['id'];
		$email  = get_post( $id );

		if ( $email === null || $email->post_type !== Emails::POST_TYPE ) {
			return new WP_Error(
				'newspack_reset_reader_activation_email_invalid_arg',
				esc_html__( 'Invalid argument: no email template matches the provided id.', 'newspack-plugin' ),
				[
					'status' => 400,
					'level'  => 'notice',
				]
			);
		}

		if ( ! \wp_trash_post( $id ) ) {
			return new WP_Error(
				'newspack_reset_reader_activation_email_reset_failed',
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
	 * Activate reader activation and publish RAS prompts/segments.
	 *
	 * @param WP_REST_Request $request WP Rest Request object.
	 * @return WP_REST_Response
	 */
	public function api_activate_reader_activation( WP_REST_Request $request ) {
		$skip_activation = $request->get_param( 'skip_activation' ) ?? false;
		$response = $skip_activation ? true : Reader_Activation::activate();

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( [ 'message' => $response->get_error_message() ], 400 );
		}

		if ( true === $response ) {
			Reader_Activation::update_setting( 'enabled', true );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Activate reader activation and publish RAS prompts/segments.
	 *
	 * @param WP_REST_Request $request WP Rest Request object.
	 * @return WP_REST_Response
	 */
	public function api_skip_prerequisite( WP_REST_Request $request ) {
		$preqrequisite       = $request->get_param( 'prerequisite' );
		$skip                = $request->get_param( 'skip' );
		$skip_campaign_setup = Reader_Activation::skip( $preqrequisite, $skip );
		if ( ! $skip_campaign_setup ) {
			return new WP_REST_Response( [ 'message' => __( 'Error skipping prerequisite.', 'newspack-plugin' ) ], 400 );
		}

		return rest_ensure_response(
			[
				'config'               => Reader_Activation::get_settings(),
				'prerequisites_status' => Reader_Activation::get_prerequisites_status(),
				'memberships'          => self::get_memberships_settings(),
				'can_esp_sync'         => Reader_Activation\Contact_Sync::has_one_syncable_integration( true ),
			]
		);
	}

	/**
	 * Get content gating settings.
	 *
	 * @return WP_REST_Response
	 */
	public function api_get_content_gating_settings() {
		return rest_ensure_response( self::get_memberships_settings() );
	}

	/**
	 * Update content gating settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_update_content_gating_settings( $request ) {
		$args = $request->get_params();
		if ( isset( $args['require_all_plans'] ) ) {
			Memberships::set_require_all_plans_setting( (bool) $args['require_all_plans'] );
		}
		if ( isset( $args['show_on_subscription_tab'] ) ) {
			Memberships::set_show_on_subscription_tab_setting( (bool) $args['show_on_subscription_tab'] );
		}
		if ( isset( $args['countdown_banner'] ) ) {
			Metering_Countdown::update_settings( $args['countdown_banner'] );
		}
		if ( isset( $args['content_gifting'] ) ) {
			if ( isset( $args['content_gifting']['enabled'] ) ) {
				Content_Gifting::set_enabled( (bool) $args['content_gifting']['enabled'] );
			}
			if ( isset( $args['content_gifting']['limit'] ) ) {
				Content_Gifting::set_gifting_limit( (int) $args['content_gifting']['limit'] );
			}
			if ( isset( $args['content_gifting']['expiration_time'] ) ) {
				Content_Gifting::set_expiration_time( (int) $args['content_gifting']['expiration_time'] );
			}
			if ( isset( $args['content_gifting']['expiration_time_unit'] ) ) {
				Content_Gifting::set_expiration_time_unit( sanitize_text_field( $args['content_gifting']['expiration_time_unit'] ) );
			}
			if ( isset( $args['content_gifting']['interval'] ) ) {
				Content_Gifting::set_gifting_reset_interval( sanitize_text_field( $args['content_gifting']['interval'] ) );
			}
			if ( isset( $args['content_gifting']['cta_label'] ) ) {
				Content_Gifting_CTA::set_cta_label( sanitize_text_field( $args['content_gifting']['cta_label'] ) );
			}
			if ( isset( $args['content_gifting']['button_label'] ) ) {
				Content_Gifting_CTA::set_button_label( sanitize_text_field( $args['content_gifting']['button_label'] ) );
			}
			if ( isset( $args['content_gifting']['cta_type'] ) ) {
				Content_Gifting_CTA::set_cta_type( sanitize_text_field( $args['content_gifting']['cta_type'] ) );
			}
			if ( isset( $args['content_gifting']['cta_product_id'] ) ) {
				Content_Gifting_CTA::set_cta_product_id( (int) $args['content_gifting']['cta_product_id'] );
			}
			if ( isset( $args['content_gifting']['cta_url'] ) ) {
				Content_Gifting_CTA::set_cta_url( sanitize_text_field( $args['content_gifting']['cta_url'] ) );
			}
			if ( isset( $args['content_gifting']['style'] ) ) {
				Content_Gifting_CTA::set_style( sanitize_text_field( $args['content_gifting']['style'] ) );
			}
		}
		return rest_ensure_response( self::get_memberships_settings() );
	}

	/**
	 * API endpoint to get Salesforce settings.
	 *
	 * @return WP_REST_Response with Salesforce settings.
	 */
	public function api_get_salesforce_settings() {
		return \rest_ensure_response( Salesforce::get_salesforce_settings() );
	}

	/**
	 * API endpoint for setting Salesforce settings.
	 *
	 * @param WP_REST_Request $request Request containing settings.
	 * @return WP_REST_Response with the latest settings.
	 */
	public function api_update_salesforce_settings( $request ) {
		$salesforce_response = Salesforce::set_salesforce_settings( $request->get_params() );
		if ( is_wp_error( $salesforce_response ) ) {
			return rest_ensure_response( $salesforce_response );
		}
		return \rest_ensure_response( Salesforce::get_salesforce_settings() );
	}

	/**
	 * Validate platform ID.
	 *
	 * @param mixed $value A param value.
	 * @return bool
	 */
	public function api_validate_platform( $value ) {
		return in_array( $value, [ 'nrh', 'wc', 'other' ] );
	}

	/**
	 * Get payment settings.
	 *
	 * @return WP_REST_Response containing ad units info.
	 */
	public function api_get_payment_settings() {
		return \rest_ensure_response( $this->get_payment_data() );
	}

	/**
	 * Sanitize payment billing fields.
	 *
	 * @param mixed $value A param value.
	 *
	 * @return array
	 */
	public function sanitize_string_array( $value ) {
		return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [];
	}

	/**
	 * Set payment settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Boolean success.
	 */
	public function api_update_payment_settings( $request ) {
		$params = $request->get_params();

		Donations::set_platform_slug( $params['platform'] );

		// Update NRH settings.
		if ( Donations::is_platform_nrh() ) {
			NRH::update_settings( $params );
		}

		// Ensure that any Reader Revenue settings changed while the platform wasn't WC are persisted to WC products.
		if ( Donations::is_platform_wc() ) {
			Donations::update_donation_product( Donations::get_donation_settings() );
		}

		return \rest_ensure_response( $this->get_payment_data() );
	}

	/**
	 * API callback to get billing fields.
	 *
	 * @return WP_REST_Response Response.
	 */
	public function api_get_billing_fields() {
		return \rest_ensure_response( $this->get_billing_fields() );
	}

	/**
	 * Update billing fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function api_update_billing_fields( $request ) {
		$params = $request->get_params();
		Donations::update_billing_fields( $params['billing_fields'] );
		return \rest_ensure_response( $this->get_billing_fields() );
	}

	/**
	 * API callback to update checkout configuration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function api_update_checkout_configuration( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();
		$checkout_options = [
			[ Reader_Activation::OPTIONS_PREFIX . 'woocommerce_registration_required', $params['woocommerce_registration_required'] ],
			[ Reader_Activation::OPTIONS_PREFIX . 'woocommerce_post_checkout_success_text', $params['woocommerce_post_checkout_success_text'] ],
			[ Reader_Activation::OPTIONS_PREFIX . 'woocommerce_checkout_privacy_policy_text', $params['woocommerce_checkout_privacy_policy_text'] ],
			[ Reader_Activation::OPTIONS_PREFIX . 'woocommerce_post_checkout_registration_success_text', $params['woocommerce_post_checkout_registration_success_text'] ],
		];
		foreach ( $checkout_options as $option ) {
			[ $key, $value ] = $option;
			update_option( $key, $value );
		}
		return rest_ensure_response( Reader_Activation::get_checkout_configuration() );
	}

	/**
	 * Get billing fields data.
	 */
	public function get_billing_fields() {
		$wc_installed = 'active' === Plugin_Manager::get_managed_plugin_status( 'woocommerce' );

		$available_billing_fields = [];
		$order_notes_field = [];

		if ( $wc_installed && Donations::is_platform_wc() ) {
			$checkout        = new \WC_Checkout();
			$fields          = $checkout->get_checkout_fields();
			if ( ! empty( $fields['order']['order_comments'] ) ) {
				$order_notes_field = $fields['order']['order_comments'];
			}
			if ( ! empty( $fields['billing'] ) ) {
				$available_billing_fields = $fields['billing'];
			}
		}
		return [
			'available_billing_fields' => $available_billing_fields,
			'billing_fields'           => Donations::get_billing_fields(),
			'order_notes_field'        => $order_notes_field,
		];
	}

	/**
	 * Save Stripe settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function api_update_stripe_settings( $request ) {
		$params = $request->get_params();
		$result = $this->update_stripe_settings( $params );
		return \rest_ensure_response( $result );
	}

	/**
	 * Handler for setting Stripe settings.
	 *
	 * @param object $settings Stripe settings.
	 * @return WP_REST_Response with the latest settings.
	 */
	public function update_stripe_settings( $settings ) {
		if ( ! empty( $settings['activate'] ) ) {
			// If activating the Stripe Gateway plugin, let's enable it.
			$settings = [ 'enabled' => true ];
		}
		$result = Stripe_Connection::update_stripe_data( $settings );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get_payment_data();
	}

	/**
	 * Save WooPayments settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function api_update_gateway_settings( $request ) {
		$wc_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'woocommerce' );

		$params = $request->get_params();
		if ( ! isset( $params['slug'] ) ) {
			return \rest_ensure_response(
				new WP_Error( 'newspack_invalid_param', __( 'Gateway slug is required.', 'newspack' ) )
			);
		}
		$slug = $params['slug'];
		unset( $params['slug'] );
		$result = $wc_configuration_manager->update_gateway_settings( $slug, $params );
		return \rest_ensure_response( $result );
	}

	/**
	 * Get payment data for the wizard.
	 *
	 * @return Array
	 */
	public function get_payment_data() {
		$platform                 = Donations::get_platform_slug();
		$wc_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'woocommerce' );

		$args = [
			'payment_gateways' => [
				'stripe'               => Stripe_Connection::get_stripe_data(),
				'woocommerce_payments' => $wc_configuration_manager->gateway_data( 'woocommerce_payments' ),
				'ppcp-gateway'         => $wc_configuration_manager->gateway_data( 'ppcp-gateway' ),
			],
			'platform_data'    => [
				'platform' => $platform,
			],
			'is_ssl'           => is_ssl(),
			'errors'           => [],
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
	 * Get memberships settings.
	 *
	 * @return array
	 */
	private static function get_memberships_settings() {
		return [
			'edit_gate_url'            => Memberships::get_edit_gate_url(),
			'gate_status'              => get_post_status( Memberships::get_gate_post_id() ),
			'plans'                    => Memberships::get_plans(),
			'require_all_plans'        => Memberships::get_require_all_plans_setting(),
			'show_on_subscription_tab' => Memberships::get_show_on_subscription_tab_setting(),
			'countdown_banner'         => Metering_Countdown::get_settings(),
			'content_gifting'          => Content_Gifting::get_settings(),
		];
	}

	/**
	 * Parent file filter. Used to determine active menu items.
	 *
	 * @param string $parent_file Parent file to be overridden.
	 * @return string
	 */
	public function parent_file( $parent_file ) {
		global $pagenow, $typenow;

		$cpts = [
			Memberships::GATE_CPT,
			Emails::POST_TYPE,
		];

		if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) && in_array( $typenow, $cpts ) ) {
			return $this->slug;
		}

		return $parent_file;
	}

	/**
	 * Submenu file filter. Used to determine active submenu items.
	 *
	 * @param string $submenu_file Submenu file to be overridden.
	 * @return string
	 */
	public function submenu_file( $submenu_file ) {
		global $pagenow, $typenow;

		$cpts = [
			Memberships::GATE_CPT,
			Emails::POST_TYPE,
		];

		if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) && in_array( $typenow, $cpts ) ) {
			return $this->slug;
		}

		return $submenu_file;
	}

	/**
	 * Get subscription settings.
	 *
	 * @return WP_REST_Response Response with the settings.
	 */
	public function api_get_subscription_settings() {
		$settings = [
			'woocommerce_enable_subscription_confirmation' => get_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_enable_subscription_confirmation', false ),
			'woocommerce_subscription_confirmation_text'   => get_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_subscription_confirmation_text', Reader_Activation::get_subscription_confirmation_text() ),
			'woocommerce_enable_terms_confirmation'        => get_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_enable_terms_confirmation', false ),
			'woocommerce_terms_confirmation_text'          => get_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_terms_confirmation_text', Reader_Activation::get_terms_confirmation_text() ),
			'woocommerce_terms_confirmation_url'           => get_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_terms_confirmation_url', Reader_Activation::get_terms_confirmation_url() ),
		];
		return rest_ensure_response( $settings );
	}

	/**
	 * Update subscription settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response with the updated settings.
	 */
	public function api_update_subscription_settings( $request ) {
		$wc_installed = 'active' === Plugin_Manager::get_managed_plugin_status( 'woocommerce' );
		if ( ! $wc_installed ) {
			return $this->api_get_subscription_settings();
		}

		$params = $request->get_params();
		if ( isset( $params['woocommerce_enable_subscription_confirmation'] ) ) {
			update_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_enable_subscription_confirmation', (bool) $params['woocommerce_enable_subscription_confirmation'] );
		}

		if ( isset( $params['woocommerce_subscription_confirmation_text'] ) ) {
			update_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_subscription_confirmation_text', sanitize_text_field( $params['woocommerce_subscription_confirmation_text'] ) );
		}

		if ( isset( $params['woocommerce_enable_terms_confirmation'] ) ) {
			update_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_enable_terms_confirmation', (bool) $params['woocommerce_enable_terms_confirmation'] );
		}

		if ( isset( $params['woocommerce_terms_confirmation_text'] ) ) {
			update_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_terms_confirmation_text', sanitize_text_field( $params['woocommerce_terms_confirmation_text'] ) );
		}

		if ( isset( $params['woocommerce_terms_confirmation_url'] ) ) {
			update_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'woocommerce_terms_confirmation_url', sanitize_url( $params['woocommerce_terms_confirmation_url'] ) );
		}

		return $this->api_get_subscription_settings();
	}

	/**
	 * Get cover fees settings.
	 *
	 * @return WP_REST_Response Response with the settings.
	 */
	public function api_get_cover_fees_settings() {
		return rest_ensure_response(
			[
				'allow_covering_fees'                => boolval( get_option( 'newspack_donations_allow_covering_fees', true ) ),
				'allow_covering_fees_default'        => boolval( get_option( 'newspack_donations_allow_covering_fees_default', false ) ),
				'allow_covering_fees_label'          => get_option( 'newspack_donations_allow_covering_fees_label', '' ),
				'allow_covering_fees_donations_only' => boolval( get_option( 'newspack_donations_allow_covering_fees_donations_only', true ) ),
				'fee_multiplier'                     => get_option( 'newspack_blocks_donate_fee_multiplier', '2.9' ),
				'fee_static'                         => get_option( 'newspack_blocks_donate_fee_static', '0.3' ),
			]
		);
	}

	/**
	 * Update cover fees settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response with the updated settings.
	 */
	public function api_update_cover_fees_settings( $request ) {
		$params = $request->get_params();
		if ( isset( $params['allow_covering_fees'] ) ) {
			update_option( 'newspack_donations_allow_covering_fees', intval( $params['allow_covering_fees'] ) );
		}
		if ( isset( $params['allow_covering_fees_default'] ) ) {
			update_option( 'newspack_donations_allow_covering_fees_default', intval( $params['allow_covering_fees_default'] ) );
		}
		if ( isset( $params['allow_covering_fees_label'] ) ) {
			update_option( 'newspack_donations_allow_covering_fees_label', sanitize_text_field( $params['allow_covering_fees_label'] ) );
		}
		if ( isset( $params['allow_covering_fees_donations_only'] ) ) {
			update_option( 'newspack_donations_allow_covering_fees_donations_only', intval( $params['allow_covering_fees_donations_only'] ) );
		}
		if ( isset( $params['fee_multiplier'] ) ) {
			update_option( 'newspack_blocks_donate_fee_multiplier', $params['fee_multiplier'] );
		}
		if ( isset( $params['fee_static'] ) ) {
			update_option( 'newspack_blocks_donate_fee_static', $params['fee_static'] );
		}
		return $this->api_get_cover_fees_settings();
	}
}
