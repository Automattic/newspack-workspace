<?php
/**
 * Plugin Name:     Newspack Campaigns
 * Plugin URI:      https://newspack.com
 * Description:     Build persuasive call-to-action prompts from scratch and display them as overlays, inline with the story, or above the site header.
 * Author:          Automattic
 * Author URI:      https://newspack.com
 * Text Domain:     newspack-popups
 * Domain Path:     /languages
 * Version:         3.12.1
 *
 * @package         Newspack_Popups
 */

defined( 'ABSPATH' ) || exit;

// Define the plugin file path.
if ( ! defined( 'NEWSPACK_POPUPS_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_POPUPS_PLUGIN_FILE', __FILE__ );
}

require_once __DIR__ . '/vendor/autoload.php';

// Include the main Newspack Google Ad Manager class.
if ( ! class_exists( 'Newspack_Popups' ) ) {
	include_once __DIR__ . '/includes/class-newspack-popups.php';
}
