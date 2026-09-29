<?php
/**
 * Tests request-utm merging for direct donation URLs.
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;

/**
 * Donation request UTM passthrough test case.
 */
class Newspack_Test_Donation_Request_Utm extends WP_UnitTestCase {
	/**
	 * Tear down test.
	 */
	public function tear_down() {
		$_GET = [];
		parent::tear_down();
	}

	/**
	 * Test merges request utm params only.
	 */
	public function test_merges_request_utm_params_only() {
		$_GET   = [
			'utm_source'         => 'newsletter',
			'donation_frequency' => 'month',
		];
		$result = Donations::merge_request_utm_params( [ 'utm_medium' => 'referer-value' ] );
		$this->assertSame( 'newsletter', $result['utm_source'] );
		$this->assertSame( 'referer-value', $result['utm_medium'] );
		$this->assertArrayNotHasKey( 'donation_frequency', $result );
	}

	/**
	 * Test request utm wins over referer utm.
	 */
	public function test_request_utm_wins_over_referer_utm() {
		$_GET   = [ 'utm_source' => 'direct' ];
		$result = Donations::merge_request_utm_params( [ 'utm_source' => 'referer' ] );
		$this->assertSame( 'direct', $result['utm_source'] );
	}
}
