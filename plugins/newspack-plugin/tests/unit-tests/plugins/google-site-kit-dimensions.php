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
	 * Access_source only fires on sites running Access Control, so it only
	 * registers there.
	 */
	public function test_access_source_tracks_the_access_control_flag() {
		$dimensions = GA4_Custom_Dimensions::get_dimensions();
		if ( Newspack\Content_Gate::is_newspack_feature_enabled() ) {
			$this->assertArrayHasKey( 'access_source', $dimensions );
			$this->assertSame( 'Access Source', $dimensions['access_source'] );
		} else {
			$this->assertArrayNotHasKey( 'access_source', $dimensions );
		}
	}
}
