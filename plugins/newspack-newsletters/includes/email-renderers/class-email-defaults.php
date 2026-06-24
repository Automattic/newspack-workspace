<?php
/**
 * Newsletter email defaults provider.
 *
 * Injects Newspack fallback values at the theme.json `default` origin so the
 * newsletter editor canvas reflects them when the active theme defines nothing,
 * while still allowing any theme-origin value to win via the normal merge order.
 *
 * This class's sole responsibility is injecting *fallback* defaults — it must
 * NOT touch any non-newsletter context. Every public method is flag-gated and
 * email-editor-request-gated.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Provides Newspack-specific fallback defaults at the theme.json default origin.
 *
 * The `wp_theme_json_data_default` filter fires during global theme.json
 * resolution for every request. The callback is carefully guarded so it only
 * injects in the newsletter email-editor context with the WC renderer flag on.
 */
class Email_Defaults {

	/**
	 * Fallback button border-radius injected at the default origin.
	 *
	 * Task 8 (render side) must import this same constant so the canvas and the
	 * rendered email agree on the fallback value when no theme defines one.
	 *
	 * @var string
	 */
	const DEFAULT_BUTTON_BORDER_RADIUS = '4px';

	/**
	 * Register the wp_theme_json_data_default filter.
	 *
	 * Call this once from Editor_Bootstrap::init() (or equivalent) so the hook
	 * is only wired up when the email-editor package is available.
	 *
	 * The `wp_theme_json_data_default` filter fires globally on every theme.json
	 * resolution. Only register it when the WC renderer flag is on to avoid
	 * unnecessary overhead on sites where the flag is off.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! Feature_Flag::is_enabled() ) {
			return;
		}
		add_filter( 'wp_theme_json_data_default', [ __CLASS__, 'inject_button_border_radius' ] );
		add_filter( 'wp_theme_json_data_default', [ __CLASS__, 'inject_fonts' ] );
	}

	/**
	 * Inject the Newspack fallback button border-radius at the default origin.
	 *
	 * The `wp_theme_json_data_default` filter is GLOBAL — it fires for every
	 * theme.json resolution on the site. We must guard it tightly:
	 *
	 * - WC renderer flag must be on (feature-flag guard).
	 * - Must be an email-editor request (`Newspack_Newsletters_Editor::is_email_editor_request()`).
	 *
	 * Any false positive here would silently change button styling across the
	 * entire front-end, so returning early is always the safe path.
	 *
	 * Because `_default` fires before `_theme`, the theme's own button radius
	 * (theme origin) merges on top and wins — this is pure fallback behaviour.
	 *
	 * @param \WP_Theme_JSON_Data $theme_json Incoming default theme.json data.
	 * @return \WP_Theme_JSON_Data Potentially modified default theme.json data.
	 */
	public static function inject_button_border_radius( \WP_Theme_JSON_Data $theme_json ): \WP_Theme_JSON_Data {
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
	 * Inject the resolved newsletter body/header fonts at the default origin.
	 *
	 * This makes the editor canvas show the same fonts the rendered email uses
	 * for an un-customized newsletter — by default the active theme's fonts,
	 * matching the standard post editor. Injecting at the `default` origin (this
	 * filter fires before `_theme`/`_user`) means any global-styles or
	 * theme-origin font family still overrides it: that is exactly the "unless
	 * global fonts are set" requirement.
	 *
	 * Guarded identically to inject_button_border_radius(): WC renderer flag on
	 * AND an email-editor request. is_email_editor_request() is also true on
	 * post-new.php (create), where there is no post yet — in that case fonts are
	 * resolved with empty meta (global → theme → default), so a brand-new
	 * newsletter's canvas still shows the resolved theme fonts instead of the
	 * hardcoded builder defaults.
	 *
	 * @param \WP_Theme_JSON_Data $theme_json Incoming default theme.json data.
	 * @return \WP_Theme_JSON_Data Potentially modified default theme.json data.
	 */
	public static function inject_fonts( \WP_Theme_JSON_Data $theme_json ): \WP_Theme_JSON_Data {
		if ( ! Feature_Flag::is_enabled() ) {
			return $theme_json;
		}

		if ( ! \Newspack_Newsletters_Editor::is_email_editor_request() ) {
			return $theme_json;
		}

		// On post-new.php there is no editing post yet; resolve() accepts null and
		// skips the per-post meta step, running the global → theme → default chain.
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
	 * Resolve the newsletter post currently being edited, if any.
	 *
	 * Reads the `post` URL param (the same signal is_email_editor_request()
	 * validates for post.php). Returns null on post-new.php / when absent so the
	 * caller can skip injection gracefully.
	 *
	 * @return \WP_Post|null The post being edited, or null.
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
