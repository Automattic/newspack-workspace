<?php
/**
 * Plugin Name:     Newspack Ads
 * Plugin URI:      https://newspack.com
 * Description:     Ad services integration.
 * Author:          Automattic
 * License:         GPL2
 * Version:         3.11.2
 *
 * @package         Newspack
 */

namespace Newspack_Ads;

defined( 'ABSPATH' ) || exit;

define( 'NEWSPACK_ADS_VERSION', '3.11.2' );

// Define NEWSPACK_ADS_PLUGIN_FILE.
if ( ! defined( 'NEWSPACK_ADS_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_ADS_PLUGIN_FILE', __FILE__ );
}

define( 'NEWSPACK_ADS_ABSPATH', dirname( NEWSPACK_ADS_PLUGIN_FILE ) . '/' );

define( 'NEWSPACK_ADS_BLOCKS_PATH', NEWSPACK_ADS_ABSPATH . 'src/blocks/' );

/**
 * Path to the Composer vendor directory for Newspack Ads.
 * Useful when running with a custom autoloader setup.
 *
 * @constant NEWSPACK_ADS_COMPOSER_ABSPATH
 * @type     string
 * @default  Plugin vendor directory
 * @status   draft
 *
 * @example define( 'NEWSPACK_ADS_COMPOSER_ABSPATH', '/path/to/vendor/' );
 */
if ( ! defined( 'NEWSPACK_ADS_COMPOSER_ABSPATH' ) ) {
	define( 'NEWSPACK_ADS_COMPOSER_ABSPATH', dirname( NEWSPACK_ADS_PLUGIN_FILE ) . '/vendor/' );
}

// Include the main Newspack Ads class.
if ( ! class_exists( 'Newspack_Ads\Core' ) ) {
	include_once __DIR__ . '/includes/class-core.php';
}
