<?php
/**
 * ClawPress Uninstall
 *
 * Removes all plugin data when the plugin is deleted via wp-admin.
 *
 * @package ClawPress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove ClawPress post meta from all posts.
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_clawpress_created' ) );

// Remove any transients we may have set.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_clawpress\_%'
	 OR option_name LIKE '_transient_timeout_clawpress\_%'"
);

// Note: We do NOT delete Application Passwords here — that would break
// existing connections and is a destructive action beyond plugin scope.
// Users should revoke connections manually before uninstalling.
