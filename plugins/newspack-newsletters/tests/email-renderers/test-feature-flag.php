<?php
/**
 * Class Feature Flag Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Feature_Flag;

/**
 * Feature Flag Test.
 */
class Test_Feature_Flag extends WP_UnitTestCase {
	/**
	 * Test that the Woo renderer feature flag is disabled by default.
	 */
	public function test_disabled_by_default() {
		$this->assertFalse( Feature_Flag::is_enabled() );
	}

	/**
	 * Test that the feature flag is enabled when the option is set.
	 */
	public function test_enabled_by_option() {
		update_option( 'newspack_newsletters_use_woo_renderer', '1' );
		$this->assertTrue( Feature_Flag::is_enabled() );
		delete_option( 'newspack_newsletters_use_woo_renderer' );
	}

	/**
	 * Test that the filter overrides the option value (filter wins over an enabled option).
	 */
	public function test_filter_overrides_option() {
		update_option( 'newspack_newsletters_use_woo_renderer', '1' );
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		$this->assertFalse( Feature_Flag::is_enabled() );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		delete_option( 'newspack_newsletters_use_woo_renderer' );
	}

	/**
	 * Test that the NEWSPACK_NEWSLETTERS_WOO_RENDERER constant overrides the option.
	 */
	public function test_constant_overrides_option() {
		if ( ! defined( 'NEWSPACK_NEWSLETTERS_WOO_RENDERER' ) ) {
			define( 'NEWSPACK_NEWSLETTERS_WOO_RENDERER', true );
		}
		delete_option( 'newspack_newsletters_use_woo_renderer' );
		$this->assertTrue( Feature_Flag::is_enabled() );
	}
}
