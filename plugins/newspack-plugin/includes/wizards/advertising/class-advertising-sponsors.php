<?php
/**
 * Newspack's Advertising Wizard
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack_Sponsors\Settings;
use Newspack\Wizards\Traits\Admin_Header;

defined( 'ABSPATH' ) || exit;

/**
 * Easy interface for setting up general store info.
 */
class Advertising_Sponsors extends Wizard {

	use Admin_Header;

	/**
	 * Newspack Sponsors CPT name.
	 *
	 * @var string
	 */
	const CPT_NAME = 'newspack_spnsrs_cpt';

	/**
	 * Sponsors CPT list path.
	 *
	 * @var string
	 */
	const URL = 'edit.php?post_type=newspack_spnsrs_cpt';

	/**
	 * Advertising Page path.
	 *
	 * @var string
	 */
	const PARENT_URL = 'admin.php?page=newspack-ads-display-ads';

	/**
	 * The parent menu item name.
	 *
	 * @var string
	 */
	public $parent_menu = 'newspack-ads-display-ads';

	/**
	 * The capability required to access this wizard.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Use a late priority for this wizard's menu adjustments so they happen
	 * after the Sponsors Plugin is done adding it's menu items.
	 *
	 * @var int.
	 */
	protected $admin_menu_priority = 11;

	/**
	 * Advertising_Sponsors Constructor.
	 */
	public function __construct() {
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		if ( ! is_plugin_active( 'newspack-sponsors/newspack-sponsors.php' ) ) {
			return;
		}
		parent::__construct();

		add_action( 'register_post_type_args', [ $this, 'update_sponsors_cpt_args' ], 10, 2 );

		// Below filters are used to determine active menu items.
		add_filter( 'parent_file', [ $this, 'parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'submenu_file' ] );

		if ( $this->is_wizard_page() ) {
			// Initialize Wizards Admin Header.
			$this->admin_header_init(
				[
					'tabs'  => [
						[
							'textContent' => esc_html__( 'All Sponsors', 'newspack-plugin' ),
							'href'        => admin_url( static::URL ),
						],
						[
							'textContent' => esc_html__( 'Settings', 'newspack-plugin' ),
							'href'        => admin_url( static::URL . '&page=newspack-sponsors-settings-admin' ),
						],
					],
					'title' => $this->get_name(),
				]
			);
		}
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Advertising / Sponsors', 'newspack-plugin' );
	}

	/**
	 * Check if we are on the Sponsors CPT edit screen.
	 *
	 * @return bool true if browsing `edit.php?post_type=newspack_spnsrs_cpt`, false otherwise.
	 */
	public function is_wizard_page() {
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return false;
		}
		return isset( $_GET['post_type'] ) && $_GET['post_type'] === static::CPT_NAME; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}
		Newspack::load_common_assets();
		wp_register_style(
			$this->parent_menu,
			Newspack::plugin_url() . '/dist/billboard.css',
			$this->get_style_dependencies(),
			NEWSPACK_PLUGIN_VERSION
		);
		wp_style_add_data( $this->parent_menu, 'rtl', 'replace' );
		wp_enqueue_style( $this->parent_menu );
	}

	/**
	 * Move Sponsors CPT menu item under the ($) Advertising menu.
	 */
	public function add_page() {
		global $submenu;
		if ( isset( $submenu[ $this->parent_menu ] ) ) {
			$submenu[ $this->parent_menu ][] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				__( 'Sponsors', 'newspack-plugin' ),
				'manage_options',
				static::URL,
			);
		}

		// Register Settings page.
		add_submenu_page(
			'', // No parent menu item, means its not on the menu.
			__( 'Newspack Sponsors: Site-Wide Settings', 'newspack-sponsors' ),
			__( 'Settings', 'newspack-sponsors' ),
			'manage_options',
			'newspack-sponsors-settings-admin',
			[ Settings::class, 'create_admin_page' ]
		);
	}

	/**
	 * Update the Sponsor CPT args.
	 *
	 * @param array $args The sponsor args.
	 * @param array $post_type The post type name.
	 * @return array Modified sponsor cpt args.
	 */
	public function update_sponsors_cpt_args( $args, $post_type ) {
		if ( $post_type === static::CPT_NAME ) {
			// Move the CPT under the Advertising menu. Necessary to hide default Sponsors CPT menu item.
			$args['show_in_menu'] = static::PARENT_URL;
		}
		return $args;
	}

	/**
	 * Parent file filter. Used to determine active menu items.
	 *
	 * @param string $parent_file Parent file to be overridden.
	 * @return string
	 */
	public function parent_file( $parent_file ) {
		global $pagenow, $typenow;

		if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) && $typenow === static::CPT_NAME ) {
			return $this->parent_menu;
		}

		if ( isset( $_GET['page'] ) && $_GET['page'] === 'newspack-sponsors-settings-admin' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return static::PARENT_URL;
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
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'newspack-sponsors-settings-admin' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return static::URL;
		}

		return $submenu_file;
	}
}
