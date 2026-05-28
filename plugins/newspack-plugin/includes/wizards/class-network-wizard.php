<?php
/**
 * Network (Plugin) Wizard
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Wizards\Traits\Admin_Header;
use Newspack_Network\Admin as Newspack_Network_Admin;
use Newspack_Network\Site_Role as Newspack_Network_Site_Role;
use Newspack_Network\Hub\Distributor_Settings as Newspack_Network_Hub_Distributor_Settings;
use Newspack_Network\Node\Settings as Newspack_Network_Node_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Easy interface for setting up info.
 */
class Network_Wizard extends Wizard {

	use Admin_Header;

	/**
	 * Newspack Network plugin's Admin screen definitions (see constructor).
	 *
	 * @var array
	 */
	private $admin_screens = [];

	/**
	 * The parent menu item name.
	 *
	 * @var string
	 */
	public $parent_menu = 'newspack-network';

	/**
	 * Order relative to the Newspack Dashboard menu item.
	 *
	 * @var int
	 */
	public $parent_menu_order = 5;

	/**
	 * Adjust the menu after the Network plugin fully loads.
	 *
	 * @var int.
	 */
	protected $admin_menu_priority = 11;

	/**
	 * Constructor.
	 */
	public function __construct() {

		if ( ! is_plugin_active( 'newspack-network/newspack-network.php' ) ) {
			return;
		}

		// Admin screens based on Newspack Network plugin's admin pages and post types.
		$this->admin_screens = [
			// admin pages.
			'newspack-network'                       => __( 'Network / Settings', 'newspack-plugin' ),
			'newspack-network-event-log'             => __( 'Network / Event Log', 'newspack-plugin' ),
			'newspack-network-membership-plans'      => __( 'Network / Membership Plans', 'newspack-plugin' ),
			'newspack-network-distribution-settings' => __( 'Network / Content Distribution', 'newspack-plugin' ),
			'newspack-network-distributor-settings'  => __( 'Network / Distributor Settings', 'newspack-plugin' ),
			'newspack-network-node'                  => __( 'Network / Node Settings', 'newspack-plugin' ),
			// post types.
			'newspack_hub_nodes'                     => __( 'Network / Nodes', 'newspack-plugin' ),
			'np_hub_orders'                          => __( 'Network / Orders', 'newspack-plugin' ),
			'np_hub_subscriptions'                   => __( 'Network / Subscriptions', 'newspack-plugin' ),
		];

		// Hooks: admin_menu/add_page, admin_enqueue_scripts/enqueue_scripts_and_styles, admin_body_class/add_body_class .
		parent::__construct();

		// Display screen.
		if ( $this->is_wizard_page() ) {

			// Set active menu items for hidden screens.
			add_filter( 'parent_file', [ $this, 'parent_file' ] );
			add_filter( 'submenu_file', [ $this, 'submenu_file' ] );

			// Display header.
			$this->admin_header_init(
				[
					'title' => $this->get_name(),
					'tabs'  => $this->get_tabs(),
				]
			);
		}
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
	 * Get slug if we're currently viewing a Network screen.
	 *
	 * @return string
	 */
	private function get_screen_slug() {

		global $pagenow;

		static $screen_slug;
		if ( isset( $screen_slug ) ) {
			return $screen_slug;
		}
		$screen_slug = '';

		$sanitized_action    = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$sanitized_page      = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$sanitized_post_type = filter_input( INPUT_GET, 'post_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$sanitized_post_id   = filter_input( INPUT_GET, 'post', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		$screen_slug = match ( true ) {
			// admin page screen: admin.php?page={page} .
			'admin.php' === $pagenow && isset( $this->admin_screens[ $sanitized_page ] )
				=> $sanitized_page,
			// post type list screen: edit.php?post_type={post_type} .
			'edit.php' === $pagenow && isset( $this->admin_screens[ $sanitized_post_type ] )
				=> $sanitized_post_type,
			// add new node screen: post-new.php?post_type=newspack_hub_nodes .
			// note: assumes non-block editor, otherwise we need to not set this.
			'post-new.php' === $pagenow && 'newspack_hub_nodes' === $sanitized_post_type
				=> $sanitized_post_type,
			// edit node screen: post.php?post={ID}&action=edit
			// note: assumes non-block editor, otherwise we need to not set this.
			'post.php' === $pagenow && 'edit' === $sanitized_action && 'newspack_hub_nodes' === get_post_type( $sanitized_post_id )
				=> 'newspack_hub_nodes',
			default => '',
		};

		return $screen_slug;
	}

	/**
	 * Wrapper for Network Plugin's is_node/is_hub functions. Also called by Newspack_Dashboard.
	 *
	 * @return string Blank '', 'node', or 'hub'.
	 */
	public static function get_site_role(): string {

		static $site_role;
		if ( isset( $site_role ) ) {
			return $site_role;
		}

		// Function must exist and be callable.
		$fn_get_role = [ Newspack_Network_Site_Role::class, 'get' ];
		if ( ! is_callable( $fn_get_role ) ) {
			return '';
		}

		// Get the role.
		$site_role = call_user_func( $fn_get_role );

		// In the case where return value isn't a string (possibly option/value not set yet), return blank.
		if ( ! is_string( $site_role ) ) {
			return '';
		}

		return $site_role;
	}

	/**
	 * Get admin header tabs (if exists) for current sreen.
	 *
	 * @return array Tabs. Default []
	 */
	private function get_tabs() {

		if ( in_array( $this->get_screen_slug(), [ 'newspack-network', 'newspack-network-node', 'newspack-network-distributor-settings', 'newspack-network-distribution-settings' ], true ) ) {

			if ( '' === static::get_site_role() ) {
				return [];
			}

			$tabs = [
				[
					'textContent' => esc_html__( 'Site Role', 'newspack-plugin' ),
					'href'        => admin_url( 'admin.php?page=newspack-network' ),
				],
			];

			if ( 'node' === static::get_site_role() ) {
				$tabs[] = [
					'textContent' => esc_html__( 'Node Settings', 'newspack-plugin' ),
					'href'        => admin_url( 'admin.php?page=newspack-network-node' ),
				];
			}

			$tabs[] = [
				'textContent' => esc_html__( 'Content Distribution', 'newspack-plugin' ),
				'href'        => admin_url( 'admin.php?page=newspack-network-distribution-settings' ),
			];

			return $tabs;

		}

		return [];
	}

	/**
	 * Callback for 'admin_enqueue_scripts' => 'enqueue_scripts_and_styles' inside parent::__construct().
	 */
	public function enqueue_scripts_and_styles() {
		// No scripts or styles for this wizard besides whatever the Network Plugin itself enqueues.
	}

	/**
	 * Is a Network admin page or post_type being viewed. Needed for 'add_body_class' callback.
	 *
	 * @return bool Is current wizard page or not.
	 */
	public function is_wizard_page() {
		return isset( $this->admin_screens[ $this->get_screen_slug() ] );
	}

	/**
	 * Admin Menu hook to modify Network admin menu.
	 *
	 * The code below will modify the global $menu instead of overriding the different add_menu_page/add_submenu_page functions.
	 * It's just a lot easier to use the code below because the Network Plugin has different submenu pages for each of the
	 * different Site Roles (none, is_node, is_hub). It became difficult to try to rebuild the menu/submenus based on the current
	 * Site Role, some of which have a first submenu item of an admin page vs post type.
	 *
	 * Network Plugin's normal submenu loading order:
	 *
	 *  No site role:
	 *    MENU PARENT URL: admin.php?page=newspack-network
	 *      site role     - not shown: because wp hides single item menus.
	 *      node settings - not shown: because is_node is false.
	 *  Node role:
	 *    MENU PARENT URL: admin.php?page=newspack-network
	 *      site role     - is shown.
	 *      node settings - is shown.
	 *  Hub role:
	 *    MENU PARENT URL: edit.php?post_type=newspack_hub_nodes
	 *      nodes         (post type) - is shown: 'show_in_menu' is set to Network_Admin::PAGE_SLUG .
	 *      subscriptions (post type) - is shown: 'show_in_menu' is set to Network_Admin::PAGE_SLUG .
	 *      orders        (post type) - is shown: 'show_in_menu' is set to Network_Admin::PAGE_SLUG .
	 *      site role                 - is shown: this defines the parent $menu but shows 4th since register_post_type(s) run first.
	 *      event log                 - is shown.
	 *      membership plans          - is shown.
	 *      distributor settings      - is shown.
	 *
	 * @return void
	 */
	public function add_page() {
		global $menu;

		// Find the Newspack Network menu item in the admin menu.
		$network_key = null;
		foreach ( $menu as $k => $v ) {
			// Get the network key from the menu array.
			if ( $v[2] === $this->parent_menu ) {
				$network_key = $k;
				break;
			}
		}

		// Verify a key was found.
		if ( empty( $network_key ) ) {
			return;
		}

		// Adjust the network menu attributes.
		$icon = sprintf(
			'data:image/svg+xml;base64,%s',
			base64_encode( Newspack_UI_Icons::get_svg( 'globe' ) )
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$menu[ $network_key ][0] = __( 'Network', 'newspack-plugin' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$menu[ $network_key ][6] = $icon;

		// Adjust submenu items.
		if ( 'node' === static::get_site_role() ) {

			// Re-add "Node Settings" as hidden page.
			// Note: this will leave only "Site Role" in the submenu, so WordPress will collapse the menu.
			if ( is_callable( [ Newspack_Network_Node_Settings::class, 'render' ] ) ) {
				remove_submenu_page( $this->parent_menu, 'newspack-network-node' );
				$title = __( 'Node Settings', 'newspack-plugin' );
				$hook = add_submenu_page(
					'', // hidden.
					$title,
					__( 'Node Settings', 'newspack-plugin' ),
					'manage_options', // copied from original.
					'newspack-network-node',
					[ Newspack_Network_Node_Settings::class, 'render' ]
				);
				$this->set_html_title( $hook, $title );
			}
		}

		// Adjust submenu items.
		if ( 'hub' === static::get_site_role() ) {

			// Re-add "Site Role" as "Settings" and put at bottom of submenu.
			if ( is_callable( [ Newspack_Network_Admin::class, 'render_page' ] ) ) {
				remove_submenu_page( $this->parent_menu, 'newspack-network' );
				add_submenu_page(
					$this->parent_menu,
					__( 'Site Role', 'newspack-plugin' ),
					__( 'Settings', 'newspack-plugin' ),
					'manage_options', // copied from original.
					'newspack-network',
					[ Newspack_Network_Admin::class, 'render_page' ],
					5 // last submenu item.
				);
			}

			// Re-add "Distributor Settings" as hidden page.
			if ( is_callable( [ Newspack_Network_Hub_Distributor_Settings::class, 'render' ] ) ) {
				remove_submenu_page( $this->parent_menu, 'newspack-network-distributor-settings' );
				$title = __( 'Distributor Settings', 'newspack-plugin' );
				$hook = add_submenu_page(
					'', // hidden.
					$title,
					__( 'Distributor Settings', 'newspack-plugin' ),
					'manage_options', // copied from original.
					'newspack-network-distributor-settings',
					[ Newspack_Network_Hub_Distributor_Settings::class, 'render' ]
				);
				$this->set_html_title( $hook, $title );
			}
		}

		// Re-add "Content Distribution" as hidden page.
		if ( is_callable( [ \Newspack_Network\Content_Distribution\Admin::class, 'render' ] ) ) {
			remove_submenu_page( $this->parent_menu, 'newspack-network-distribution-settings' );
			$title = __( 'Content Distribution', 'newspack-plugin' );
			$hook = add_submenu_page(
				'', // hidden.
				$title,
				__( 'Content Distribution', 'newspack-plugin' ),
				'manage_options', // copied from original.
				'newspack-network-distribution-settings',
				[ \Newspack_Network\Content_Distribution\Admin::class, 'render' ]
			);
			$this->set_html_title( $hook, $title );
		}
	}

	/**
	 * Parent file filter. Used to determine active parent menu.
	 *
	 * @param string $parent_file Parent file to be overridden.
	 *
	 * @return string
	 */
	public function parent_file( $parent_file ) {

		global $_wp_menu_nopriv, $_wp_real_parent_file;

		$sanitized_page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// Note: get_admin_page_parent() in wp-admin/menu-header.php (line 50) could reset the returned parent_file.
		// Hack: Try to make the returned value not get reset by adding to _wp_ arrays.
		if ( empty( $parent_file ) && in_array( $sanitized_page, [ 'newspack-network-node', 'newspack-network-distributor-settings', 'newspack-network-distribution-settings' ] ) ) {
			$_wp_menu_nopriv[ $sanitized_page ] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$_wp_real_parent_file[ $sanitized_page ] = 'newspack-network'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return 'newspack-network';
		}

		return $parent_file;
	}

	/**
	 * Set HTML <title>
	 *
	 * In cases where the $submenu hidden item array ( $submenu[''] = array of hidden submenu items ) is defined after the parent_slug's
	 * item array ( $submenu['post type url or menu-slug'] = array of submenu items ), the HTML <title> will not be set and a debug.log
	 * deprecated notice will be written: PHP Deprecated:  strip_tags(): Passing null ... is deprecated in wp-admin/admin-header.php on line 36
	 *
	 * If the hidden array is defined before the parent slug array, then the HTML <title> is shown and no debug.log notice. To avoid this
	 * issue completely, so we don't need to worry about where things are in the $submenu array, we'll proactivally set the title here just in case.
	 *
	 * @param string $hook  Submenu hook.
	 * @param string $title HTML <title>.
	 *
	 * @return void
	 */
	public function set_html_title( $hook, $title ) {
		add_action(
			"load-{$hook}",
			fn() => $GLOBALS['title'] = $title // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found
		);
	}

	/**
	 * Submenu file filter. Used to determine active submenu items.
	 *
	 * For admin pages return slug only.
	 * For admin post types return url: edit.php?post_type={post_type}
	 *
	 * @param string $submenu_file Submenu file to be overridden.
	 *
	 * @return string
	 */
	public function submenu_file( $submenu_file ) {

		if ( in_array( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ), [ 'newspack-network-distributor-settings', 'newspack-network-distribution-settings' ] ) ) {
			return 'newspack-network';
		}

		return $submenu_file;
	}
}
