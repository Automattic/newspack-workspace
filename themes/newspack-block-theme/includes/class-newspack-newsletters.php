<?php
/**
 * Newspack Newsletters Compatibility File
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Newsletters compatibility.
 */
final class Newspack_Newsletters {
	/**
	 * Initializer.
	 */
	public static function init() {
		if ( ! class_exists( '\Newspack_Newsletters' ) ) {
			return;
		}
		\add_action( 'newspack_newsletters_enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_styles' ] );
	}

	/**
	 * Enqueue Block Editor styles for the Newsletters editor.
	 */
	public static function enqueue_editor_styles() {
		\add_editor_style( 'assets/css/newspack-newsletters-editor.css' );
	}
}

Newspack_Newsletters::init();
