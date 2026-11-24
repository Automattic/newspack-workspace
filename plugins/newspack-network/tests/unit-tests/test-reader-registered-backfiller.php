<?php
/**
 * Tests for the reader_registered backfiller.
 *
 * @package Newspack_Network
 */

use Newspack_Network\Backfillers\Reader_Registered;

require_once dirname( __DIR__ ) . '/class-reader-activation.php';

/**
 * Tests for the reader_registered backfiller.
 */
class Test_Reader_Registered_Backfiller extends WP_UnitTestCase {

	/**
	 * With nobody eligible the backfiller must yield nothing. Loading users by an empty
	 * `include` list matches every user on the site, so it must never be queried.
	 */
	public function test_get_events_yields_nothing_when_no_user_is_eligible() {
		self::factory()->user->create(
			[
				'role'            => 'subscriber',
				'user_registered' => '2020-01-01 00:00:00',
			]
		);

		$backfiller = new Reader_Registered( '2030-01-01', '2030-12-31', false, true );

		$this->assertSame( [], iterator_to_array( $backfiller->get_events(), false ) );
	}
}
