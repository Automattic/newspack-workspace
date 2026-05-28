<?php
/**
 * Listings (Plugin) Wizard
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Wizards\Traits\Admin_Header;
use Newspack_Listings\Core as Newspack_Listings_Core;
use Newspack_Listings\Settings as Newspack_Listings_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Easy interface for setting up info.
 */
class Listings_Wizard extends Wizard {

	use Admin_Header;

	/**
	 * Listings plugin's Admin screen definitions (see constructor).
	 *
	 * @var array
	 */
	private $admin_screens = [];

	/**
	 * Slug for current wizard screen.
	 *
	 * @var string
	 */
	private $screen_slug;

	/**
	 * Primary slug for these wizard screens.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-listings';

	/**
	 * The parent menu item name.
	 *
	 * @var string
	 */
	public $parent_menu = 'newspack-listings';

	/**
	 * Order relative to the Newspack Dashboard menu item.
	 *
	 * @var int
	 */
	public $parent_menu_order = 3;

	/**
	 * Constructor.
	 */
	public function __construct() {

		/**
		 * Path to the Newspack Listings plugin file.
		 * Defined by the Newspack Listings plugin when active.
		 *
		 * @constant NEWSPACK_LISTINGS_FILE
		 * @type     string
		 * @default  Listings plugin not installed
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_LISTINGS_FILE', '/path/to/plugin/file.php' );
		 */
		if ( ! defined( 'NEWSPACK_LISTINGS_FILE' ) ) {
			return;
		}

		// Define admin screens based on Newspack Listings plugin's admin pages and post types.
		$this->admin_screens = [
			// Admin post types.
			'newspack_lst_event'               => __( 'Listings / Events', 'newspack-plugin' ),
			'newspack_lst_generic'             => __( 'Listings / Generic Listings', 'newspack-plugin' ),
			'newspack_lst_mktplce'             => __( 'Listings / Marketplace Listings', 'newspack-plugin' ),
			'newspack_lst_place'               => __( 'Listings / Places', 'newspack-plugin' ),
			// Admin pages.
			'newspack-listings-settings-admin' => __( 'Listings / Settings', 'newspack-plugin' ),
		];

		// Remove Listings plugin's menu setup.
		remove_action( 'admin_menu', [ Newspack_Listings_Core::class, 'add_plugin_page' ] );

		// Hooks: admin_menu/add_page, admin_enqueue_scripts/enqueue_scripts_and_styles, admin_body_class/add_body_class.
		parent::__construct();

		add_filter( 'submenu_file', [ $this, 'submenu_file' ] );

		// Display screen.
		if ( $this->is_wizard_page() ) {

			$this->admin_header_init(
				[
					'title' => $this->get_name(),
				]
			);

		}
	}

	/**
	 * Add the Listings menu page. Called from parent constructor 'admin_menu'.
	 *
	 * Replaces Listings Plugin's 'admin_menu' action => Newspack_Listings\Core => 'add_plugin_page'
	 */
	public function add_page() {
		$icon = sprintf(
			'data:image/svg+xml;base64,%s',
			base64_encode( Newspack_UI_Icons::get_svg( 'curatedList' ) )
		);

		// Top-level menu item.
		add_menu_page(
			__( 'Newspack Listings', 'newspack-plugin' ),
			__( 'Listings', 'newspack-plugin' ),
			'edit_posts', // Copied from Listings plugin...see docblock note above.
			$this->slug,
			'',
			$icon
		);

		if ( is_callable( [ Newspack_Listings_Settings::class, 'create_admin_page' ] ) ) {
			// Settings menu link.
			add_submenu_page(
				$this->slug,
				__( 'Newspack Listings: Site-Wide Settings', 'newspack-plugin' ),
				__( 'Settings', 'newspack-plugin' ),
				'manage_options', // Copied from Listings plugin...see docblock note above.
				'newspack-listings-settings-admin',
				[ Newspack_Listings_Settings::class, 'create_admin_page' ]
			);
		}
	}

	/**
	 * Enqueue scripts and styles. Called by parent constructor 'admin_enqueue_scripts'.
	 */
	public function enqueue_scripts_and_styles() {
		// Don't output anything...scripts and styles are enqueued by Admin Header.
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
	 * Get slug if we're currently viewing a Listings screen.
	 *
	 * @return string
	 */
	private function get_screen_slug() {

		global $pagenow;

		if ( isset( $this->screen_slug ) ) {
			return $this->screen_slug;
		}

		$sanitized_page = sanitize_text_field( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sanitized_post_type = sanitize_text_field( $_GET['post_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'admin.php' === $pagenow && isset( $this->admin_screens[ $sanitized_page ] ) ) {
			// admin page screen: admin.php?page={page} .
			$this->screen_slug = $sanitized_page;
		} elseif ( 'edit.php' === $pagenow && isset( $this->admin_screens[ $sanitized_post_type ] ) ) {
			// post type list screen: edit.php?post_type={post_type} .
			$this->screen_slug = $sanitized_post_type;
		} else {
			$this->screen_slug = '';
		}

		return $this->screen_slug;
	}

	/**
	 * Is a Listings admin page or post_type being viewed. Needed for parent constructor => 'add_body_class' callback.
	 *
	 * @return bool Is current wizard page or not.
	 */
	public function is_wizard_page() {
		return isset( $this->admin_screens[ $this->get_screen_slug() ] );
	}

	/**
	 * Menu file filter. Used to determine active menu items.
	 *
	 * Because the CPTs are registered with 'show_in_menu' => 'newspack-listings',
	 * the submenu_file is set to 'newspack-listings'. To work around this, we'll
	 * use the $pagenow and $_GET['post_type'] to determine the correct
	 * submenu_file.
	 *
	 * @param string $submenu_file Submenu file to be overridden.
	 *
	 * @return string
	 */
	public function submenu_file( $submenu_file ) {
		$cpts = array_keys( $this->admin_screens );
		global $pagenow;
		if ( ! in_array( $pagenow, [ 'post-new.php', 'post.php' ] ) ) {
			return $submenu_file;
		}
		$post_type = sanitize_text_field( $_GET['post_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id   = sanitize_text_field( $_GET['post'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_type && $post_id ) {
			$post_type = get_post_type( $post_id );
		}
		if ( ! $post_type ) {
			return $submenu_file;
		}
		foreach ( $cpts as $listing_cpt ) {
			if ( post_type_exists( $listing_cpt ) && strpos( $post_type, $listing_cpt ) !== false ) {
				return 'edit.php?post_type=' . $post_type;
			}
		}
		return $submenu_file;
	}
}
