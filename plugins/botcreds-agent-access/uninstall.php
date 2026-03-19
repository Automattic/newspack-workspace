<?php
/**
 * Agent Access Uninstall
 *
 * Removes all plugin data when the plugin is deleted via wp-admin.
 *
 * @package Agent Access
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove Agent Access post meta from all posts.
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_agent_access_created' ) );

// Remove any transients we may have set.
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
	$wpdb->esc_like( '_transient_agent_access_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_agent_access_' ) . '%'
) );

// Remove Agent Access-managed Application Passwords.
$all_user_ids = get_users( array( 'fields' => 'ID' ) );
foreach ( $all_user_ids as $uid ) {
	$passwords = WP_Application_Passwords::get_user_application_passwords( $uid );
	foreach ( $passwords as $item ) {
		if ( in_array( $item['name'], array( 'Agent Access', 'Agent Access Auto-Provisioned' ), true ) ) {
			WP_Application_Passwords::delete_application_password( $uid, $item['uuid'] );
		}
	}
}

// Remove provisioner user meta.
$agent_access_meta_keys = array(
	'_agent_access_provisioned',
	'_agent_access_provisioned_at',
	'_agent_access_provisioned_ip',
	'_agent_access_fingerprint',
	'_agent_access_verified',
	'_agent_access_verified_at',
	'_agent_access_verified_email',
);
foreach ( $agent_access_meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ) );
}

// Remove comment meta.
$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => '_agent_access_mentions' ) );

// Remove rate limit option.
delete_option( '_agent_access_rate_limits' );
