<?php
/**
 * Newspack Block Theme functions and definitions
 * Version: 1.28.5
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Newspack Block Theme
 */

defined( 'ABSPATH' ) || exit;

// Define NEWSPACK_BLOCK_THEME_FILE.
if ( ! defined( 'NEWSPACK_BLOCK_THEME_FILE' ) ) {
	define( 'NEWSPACK_BLOCK_THEME_FILE', __FILE__ );
	define( 'NEWSPACK_BLOCK_THEME_FILE_PATH', plugin_dir_path( NEWSPACK_BLOCK_THEME_FILE ) );
	define( 'NEWSPACK_BLOCK_THEME_URL', plugin_dir_url( NEWSPACK_BLOCK_THEME_FILE ) );
	define( 'NEWSPACK_BLOCK_THEME_VERSION', '1.28.5' );
}


// Include theme resources.
require_once NEWSPACK_BLOCK_THEME_FILE_PATH . '/includes/class-core.php';
require_once NEWSPACK_BLOCK_THEME_FILE_PATH . '/includes/blocks/index.php';
require_once NEWSPACK_BLOCK_THEME_FILE_PATH . '/includes/class-patterns.php';
require_once NEWSPACK_BLOCK_THEME_FILE_PATH . '/includes/class-jetpack.php';
require_once NEWSPACK_BLOCK_THEME_FILE_PATH . '/includes/class-woocommerce.php';
require_once NEWSPACK_BLOCK_THEME_FILE_PATH . '/includes/class-newspack-newsletters.php';
