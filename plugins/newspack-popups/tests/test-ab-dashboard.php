<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for the A/B Tests dashboard: provider seam, stats route and page.
 *
 * @package Newspack_Popups
 */

require_once __DIR__ . '/mocks/class-ab-stub-provider.php';

/**
 * Dashboard tests.
 *
 * @group ab-tests
 */
class ABDashboardTest extends WP_UnitTestCase_PageWithPopups {
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		Newspack_Popups_AB_Tests::reset_config_cache();
		remove_all_filters( 'newspack_popups_ab_stats_provider' );
	}

	/**
	 * Without a filter there is no provider.
	 */
	public function test_no_provider_by_default() {
		$this->assertNull( Newspack_Popups_AB_Dashboard::get_provider() );
	}

	/**
	 * The filter supplies the provider.
	 */
	public function test_filter_supplies_the_provider() {
		$stub = new AB_Stub_Provider(
			[
				'unit'     => 'readers',
				'variants' => [],
			] 
		);
		add_filter( 'newspack_popups_ab_stats_provider', fn() => $stub );
		$this->assertSame( $stub, Newspack_Popups_AB_Dashboard::get_provider() );
	}

	/**
	 * A filter value that is not a provider is treated as none.
	 */
	public function test_filter_value_that_is_not_a_provider_is_ignored() {
		add_filter( 'newspack_popups_ab_stats_provider', fn() => new stdClass() );
		$this->assertNull( Newspack_Popups_AB_Dashboard::get_provider() );
	}
}
