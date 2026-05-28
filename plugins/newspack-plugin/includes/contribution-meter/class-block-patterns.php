<?php
/**
 * Newspack Contribution Meter Block Patterns.
 *
 * @package Newspack
 */

namespace Newspack\Contribution_Meter;

defined( 'ABSPATH' ) || exit;

/**
 * Handles contribution meter block patterns registration.
 */
class Block_Patterns {

	/**
	 * Block pattern category slug.
	 */
	public const BLOCK_PATTERN_CATEGORY = 'newspack-contribution-meter';

	/**
	 * Path to block patterns.
	 */
	public const BLOCK_PATTERN_PATH = __DIR__ . '/block-patterns/{slug}.php';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register_block_patterns' ] );
	}

	/**
	 * Get list of block patterns.
	 *
	 * @return array Array of pattern slugs and titles.
	 */
	public static function get_block_patterns() {
		return [
			'contribution-1' => __( 'Two-column with cover image', 'newspack-plugin' ),
			'contribution-2' => __( 'Cover image with circular meter overlay', 'newspack-plugin' ),
			'contribution-3' => __( 'Vertical layout with image', 'newspack-plugin' ),
			'contribution-4' => __( 'Grid with circular meter highlight', 'newspack-plugin' ),
			'contribution-5' => __( 'Grid with image and thin meter', 'newspack-plugin' ),
		];
	}

	/**
	 * Register block patterns.
	 */
	public static function register_block_patterns() {
		\register_block_pattern_category(
			self::BLOCK_PATTERN_CATEGORY,
			[ 'label' => __( 'Newspack Contribution Meter', 'newspack-plugin' ) ]
		);

		$patterns = self::get_block_patterns();
		foreach ( $patterns as $slug => $title ) {
			$file_path = str_replace( '{slug}', $slug, self::BLOCK_PATTERN_PATH );
			if ( ! file_exists( $file_path ) ) {
				continue;
			}

			\register_block_pattern(
				'newspack-contribution-meter/' . $slug,
				[
					'title'       => $title,
					'description' => _x( 'A contribution meter pattern to showcase fundraising goals.', 'Block pattern description', 'newspack-plugin' ),
					'categories'  => [ self::BLOCK_PATTERN_CATEGORY ],
					'filePath'    => $file_path,
				]
			);
		}
	}
}
