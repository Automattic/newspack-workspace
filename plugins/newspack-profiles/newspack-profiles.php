<?php
/**
 * Plugin Name:       Newspack Profiles
 * Description:       Turn your Google Sheets or Airtable data into beautiful, SEO-optimized profile pages.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            Newspack
 * Author URI:        https://newspack.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       newspack-profiles
 * Domain Path:       /languages
 * Requires Plugins:  remote-data-blocks
 *
 * @package NewspackProfiles
 */

declare(strict_types=1);

namespace NewspackProfiles;

use NewspackProfiles\Registrars\Rewrite_Rule_Registrar;

defined( 'ABSPATH' ) || exit();

if ( ! defined( 'NEWSPACK_PROFILES_PLUGIN_DIR' ) ) {
	define( 'NEWSPACK_PROFILES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'NEWSPACK_PROFILES_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_PROFILES_PLUGIN_FILE', __FILE__ );
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if ( ! is_plugin_active( 'remote-data-blocks/remote-data-blocks.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						/* translators: %s: Link to the Remote Data Blocks plugin. */
						esc_html__( 'Newspack Profiles requires the %s plugin to be installed and activated.', 'newspack-profiles' ),
						'<a href="https://wordpress.org/plugins/remote-data-blocks/" target="_blank" rel="noopener noreferrer">Remote Data Blocks</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	);
	return;
}

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						/* translators: %s: Command to run Composer install. */
						esc_html__( 'Newspack Profiles plugin was not properly built. Please run %s in the plugin directory.', 'newspack-profiles' ),
						'<code>composer install</code>'
					);
					?>
				</p>
			</div>
			<?php
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

const BUILD_DIR     = __DIR__ . '/dist/';
const BLOCKS_DIR    = BUILD_DIR . 'blocks/';
const PATTERNS_DIR  = __DIR__ . '/patterns/';
const TEMPLATES_DIR = __DIR__ . '/templates/';

Plugin::get_instance();

/**
 * Flush rewrite rules on plugin activation.
 */
function activate_newspack_profiles() {
	Page_Template_Manager::get_instance()->register_post_type();
	Rewrite_Rule_Registrar::get_instance()->register_rewrite_rules();

	flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activate_newspack_profiles' );

/**
 * Flush rewrite rules on plugin deactivation.
 */
function deactivate_newspack_profiles() {
	flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate_newspack_profiles' );
