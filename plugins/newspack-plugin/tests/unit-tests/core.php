<?php
/**
 * Tests the base/util Newspack functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack;

/**
 * Test base/util functionality.
 */
class Newspack_Test_Newspack extends WP_UnitTestCase {

	/**
	 * Test that Newspack has been successfully loaded into the test suite.
	 */
	public function test_newspack_loaded() {
		$this->assertTrue( defined( 'NEWSPACK_VERSION' ) );
	}

	/**
	 * Test that the Newspack class is set up correctly.
	 */
	public function test_newspack_class() {
		$newspack = Newspack::instance();

		$this->assertInstanceOf( Newspack::class, $newspack );
		$this->assertSame( $newspack, Newspack::instance() );
	}
}
