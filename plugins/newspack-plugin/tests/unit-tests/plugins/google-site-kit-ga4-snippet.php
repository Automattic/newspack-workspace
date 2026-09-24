<?php
/**
 * Tests for whether Newspack forces Site Kit's GA4 gtag snippet on during setup.
 *
 * @package Newspack\Tests
 */

use Newspack\GoogleSiteKit;

/**
 * Test the GA4 gtag snippet-forcing decision.
 *
 * Newspack enables Site Kit's GA4 gtag snippet during setup so its reader custom
 * dimensions ride along with the GA4 page_view. On a site that already tags GA4 through
 * a Google Tag Manager container, that gtag is a second GA4 page_view feed (duplicate
 * counting). This decision is the seam Newspack Manager uses to turn the gtag off once it
 * has confirmed - from real GA4 beacons - that a container is independently sending GA4.
 *
 * @group GoogleSiteKit_Snippet
 */
class Newspack_Test_GoogleSiteKit_Force_GA4_Snippet extends WP_UnitTestCase {

	/**
	 * Clean up filters between tests so the default-behaviour test is not polluted.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_googlesitekit_force_ga4_snippet' );
		parent::tear_down();
	}

	/**
	 * The snippet is forced on by default. This is the safe choice: WordPress cannot see
	 * whether a placed GTM container actually carries a GA4 tag, so dropping the gtag by
	 * default would leave any site whose GTM lacks GA4 with no GA4 tag at all.
	 */
	public function test_forces_snippet_by_default() {
		$this->assertTrue( GoogleSiteKit::should_force_ga4_snippet( 'G-ABC123' ) );
	}

	/**
	 * The decision is overridable via filter, so Newspack Manager can switch the gtag off
	 * once it has confirmed a GTM container feeds the GA4 property.
	 */
	public function test_filter_can_disable_snippet() {
		add_filter( 'newspack_googlesitekit_force_ga4_snippet', '__return_false' );
		$this->assertFalse( GoogleSiteKit::should_force_ga4_snippet( 'G-ABC123' ) );
	}

	/**
	 * The measurement ID is passed to the filter so the decision can be made per-property -
	 * the manager keys its "GTM carries GA4" confirmation on the specific property.
	 */
	public function test_filter_receives_measurement_id() {
		$seen_measurement_id = null;
		add_filter(
			'newspack_googlesitekit_force_ga4_snippet',
			function ( $force_snippet, $measurement_id ) use ( &$seen_measurement_id ) {
				$seen_measurement_id = $measurement_id;
				return $force_snippet;
			},
			10,
			2
		);

		GoogleSiteKit::should_force_ga4_snippet( 'G-XYZ789' );

		$this->assertSame( 'G-XYZ789', $seen_measurement_id );
	}
}
