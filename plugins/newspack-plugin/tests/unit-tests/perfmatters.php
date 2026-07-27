<?php
/**
 * Tests the Perfmatters integration: Complianz RUCSS exclusions (NPPM-3052).
 *
 * @package Newspack\Tests
 */

use Newspack\Perfmatters;

/**
 * Tests that Complianz stylesheets are excluded from the "Remove Unused CSS"
 * feature that Newspack's Perfmatters defaults force on.
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
	 * With NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS defined, the backstop filter
	 * returns its input untouched — exclusions are only force-injected when
	 * Newspack is managing Perfmatters.
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
