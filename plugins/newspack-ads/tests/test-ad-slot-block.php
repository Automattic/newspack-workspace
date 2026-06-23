<?php
/**
 * Tests for the Ad Slot block.
 *
 * @package Newspack_Ads\Tests
 */

/**
 * Ad Slot block tests.
 */
class AdSlotBlockTest extends WP_UnitTestCase {

	/**
	 * The block should be registered with WordPress.
	 */
	public function test_block_is_registered() {
		$registry = WP_Block_Type_Registry::get_instance();
		self::assertTrue(
			$registry->is_registered( 'newspack-ads/ad-slot' ),
			'newspack-ads/ad-slot block should be registered'
		);
	}

	/**
	 * An empty placement attribute renders nothing.
	 */
	public function test_render_with_empty_placement() {
		$html = \Newspack_Ads\Ad_Slot_Block::render_block( [ 'placement' => '' ] );
		self::assertSame( '', $html );
	}

	/**
	 * An unknown placement key renders nothing.
	 */
	public function test_render_with_unknown_placement() {
		$html = \Newspack_Ads\Ad_Slot_Block::render_block( [ 'placement' => 'does_not_exist' ] );
		self::assertSame( '', $html );
	}

	/**
	 * A registered placement with no ad unit bound renders nothing.
	 */
	public function test_render_with_registered_placement_no_assignment() {
		// Force the block-theme branch so block placements are registered.
		add_filter( 'newspack_ads_is_block_theme', '__return_true' );

		// Reset and re-register placements.
		$ref      = new \ReflectionClass( \Newspack_Ads\Placements::class );
		$prop     = $ref->getProperty( 'placements' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
		\Newspack_Ads\Placements::register_default_placements();

		$html = \Newspack_Ads\Ad_Slot_Block::render_block( [ 'placement' => 'global_above_header' ] );
		self::assertSame( '', $html );

		remove_filter( 'newspack_ads_is_block_theme', '__return_true' );
	}

	/**
	 * When a registered placement's hook is fired, the block's render callback
	 * captures the hook's output.
	 */
	public function test_render_captures_hook_output() {
		// Force the block-theme branch so block placements are registered.
		add_filter( 'newspack_ads_is_block_theme', '__return_true' );

		// Reset placement registry and re-register so we hold the hook subscription.
		$ref  = new \ReflectionClass( \Newspack_Ads\Placements::class );
		$prop = $ref->getProperty( 'placements' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
		\Newspack_Ads\Placements::register_default_placements();

		// Plant a known-output hook listener on the synthetic hook for `global_above_header`.
		// This stands in for whatever inject_placement_ad would normally emit when
		// an ad unit is bound and a provider is active.
		$marker   = 'PLACEMENT_RENDERED_MARKER';
		$listener = function () use ( $marker ) {
			echo esc_html( $marker );
		};
		add_action( 'newspack_ads_block_placement_global_above_header', $listener, 999 );

		$html = \Newspack_Ads\Ad_Slot_Block::render_block( [ 'placement' => 'global_above_header' ] );
		self::assertStringContainsString( $marker, $html );

		remove_action( 'newspack_ads_block_placement_global_above_header', $listener, 999 );
		remove_filter( 'newspack_ads_is_block_theme', '__return_true' );
	}
}
