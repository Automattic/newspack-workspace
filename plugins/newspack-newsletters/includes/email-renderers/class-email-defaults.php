<?php
/**
 * Injects Newspack fallback values at the theme.json default origin so the
 * newsletter editor canvas reflects them when the active theme defines nothing.
 * Theme-origin values still win. Every method is flag-gated and
 * email-editor-request-gated — must NOT touch any non-newsletter context.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Provides Newspack fallback defaults at the theme.json default origin.
 *
 * The `wp_theme_json_data_default` hook fires globally; callbacks guard on the WC renderer
 * flag and the email-editor request context.
 *
 * The callbacks deliberately carry NO type declarations. Core passes a
 * `WP_Theme_JSON_Data`, but with the Gutenberg plugin active the resolver passes
 * `WP_Theme_JSON_Data_Gutenberg` — a sibling class, not a subclass — so a
 * `WP_Theme_JSON_Data` declaration fatals with a TypeError on every theme.json
 * resolution, front end included, before the guards below can run. Both classes
 * expose `update_with()`, which is all these callbacks need.
 */
class Email_Defaults {

	/**
	 * Fallback button border-radius. Used by the render side too, so canvas and
	 * email agree when no theme defines a radius.
	 *
	 * @var string
	 */
	const DEFAULT_BUTTON_BORDER_RADIUS = '4px';

	/**
	 * Wire up the wp_theme_json_data_default callbacks.
	 *
	 * Registered unconditionally rather than behind Feature_Flag at load time: a site
	 * that enables the renderer via the `newspack_newsletters_use_woo_renderer` filter
	 * on a later hook would otherwise miss these editor-canvas defaults (init() runs at
	 * plugin load). Each callback re-resolves the flag per request and guards on the
	 * email-editor request context, so the filters stay inert until both are true.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_theme_json_data_default', [ __CLASS__, 'inject_button_border_radius' ] );
		add_filter( 'wp_theme_json_data_default', [ __CLASS__, 'inject_fonts' ] );
	}

	/**
	 * Inject the fallback button border-radius at the default origin.
	 *
	 * The `wp_theme_json_data_default` hook fires globally — a false positive would change
	 * button styling site-wide, so guard tightly: WC renderer flag on AND an
	 * email-editor request. The default origin fires before _theme, so any
	 * theme-origin radius still wins.
	 *
	 * @param \WP_Theme_JSON_Data|\WP_Theme_JSON_Data_Gutenberg $theme_json Incoming default theme.json data.
	 * @return \WP_Theme_JSON_Data|\WP_Theme_JSON_Data_Gutenberg Potentially modified default theme.json data.
	 */
	public static function inject_button_border_radius( $theme_json ) {
		if ( ! Feature_Flag::is_enabled() ) {
			return $theme_json;
		}

		if ( ! \Newspack_Newsletters_Editor::is_email_editor_request() ) {
			return $theme_json;
		}

		return $theme_json->update_with(
			[
				'version' => 3,
				'styles'  => [
					'elements' => [
						'button' => [
							'border' => [
								'radius' => self::DEFAULT_BUTTON_BORDER_RADIUS,
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Inject the resolved body/header fonts at the default origin.
	 *
	 * Guarded identically to inject_button_border_radius(). Fires before _theme/
	 * _user, so global-styles or theme-origin fonts still override. On post-new.php
	 * (no post yet) fonts resolve via global → theme → fallback, so new newsletters
	 * show theme fonts instead of the hardcoded builder defaults.
	 *
	 * @param \WP_Theme_JSON_Data|\WP_Theme_JSON_Data_Gutenberg $theme_json Incoming default theme.json data.
	 * @return \WP_Theme_JSON_Data|\WP_Theme_JSON_Data_Gutenberg Potentially modified default theme.json data.
	 */
	public static function inject_fonts( $theme_json ) {
		if ( ! Feature_Flag::is_enabled() ) {
			return $theme_json;
		}

		if ( ! \Newspack_Newsletters_Editor::is_email_editor_request() ) {
			return $theme_json;
		}

		// On post-new.php there is no post yet; resolve() accepts null and skips meta.
		$fonts = Fonts::resolve( self::get_editing_post() );

		return $theme_json->update_with(
			[
				'version' => 3,
				'styles'  => [
					'typography' => [
						'fontFamily' => $fonts['body'],
					],
					'elements'   => [
						'heading' => [
							'typography' => [
								'fontFamily' => $fonts['header'],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * The post currently being edited, or null on post-new.php / when absent.
	 *
	 * @return \WP_Post|null
	 */
	private static function get_editing_post(): ?\WP_Post {
		$post_id = isset( $_GET['post'] ) ? \absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return null;
		}
		$post = \get_post( $post_id );
		return $post instanceof \WP_Post ? $post : null;
	}
}
