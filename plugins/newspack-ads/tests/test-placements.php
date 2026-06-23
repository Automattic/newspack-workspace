<?php
/**
 * Tests for Placements registration.
 *
 * @package Newspack_Ads\Tests
 */

use Newspack_Ads\Placements;

/**
 * Placements registration tests.
 */
class PlacementsTest extends WP_UnitTestCase {

	/**
	 * Reset Placements registry between tests.
	 */
	public function set_up() {
		parent::set_up();
		// Reset the static registry via reflection so each test starts clean.
		$ref      = new \ReflectionClass( Placements::class );
		$prop     = $ref->getProperty( 'placements' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}

	/**
	 * When the active theme is a classic theme, classic global placements are registered.
	 *
	 * Explicitly forces the classic branch via the newspack_ads_is_block_theme filter
	 * so the test is stable regardless of which theme WP ships as the bootstrap default.
	 */
	public function test_register_default_placements_classic_theme() {
		add_filter( 'newspack_ads_is_block_theme', '__return_false' );

		Placements::register_default_placements();
		$placements = Placements::get_placements();

		self::assertArrayHasKey( 'global_above_header', $placements );
		self::assertArrayHasKey( 'global_below_header', $placements );
		self::assertArrayHasKey( 'global_above_footer', $placements );
		self::assertArrayHasKey( 'sticky', $placements );

		// Classic placements should NOT have the synthetic block hook.
		self::assertNotSame( 'newspack_ads_block_placement_sticky', $placements['sticky']['hook_name'] );

		remove_filter( 'newspack_ads_is_block_theme', '__return_false' );
	}

	/**
	 * Block-rendered placements register with the expected keys and synthetic hook names.
	 */
	public function test_register_block_placements() {
		Placements::register_block_placements();
		$placements = Placements::get_placements();

		$expected_keys = [
			'global_above_header',
			'global_below_header',
			'global_above_footer',
			'sticky',
			'above_content',
			'below_content',
		];

		foreach ( $expected_keys as $key ) {
			self::assertArrayHasKey( $key, $placements, "Missing placement: $key" );
			self::assertSame(
				'newspack_ads_block_placement_' . $key,
				$placements[ $key ]['hook_name'],
				"Wrong hook_name for placement: $key"
			);
			self::assertTrue(
				$placements[ $key ]['block_rendered'],
				"Missing block_rendered flag for placement: $key"
			);
		}
	}

	/**
	 * When the active theme is a block theme, register_default_placements() registers
	 * the four classic-paired keys with synthetic block hook_names (so saved settings
	 * survive theme switches) plus two block-only content placements.
	 */
	public function test_register_default_placements_block_theme() {
		add_filter( 'newspack_ads_is_block_theme', '__return_true' );

		Placements::register_default_placements();
		$placements = Placements::get_placements();

		$expected = [
			'global_above_header' => 'newspack_ads_block_placement_global_above_header',
			'global_below_header' => 'newspack_ads_block_placement_global_below_header',
			'global_above_footer' => 'newspack_ads_block_placement_global_above_footer',
			'sticky'              => 'newspack_ads_block_placement_sticky',
			'above_content'       => 'newspack_ads_block_placement_above_content',
			'below_content'       => 'newspack_ads_block_placement_below_content',
		];
		foreach ( $expected as $key => $hook_name ) {
			self::assertArrayHasKey( $key, $placements, "Missing placement: $key" );
			self::assertSame(
				$hook_name,
				$placements[ $key ]['hook_name'],
				"Wrong hook_name for placement: $key (should be synthetic block hook, not the classic one)"
			);
		}

		remove_filter( 'newspack_ads_is_block_theme', '__return_true' );
	}

	/**
	 * Sidebar (widget-area) placements should NOT register when the active theme
	 * is a block theme. Block themes don't have classic widget areas.
	 */
	public function test_sidebar_placements_skipped_on_block_theme() {
		add_filter( 'newspack_ads_is_block_theme', '__return_true' );
		register_sidebar(
			[
				'id'   => 'test-sidebar',
				'name' => 'Test Sidebar',
			]
		);

		// Reset the static $placements registry so we observe a clean run.
		$ref  = new \ReflectionClass( \Newspack_Ads\Placements::class );
		$prop = $ref->getProperty( 'placements' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );

		\Newspack_Ads\Sidebar_Placements::register_placements();
		$keys = array_keys( \Newspack_Ads\Placements::get_placements() );

		$sidebar_keys = array_filter( $keys, fn( $k ) => str_starts_with( $k, 'sidebar_' ) );
		self::assertSame(
			[],
			array_values( $sidebar_keys ),
			'No sidebar_* placements should be registered on a block theme'
		);

		unregister_sidebar( 'test-sidebar' );
		remove_filter( 'newspack_ads_is_block_theme', '__return_true' );
	}
}
