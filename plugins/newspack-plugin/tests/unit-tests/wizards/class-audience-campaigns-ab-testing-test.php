<?php
/**
 * Tests for the A/B Tests tab's flag in the Campaigns wizard.
 *
 * @package Newspack\Tests
 */

use Newspack\Audience_Campaigns;

/**
 * The tab shows only when newspack-popups says A/B testing is on.
 */
class Audience_Campaigns_AB_Testing_Test extends WP_UnitTestCase {
	/**
	 * Without newspack-popups the tab stays hidden. The suite does not load
	 * newspack-popups, and defining a stand-in Newspack_Popups would leak into
	 * every later test that checks for the class, so neither an older
	 * newspack-popups nor the enabled path is covered here.
	 */
	public function test_tab_is_hidden_without_newspack_popups() {
		if ( method_exists( 'Newspack_Popups', 'is_ab_testing_enabled' ) ) {
			$this->markTestSkipped( 'newspack-popups is loaded in this run.' );
		}
		$this->assertFalse( Audience_Campaigns::is_ab_testing_enabled() );
	}
}
