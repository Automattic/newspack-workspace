<?php
/**
 * Tests request-utm merging for direct checkout URLs.
 *
 * @package Newspack_Blocks
 */

use Newspack_Blocks\Modal_Checkout;

/**
 * Modal checkout UTM passthrough test case.
 */
class Newspack_Blocks_Test_Modal_Checkout_Utm extends WP_UnitTestCase {
	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		$_GET = [];
		parent::tear_down();
	}

	/**
	 * Test that only utm params are merged.
	 */
	public function test_merges_request_utm_params_only() {
		$_GET   = [
			'utm_source'   => 'newsletter',
			'utm_campaign' => 'spring',
			'coupon'       => 'NOPE',
		];
		$result = Modal_Checkout::merge_request_utm_params( [ 'utm_medium' => 'referer-value' ] );
		$this->assertSame( 'newsletter', $result['utm_source'] );
		$this->assertSame( 'spring', $result['utm_campaign'] );
		$this->assertSame( 'referer-value', $result['utm_medium'] );
		$this->assertArrayNotHasKey( 'coupon', $result );
	}

	/**
	 * Test that request utm params override referer utm params.
	 */
	public function test_request_utm_wins_over_referer_utm() {
		$_GET   = [ 'utm_source' => 'direct' ];
		$result = Modal_Checkout::merge_request_utm_params( [ 'utm_source' => 'referer' ] );
		$this->assertSame( 'direct', $result['utm_source'] );
	}

	/**
	 * Test that empty and non-string values are ignored.
	 */
	public function test_ignores_empty_and_non_string_values() {
		$_GET   = [
			'utm_source' => [ 'array' ],
			'utm_medium' => '',
		];
		$result = Modal_Checkout::merge_request_utm_params( [] );
		$this->assertSame( [], $result );
	}
}
