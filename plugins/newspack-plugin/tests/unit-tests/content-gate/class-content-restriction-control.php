<?php
/**
 * Tests for Content_Restriction_Control (NPPM-2982).
 *
 * @package Newspack
 */

use Newspack\Content_Restriction_Control;

/**
 * Test_Content_Restriction_Control.
 */
class Test_Content_Restriction_Control extends WP_UnitTestCase {

	/**
	 * Reset registered meta between tests.
	 */
	public function tear_down() {
		unregister_meta_key( 'post', Content_Restriction_Control::IS_EXEMPT_META_KEY, 'post' );
		parent::tear_down();
	}

	/**
	 * Runs in a separate process so that other content-gate test classes
	 * defining NEWSPACK_CONTENT_GATES=true in their setUp (a constant, so it
	 * can never become undefined again once defined) can't leak into this
	 * test and make it see the feature as already enabled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_meta_not_registered_when_feature_disabled() {
		// NEWSPACK_CONTENT_GATES is undefined in the default test env.
		Content_Restriction_Control::register_meta();
		$this->assertFalse(
			registered_meta_key_exists( 'post', Content_Restriction_Control::IS_EXEMPT_META_KEY, 'post' )
		);
	}
}
