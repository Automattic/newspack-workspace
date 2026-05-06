<?php
/**
 * Plugin Name:     Newspack Blocks
 * Plugin URI:      https://newspack.com/
 * Description:     A collection of blocks for news publishers.
 * Author:          Automattic
 * Author URI:      https://newspack.com/
 * Text Domain:     newspack-blocks
 * Domain Path:     /languages
 * Version:         4.26.2
 *
 * @package         Newspack_Blocks
 */

define( 'NEWSPACK_BLOCKS__PLUGIN_FILE', __FILE__ );
define( 'NEWSPACK_BLOCKS__BLOCKS_DIRECTORY', 'dist/' );
define( 'NEWSPACK_BLOCKS__PLUGIN_DIR', plugin_dir_path( NEWSPACK_BLOCKS__PLUGIN_FILE ) );
define( 'NEWSPACK_BLOCKS__VERSION', '4.26.2' );

require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/class-newspack-blocks.php';
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/class-newspack-blocks-api.php';
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/class-newspack-blocks-patterns.php';
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/class-newspack-blocks-caching.php';
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/modal-checkout/class-checkout-data.php';
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/modal-checkout/class-change-payment-gateway.php';
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/class-modal-checkout.php';

require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/plugins/class-the-events-calendar.php';

require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'includes/tracking/class-data-events.php';

// Shared functions for blocks.
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/shared/authors.php';

// REST Controller for Articles Block.
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/homepage-articles/class-wp-rest-newspack-articles-controller.php';

// REST Controller for Author Profile Block.
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/author-profile/class-wp-rest-newspack-authors-controller.php';

// REST Controller for Author List Block.
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/author-list/class-wp-rest-newspack-author-list-controller.php';

// REST Controller for Iframe Block.
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/iframe/class-wp-rest-newspack-iframe-controller.php';

/**
 * Registers Articles block routes.
 */
function newspack_articles_block_register_rest_routes() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$articles_controller = new WP_REST_Newspack_Articles_Controller();
	$articles_controller->register_routes();
}
add_action( 'rest_api_init', 'newspack_articles_block_register_rest_routes' );

/**
 * Registers Authors block routes.
 */
function newspack_authors_block_register_rest_routes() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$authors_controller = new WP_REST_Newspack_Authors_Controller();
	$authors_controller->register_routes();
}
add_action( 'rest_api_init', 'newspack_authors_block_register_rest_routes' );

/**
 * Registers Author List block routes.
 */
function newspack_author_list_block_register_rest_routes() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$author_list_controller = new WP_REST_Newspack_Author_List_Controller();
	$author_list_controller->register_routes();
}
add_action( 'rest_api_init', 'newspack_author_list_block_register_rest_routes' );

/**
 * Registers Iframe block routes.
 */
function newspack_iframe_block_register_rest_routes() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$iframe_controller = new WP_REST_Newspack_Iframe_Controller();
	$iframe_controller->register_routes();
}
add_action( 'rest_api_init', 'newspack_iframe_block_register_rest_routes' );

Newspack_Blocks::manage_view_scripts();
add_action( 'enqueue_block_assets', array( 'Newspack_Blocks', 'enqueue_block_assets' ) );
add_action( 'enqueue_block_assets', array( 'Newspack_Blocks', 'enqueue_placeholder_blocks_assets' ), 9999 );
add_action( 'enqueue_block_assets', array( 'Newspack_Blocks', 'enqueue_block_styles_assets' ) );

/**
 * Load language files
 *
 * @action plugins_loaded
 */
function newspack_blocks_plugin_textdomain() {
	load_plugin_textdomain( 'newspack-blocks', false, dirname( plugin_basename( NEWSPACK_BLOCKS__PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'newspack_blocks_plugin_textdomain' );
