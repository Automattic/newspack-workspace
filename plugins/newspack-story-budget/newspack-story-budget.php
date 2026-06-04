<?php
/**
 * Plugin Name: Newspack Story Budget
 * Description: Story budgeting by Newspack.
 * Version: 1.0.1
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL2
 * Text Domain: newspack-story-budget
 * Domain Path: /languages/
 *
 * @package Newspack_Story_Budget
 */

defined( 'ABSPATH' ) || exit;

define( 'NEWSPACK_STORY_BUDGET_VERSION', '1.0.1' );

// Define NEWSPACK_STORY_BUDGET_PLUGIN_DIR.
if ( ! defined( 'NEWSPACK_STORY_BUDGET_PLUGIN_DIR' ) ) {
	define( 'NEWSPACK_STORY_BUDGET_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Define NEWSPACK_STORY_BUDGET_PLUGIN_FILE.
if ( ! defined( 'NEWSPACK_STORY_BUDGET_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_STORY_BUDGET_PLUGIN_FILE', __FILE__ );
}

require_once __DIR__ . '/vendor/autoload.php';

Newspack_Story_Budget\Initializer::init();
