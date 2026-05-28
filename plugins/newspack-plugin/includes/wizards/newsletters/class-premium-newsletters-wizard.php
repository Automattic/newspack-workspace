<?php
/**
 * Premium Newsletters Wizard
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Premium Newsletters Wizard.
 */
class Premium_Newsletters_Wizard extends Wizard {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-premium-newsletters';

	/**
	 * Parent slug.
	 *
	 * @var string
	 */
	protected $parent_slug = 'newspack-newsletters';

	/**
	 * The parent menu item name.
	 *
	 * @var string
	 */
	public $parent_menu = 'edit.php?post_type=newspack_nl_cpt';

	/**
	 * Order relative to the Newspack Dashboard menu item.
	 *
	 * @var int
	 */
	public $parent_menu_order = 3;

	/**
	 * Run add_page() at priority 9 so our submenu entries are inserted before
	 * Newsletters_Wizard::add_page() (priority 10) appends "Settings".
	 *
	 * @var int
	 */
	protected $admin_menu_priority = 9;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! defined( 'NEWSPACK_NEWSLETTERS_PLUGIN_FILE' ) ) {
			return;
		}

		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );

		// Determine active menu items.
		add_filter( 'parent_file', [ $this, 'parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'submenu_file' ] );
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
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
			'/wizard/' . $this->slug . '/config',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_config' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
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
	}

	/**
	 * Parent file filter. Used to determine active menu items.
	 *
	 * Note: get_admin_page_parent() runs after this filter in menu-header.php and
	 * sets $parent_file by scanning $submenu directly. The hidden marker entry added
	 * in add_page() ensures it returns the correct Newsletters CPT parent. This filter
	 * is a belt-and-suspenders fallback.
	 *
	 * @param string $parent_file Parent file to be overridden.
	 * @return string
	 */
	public function parent_file( $parent_file ) {
		global $pagenow;
		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( 'admin.php' === $pagenow && $page === $this->slug ) {
			return $this->parent_menu;
		}
		return $parent_file;
	}

	/**
	 * Submenu file filter. Used to determine active submenu items.
	 *
	 * Must return the full URL stored in the visible $submenu entry so WordPress
	 * applies the 'current' class to the correct item.
	 *
	 * @param string $submenu_file Submenu file to be overridden.
	 * @return string
	 */
	public function submenu_file( $submenu_file ) {
		global $pagenow;
		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( 'admin.php' === $pagenow && $page === $this->slug ) {
			return 'admin.php?page=' . $this->slug;
		}
		return $submenu_file;
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Newspack / Premium Newsletters', 'newspack-plugin' );
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() || ! Content_Gate::is_newspack_feature_enabled() ) {
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
				'available_content_rules' => Content_Rules::get_premium_newsletter_rules(),
			]
		);

		\wp_localize_script(
			'newspack-wizards',
			'newspackAudience',
			[
				'available_products' => Content_Gate::get_purchasable_product_options(),
			]
		);
	}

	/**
	 * Add the "Premium newsletters" submenu item to the Newsletters menu.
	 */
	public function add_page() {
		global $submenu, $_registered_pages;

		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return;
		}

		// Register the page via an empty parent so it routes through admin.php.
		// This creates hookname 'admin_page_newspack-premium-newsletters' and gives
		// the page its admin.php?page=newspack-premium-newsletters URL.
		add_submenu_page(
			'',
			$this->get_name(),
			esc_html__( 'Premium', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);

		// Remove the $submenu[''] entry; otherwise get_admin_page_parent() finds our
		// slug there and sets $parent_file = '', collapsing the Newsletters menu.
		foreach ( ( $submenu[''] ?? [] ) as $key => $item ) {
			if ( $item[2] === $this->slug ) {
				unset( $submenu[''][ $key ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				break;
			}
		}

		if ( ! isset( $submenu[ $this->parent_menu ] ) ) {
			return;
		}

		// Hidden marker entry. Its sole purpose is to give get_admin_page_parent() a
		// slug match under the Newsletters CPT menu, which sets $parent_file correctly
		// and expands the menu. Requirements:
		//
		// [1] = $this->capability: user_can_access_admin_page() calls the same
		// get_admin_page_parent() and then scans $submenu for slug === $plugin_page,
		// returning current_user_can( $submenu_array[1] ). An impossible capability
		// here causes access to be denied, so the real capability is required.
		// [2] = $this->slug: matched by get_admin_page_parent() to set $parent_file.
		// [4] = 'hidden': WordPress admin CSS (display:none !important) prevents this
		// entry from rendering visibly; the real capability alone would render it.
		$submenu[ $this->parent_menu ][] = [ // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			'',
			$this->capability,
			$this->slug,
			$this->get_name(),
			'hidden',
		];

		// user_can_access_admin_page() also checks $_registered_pages using a hookname
		// computed from get_admin_page_parent()'s result ($this->parent_menu). That
		// hookname differs from the one add_submenu_page('', ...) registered ('admin_page_
		// newspack-premium-newsletters'). Register it so the check at plugin.php:2211
		// passes.
		$_registered_pages[ get_plugin_page_hookname( $this->slug, $this->parent_menu ) ] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Visible entry: uses the full URL so WordPress renders it as a direct href,
		// producing the correct admin.php?page=newspack-premium-newsletters link.
		$submenu[ $this->parent_menu ][] = [ // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			esc_html__( 'Premium', 'newspack-plugin' ),
			$this->capability,
			'admin.php?page=' . $this->slug,
		];
	}

	/**
	 * Get the gates.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_config() {
		$config = [
			'gates'  => Content_Gate::get_gates( Content_Gate::GATE_CPT, null, true ),
			'config' => [
				// Auto signup for restricted lists as soon as a user meets the access requirements. Defaults to true.
				'auto_signup' => boolval( get_option( 'newspack_premium_newsletters_auto_signup', 1 ) ),
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
	public function update_config( $request ) {
		$config = $request->get_param( 'config' );
		$updated = update_option( 'newspack_premium_newsletters_auto_signup', ( (bool) $config['auto_signup'] ? 1 : 0 ), false );
		return rest_ensure_response( $updated );
	}

	/**
	 * Create a gate.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_gate( $request ) {
		$gate = Content_Gate::create_gate( $request->get_param( 'gate' ), Content_Gate::GATE_CPT, true );
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
		$id   = $request->get_param( 'id' );
		$gate = get_post( $id );
		if ( ! $gate ) {
			return new \WP_Error( 'invalid_gate_id', __( 'Invalid gate ID.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		if ( Content_Gate::GATE_CPT !== $gate->post_type ) {
			return new \WP_Error( 'invalid_gate_type', __( 'Invalid gate type.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		if ( ! get_post_meta( $id, 'is_newsletter', true ) ) {
			return new \WP_Error( 'invalid_newsletter_gate', __( 'Invalid newsletter gate.', 'newspack-plugin' ), [ 'status' => 400 ] );
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
		$id   = $request->get_param( 'id' );
		$gate = get_post( $id );
		if ( ! $gate ) {
			return new \WP_Error( 'invalid_gate_id', __( 'Invalid gate ID.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		if ( Content_Gate::GATE_CPT !== $gate->post_type ) {
			return new \WP_Error( 'invalid_gate_type', __( 'Invalid gate type.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		if ( ! get_post_meta( $id, 'is_newsletter', true ) ) {
			return new \WP_Error( 'invalid_newsletter_gate', __( 'Invalid newsletter gate.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		$updated_gate = Content_Gate::update_gate_settings( $id, $request->get_param( 'gate' ) );
		if ( is_wp_error( $updated_gate ) ) {
			return $updated_gate;
		}
		return rest_ensure_response( $updated_gate );
	}
}
