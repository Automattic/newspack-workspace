<?php
/**
 * Newspack Newsletters Blocks.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Blocks Class.
 */
final class Newspack_Newsletters_Blocks {
	/**
	 * Initialize Hooks.
	 */
	public static function init() {
		require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/src/blocks/subscribe/index.php';
		add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
	}

	/**
	 * Get the access gates that restrict each subscription list.
	 *
	 * Newsletters has no notion of gates itself. Whatever restricts lists (Access
	 * Control in Newspack) reports them here so the subscribe block's editor can
	 * mark gated lists and preview them.
	 *
	 * @return array[] Gates keyed by public list ID. Each gate is an array with
	 *                 `id` and `title`, plus `edit_url` when the current user may edit it.
	 */
	public static function get_list_gates() {
		/**
		 * Filters the gates that restrict each subscription list.
		 *
		 * @param array[] $list_gates Gates keyed by public list ID.
		 */
		$list_gates = apply_filters( 'newspack_newsletters_subscription_list_gates', [] );
		return is_array( $list_gates ) ? $list_gates : [];
	}

	/**
	 * Enqueue blocks scripts and styles for editor.
	 */
	public static function enqueue_block_assets() {
		if ( ! is_admin() ) {
			return;
		}
		$handle = 'newspack-newsletters-blocks';
		$asset  = include NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/blocks.asset.php';
		wp_enqueue_script(
			$handle,
			plugins_url( '../dist/blocks.js', __FILE__ ),
			$asset['dependencies'],
			filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/blocks.js' ),
			true
		);
		$provider = Newspack_Newsletters::get_service_provider();
		wp_localize_script(
			$handle,
			'newspack_newsletters_blocks',
			[
				'provider'           => $provider ? $provider->service : '',
				'settings_url'       => Newspack_Newsletters_Settings::get_settings_url(),
				'supports_recaptcha' => class_exists( 'Newspack\Recaptcha' ),
				'has_recaptcha'      => class_exists( 'Newspack\Recaptcha' ) && \Newspack\Recaptcha::can_use_captcha(),
				'recaptcha_url'      => admin_url( 'admin.php?page=newspack-settings#/' ),
				// Cast so the editor always gets an object: list IDs can be numeric, and
				// PHP would encode sequential numeric keys as a JSON array.
				'list_gates'         => (object) self::get_list_gates(),
			]
		);
		wp_enqueue_style(
			$handle,
			plugins_url( '../dist/blocks.css', __FILE__ ),
			[],
			filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/blocks.css' )
		);
	}
}
Newspack_Newsletters_Blocks::init();
