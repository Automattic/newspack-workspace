<?php
/**
 * Newspack Multibranded site plugin administration screen handling.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site;

/**
 * Class to handle the plugin admin pages
 */
class Admin {
	const MULTI_BRANDED_PAGE_SLUG = 'newspack-multi-branded-sites';

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );

		Admin\User_Primary_Brand::init();
		Admin\Cat_Primary_Brand::init();
		Admin\Post_Primary_Brand::init();
		Admin\Prompt_Popups::init();
		Admin\Show_Page_On_Front::init();
		Admin\Filter_Posts::init();
	}

	/**
	 * Redirects the admin page to the Newspack settings page if Newspack plugin is installed.
	 *
	 * @return void
	 */
	public static function redirect_admin_page() {
		global $pagenow;
		if ( class_exists( 'Newspack\Newspack' ) && 'admin.php' === $pagenow && isset( $_GET['page'] ) && 0 === strpos( wp_unslash( $_GET['page'] ), self::MULTI_BRANDED_PAGE_SLUG ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_safe_redirect( admin_url( 'admin.php?page=newspack-settings#/additional-brands' ) );
			exit;
		}
	}

	/**
	 * Add or redirect the admin page.
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		// If using with the Newspack plugin, redirect to the Newspack settings page.
		if ( class_exists( 'Newspack\Newspack' ) ) {
			self::redirect_admin_page();
			return;
		}

		// Otherwise, add the standalone admin page.
		$icon        = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjE4cHgiIGhlaWdodD0iNjE4cHgiIHZpZXdCb3g9IjAgMCA2MTggNjE4IiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiPgogICAgPGcgaWQ9IlBhZ2UtMSIgc3Ryb2tlPSJub25lIiBzdHJva2Utd2lkdGg9IjEiIGZpbGw9Im5vbmUiIGZpbGwtcnVsZT0iZXZlbm9kZCI+CiAgICAgICAgPHBhdGggZD0iTTMwOSwwIEM0NzkuNjU2NDk1LDAgNjE4LDEzOC4zNDQyOTMgNjE4LDMwOS4wMDE3NTkgQzYxOCw0NzkuNjU5MjI2IDQ3OS42NTY0OTUsNjE4IDMwOSw2MTggQzEzOC4zNDM1MDUsNjE4IDAsNDc5LjY1OTIyNiAwLDMwOS4wMDE3NTkgQzAsMTM4LjM0NDI5MyAxMzguMzQzNTA1LDAgMzA5LDAgWiBNMTc0LDE3MSBMMTc0LDI2Mi42NzEzNTYgTDE3NS4zMDUsMjY0IEwxNzQsMjY0IEwxNzQsNDQ2IEwyNDEsNDQ2IEwyNDEsMzMwLjkxMyBMMzUzLjk5Mjk2Miw0NDYgTDQ0NCw0NDYgTDE3NCwxNzEgWiBNNDQ0LDI5OSBMMzg5LDI5OSBMNDEwLjQ3NzYxLDMyMSBMNDQ0LDMyMSBMNDQ0LDI5OSBaIE00NDQsMjM1IEwzMjcsMjM1IEwzNDguMjQ1OTE5LDI1NyBMNDQ0LDI1NyBMNDQ0LDIzNSBaIE00NDQsMTcxIEwyNjQsMTcxIEwyODUuMjkwNTEyLDE5MyBMNDQ0LDE5MyBMNDQ0LDE3MSBaIiBpZD0iQ29tYmluZWQtU2hhcGUiIGZpbGw9IiMyQTdERTEiPjwvcGF0aD4KICAgIDwvZz4KPC9zdmc+';
		$page_suffix = add_menu_page(
			__( 'Multibranded site', 'newspack-multibranded-site' ),
			__( 'Multibranded site', 'newspack-multibranded-site' ),
			'manage_options',
			self::MULTI_BRANDED_PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			$icon
		);

		add_action( 'load-' . $page_suffix, array( __CLASS__, 'admin_init' ) );
	}

	/**
	 * Renders the page content
	 *
	 * @return void
	 */
	public static function render_page() {
		echo '<div id="root"></div>';
	}

	/**
	 * Callback for the load admin page hook.
	 *
	 * @return void
	 */
	public static function admin_init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Add Newspack admin body class, necessary for wizard styling.
	 *
	 * @param string $classes Space-separated list of CSS classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = get_current_screen();

		$is_newspack_screen = ( 'toplevel_page_newspack-' === substr( $screen->base, 0, 23 ) );
		if ( ! $screen || ! $is_newspack_screen ) {
			return $classes;
		}

		$classes .= ' admin_page_newspack-multi-branded-sites';

		return $classes;
	}

	/**
	 * Enqueue admin page assets.
	 *
	 * @param string $handler Page handler.
	 *
	 * @return void
	 */
	public static function enqueue_scripts( $handler ) {
		if ( false === strpos( $handler, self::MULTI_BRANDED_PAGE_SLUG ) ) {
			return;
		}

		\wp_register_script(
			self::MULTI_BRANDED_PAGE_SLUG,
			plugins_url( '../dist/admin.js', __FILE__ ),
			array( 'wp-components', 'wp-api-fetch' ),
			filemtime( NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . 'dist/admin.js' ),
			true
		);

		$menus = array_map(
			function ( $menu ) {
				return array(
					'value' => $menu->term_id,
					'label' => $menu->name,
				);
			},
			wp_get_nav_menus()
		);

		$aux_data = array(
			'theme_colors'   => Customizations\Theme_Colors::get_registered_theme_colors(),
			'menu_locations' => get_registered_nav_menus(),
			'menus'          => $menus,
			'site'           => get_site_url(),
		);

		wp_localize_script(
			self::MULTI_BRANDED_PAGE_SLUG,
			'newspack_urls',
			array(
				'dashboard' => esc_url( admin_url( 'admin.php?page=' . self::MULTI_BRANDED_PAGE_SLUG ) ),
				'support'   => esc_url( 'https://help.newspack.com/' ),
			)
		);
		wp_localize_script( self::MULTI_BRANDED_PAGE_SLUG, 'newspack_aux_data', $aux_data );

		\wp_enqueue_script( self::MULTI_BRANDED_PAGE_SLUG );

		\wp_register_style(
			self::MULTI_BRANDED_PAGE_SLUG,
			plugins_url( '../dist/admin.css', __FILE__ ),
			array( 'wp-components' ),
			filemtime( NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . 'dist/admin.css' )
		);
		\wp_style_add_data( self::MULTI_BRANDED_PAGE_SLUG, 'rtl', 'replace' );
		\wp_enqueue_style( self::MULTI_BRANDED_PAGE_SLUG );

		\wp_enqueue_style( // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			'tachyons',
			'https://unpkg.com/tachyons@4.12.0/css/tachyons.min.css'
		);
	}
}
