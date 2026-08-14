<?php
/**
 * Tests that the Users-list network columns escape node-supplied output.
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Users;
use Newspack_Network\Site_Role;
use Newspack_Network\Utils\Users as Users_Utils;

/**
 * Verify the Network User and Network Activity columns are escaped.
 */
class Test_Users_Columns_Escaping extends WP_UnitTestCase {

	/**
	 * The Network User column must escape the remote-site anchor text.
	 */
	public function test_network_user_column_escapes_remote_site() {
		$payload = '<img src=x onerror=NPPM3042>';
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Users_Utils::USER_META_REMOTE_SITE, $payload );
		update_user_meta( $user_id, Users_Utils::USER_META_REMOTE_ID, 7 );

		$out = Users::manage_users_custom_column( '', 'newspack_network_user', $user_id );
		$this->assertStringNotContainsString( '<img src=x', $out );
		$this->assertStringContainsString( '&lt;img src=x onerror=NPPM3042&gt;', $out );
	}

	/**
	 * The Network Activity column must escape the node event summary.
	 *
	 * `canonical_url_updated` events derive their summary from the event's
	 * `data.url` field (not from `email`, which doubles as the Event_Log
	 * lookup key), so the payload can be carried there while the row's email
	 * stays a plain, matchable value.
	 */
	public function test_network_activity_column_escapes_summary() {
		update_option( Site_Role::OPTION_NAME, Site_Role::HUB_ROLE );

		$payload = '<img src=x onerror=NPPM3042>';
		$user    = self::factory()->user->create_and_get();

		global $wpdb;
		// Ensure the event-log table exists; it's created lazily on first access.
		$table_name = \Newspack_Network\Hub\Database\Event_Log::get_table_name();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			[
				'action_name' => 'canonical_url_updated',
				'node_id'     => 0,
				'email'       => $user->user_email,
				'data'        => wp_json_encode( [ 'url' => $payload ] ),
				'timestamp'   => time(),
			],
			[ '%s', '%d', '%s', '%s', '%d' ]
		);

		$out = Users::manage_users_custom_column( '', 'newspack_network_activity', $user->ID );
		$this->assertStringNotContainsString( '<img src=x', $out );
		$this->assertStringContainsString( '&lt;img src=x onerror=NPPM3042&gt;', $out );
	}
}
