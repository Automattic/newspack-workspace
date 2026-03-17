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
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
	$wpdb->esc_like( '_transient_clawpress_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_clawpress_' ) . '%'
) );

// Remove ClawPress-managed Application Passwords.
$all_user_ids = get_users( array( 'fields' => 'ID' ) );
foreach ( $all_user_ids as $uid ) {
	$passwords = WP_Application_Passwords::get_user_application_passwords( $uid );
	foreach ( $passwords as $item ) {
		if ( in_array( $item['name'], array( 'OpenClaw', 'ClawPress Auto-Provisioned' ), true ) ) {
			WP_Application_Passwords::delete_application_password( $uid, $item['uuid'] );
		}
	}
}

// Remove provisioner user meta.
$clawpress_meta_keys = array(
	'_clawpress_provisioned',
	'_clawpress_provisioned_at',
	'_clawpress_provisioned_ip',
	'_clawpress_fingerprint',
	'_clawpress_verified',
	'_clawpress_verified_at',
	'_clawpress_verified_email',
);
foreach ( $clawpress_meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ) );
}

// Remove comment meta.
$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => '_clawpress_mentions' ) );

// Remove rate limit option.
delete_option( '_clawpress_rate_limits' );
