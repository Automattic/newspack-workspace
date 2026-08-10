<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ImageSizesTest
 *
 * @package Newspack_Blocks
 */

/**
 * Tests for Newspack article block image sub-size generation.
 */
class ImageSizesTest extends WP_UnitTestCase { // phpcs:ignore

	/**
	 * Sample sub-sizes as passed to `intermediate_image_sizes_advanced`:
	 * core sizes plus the Newspack article block crops.
	 *
	 * @return array
	 */
	private function sample_sizes() {
		$sizes = [
			'thumbnail'    => [
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
			],
			'medium'       => [
				'width'  => 300,
				'height' => 300,
				'crop'   => false,
			],
			'medium_large' => [
				'width'  => 768,
				'height' => 0,
				'crop'   => false,
			],
			'large'        => [
				'width'  => 1024,
				'height' => 1024,
				'crop'   => false,
			],
		];
		foreach ( [ 'landscape', 'portrait', 'square' ] as $orientation ) {
			foreach ( [ 'large', 'medium', 'intermediate', 'small', 'tiny' ] as $tier ) {
				$sizes[ "newspack-article-block-{$orientation}-{$tier}" ] = [
					'width'  => 400,
					'height' => 300,
					'crop'   => true,
				];
			}
		}
		$sizes['newspack-article-block-uncropped'] = [
			'width'  => 1200,
			'height' => 9999,
			'crop'   => false,
		];
		return $sizes;
	}

	/**
	 * Returns the article-block size keys present in a sizes array.
	 *
	 * @param array $sizes Sizes array.
	 * @return string[]
	 */
	private function article_block_keys( $sizes ) {
		return array_values(
			array_filter(
				array_keys( $sizes ),
				function ( $key ) {
					return str_starts_with( $key, 'newspack-article-block-' );
				}
			)
		);
	}

	/**
	 * Default (no filter, no on-the-fly image CDN detected): all article block crops
	 * are left in place.
	 *
	 * With no filter attached this exercises the real default — `is_wpcom_image_cdn_active()`,
	 * which returns false in the test env because the Jetpack Status\Host and Jetpack classes
	 * aren't loaded — so it also covers the `class_exists` guard, the branch most likely to
	 * regress.
	 */
	public function test_article_block_subsizes_kept_by_default() {
		$filtered = apply_filters( 'intermediate_image_sizes_advanced', $this->sample_sizes(), [], 0 );

		$this->assertCount( 16, $this->article_block_keys( $filtered ), 'All 16 article block crops should be retained.' );
	}

	/**
	 * Skipping enabled (wpcom / image CDN): article block crops are removed,
	 * core sizes are retained.
	 */
	public function test_article_block_subsizes_removed_when_skipping() {
		add_filter( 'newspack_blocks_skip_article_image_subsizes', '__return_true' );
		$filtered = apply_filters( 'intermediate_image_sizes_advanced', $this->sample_sizes(), [], 0 );
		remove_filter( 'newspack_blocks_skip_article_image_subsizes', '__return_true' );

		$this->assertSame( [], $this->article_block_keys( $filtered ), 'No article block crops should be generated when skipping.' );
		$this->assertArrayHasKey( 'thumbnail', $filtered, 'Core thumbnail size must be retained.' );
		$this->assertArrayHasKey( 'large', $filtered, 'Core large size must be retained.' );
		$this->assertCount( 4, $filtered, 'Only the four core sizes should remain.' );
	}
}
