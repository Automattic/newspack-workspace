<?php
/**
 * Newsletters (Plugin) Wizard
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Wizards\Traits\Admin_Header;
use Newspack_Newsletters;
use Newspack_Newsletters_Settings;
use Newspack_Newsletters\Tracking\Admin as Newspack_Newsletters_Tracking_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Easy interface for setting up info.
 */
class Newsletters_Wizard extends Wizard {

	use Admin_Header;

	/**
	 * The slug of this wizard.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-newsletters';

	/**
	 * The capability required to access this wizard.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Newsletters plugin's Admin screen definitions (see constructor).
	 *
	 * @var array
	 */
	private $admin_screens = [];

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
	public $parent_menu_order = 2;

	/**
	 * Constructor.
	 */
	public function __construct() {

		if ( ! defined( 'NEWSPACK_NEWSLETTERS_PLUGIN_FILE' ) ) {
			return;
		}

		// Define admin screens based on Newspack Newsletters plugin's admin pages, post types, and taxonomies.
		$this->admin_screens = [
			// Admin pages.
			'newspack-newsletters-settings' => __( 'Newsletters / Settings', 'newspack-plugin' ),
			// Admin post types.
			'newspack_nl_cpt'               => __( 'Newsletters / All Newsletters', 'newspack-plugin' ),
			'newspack_nl_ads_cpt'           => __( 'Newsletters / Advertising', 'newspack-plugin' ),
			// Admin taxonomies.
			'newspack_nl_advertiser'        => __( 'Newsletters / Advertising', 'newspack-plugin' ),
			// Admin Newsletter Lists.
			'newspack_nl_list'              => __( 'Newsletters / Lists', 'newspack-plugin' ),
		];

		// Menu removals.
		remove_action( 'admin_menu', [ Newspack_Newsletters_Settings::class, 'add_plugin_page' ] );
		remove_action( 'admin_menu', [ Newspack_Newsletters_Tracking_Admin::class, 'add_settings_page' ] );

		// Customize the Newsletter ads menu titles.
		add_filter(
			'newspack_newsletters_ads_page_title',
			function() {
				return __( 'Newsletters Advertising', 'newspack-plugin' );
			}
		);
		add_filter(
			'newspack_newsletters_ads_menu_title',
			function() {
				return __( 'Advertising', 'newspack-plugin' );
			}
		);

		// Hooks: admin_menu/add_page, admin_enqueue_scripts/enqueue_scripts_and_styles, admin_body_class/add_body_class .
		parent::__construct();

		// Adjust post types.
		add_action( 'registered_post_type', [ $this, 'registered_post_type_newsletters' ] );

		// Adjust taxonomies.
		add_action( 'registered_taxonomy', [ $this, 'registered_taxonomy_advertiser' ] );

		// Set active menu items for hidden screens.
		add_filter( 'submenu_file', [ $this, 'submenu_file' ] );
		add_filter( 'parent_file', [ $this, 'parent_file' ] );

		// Display screen.
		if ( $this->is_wizard_page() ) {

			// Remove Newsletters branding (blue banner bar) from all screens.
			remove_action( 'admin_enqueue_scripts', [ Newspack_Newsletters::class, 'branding_scripts' ] );

			// Only show the admin header on non-wizard pages.
			if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
				// Add the admin header.
				$this->admin_header_init(
					[
						'title' => $this->get_name(),
						'tabs'  => $this->get_tabs(),
					]
				);
			}
		}

		// Wizard REST API.
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );

		// Modify newsletters settings URL.
		add_filter( 'newspack_newsletters_settings_url', [ $this, 'newsletters_settings_url' ] );
	}

	/**
	 * Modify newsletters settings URL.
	 */
	public function newsletters_settings_url() {
		return admin_url( 'edit.php?post_type=newspack_nl_cpt&page=newspack-newsletters' );
	}

	/**
	 * Register REST API endpoints.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_newsletters_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_newsletters_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/lists',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_newsletters_lists' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/tracking',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_tracking' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/tracking',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_tracking' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'click' => [
						'type'        => 'boolean',
						'description' => __( 'Whether click tracking is enabled.', 'newspack-plugin' ),
						'required'    => true,
					],
					'pixel' => [
						'type'        => 'boolean',
						'description' => __( 'Whether the tracking pixel is enabled.', 'newspack-plugin' ),
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Get lists of configured ESP.
	 */
	public static function api_get_newsletters_lists() {
		$newsletters_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-newsletters' );
		return $newsletters_configuration_manager->get_lists();
	}

	/**
	 * Get Newspack Newsletters setttings.
	 *
	 * @return object with the info.
	 */
	private static function get_newsletters_settings() {
		$newsletters_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-newsletters' );
		$settings                          = array_reduce(
			$newsletters_configuration_manager->get_settings(),
			function ( $acc, $value ) {
				$acc[ $value['key'] ] = $value;
				return $acc;
			},
			[]
		);
		return [
			'configured' => $newsletters_configuration_manager->is_configured(),
			'settings'   => $settings,
		];
	}

	/**
	 * Get Newspack Newsletters setttings API response.
	 *
	 * @return WP_REST_Response with the info.
	 */
	public function api_get_newsletters_settings() {
		return rest_ensure_response( self::get_newsletters_settings() );
	}

	/**
	 * Get Newspack Newsletters setttings.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response with the info.
	 */
	public function api_update_newsletters_settings( $request ) {
		$args                              = $request->get_params();
		$newsletters_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-newsletters' );
		$newsletters_configuration_manager->update_settings( $args );
		return $this->api_get_newsletters_settings();
	}

	/**
	 * Get tracking settings.
	 *
	 * @return WP_REST_Response with the info.
	 */
	public function api_get_tracking() {
		$tracking = [
			'click' => false,
			'pixel' => false,
		];
		if ( method_exists( 'Newspack_Newsletters\Tracking\Admin', 'is_tracking_click_enabled' ) ) {
			$tracking['click'] = Newspack_Newsletters_Tracking_Admin::is_tracking_click_enabled();
		}
		if ( method_exists( 'Newspack_Newsletters\Tracking\Admin', 'is_tracking_pixel_enabled' ) ) {
			$tracking['pixel'] = Newspack_Newsletters_Tracking_Admin::is_tracking_pixel_enabled();
		}
		return rest_ensure_response( $tracking );
	}

	/**
	 * Update tracking settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response with the info.
	 */
	public function api_update_tracking( $request ) {
		update_option( 'newspack_newsletters_use_click_tracking', intval( $request->get_param( 'click' ) ) );
		update_option( 'newspack_newsletters_use_tracking_pixel', intval( $request->get_param( 'pixel' ) ) );
		return rest_ensure_response( true );
	}

	/**
	 * Adjusts the Newsletters menu. Called from parent constructor 'admin_menu'.
	 */
	public function add_page() {
		// Remove catetory and tags. For remove_submenu_page() to match (===) on submenu slug: "&" in urls need be replaced with "&amp;".
		remove_submenu_page( 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT, 'edit-tags.php?taxonomy=category&amp;post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		remove_submenu_page( 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT, 'edit-tags.php?taxonomy=post_tag&amp;post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		remove_submenu_page( 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT, 'edit-tags.php?taxonomy=' . Newspack_Newsletters\Ads::ADVERTISER_TAX . '&amp;post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );

		add_submenu_page(
			'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			__( 'Newsletters Settings', 'newspack-plugin' ),
			__( 'Settings', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Enqueue scripts and styles. Called by parent constructor 'admin_enqueue_scripts'.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();

		if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
			return;
		}

		\wp_enqueue_script(
			'newspack-newsletters-wizard',
			Newspack::plugin_url() . '/dist/newsletters.js',
			$this->get_script_dependencies(),
			NEWSPACK_PLUGIN_VERSION,
			true
		);

		$data = [];
		if ( method_exists( 'Newspack\Newsletters\Subscription_Lists', 'get_add_new_url' ) ) {
			$data['new_subscription_lists_url'] = \Newspack\Newsletters\Subscription_Lists::get_add_new_url();
		}

		\wp_localize_script(
			'newspack-newsletters-wizard',
			'newspack_newsletters_wizard',
			$data
		);
	}

	/**
	 * Get the name for this current screen's wizard. Required by parent abstract.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html( $this->admin_screens[ $this->get_screen_slug() ] );
	}

	/**
	 * Get slug if we're currently viewing a Newsletters screen.
	 *
	 * @return string
	 */
	private function get_screen_slug() {

		global $pagenow;

		static $screen_slug;

		if ( isset( $screen_slug ) ) {
			return $screen_slug;
		}

		$sanitized_page      = sanitize_text_field( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sanitized_post_type = sanitize_text_field( $_GET['post_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sanitized_post_id   = sanitize_text_field( $_GET['post'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sanitized_taxonomy  = sanitize_text_field( $_GET['taxonomy'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'admin.php' === $pagenow && isset( $this->admin_screens[ $sanitized_page ] ) ) {
			// admin page screen: admin.php?page={page} .
			$screen_slug = $sanitized_page;
		} elseif ( 'edit.php' === $pagenow ) {
			if ( ! $sanitized_post_type ) {
				$sanitized_post_type = get_post_type( $sanitized_post_id );
			}
			if ( isset( $this->admin_screens[ $sanitized_post_type ] ) && isset( $this->admin_screens[ $sanitized_page ] ) ) {
				// post type with page: edit.php?post_type={post_type}&page={page} .
				$screen_slug = $sanitized_page;
			} elseif ( isset( $this->admin_screens[ $sanitized_post_type ] ) ) {
				// post type list screen: edit.php?post_type={post_type} .
				$screen_slug = $sanitized_post_type;
			} else {
				$screen_slug = '';
			}
		} elseif ( 'edit-tags.php' === $pagenow && isset( $this->admin_screens[ $sanitized_taxonomy ] ) && isset( $this->admin_screens[ $sanitized_post_type ] ) ) {
			// taxonomy list: edit-tags.php?taxonomy={taxonomy}&post_type={post_type} / phpcs:ignore Squiz.PHP.CommentedOutCode.Found .
			$screen_slug = $sanitized_taxonomy;
		} elseif ( 'term.php' === $pagenow && isset( $this->admin_screens[ $sanitized_taxonomy ] ) && isset( $this->admin_screens[ $sanitized_post_type ] ) ) {
			// taxonomy edit: term.php?taxonomy={taxonomy}&post_type={post_type}.... / phpcs:ignore Squiz.PHP.CommentedOutCode.Found .
			$screen_slug = $sanitized_taxonomy;
		} else {
			$screen_slug = '';
		}

		return $screen_slug;
	}

	/**
	 * Get admin header tabs (if exists) for current sreen.
	 *
	 * @return array Tabs. Default []
	 */
	private function get_tabs() {

		if ( in_array( $this->get_screen_slug(), [ 'newspack_nl_ads_cpt', 'newspack_nl_advertiser' ], true ) ) {
			// Check if user can access newsletters to determine tab URLs.
			$newsletters_post_type_object = get_post_type_object( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
			$can_edit_newsletters = current_user_can( $newsletters_post_type_object->cap->edit_posts );

			$tabs = [
				[
					'textContent' => esc_html__( 'Ads', 'newspack-plugin' ),
					'href'        => admin_url( 'edit.php?post_type=newspack_nl_ads_cpt' ),
				],
			];

			// Only show Advertisers tab if user can edit newsletters (otherwise it won't be accessible).
			if ( $can_edit_newsletters ) {
				$tabs[] = [
					'textContent'   => esc_html__( 'Advertisers', 'newspack-plugin' ),
					'href'          => admin_url( 'edit-tags.php?taxonomy=newspack_nl_advertiser&post_type=newspack_nl_cpt' ),
					// also force selected tab for url: term.php?taxonomy=newspack_nl_advertiser&tag_ID=32&post_type=newspack_nl_cpt...
					'forceSelected' => ( 'newspack_nl_advertiser' === $this->get_screen_slug() ),
				];
			}

			$tabs[] = [
				'textContent' => esc_html__( 'Tracking', 'newspack-plugin' ),
				'href'        => admin_url( 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT . '&page=' . $this->slug . '#/tracking' ),
			];

			return $tabs;
		}

		return [];
	}

	/**
	 * Is a Newsletters admin page or post_type being viewed. Needed for parent constructor => 'add_body_class' callback.
	 *
	 * @return bool Is current wizard page or not.
	 */
	public function is_wizard_page() {
		return isset( $this->admin_screens[ $this->get_screen_slug() ] );
	}

	/**
	 * Callback when Newsletters CPT is registered.
	 *
	 * @param string $post_type Post type to check.
	 * @return void
	 */
	public function registered_post_type_newsletters( $post_type ) {

		global $wp_post_types;

		if ( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== $post_type ) {
			return;
		}

		if ( empty( $wp_post_types[ $post_type ] ) ) {
			return;
		}

		// Change menu icon.
		$icon = sprintf(
			'data:image/svg+xml;base64,%s',
			base64_encode( Newspack_UI_Icons::get_svg( 'email' ) )
		);

		$wp_post_types[ $post_type ]->menu_icon = $icon;
	}

	/**
	 * Callback when Advertiser Taxonomy is registered.  Do not show in menu for IA Epic.
	 *
	 * @param string $taxonomy Taxonomy to check.
	 * @return void
	 */
	public function registered_taxonomy_advertiser( $taxonomy ) {

		global $wp_taxonomies;

		if ( Newspack_Newsletters\Ads::ADVERTISER_TAX !== $taxonomy ) {
			return;
		}

		if ( empty( $wp_taxonomies[ $taxonomy ] ) ) {
			return;
		}

		$wp_taxonomies[ $taxonomy ]->show_in_menu = false;
	}

	/**
	 * Menu file filter. Used to determine active menu items.
	 *
	 * @param string $submenu_file Submenu file to be overridden.
	 *
	 * @return string
	 */
	public function submenu_file( $submenu_file ) {
		// Check if user can access newsletters to determine where ads menu is placed.
		$newsletters_post_type_object = get_post_type_object( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		$can_edit_newsletters = current_user_can( $newsletters_post_type_object->cap->edit_posts );

		// Move newsletter ads menu file.
		if ( ! empty( $submenu_file ) && strpos( $submenu_file, 'newspack_nl_ads_cpt' ) !== false ) {
			// If user can edit newsletters, ads are in submenu. Otherwise, they're top-level.
			return $can_edit_newsletters ? 'edit.php?post_type=newspack_nl_ads_cpt' : $submenu_file;
		}
		// Move newsletter ads taxonomy menu submenu_file.
		if ( ! empty( $submenu_file ) && strpos( $submenu_file, 'newspack_nl_advertiser' ) !== false ) {
			return $can_edit_newsletters ? 'edit.php?post_type=newspack_nl_ads_cpt' : $submenu_file;
		}

		// Move new newsletter menu submenu_file.
		if ( 'post-new.php?post_type=newspack_nl_cpt' === $submenu_file ) {
			return 'edit.php?post_type=newspack_nl_cpt';
		}

		// Move newsletter subscription list submenu_file.
		if ( ! empty( $submenu_file ) && strpos( $submenu_file, 'newspack_nl_list' ) !== false ) {
			return $this->slug;
		}

		return $submenu_file;
	}

	/**
	 * Modify the parent file.
	 *
	 * @param string $parent_file Parent file to be overridden.
	 *
	 * @return string
	 */
	public function parent_file( $parent_file ) {
		// Check if user can access newsletters to determine where ads menu is placed.
		$newsletters_post_type_object = get_post_type_object( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		$can_edit_newsletters = current_user_can( $newsletters_post_type_object->cap->edit_posts );

		if (
			strpos( $parent_file, 'newspack_nl_ads_cpt' ) !== false || // Newsletter Ads.
			strpos( $parent_file, 'newspack_nl_advertiser' ) !== false    // Newsletter Advertisers.
		) {
			// If user can edit newsletters, ads appear under newsletters menu. Otherwise, they're top-level.
			return $can_edit_newsletters ? 'edit.php?post_type=newspack_nl_cpt' : $parent_file;
		}

		if ( strpos( $parent_file, 'newspack_nl_list' ) !== false ) { // Newsletter Subscription Lists.
			return 'edit.php?post_type=newspack_nl_cpt';
		}

		return $parent_file;
	}
}
