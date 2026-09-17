<?php
/**
 * Tests for the integrations push log.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integrations\Push_Log;

/**
 * Push log test case.
 *
 * The push log is the publisher-facing record of what was sent to each
 * integration: one row per reader, integration and triggering push.
 *
 * @group integrations
 * @group push-log
 */
class Test_Push_Log extends \WP_UnitTestCase {

	/**
	 * The lookups the log exists for (by reader email, by account, and the
	 * per-integration lists) each rely on an index. Without one they scan the
	 * whole table on every admin page load.
	 */
	public function test_table_has_the_indexes_the_lookups_rely_on() {
		global $wpdb;
		$index_rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', Push_Log::get_table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$index_names = array_unique( wp_list_pluck( $index_rows, 'Key_name' ) );

		foreach ( [ 'PRIMARY', 'email', 'user_id', 'integration_updated', 'integration_status' ] as $expected_index ) {
			$this->assertContains( $expected_index, $index_names );
		}
	}
}
