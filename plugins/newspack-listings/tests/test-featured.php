<?php
/**
 * Class Featured Test
 *
 * @package Newspack_Listings
 */

use Newspack_Listings\Core;
use Newspack_Listings\Featured;
use Newspack_Listings\Utils;

/**
 * Featured listing test case.
 */
class FeaturedTest extends WP_UnitTestCase {
	private static $listings     = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $terms        = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $publish_date = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		// Remove any listings (from previous tests).
		foreach ( self::$listings as $listing_id ) {
			wp_delete_post( $listing_id );
			Featured::update_priority( $listing_id, 0 );
		}

		// Remove any terms (from previous tests).
		foreach ( self::$terms as $term ) {
			wp_delete_term( $term['taxonomy'], $term['term_id'] );
		}

		// Set yesterday's date as a baseline for publish dates.
		self::$publish_date = new \DateTime( 'yesterday' );
	}

	/**
	 * Create a listing.
	 *
	 * @param array $options Options for creating the listing post.
	 * @return int Post ID of the created listing.
	 */
	private function create_listing( $options = [] ) {
		$args = wp_parse_args(
			$options,
			[
				'title'    => 'Listing Title',
				'type'     => 'place',
				'date'     => self::$publish_date,
				'featured' => false,
				'priority' => 5,
				'expires'  => '',
			]
		);

		$title      = $args['title'];
		$type       = $args['type'];
		$date       = $args['date'];
		$listing_id = self::factory()->post->create(
			[
				'post_type'    => Core::NEWSPACK_LISTINGS_POST_TYPES[ $type ],
				'post_title'   => $title,
				'post_content' => 'Some ' . $type . ' listing content',
				'post_date'    => $date,
			]
		);

		// Add featured meta.
		if ( $args['featured'] ) {
			update_post_meta( $listing_id, Featured::META_KEYS['featured'], true );
			update_post_meta( $listing_id, Featured::META_KEYS['expires'], $args['expires'] );
			Featured::update_priority( $listing_id, $args['priority'] );
		}

		self::$listings[] = $listing_id;
		return $listing_id;
	}

	/**
	 * Create a term.
	 *
	 * @param string $term_name Name of the term to create.
	 * @param string $taxonomy Type of term to create.
	 *
	 * @return array Array of term data.
	 */
	public function create_term( $term_name, $taxonomy ) {
		$term_id = self::factory()->term->create(
			[
				'name'     => $term_name,
				'taxonomy' => $taxonomy,
			]
		);

		self::$terms[] = [
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
		];

		return $term_id;
	}

	/**
	 * Control query with no featured listings.
	 */
	public function test_control_query() {
		// Generate 10 standard (non-featured) listings.
		$number_of_listings = 10;
		$current_listing    = 0;
		$category_id        = self::create_term( 'Featured Category', 'category' );
		while ( $current_listing < $number_of_listings ) {
			$current_listing++;
			$listing_id = self::create_listing(
				[
					'date'  => self::$publish_date->modify( '+' . $current_listing . 'minute' )->format( 'Y-m-d H:i:s' ),
					'title' => 'Listing title ' . zeroise( $current_listing, 2 ),
				]
			);
			wp_set_object_terms( $listing_id, $category_id, 'category' );
		}

		self::go_to( get_term_link( $category_id ) );
		self::assertQueryTrue( 'is_archive', 'is_category' );

		global $wp_query;
		$index = 10;
		foreach ( $wp_query->posts as $listing ) {
			$stringified_index = zeroise( $index, 2 );
			self::assertEquals(
				$listing->post_title,
				'Listing title ' . $stringified_index,
				$listing->post_title . ' is item number ' . $index . ' in the query results.'
			);

			--$index;
		}
	}

	/**
	 * Test featured listing sort order.
	 */
	public function test_featured_sort_order() {
		$tag_id = self::create_term( 'Featured Tag', 'post_tag' );

		// Generate a featured listing with default priority.
		$featured_listing = self::create_listing(
			[
				'date'     => self::$publish_date->format( 'Y-m-d H:i:s' ),
				'title'    => 'Featured Listing',
				'featured' => true,
			]
		);
		wp_set_object_terms( $featured_listing, $tag_id, 'post_tag' );

		// Generate 10 standard (non-featured) listings.
		$number_of_listings = 10;
		$current_listing    = 0;
		while ( $current_listing < $number_of_listings ) {
			$current_listing++;
			$listing_id = self::create_listing(
				[
					'date'  => self::$publish_date->modify( '+' . $current_listing . 'minute' )->format( 'Y-m-d H:i:s' ),
					'title' => 'Listing title ' . zeroise( $current_listing, 2 ),
				]
			);
			wp_set_object_terms( $listing_id, $tag_id, 'post_tag' );
		}

		self::go_to( get_term_link( $tag_id ) );

		global $wp_query;
		self::assertQueryTrue( 'is_archive', 'is_tag' );

		$index = 10;
		foreach ( $wp_query->posts as $listing ) {
			$stringified_index = zeroise( $index + 1, 2 ); // Index will be offset by one because the featured listing comes first.
			if ( 10 === $index ) {
				self::assertEquals(
					$listing->post_title,
					get_the_title( $featured_listing ),
					'Featured listing appears first in results regardless of sort order.'
				);
			} else {
				self::assertEquals(
					$listing->post_title,
					'Listing title ' . $stringified_index,
					$listing->post_title . ' is item number ' . $index . ' in the query results.'
				);
			}

			--$index;
		}
	}

	/**
	 * Test featured listing sort order with priority.
	 */
	public function test_featured_priority_sort_order() {
		// Generate a featured listing with higher priority.
		$featured_listing_priority_high = self::create_listing(
			[
				'date'     => self::$publish_date->format( 'Y-m-d H:i:s' ),
				'title'    => 'Featured Listing with High Priority',
				'featured' => true,
				'priority' => 9,
			]
		);

		// Generate a featured listing with default priority.
		$featured_listing_priority_default = self::create_listing(
			[
				'date'     => self::$publish_date->modify( '+10 seconds' )->format( 'Y-m-d H:i:s' ),
				'title'    => 'Featured Listing with Default Priority',
				'featured' => true,
			]
		);

		// Generate 10 standard (non-featured) listings.
		$number_of_listings = 10;
		$current_listing    = 0;
		while ( $current_listing < $number_of_listings ) {
			$current_listing++;
			$listing_id = self::create_listing(
				[
					'date'  => self::$publish_date->modify( '+' . $current_listing . 'minute' )->format( 'Y-m-d H:i:s' ),
					'title' => 'Listing title ' . zeroise( $current_listing, 2 ),
				]
			);
		}

		self::go_to( get_post_type_archive_link( Core::NEWSPACK_LISTINGS_POST_TYPES['place'] ) );

		global $wp_query;
		self::assertQueryTrue( 'is_archive', 'is_post_type_archive' );

		$index = 10;
		foreach ( $wp_query->posts as $listing ) {
			$stringified_index = zeroise( $index + 2, 2 ); // Index will be offset by two because the featured listings come first.
			if ( 10 === $index ) {
				self::assertEquals(
					$listing->post_title,
					get_the_title( $featured_listing_priority_high ),
					'Featured listings with higher priority appear first.'
				);
			} elseif ( 9 === $index ) {
				self::assertEquals(
					$listing->post_title,
					get_the_title( $featured_listing_priority_default ),
					'Featured listings with lower priority still appear before non-featured results.'
				);
			} else {
				self::assertEquals(
					$listing->post_title,
					'Listing title ' . $stringified_index,
					$listing->post_title . ' is item number ' . $index . ' in the query results.'
				);
			}

			--$index;
		}
	}
}
