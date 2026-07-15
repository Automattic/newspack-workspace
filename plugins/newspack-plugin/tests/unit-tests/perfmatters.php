<?php
/**
 * Tests for the Perfmatters integration WooCommerce veto.
 *
 * @package Newspack
 */

use Newspack\Perfmatters;
use Newspack\WooCommerce_Content_Detector;

/**
 * Test the perfmatters_disable_woocommerce_scripts callback.
 */
class Newspack_Test_Perfmatters extends WP_UnitTestCase {

	/**
	 * Reset the detector memo before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		WooCommerce_Content_Detector::reset_memo();
	}

	/**
	 * Reset the detector memo after each test.
	 */
	public function tearDown(): void {
		WooCommerce_Content_Detector::reset_memo();
		parent::tearDown();
	}

	/**
	 * When WooCommerce content is present, the callback vetoes the strip
	 * (returns false) regardless of the incoming value.
	 */
	public function test_vetoes_when_wc_content_present() {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:woocommerce/product-category /-->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertFalse( Perfmatters::maybe_keep_woocommerce_assets( true ) );
	}

	/**
	 * When no WooCommerce content is present, the callback passes the incoming
	 * value through unchanged.
	 */
	public function test_passes_through_when_no_wc_content() {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( Perfmatters::maybe_keep_woocommerce_assets( true ) );
		$this->assertFalse( Perfmatters::maybe_keep_woocommerce_assets( false ) );
	}

	/**
	 * With NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS defined, the callback returns the
	 * incoming value untouched and never consults the detector.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ignore_defaults_passes_through() {
		define( 'NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS', true );
		$this->assertTrue( Perfmatters::maybe_keep_woocommerce_assets( true ) );
		$this->assertFalse( Perfmatters::maybe_keep_woocommerce_assets( false ) );
	}
}
