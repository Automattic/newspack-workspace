<?php
/**
 * Newspack Prompt Popups.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Prompt Popups.
 */
class Prompt_Popups {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueues the scripts to the Post edit screen.
	 */
	public static function enqueue_scripts() {
		$screen = get_current_screen();

		if ( 'audience_page_newspack-audience-campaigns' !== $screen->base ) {
			return;
		}

		$asset = require NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . '/dist/promptBrands.asset.php';

		wp_enqueue_script(
			'newspack-prompt-brands',
			plugins_url( '../../dist/promptBrands.js', __FILE__ ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}
}
