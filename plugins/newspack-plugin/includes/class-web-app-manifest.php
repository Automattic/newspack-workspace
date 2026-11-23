<?php
/**
 * Web app manifest.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the web app manifest that lets readers install the site as an app.
 *
 * Browsers install a site from a manifest alone, without a service worker. The PWA plugin used to serve this
 * manifest; this class takes over once that plugin is gone, at the same address so apps readers already installed
 * keep finding it. It stays off while the PWA plugin is active, so a site never has two manifests.
 */
final class Web_App_Manifest {

	/**
	 * REST namespace and route, matching the PWA plugin's.
	 */
	const REST_NAMESPACE = 'wp/v2';
	const REST_ROUTE     = '/web-app-manifest';

	/**
	 * Option holding the display mode, shared with the PWA plugin era.
	 */
	const DISPLAY_MODE_OPTION = 'newspack_pwa_display_mode';

	/**
	 * Display modes a manifest may declare. The first is used when the stored value isn't one of them.
	 */
	const DISPLAY_MODES = [ 'minimal-ui', 'fullscreen', 'standalone', 'browser' ];

	/**
	 * Icon sizes browsers ask for when installing.
	 */
	const ICON_SIZES = [ 192, 512 ];

	/**
	 * Add hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
		add_action( 'wp_head', [ __CLASS__, 'print_head_tags' ] );
	}

	/**
	 * Point browsers at the manifest from every page.
	 */
	public static function print_head_tags() {
		if ( ! self::is_enabled() ) {
			return;
		}
		$manifest = self::get_manifest();
		if ( null === $manifest ) {
			return;
		}
		?>
		<link rel="manifest" href="<?php echo esc_url( self::get_url() ); ?>">
		<?php if ( ! empty( $manifest['theme_color'] ) ) : ?>
			<meta name="theme-color" content="<?php echo esc_attr( $manifest['theme_color'] ); ?>">
		<?php endif; ?>
		<?php $app_name = $manifest['short_name'] ?? $manifest['name']; ?>
		<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( $app_name ); ?>">
		<meta name="application-name" content="<?php echo esc_attr( $app_name ); ?>">
		<?php if ( 'browser' !== $manifest['display'] ) : ?>
			<meta name="apple-mobile-web-app-capable" content="yes">
			<meta name="mobile-web-app-capable" content="yes">
		<?php endif; ?>
		<?php
	}

	/**
	 * Whether this class serves the manifest. The PWA plugin serves its own while it's active.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return ! PWA::is_pwa_plugin_active();
	}

	/**
	 * URL of the manifest.
	 *
	 * @return string
	 */
	public static function get_url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Display mode the publisher chose.
	 *
	 * @return string
	 */
	public static function get_display_mode(): string {
		$mode = get_option( self::DISPLAY_MODE_OPTION, '' );
		return in_array( $mode, self::DISPLAY_MODES, true ) ? $mode : self::DISPLAY_MODES[0];
	}

	/**
	 * The manifest, or null when the site has no icon, since browsers won't install without one.
	 *
	 * @return array|null
	 */
	public static function get_manifest(): ?array {
		$icons = self::get_icons();
		if ( empty( $icons ) ) {
			return null;
		}

		$manifest = [
			'name'      => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'start_url' => home_url( '/' ),
			'display'   => self::get_display_mode(),
			'dir'       => is_rtl() ? 'rtl' : 'ltr',
		];

		$short_name = get_option( 'short_name', '' );
		if ( $short_name ) {
			$manifest['short_name'] = $short_name;
		} elseif ( mb_strlen( trim( $manifest['name'] ) ) <= 12 ) {
			// Home screens truncate longer labels, and Lighthouse flags a missing short_name.
			$manifest['short_name'] = trim( $manifest['name'] );
		}

		$language = get_bloginfo( 'language' );
		if ( $language ) {
			$manifest['lang'] = $language;
		}

		$theme_color = self::get_theme_color();
		if ( $theme_color ) {
			$manifest['background_color'] = $theme_color;
			$manifest['theme_color']      = $theme_color;
		}

		$description = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' );
		if ( $description ) {
			$manifest['description'] = $description;
		}

		$manifest['icons'] = $icons;

		return $manifest;
	}

	/**
	 * Site icon at the sizes browsers ask for.
	 *
	 * @return array
	 */
	private static function get_icons(): array {
		$site_icon_id = get_option( 'site_icon' );
		if ( ! $site_icon_id ) {
			return [];
		}
		$type     = get_post_mime_type( $site_icon_id );
		$maskable = get_option( 'site_icon_maskable', false );
		$icons    = [];
		foreach ( self::ICON_SIZES as $size ) {
			$src = get_site_icon_url( $size );
			if ( ! $src ) {
				continue;
			}
			$icon    = [
				'purpose' => 'any',
				'src'     => $src,
				'sizes'   => "{$size}x{$size}",
				'type'    => $type,
			];
			$icons[] = $icon;
			// A separate entry, so the plain icon isn't cropped where it's shown as is.
			if ( $maskable ) {
				$icon['purpose'] = 'maskable';
				$icons[]         = $icon;
			}
		}
		return $icons;
	}

	/**
	 * Colour for the installed app's title bar and splash screen, read the way the PWA plugin did, white when the theme sets none.
	 *
	 * @return string
	 */
	private static function get_theme_color(): string {
		if ( current_theme_supports( 'theme-color' ) ) {
			$theme_color = get_theme_support( 'theme-color' );
			if ( $theme_color ) {
				return (string) ( is_array( $theme_color ) ? array_shift( $theme_color ) : $theme_color );
			}
		}
		if ( current_theme_supports( 'custom-background' ) ) {
			$background_color = get_background_color();
			if ( $background_color ) {
				return '#' . $background_color;
			}
		}
		return '#fff';
	}

	/**
	 * Register the manifest route.
	 */
	public static function register_route() {
		if ( ! self::is_enabled() ) {
			return;
		}
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'serve_manifest' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Serve the manifest.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function serve_manifest() {
		$manifest = self::get_manifest();
		if ( null === $manifest ) {
			return new WP_Error( 'newspack_no_web_app_manifest', __( 'The site has no icon, so there is no web app manifest.', 'newspack-plugin' ), [ 'status' => 404 ] );
		}
		$response = new WP_REST_Response( $manifest );
		$response->header( 'Content-Type', 'application/manifest+json' );
		return $response;
	}
}
Web_App_Manifest::init();
