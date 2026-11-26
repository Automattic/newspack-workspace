<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class HomepagePostsImageSizesTest
 *
 * @package Newspack_Blocks
 */

/**
 * The `sizes` attribute Homepage Posts gives its thumbnails.
 *
 * Each expected value reads as "desktop, tablet, mobile": the width the
 * thumbnail takes in the block's CSS at each breakpoint, so the browser
 * downloads an image no larger than the slot it fills.
 */
class HomepagePostsImageSizesTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		global $newspack_blocks_hpb_current_theme;
		$newspack_blocks_hpb_current_theme = 'newspack-theme';
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		global $newspack_blocks_hpb_current_theme, $newspack_blocks_hpb_rendering_context;
		$newspack_blocks_hpb_current_theme     = null;
		$newspack_blocks_hpb_rendering_context = null;
		parent::tear_down();
	}

	/**
	 * Run the sizes filter for a block with the given attributes.
	 *
	 * @param array $attributes Block attributes overriding the block defaults.
	 * @return string The `sizes` value.
	 */
	private function sizes_for( $attributes ) {
		global $newspack_blocks_hpb_rendering_context;
		$newspack_blocks_hpb_rendering_context = [
			'attrs' => array_merge(
				[
					'postLayout'    => 'list',
					'columns'       => 3,
					'mediaPosition' => 'top',
					'imageScale'    => 3,
					'mobileStack'   => false,
				],
				$attributes
			),
		];
		return newspack_blocks_filter_hpb_sizes( 'wp-default' );
	}

	/**
	 * Layouts and the slot their thumbnail fills at each breakpoint.
	 */
	public function layouts() {
		return [
			'side thumbnail stays beside text on mobile' => [
				[ 'mediaPosition' => 'left' ],
				'(min-width: 782px) 600px, (min-width: 600px) 45vw, 30vw',
			],
			'side thumbnail stacks on mobile'            => [
				[
					'mediaPosition' => 'right',
					'mobileStack'   => true,
				],
				'(min-width: 782px) 600px, (min-width: 600px) 45vw, 90vw',
			],
			'smallest image scale is a quarter at every width' => [
				[
					'mediaPosition' => 'left',
					'imageScale'    => 1,
				],
				'(min-width: 782px) 300px, (min-width: 600px) 22.5vw, 22.5vw',
			],
			'largest image scale in a three-column grid' => [
				[
					'postLayout'    => 'grid',
					'mediaPosition' => 'left',
					'imageScale'    => 4,
				],
				'(min-width: 782px) 300px, (min-width: 600px) 22.5vw, 30vw',
			],
			'top image in a four-column grid'            => [
				[
					'postLayout' => 'grid',
					'columns'    => 4,
				],
				'(min-width: 782px) 300px, (min-width: 600px) 45vw, 90vw',
			],
			'six-column grid shows three columns on tablet' => [
				[
					'postLayout' => 'grid',
					'columns'    => 6,
				],
				'(min-width: 782px) 200px, (min-width: 600px) 30vw, 90vw',
			],
		];
	}

	/**
	 * The `sizes` value follows the layout's thumbnail width at each breakpoint.
	 *
	 * @dataProvider layouts
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $expected   Expected `sizes` value.
	 */
	public function test_sizes_follow_thumbnail_layout( $attributes, $expected ) {
		$this->assertSame( $expected, $this->sizes_for( $attributes ) );
	}

	/**
	 * Layouts whose thumbnail width depends on more than the block's own settings.
	 */
	public function layouts_without_derivable_width() {
		return [
			'full-width list image' => [ [] ],
			'wide-aligned grid'     => [
				[
					'postLayout' => 'grid',
					'align'      => 'wide',
				],
			],
		];
	}

	/**
	 * These layouts keep the WordPress default `sizes`.
	 *
	 * Guards the early returns in the width helper, so the per-breakpoint
	 * value is never applied to a layout it doesn't describe.
	 *
	 * @dataProvider layouts_without_derivable_width
	 *
	 * @param array $attributes Block attributes.
	 */
	public function test_unknown_width_keeps_default_sizes( $attributes ) {
		$this->assertSame( 'wp-default', $this->sizes_for( $attributes ) );
	}
}
