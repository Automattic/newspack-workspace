<?php
/**
 * The new Newspack admin: renders the Newspack Dashboard and Settings inside
 * WordPress's full-page admin frame (`@wordpress/boot`, WordPress 7.0+).
 * Mirrors the PHP that `@wordpress/build` generates for core's boot pages.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * New Newspack admin frame.
 */
final class Admin_App {
	const PAGE          = 'newspack-dashboard';
	const SETTINGS_PAGE = 'newspack-settings';
	const WIZARDS       = [ self::PAGE, self::SETTINGS_PAGE ];
	const MOUNT         = 'newspack-admin-app';

	/**
	 * Whether the boot module is available and the flag is on, computed once.
	 *
	 * @var bool|null
	 */
	private static ?bool $enabled = null;

	/**
	 * Settings tabs, computed once per request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $settings_tabs = null;

	/**
	 * Hook in.
	 */
	public static function init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_action( 'admin_menu', [ __CLASS__, 'add_load_hooks' ], 100 );
	}

	/**
	 * Take over the Dashboard and Settings pages once WordPress has checked
	 * access, set the screen and sent the admin headers.
	 */
	public static function add_load_hooks(): void {
		add_action( 'load-' . get_plugin_page_hookname( self::PAGE, '' ), [ __CLASS__, 'render_page' ] );
		add_action( 'load-' . get_plugin_page_hookname( self::SETTINGS_PAGE, self::PAGE ), [ __CLASS__, 'redirect_settings' ] );
	}

	/**
	 * Whether the new admin frame replaces the Newspack Dashboard and Settings.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( null !== self::$enabled ) {
			return self::$enabled;
		}
		/**
		 * Opens the Newspack Dashboard and Settings in WordPress's full-page
		 * admin frame, with Settings sections in its sidebar. Needs the
		 * `@wordpress/boot` script module from WordPress 7.0+; without it the
		 * classic pages are used.
		 *
		 * @constant NEWSPACK_NEW_ADMIN
		 * @type     bool
		 * @default  Classic Newspack admin pages
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NEW_ADMIN', true );
		 */
		self::$enabled = defined( 'NEWSPACK_NEW_ADMIN' ) && NEWSPACK_NEW_ADMIN && file_exists( self::boot_asset_file() );
		return self::$enabled;
	}

	/**
	 * Whether this request is the frame page and it renders the given wizard.
	 *
	 * @param string $slug Wizard slug.
	 * @return bool
	 */
	public static function serves( string $slug ): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::is_enabled() && self::PAGE === $page && in_array( $slug, self::WIZARDS, true );
	}

	/**
	 * Where a Settings request lands inside the frame, keeping its other query
	 * args (OAuth returns, `scrollTo`). Browsers carry the fragment across the
	 * redirect, so `#/seo` links still reach their section.
	 *
	 * @param array<string, mixed> $query The request's query args.
	 * @return string
	 */
	public static function settings_redirect_url( array $query ): string {
		$args = [];
		foreach ( $query as $key => $value ) {
			if ( 'page' !== $key && 'p' !== $key && is_scalar( $value ) ) {
				$args[ rawurlencode( (string) $key ) ] = rawurlencode( (string) $value );
			}
		}
		$args['p'] = rawurlencode( '/settings' );
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/**
	 * Path to the boot script module's asset file.
	 *
	 * @return string
	 */
	private static function boot_asset_file(): string {
		return ABSPATH . WPINC . '/js/dist/script-modules/boot/index.min.asset.php';
	}

	/**
	 * Version string for the app's own static files.
	 *
	 * @param string $file Path relative to this directory.
	 * @return string
	 */
	private static function file_version( string $file ): string {
		return (string) filemtime( __DIR__ . '/' . $file );
	}

	/**
	 * Send Settings requests to their route inside the frame.
	 */
	public static function redirect_settings(): void {
		wp_safe_redirect( self::settings_redirect_url( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		exit;
	}

	/**
	 * Print the frame in place of the classic Dashboard page.
	 */
	public static function render_page(): void {
		self::render();
		exit;
	}

	/**
	 * Sidebar menu items.
	 */
	private static function menu_items(): array {
		$items = [
			[
				'id'    => 'dashboard',
				'label' => __( 'Dashboard', 'newspack-plugin' ),
				'to'    => '/',
			],
			[
				'id'          => 'settings',
				'label'       => __( 'Settings', 'newspack-plugin' ),
				'to'          => '',
				'parent_type' => 'drilldown',
			],
		];
		foreach ( self::settings_tabs() as $slug => $label ) {
			$items[] = [
				'id'     => 'settings-' . $slug,
				'label'  => $label,
				'to'     => '/settings/' . $slug,
				'parent' => 'settings',
			];
		}
		return $items;
	}

	/**
	 * Settings tabs as slug => label, in tab order.
	 */
	private static function settings_tabs(): array {
		if ( null !== self::$settings_tabs ) {
			return self::$settings_tabs;
		}
		self::$settings_tabs = [];
		$wizard              = Wizards::get_wizard( self::SETTINGS_PAGE );
		foreach ( $wizard ? $wizard->get_local_data() : [] as $slug => $tab ) {
			if ( is_array( $tab ) && isset( $tab['label'] ) ) {
				self::$settings_tabs[ $slug ] = $tab['label'];
			}
		}
		return self::$settings_tabs;
	}

	/**
	 * Routes, each pointing at a content module.
	 */
	private static function routes(): array {
		$routes = [
			[
				'path'           => '/',
				'route_module'   => '@newspack/admin-app/dashboard-route',
				'content_module' => '@newspack/admin-app/dashboard',
			],
			[
				'path'           => '/settings',
				'route_module'   => '@newspack/admin-app/settings-route',
				'content_module' => '@newspack/admin-app/settings',
			],
		];
		foreach ( array_keys( self::settings_tabs() ) as $slug ) {
			$routes[] = [
				'path'           => '/settings/' . $slug,
				'route_module'   => '@newspack/admin-app/settings-route',
				'content_module' => '@newspack/admin-app/settings',
			];
		}
		return $routes;
	}

	/**
	 * Register the app's script modules.
	 */
	private static function register_modules(): void {
		$base = Newspack::plugin_url() . '/includes/admin-app/modules/';
		wp_register_script_module( '@newspack/admin-app/content', $base . 'content.js', [], self::file_version( 'modules/content.js' ) );
		wp_register_script_module( '@newspack/admin-app/init', $base . 'init.js', [], self::file_version( 'modules/init.js' ) );
		foreach ( [ 'dashboard', 'settings' ] as $name ) {
			wp_register_script_module( "@newspack/admin-app/{$name}-route", "{$base}{$name}-route.js", [], self::file_version( "modules/{$name}-route.js" ) );
			wp_register_script_module( "@newspack/admin-app/{$name}", "{$base}{$name}.js", [ '@newspack/admin-app/content' ], self::file_version( "modules/{$name}.js" ) );
		}
	}

	/**
	 * Preload the REST requests boot makes before it renders any route.
	 */
	private static function preload_data(): void {
		// Copied from core's boot pages: it must exactly match the _fields list in
		// core-data's entities.js, in the same order, or the preload is never used.
		$preload_paths = [
			'/?_fields=description,gmt_offset,home,image_max_bit_depth,image_sizes,image_size_threshold,image_strip_meta,name,site_icon,site_icon_url,site_logo,timezone_string,url,page_for_posts,page_on_front,show_on_front',
			[ '/wp/v2/settings', 'OPTIONS' ],
		];
		$preload_data  = array_reduce( $preload_paths, 'rest_preload_api_request', [] );
		wp_add_inline_script(
			'wp-api-fetch',
			sprintf( 'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );', wp_json_encode( $preload_data, JSON_HEX_TAG ) ),
			'after'
		);
	}

	/**
	 * Title of the route the request opens on. Boot only retitles the document
	 * on later navigations.
	 *
	 * @return string
	 */
	private static function route_title(): string {
		$path = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return str_starts_with( $path, '/settings' ) ? __( 'Settings', 'newspack-plugin' ) : __( 'Dashboard', 'newspack-plugin' );
	}

	/**
	 * Print the full-page document.
	 */
	private static function render(): void {
		remove_action( 'admin_head', 'wp_admin_bar_header' );

		foreach ( wp_scripts()->queue as $script ) {
			wp_dequeue_script( $script );
		}
		foreach ( wp_styles()->queue as $style ) {
			wp_dequeue_style( $style );
		}

		// The wizards' enqueue callbacks normally run on admin_enqueue_scripts,
		// which this document never reaches.
		foreach ( self::WIZARDS as $slug ) {
			$wizard = Wizards::get_wizard( $slug );
			if ( $wizard ) {
				$wizard->enqueue_scripts_and_styles();
			}
		}

		// Our screens are styled against wp-admin's base CSS, which admin-header.php
		// normally prints and this document skips.
		wp_enqueue_style( 'wp-admin' );
		wp_enqueue_style( 'buttons' );
		wp_enqueue_style( 'colors' );
		wp_enqueue_style( 'admin-bar' );
		wp_enqueue_script( 'admin-bar' );

		if ( function_exists( 'wp_enqueue_command_palette_assets' ) ) {
			wp_enqueue_command_palette_assets();
		}

		self::preload_data();

		$asset = require self::boot_asset_file();

		wp_register_script( 'newspack-admin-app-prerequisites', '', array_merge( $asset['dependencies'], [ 'newspack-wizards' ] ), $asset['version'], true );
		wp_add_inline_script(
			'newspack-admin-app-prerequisites',
			sprintf(
				'import("@wordpress/boot").then(mod => mod.init({mountId: %s, menuItems: %s, routes: %s, initModules: ["@newspack/admin-app/init"], dashboardLink: %s}));',
				wp_json_encode( self::MOUNT ),
				wp_json_encode( self::menu_items(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
				wp_json_encode( self::routes(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
				wp_json_encode( admin_url( '/' ), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
			)
		);
		$style_deps = array_filter(
			$asset['dependencies'],
			function ( $handle ) {
				return wp_style_is( $handle, 'registered' );
			}
		);
		wp_register_style( 'newspack-admin-app-prerequisites', false, $style_deps, $asset['version'] );

		self::register_modules();
		$deps = [
			[
				'import' => 'static',
				'id'     => '@wordpress/boot',
			],
			[
				'import' => 'static',
				'id'     => '@newspack/admin-app/init',
			],
		];
		foreach ( self::routes() as $route ) {
			$deps[] = [
				'import' => 'static',
				'id'     => $route['route_module'],
			];
			$deps[] = [
				'import' => 'dynamic',
				'id'     => $route['content_module'],
			];
		}
		$deps = array_values( array_unique( $deps, SORT_REGULAR ) );
		wp_register_script_module( self::MOUNT, Newspack::plugin_url() . '/includes/admin-app/modules/loader.js', $deps, self::file_version( 'modules/loader.js' ) );

		wp_enqueue_script( 'newspack-admin-app-prerequisites' );
		wp_enqueue_script_module( self::MOUNT );
		wp_enqueue_style( 'newspack-admin-app-prerequisites' );
		wp_enqueue_style( 'newspack-admin-app', Newspack::plugin_url() . '/includes/admin-app/style.css', [ 'newspack-admin-app-prerequisites', 'wp-components' ], self::file_version( 'style.css' ) );

		global $hook_suffix;
		$route_title = self::route_title();
		$admin_title = sprintf( /* translators: Admin screen title. 1: Admin screen name, 2: Network or site name. */ __( '%1$s &lsaquo; %2$s &#8212; WordPress' ), $route_title, get_bloginfo( 'name' ) );
		/** This filter is documented in wp-admin/admin-header.php */
		$admin_title = apply_filters( 'admin_title', $admin_title, $route_title );
		?>
		<!DOCTYPE html>
		<html class="wp-toolbar" <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( wp_strip_all_tags( $admin_title ) ); ?></title>
			<style>
				html { background: #f1f1f1; color: #444; font-family: -apple-system, system-ui, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; font-size: 13px; line-height: 1.4em; }
				body { margin: 0; }
				#<?php echo esc_html( self::MOUNT ); ?> { height: calc(100vh - var(--wp-admin--admin-bar--height, 32px)); box-sizing: border-box; }
			</style>
			<?php
			print_admin_styles();
			print_head_scripts();
			do_action( "admin_head-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			do_action( 'admin_head' );
			?>
		</head>
		<body class="wp-admin admin-bar newspack-admin-app newspack-wizard-page">
			<?php wp_admin_bar_render(); ?>
			<div id="<?php echo esc_attr( self::MOUNT ); ?>"></div>
			<?php
			do_action( 'admin_footer', $hook_suffix );
			wp_script_modules()->print_import_map();
			print_footer_scripts();
			wp_script_modules()->print_enqueued_script_modules();
			wp_script_modules()->print_script_module_preloads();
			wp_script_modules()->print_script_module_data();
			do_action( "admin_footer-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			?>
		</body>
		</html>
		<?php
	}
}
Admin_App::init();
