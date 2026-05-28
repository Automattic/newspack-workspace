<?php
/**
 * Plugin Name: Super Cool Ad Inserter Plugin
 * Plugin URI: https://github.com/Automattic/super-cool-ad-inserter-plugin/tree/trunk/docs
 * Description: A simple way to insert widgets after the nth paragraph
 * Version: 0.7.4
 * Author: Automattic
 * License: GPL Version 2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: scaip
 *
 * @package super-cool-ad-inserter-plugin
 */

// Plugin directory.
define( 'SCAIP_PLUGIN_FILE', __FILE__ );

/**
 * Shortcode addition.
 */
require_once __DIR__ . '/inc/scaip-shortcode-inserter.php';
require_once __DIR__ . '/inc/scaip-shortcode.php';
require_once __DIR__ . '/blocks/scaip-sidebar.php';

/**
 * Settings.
 */
require_once __DIR__ . '/inc/scaip-settings.php';
require_once __DIR__ . '/inc/scaip-metaboxes.php';
