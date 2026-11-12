<?php
/**
 * Plugin Name:     Newspack Content Blocks
 * Plugin URI:      https://newspack.com/
 * Description:     The Content Loop and Carousel blocks for news publishers.
 * Author:          Automattic
 * Author URI:      https://newspack.com/
 * License:         GPL2
 * Text Domain:     newspack-content-blocks
 * Domain Path:     /languages
 * Version:         1.0.0
 * Requires PHP:    8.1
 *
 * @package         Newspack_Content_Blocks
 */

defined( 'ABSPATH' ) || exit;

define( 'NEWSPACK_CONTENT_BLOCKS_VERSION', '1.0.0' );
define( 'NEWSPACK_CONTENT_BLOCKS_PLUGIN_FILE', __FILE__ );
define( 'NEWSPACK_CONTENT_BLOCKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/includes/class-guard.php';
require_once __DIR__ . '/includes/class-plugin.php';

// Decided at file load, not on a hook: while newspack-blocks is active this plugin
// registers nothing, so the two never register the same blocks or routes.
if ( ! \Newspack_Content_Blocks\Guard::is_legacy_plugin_active() ) {
	\Newspack_Content_Blocks\Plugin::load();
	add_action(
		'init',
		function() {
			load_plugin_textdomain( 'newspack-content-blocks', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}
	);
}
