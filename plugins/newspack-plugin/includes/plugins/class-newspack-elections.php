<?php
/**
 * Newspack Elections Block Patterns.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * Newspack_Elections class.
 */
class Newspack_Elections {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register_block_patterns' ] );
	}

	/**
	 * Get block patterns.
	 *
	 * Each pattern content should be a PHP file in the block-patterns directory
	 * named after the pattern slug.
	 *
	 * @return array
	 */
	public static function get_block_patterns() {
		return [
			'home-pre-results-ap'    => __( 'Homepage Pre-Results Module — AP', 'newspack-plugin' ),
			'home-results-ap'        => __( 'Homepage Results Module — AP', 'newspack-plugin' ),
			'live-results-post-ap'   => __( 'Live Election Results Post — AP', 'newspack-plugin' ),
			'home-pre-results-ddhq'  => __( 'Homepage Pre-Results Module — DDHQ', 'newspack-plugin' ),
			'home-results-ddhq'      => __( 'Homepage Results Module — DDHQ', 'newspack-plugin' ),
			'live-results-post-ddhq' => __( 'Live Election Results Post — DDHQ', 'newspack-plugin' ),
		];
	}

	/**
	 * Register block patterns.
	 */
	public static function register_block_patterns() {
		\register_block_pattern_category( 'newspack-plugin', [ 'label' => __( 'Newspack Elections', 'newspack-plugin' ) ] );
		$patterns = self::get_block_patterns();
		foreach ( $patterns as $slug => $title ) {
			$path = dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/block-patterns/elections/' . $slug . '.php';
			if ( ! file_exists( $path ) ) {
				continue;
			}
			ob_start();
			require $path;
			$content = ob_get_clean();
			if ( empty( $content ) ) {
				continue;
			}
			\register_block_pattern(
				'newspack-elections/' . $slug,
				[
					'categories'  => [ 'newspack-plugin' ],
					'title'       => $title,
					'description' => _x( 'Help format and provide context to AP and DDHQ elections embeds.', 'Block pattern description', 'newspack-plugin' ),
					'content'     => $content,
				]
			);
		}
	}
}
Newspack_Elections::init();
