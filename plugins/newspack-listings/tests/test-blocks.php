<?php
/**
 * Class Blocks Test
 *
 * @package Newspack_Listings
 */

use Newspack_Listings\Core;
use Newspack_Listings\Utils;

/**
 * Blocks test case.
 */
class BlocksTest extends WP_UnitTestCase {
	private static $listings           = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $default_attributes = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		// Remove any listings (from previous tests).
		foreach ( self::$listings as $listing_id ) {
			wp_delete_post( $listing_id );
		}

		// Set default block options for Curated List.
		$block_json = json_decode(
			file_get_contents( NEWSPACK_LISTINGS_PLUGIN_FILE . '/src/blocks/curated-list/block.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			true
		);
		foreach ( $block_json['attributes'] as $attribute => $options ) {
			self::$default_attributes[ $attribute ] = $options['default'];
		}
	}

	/**
	 * Create a listing.
	 *
	 * @param string $type Listing type.
	 */
	private function create_listing( $type = 'place' ) {
		$listing_id = self::factory()->post->create(
			[
				'post_type'    => Core::NEWSPACK_LISTINGS_POST_TYPES[ $type ],
				'post_title'   => 'Listing Title: ' . $type,
				'post_content' => 'Some ' . $type . ' listing content',
			]
		);

		self::$listings[] = $listing_id;
		return $listing_id;
	}

	/**
	 * Basic Block rendering - Curated List block.
	 */
	public function test_curated_list_block_query_types() {
		$place = self::create_listing( 'place' );
		$event = self::create_listing( 'event' );

		// Curated List: query mode with all listing types.
		$query_all_block_content = Newspack_Listings\Curated_List_Block\render_block(
			wp_parse_args(
				[
					'queryMode'       => true,
					'queriedListings' => self::$listings,
				],
				self::$default_attributes
			),
			''
		);

		self::assertStringContainsString(
			get_the_title( $place ),
			$query_all_block_content,
			'Query block with type set to all contains the place listing.'
		);

		self::assertStringContainsString(
			get_the_title( $event ),
			$query_all_block_content,
			'Query block with type set to all contains the event listing.'
		);

		// Curated List: query mode with only Place listings.
		$query_place_block_content = Newspack_Listings\Curated_List_Block\render_block(
			wp_parse_args(
				[
					'queryMode'       => true,
					'queryOptions'    => [
						'type'               => Core::NEWSPACK_LISTINGS_POST_TYPES['place'],
						'authors'            => [],
						'categories'         => [],
						'tags'               => [],
						'categoryExclusions' => [],
						'tagExclusions'      => [],
						'maxItems'           => 10,
						'sortBy'             => 'date',
						'order'              => 'DESC',
					],
					'queriedListings' => self::$listings,
				],
				self::$default_attributes
			),
			''
		);

		self::assertStringContainsString(
			get_the_title( $place ),
			$query_place_block_content,
			'Query block with type set to "place" contains the place listing.'
		);

		self::assertStringNotContainsString(
			get_the_title( $event ),
			$query_place_block_content,
			'Query block with type set to "place" does not contain the event listing.'
		);
	}
}
