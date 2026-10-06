<?php
/**
 * Tests for the reader data flags sent as GA4 custom parameters.
 *
 * @package Newspack\Tests
 */

use Newspack\GoogleSiteKit;
use Newspack\Reader_Data;

/**
 * The `is_donor` and `is_newsletter_subscriber` parameters follow the reader's
 * current state, so a reader who stops donating or leaves every newsletter is
 * reported as "no" from then on.
 *
 * @group GoogleSiteKit_Reader_Data
 */
class Newspack_Test_GoogleSiteKit_Reader_Data extends WP_UnitTestCase {

	/**
	 * The signed-in reader.
	 *
	 * @var int
	 */
	private $reader_id;

	/**
	 * Sign in a reader for each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->reader_id );
	}

	/**
	 * Sign the reader out.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Cancelling a recurring donation makes the reader a former donor, who is
	 * no longer reported as a donor.
	 */
	public function test_former_donor_is_not_reported_as_donor() {
		Reader_Data::set_is_donor( time(), [ 'user_id' => $this->reader_id ] );
		$this->assertSame( 'yes', GoogleSiteKit::get_custom_event_parameters()['is_donor'] );

		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $this->reader_id ] );
		$this->assertSame( 'no', GoogleSiteKit::get_custom_event_parameters()['is_donor'] );
	}

	/**
	 * A reader who leaves their last newsletter list is no longer reported as a
	 * newsletter subscriber.
	 */
	public function test_reader_who_left_every_newsletter_is_not_reported_as_subscriber() {
		Reader_Data::update_newsletter_subscribed_lists(
			time(),
			[
				'user_id' => $this->reader_id,
				'lists'   => [ 'list-1' ],
			]
		);
		$this->assertSame( 'yes', GoogleSiteKit::get_custom_event_parameters()['is_newsletter_subscriber'] );

		Reader_Data::update_newsletter_subscribed_lists(
			time(),
			[
				'user_id'       => $this->reader_id,
				'lists_removed' => [ 'list-1' ],
			]
		);
		$this->assertSame( 'no', GoogleSiteKit::get_custom_event_parameters()['is_newsletter_subscriber'] );
	}
}
