<?php
/**
 * Tests conditional GA4 custom dimension provisioning.
 *
 * @package Newspack\Tests
 */

use Newspack\GA4_Custom_Dimensions;

/**
 * GA4 caps event-scoped custom dimensions at 50 and at least one publisher has
 * hit it, so a dimension that never fires must not spend a slot.
 *
 * @group GoogleSiteKit_Dimensions
 */
class Newspack_Test_GoogleSiteKit_Dimensions extends WP_UnitTestCase {

	/**
	 * The always-on dimensions are unaffected by the feature flag.
	 */
	public function test_core_dimensions_are_always_provisioned() {
		$dimensions = GA4_Custom_Dimensions::get_dimensions();
		$this->assertArrayHasKey( 'is_subscriber', $dimensions );
		$this->assertArrayHasKey( 'logged_in', $dimensions );
	}

	/**
	 * `access_source` only fires on sites running Access Control, so that is
	 * where it registers.
	 */
	public function test_access_source_is_provisioned_with_access_control_on() {
		// Defined here rather than in a beforeClass hook: PHPUnit runs those in
		// the child process too, which would hand the isolated feature-off test
		// below the very constant it exists to do without.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}

		$this->assertTrue(
			Newspack\Content_Gate::is_newspack_feature_enabled(),
			'This half is meaningless unless Access Control is actually on.'
		);

		$dimensions = GA4_Custom_Dimensions::get_dimensions();

		$this->assertArrayHasKey( 'access_source', $dimensions );
		$this->assertSame( 'Access Source', $dimensions['access_source'] );
	}

	/**
	 * Without Access Control the dimension never fires, so it must not spend one
	 * of the 50 event-scoped slots.
	 *
	 * Runs in a separate process because NEWSPACK_CONTENT_GATES is a constant:
	 * this class and a dozen sibling test classes define it, and once defined it
	 * can never be unset. Sharing a process would leave this assertion at the
	 * mercy of test ordering.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_access_source_is_not_provisioned_with_access_control_off() {
		$this->assertFalse(
			Newspack\Content_Gate::is_newspack_feature_enabled(),
			'Process isolation failed: the flag leaked in, so the off half was never tested.'
		);

		$dimensions = GA4_Custom_Dimensions::get_dimensions();

		$this->assertArrayNotHasKey( 'access_source', $dimensions );
		$this->assertArrayHasKey( 'is_subscriber', $dimensions, 'The always-on dimensions stay put.' );
	}
}
