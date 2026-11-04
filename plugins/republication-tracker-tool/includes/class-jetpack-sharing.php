<?php
/**
 * Jetpack sharing buttons integration.
 *
 * @package Republication_Tracker_Tool
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Republish button to Jetpack's sharing buttons.
 */
final class Republication_Tracker_Tool_Jetpack_Sharing {

	/**
	 * Jetpack sharing service ID.
	 *
	 * @var string
	 */
	public const SERVICE = 'republish';

	/**
	 * ID of the template holding the icon on Jetpack's sharing settings screen.
	 *
	 * @var string
	 */
	private const ICON_TEMPLATE_ID = 'republication-tracker-tool-jetpack-icon';

	/**
	 * The post a Republish button was displayed for on this page.
	 *
	 * Kept as the object Jetpack passed in rather than reloaded, so the modal
	 * copies the post as the page holds it.
	 *
	 * @var WP_Post|null
	 */
	private static ?WP_Post $post = null;

	/**
	 * Initialize the integration.
	 */
	public static function init(): void {
		add_filter( 'sharing_services', [ __CLASS__, 'register_service' ] );
		add_filter( 'sharing_enabled', [ __CLASS__, 'filter_enabled_services' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
	}

	/**
	 * Add the Republish button to Jetpack's available sharing services.
	 *
	 * @param array $services Sharing service classes, keyed by service ID.
	 * @return array
	 */
	public static function register_service( $services ) {
		if ( ! class_exists( 'Sharing_Source' ) ) {
			return $services;
		}
		require_once __DIR__ . '/class-jetpack-share-republish.php';
		$services[ self::SERVICE ] = 'Republication_Tracker_Tool_Jetpack_Share_Republish';
		return $services;
	}

	/**
	 * Leave the Republish button out of the sharing buttons where it can't be shown.
	 *
	 * Jetpack prints a list item for every enabled service whatever the service
	 * returns, so an empty button would otherwise leave a gap, or a "Share this:"
	 * heading over nothing when Republish is the only service.
	 *
	 * @param array $enabled Enabled services, grouped as visible, hidden and all.
	 * @return array
	 */
	public static function filter_enabled_services( $enabled ) {
		if ( ! is_array( $enabled ) || self::should_display( get_post() ) ) {
			return $enabled;
		}
		foreach ( [ 'visible', 'hidden', 'all' ] as $group ) {
			if ( isset( $enabled[ $group ] ) && is_array( $enabled[ $group ] ) ) {
				unset( $enabled[ $group ][ self::SERVICE ] );
			}
		}
		return $enabled;
	}

	/**
	 * Whether the Republish button may be shown for a post.
	 *
	 * Only on the post's own page, since the modal holds a single post and
	 * Jetpack also adds sharing buttons to posts listed on archives.
	 *
	 * @param WP_Post|null $post The post object.
	 * @return bool
	 */
	public static function should_display( $post ): bool {
		if ( ! $post instanceof \WP_Post || ! is_singular() || get_queried_object_id() !== $post->ID ) {
			return false;
		}
		if ( Republication_Tracker_Tool_Widget::is_amp() ) {
			return false;
		}
		return Republication_Tracker_Tool::can_offer_republish( $post );
	}

	/**
	 * Load the modal's assets and print the modal in the footer.
	 *
	 * @param WP_Post $post The post the button was displayed for.
	 */
	public static function queue_modal( WP_Post $post ): void {
		if ( self::$post ) {
			return;
		}
		self::$post = $post;

		Republication_Tracker_Tool_Republish_Button_Block::enqueue_modal_assets();
		self::enqueue_style();
		add_action( 'wp_footer', [ __CLASS__, 'print_modal' ] );
	}

	/**
	 * Print the republish modal, unless the widget or the block already did.
	 */
	public static function print_modal(): void {
		if ( ! self::$post || Republication_Tracker_Tool::$modal_rendered ) {
			return;
		}
		Republication_Tracker_Tool::$modal_rendered = true;

		global $post;
		$post   = self::$post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$is_amp = false;
		setup_postdata( $post );

		include __DIR__ . '/shareable-content.php';

		wp_reset_postdata();
	}

	/**
	 * Icon markup for the button.
	 *
	 * @return string
	 */
	public static function get_icon(): string {
		if ( class_exists( '\Newspack\Newspack_UI_Icons' ) ) {
			$svg = \Newspack\Newspack_UI_Icons::get_svg( 'copy' );
			if ( $svg ) {
				return $svg;
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M5.625 5.5h9.75c.069 0 .125.056.125.125v9.75a.125.125 0 0 1-.125.125h-9.75a.125.125 0 0 1-.125-.125v-9.75c0-.069.056-.125.125-.125ZM4 5.625C4 4.728 4.728 4 5.625 4h9.75C16.273 4 17 4.728 17 5.625v9.75c0 .898-.727 1.625-1.625 1.625h-9.75A1.625 1.625 0 0 1 4 15.375v-9.75Zm14.5 11.656v-9H20v9C20 18.8 18.77 20 17.251 20H6.25v-1.5h11.001c.69 0 1.249-.528 1.249-1.219Z" /></svg>';
	}

	/**
	 * Enqueue the button's styles.
	 */
	public static function enqueue_style(): void {
		wp_enqueue_style(
			'republication-tracker-tool-jetpack-sharing',
			REPUBLICATION_TRACKER_TOOL_URL . 'assets/jetpack-sharing.css',
			[],
			filemtime( REPUBLICATION_TRACKER_TOOL_PATH . 'assets/jetpack-sharing.css' )
		);
	}

	/**
	 * Enqueue the button's styles and settings script on Jetpack's sharing settings screen.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'settings_page_sharing' !== $hook_suffix ) {
			return;
		}
		self::enqueue_style();
		wp_enqueue_script(
			'republication-tracker-tool-jetpack-sharing-admin',
			REPUBLICATION_TRACKER_TOOL_URL . 'assets/jetpack-sharing-admin.js',
			[],
			filemtime( REPUBLICATION_TRACKER_TOOL_PATH . 'assets/jetpack-sharing-admin.js' ),
			[ 'in_footer' => true ]
		);
		add_action( 'admin_footer', [ __CLASS__, 'print_icon_template' ] );
	}

	/**
	 * Print the icon for the settings script to copy into the button's tile,
	 * which Jetpack renders as escaped text.
	 */
	public static function print_icon_template(): void {
		printf(
			'<template id="%s">%s</template>',
			esc_attr( self::ICON_TEMPLATE_ID ),
			self::get_icon() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static icon markup.
		);
	}
}

Republication_Tracker_Tool_Jetpack_Sharing::init();
