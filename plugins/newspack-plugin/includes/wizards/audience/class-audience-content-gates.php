<?php
/**
 * Audience Content Gates Wizard
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Campaigns Wizard.
 */
class Audience_Content_Gates extends Wizard {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience-access-control';

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

		// Determine active menu items.
		add_filter( 'parent_file', [ $this, 'parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'submenu_file' ] );
	}

	/**
	 * Parent file filter. Used to determine active menu items.
	 *
	 * @param string $parent_file Parent file to be overridden.
	 * @return string
	 */
	public function parent_file( $parent_file ) {
		global $pagenow, $typenow;
		if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) && $typenow === Content_Gate::GATE_CPT ) {
			return $this->parent_slug;
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
		if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) && $typenow === Content_Gate::GATE_CPT ) {
			return $this->slug;
		}
		return $submenu_file;
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Audience Management / Access control', 'newspack-plugin' );
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() || ! $this->is_feature_enabled() ) {
			return;
		}

		parent::enqueue_scripts_and_styles();

		wp_enqueue_script( 'newspack-wizards' );

		\wp_localize_script(
			'newspack-wizards',
			'newspackAudienceContentGates',
			[
				'api'                     => '/' . NEWSPACK_API_NAMESPACE . '/wizard/' . $this->slug,
				'available_access_rules'  => Access_Rules::get_access_rules(),
				'available_content_rules' => Content_Rules::get_content_rules(),
				'edit_gate_layout_url'    => Content_Gate::get_edit_gate_layout_url(),
			]
		);

		\wp_localize_script(
			'newspack-wizards',
			'newspackAudience',
			[
				'available_products'       => Content_Gate::get_purchasable_product_options(),
				'institutional_access_url' => home_url( Content_Gate\IP_Access_Rule::ENDPOINT ),
				'content_gifting'          => [
					'can_use_gifting' => Content_Gifting::can_use_gifting(),
					'has_metering'    => Content_Gate::is_metering_enabled(),
				],
			]
		);

		// Enqueue content banner CSS for previews.
		wp_enqueue_style( 'newspack-content-banner', Newspack::plugin_url() . '/dist/content-banner.css', [], NEWSPACK_PLUGIN_VERSION );
	}

	/**
	 * Add Audience top-level and Content Gate subpage to the /wp-admin menu.
	 */
	public function add_page() {
		if ( ! $this->is_feature_enabled() ) {
			return;
		}

		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Access control', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Check feature flag status.
	 *
	 * @return bool
	 */
	public function is_feature_enabled() {
		return Content_Gate::is_newspack_feature_enabled();
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		if ( ! $this->is_feature_enabled() ) {
			return;
		}

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_config' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'advanced_settings' => [
						'type'       => 'object',
						'properties' => [
							'restrict_feeds' => [ 'type' => 'boolean' ],
							'newsletter_link_bypass_enabled' => [ 'type' => 'boolean' ],
						],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/content-gifting',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_content_gifting' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'button_label'         => [
						'type' => 'string',
					],
					'cta_label'            => [
						'type' => 'string',
					],
					'cta_product_id'       => [
						'type' => 'integer',
					],
					'cta_type'             => [
						'type' => 'string',
					],
					'cta_url'              => [
						'type' => 'string',
					],
					'enabled'              => [
						'type' => 'boolean',
					],
					'expiration_time'      => [
						'type' => 'integer',
					],
					'expiration_time_unit' => [
						'type' => 'string',
					],
					'interval'             => [
						'type' => 'string',
					],
					'limit'                => [
						'type' => 'integer',
					],
					'style'                => [
						'type' => 'string',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/countdown-banner',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_countdown_banner' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'button_label'   => [
						'type' => 'string',
					],
					'cta_label'      => [
						'type' => 'string',
					],
					'cta_product_id' => [
						'type' => 'integer',
					],
					'cta_type'       => [
						'type' => 'string',
					],
					'cta_url'        => [
						'type' => 'string',
					],
					'enabled'        => [
						'type' => 'boolean',
					],
					'style'          => [
						'type' => 'string',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_gate' ],
				'args'                => [
					'gate' => [
						'type'              => 'object',
						'sanitize_callback' => [ 'Newspack\Content_Gate_API', 'sanitize_gate' ],
						'properties'        => Content_Gate_API::$gate_properties,
					],
				],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_gate' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/priority',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_gate_priorities' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'gates' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'       => [
									'type'              => 'integer',
									'sanitize_callback' => 'absint',
								],
								'priority' => [
									'type'              => 'integer',
									'sanitize_callback' => 'absint',
								],
							],
						],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/(?P<id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_gate' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'gate' => [
						'type'              => 'object',
						'sanitize_callback' => [ 'Newspack\Content_Gate_API', 'sanitize_gate' ],
						'properties'        => Content_Gate_API::$gate_properties,
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/posts-search',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'posts_search' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'search'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'include'  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'per_page' => [
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
	}

	/**
	 * Get the gates.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_config() {
		$advanced = Content_Gate_Advanced_Settings::get_settings();
		// Cast to bool at the REST boundary so the TS type (boolean) is satisfied.
		// Internal storage remains as 0/1 integers — only the REST response payload is cast.
		$advanced_settings_response = [
			'restrict_feeds'                 => (bool) ( $advanced['restrict_feeds'] ?? false ),
			'newsletter_link_bypass_enabled' => (bool) ( $advanced['newsletter_link_bypass_enabled'] ?? false ),
		];
		$config = [
			'gates'  => Content_Gate::get_gates(),
			'config' => [
				'countdown_banner'  => Metering_Countdown::get_settings(),
				'content_gifting'   => Content_Gifting::get_settings(),
				'advanced_settings' => $advanced_settings_response,
				'has_newsletters'   => Reader_Activation::is_esp_configured(),
			],
		];
		return rest_ensure_response( $config );
	}

	/**
	 * Update advanced settings.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( $request ) {
		$settings = $request->get_param( 'advanced_settings' );
		$updated = Content_Gate_Advanced_Settings::update_settings( $settings );
		return rest_ensure_response( $updated );
	}

	/**
	 * Update content gifting settings.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_content_gifting( $request ) {
		$args = $request->get_params();

		if ( isset( $args['enabled'] ) ) {
			Content_Gifting::set_enabled( (bool) $args['enabled'] );
		}
		if ( isset( $args['limit'] ) ) {
			Content_Gifting::set_gifting_limit( (int) $args['limit'] );
		}
		if ( isset( $args['expiration_time'] ) ) {
			Content_Gifting::set_expiration_time( (int) $args['expiration_time'] );
		}
		if ( isset( $args['expiration_time_unit'] ) ) {
			Content_Gifting::set_expiration_time_unit( sanitize_text_field( $args['expiration_time_unit'] ) );
		}
		if ( isset( $args['interval'] ) ) {
			Content_Gifting::set_gifting_reset_interval( sanitize_text_field( $args['interval'] ) );
		}
		if ( isset( $args['cta_label'] ) ) {
			Content_Gifting_CTA::set_cta_label( sanitize_text_field( $args['cta_label'] ) );
		}
		if ( isset( $args['button_label'] ) ) {
			Content_Gifting_CTA::set_button_label( sanitize_text_field( $args['button_label'] ) );
		}
		if ( isset( $args['cta_type'] ) ) {
			Content_Gifting_CTA::set_cta_type( sanitize_text_field( $args['cta_type'] ) );
		}
		if ( isset( $args['cta_product_id'] ) ) {
			Content_Gifting_CTA::set_cta_product_id( (int) $args['cta_product_id'] );
		}
		if ( isset( $args['cta_url'] ) ) {
			Content_Gifting_CTA::set_cta_url( sanitize_text_field( $args['cta_url'] ) );
		}
		if ( isset( $args['style'] ) ) {
			Content_Gifting_CTA::set_style( sanitize_text_field( $args['style'] ) );
		}
		return rest_ensure_response( Content_Gifting::get_settings() );
	}

	/**
	 * Update countdown banner settings.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_countdown_banner( $request ) {
		$args = $request->get_params();
		return rest_ensure_response( Metering_Countdown::update_settings( $args ) );
	}

	/**
	 * Create a gate.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_gate( $request ) {
		$gate = Content_Gate::create_gate( $request->get_param( 'gate' ) );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return rest_ensure_response( Content_Gate::get_gate( $gate ) );
	}

	/**
	 * Delete a gate.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_gate( $request ) {
		$id = $request->get_param( 'id' );
		$gate = get_post( $id );
		if ( ! $gate ) {
			return new \WP_Error( 'invalid_gate_id', __( 'Invalid gate ID.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		if ( Content_Gate::GATE_CPT !== $gate->post_type ) {
			return new \WP_Error( 'invalid_gate_type', __( 'Invalid gate type.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		wp_delete_post( $id, true );
		return rest_ensure_response( true );
	}

	/**
	 * Update a gate.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_gate( $request ) {
		$gate = Content_Gate::update_gate_settings( $request->get_param( 'id' ), $request->get_param( 'gate' ) );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return rest_ensure_response( $gate );
	}

	/**
	 * Update multiple gates.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_gate_priorities( $request ) {
		$gates = $request->get_param( 'gates' );
		$updated_gates = [];
		foreach ( $gates as $gate ) {
			$updated_gate = Content_Gate::update_gate_setting( $gate['id'], 'gate_priority', $gate['priority'] );
			if ( is_wp_error( $updated_gate ) ) {
				return $updated_gate;
			}
			$updated_gates[] = $updated_gate;
		}
		return rest_ensure_response( $updated_gates );
	}

	/**
	 * REST callback: search published posts across all post types supported by content gates.
	 *
	 * Returns items in the shape consumed by ContentRuleControlTokenField:
	 * `[{ id, name, type_label }]`. Supports `search` (by title/content) and `include`
	 * (comma-separated IDs to hydrate saved tokens regardless of search match).
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function posts_search( $request ) {
		$post_types = array_column( Content_Restriction_Control::get_available_post_types(), 'value' );

		$args = [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $request->get_param( 'per_page' ),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		];

		$include = $request->get_param( 'include' );
		if ( ! empty( $include ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $include ) ) );
			if ( empty( $ids ) ) {
				return rest_ensure_response( [] );
			}
			// Broader status filter when hydrating saved tokens so the editor
			// keeps showing items whose status changed since the gate was saved.
			$args['post_status']    = [ 'publish', 'draft', 'pending', 'private', 'future' ];
			$args['post__in']       = $ids;
			$args['posts_per_page'] = min( count( $ids ), 100 );
			$args['orderby']        = 'post__in';
		}

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			// Numeric search: treat as a post ID lookup.
			if ( is_numeric( $search ) ) {
				$args['p'] = absint( $search );
			} else {
				$args['s'] = $search;
			}
		}

		$query = new \WP_Query( $args );

		$labels = [];
		foreach ( $post_types as $pt ) {
			$obj = get_post_type_object( $pt );
			$labels[ $pt ] = $obj && isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : $pt;
		}

		$data = array_map(
			function( $post ) use ( $labels ) {
				return [
					'id'         => (int) $post->ID,
					'name'       => $post->post_title !== '' ? $post->post_title : sprintf( '#%d', $post->ID ),
					'type_label' => $labels[ $post->post_type ] ?? $post->post_type,
				];
			},
			$query->posts
		);

		return rest_ensure_response( $data );
	}
}
