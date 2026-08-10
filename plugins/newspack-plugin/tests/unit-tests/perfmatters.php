<?php
/**
 * Tests the Perfmatters integration:
 * - Complianz RUCSS exclusions (NPPM-3052)
 * - Perfmatters integration WooCommerce veto (NPPM-193)
 *
 * @package Newspack\Tests
 */

use Newspack\Perfmatters;
use Newspack\WooCommerce_Content_Detector;

/**
 * Tests for Newspack's default Perfmatters configuration.
 *
 * @group perfmatters
 */
class Newspack_Test_Perfmatters extends WP_UnitTestCase {
	/**
	 * Newspack forces RUCSS on, so its defaults must exclude the Complianz
	 * stylesheets: the consent banner markup is injected client-side, its
	 * selectors never reach the server-generated used-CSS snapshot, and the
	 * delayed original stylesheet leaves the banner unstyled until user
	 * interaction or the delay timeout.
	 */
	public function test_complianz_stylesheets_excluded_from_rucss_defaults() {
		$options = Perfmatters::set_defaults( [] );

		$this->assertTrue( $options['assets']['remove_unused_css'] );
		$this->assertContains(
			'plugins/complianz-gdpr',
			$options['assets']['rucss_excluded_stylesheets'],
			'Complianz plugin-dir CSS (free + premium, prefix-matched) must be excluded from RUCSS.'
		);
		$this->assertContains(
			'uploads/complianz',
			$options['assets']['rucss_excluded_stylesheets'],
			'Complianz generated banner CSS in uploads must be excluded from RUCSS.'
		);
	}

	/**
	 * The backstop filter enforces the same exclusions even when a publisher's
	 * saved settings override the defaults, and preserves publisher entries.
	 */
	public function test_complianz_stylesheets_excluded_via_backstop_filter() {
		$this->assertNotFalse(
			has_filter( 'perfmatters_rucss_excluded_stylesheets', [ Perfmatters::class, 'add_rucss_excluded_stylesheets' ] ),
			'The backstop filter must be registered so exclusions apply even when saved settings override the defaults.'
		);

		$exclusions = Perfmatters::add_rucss_excluded_stylesheets( [ 'example-publisher-entry' ] );

		$this->assertContains( 'plugins/complianz-gdpr', $exclusions );
		$this->assertContains( 'uploads/complianz', $exclusions );
		$this->assertContains( 'example-publisher-entry', $exclusions );
	}

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
	public function test_ignore_defaults_leaves_backstop_untouched() {
		define( 'NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS', true );

		$input = [ 'example-publisher-entry' ];

		$this->assertSame( $input, Perfmatters::add_rucss_excluded_stylesheets( $input ) );
	}

	/**
	 * A saved option that already carries the manually-applied workaround entry
	 * doesn't end up with duplicates after the defaults merge.
	 */
	public function test_no_duplicate_exclusions_after_merge() {
		$options = Perfmatters::set_defaults(
			[
				'assets' => [
					'rucss_excluded_stylesheets' => [ 'plugins/complianz-gdpr' ],
				],
			]
		);

		$this->assertSame(
			array_unique( $options['assets']['rucss_excluded_stylesheets'] ),
			$options['assets']['rucss_excluded_stylesheets']
		);
		$this->assertContains( 'plugins/complianz-gdpr', $options['assets']['rucss_excluded_stylesheets'] );
	}
}
