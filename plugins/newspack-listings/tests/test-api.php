<?php
/**
 * Class Api Test
 *
 * @package Newspack_Listings
 */

use Newspack_Listings\Api;
use Newspack_Listings\Core;

/**
 * Listings API test case.
 */
class ApiTest extends WP_UnitTestCase {
	/**
	 * A post type that is neither public nor a listing type.
	 *
	 * @var string
	 */
	const OTHER_POST_TYPE = 'test_private_type';

	/**
	 * Create a published post.
	 *
	 * @param string $post_type Post type.
	 * @param string $title     Post title.
	 * @return int Post ID.
	 */
	private function create_post( $post_type, $title ) {
		return self::factory()->post->create(
			[
				'post_type'   => $post_type,
				'post_title'  => $title,
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * Create a place listing, an event listing and a post of another type.
	 */
	private function create_posts() {
		register_post_type( self::OTHER_POST_TYPE, [ 'public' => false ] );

		$this->create_post( Core::NEWSPACK_LISTINGS_POST_TYPES['place'], 'Place listing' );
		$this->create_post( Core::NEWSPACK_LISTINGS_POST_TYPES['event'], 'Event listing' );
		$this->create_post( self::OTHER_POST_TYPE, 'Other post' );
	}

	/**
	 * Get the titles the listings endpoint returns for the given params.
	 *
	 * @param array $params Request params.
	 * @return array Post titles.
	 */
	private function get_listing_titles( $params ) {
		$request = new WP_REST_Request( 'GET', '/newspack-listings/v1/listings' );
		$request->set_query_params( array_merge( [ '_fields' => 'id,title' ], $params ) );

		return wp_list_pluck( rest_do_request( $request )->get_data(), 'title' );
	}

	/**
	 * The listings endpoint only returns listing types, whichever param names the type.
	 */
	public function test_listings_endpoint_only_returns_listing_types() {
		$this->create_posts();

		$this->assertSame( [], $this->get_listing_titles( [ 'type' => [ self::OTHER_POST_TYPE ] ] ), 'A type that is not a listing type finds nothing.' );
		$this->assertSame( [], $this->get_listing_titles( [ 'query' => [ 'type' => self::OTHER_POST_TYPE ] ] ), 'A query type that is not a listing type finds nothing.' );
		$this->assertSame(
			[ 'Place listing' ],
			$this->get_listing_titles( [ 'type' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['place'], self::OTHER_POST_TYPE ] ] ),
			'Only the listing types in a list of types are looked up.'
		);
	}

	/**
	 * Requests for listing types return the same listings as before.
	 */
	public function test_listings_endpoint_returns_requested_listing_types() {
		$this->create_posts();

		$this->assertEqualSets( [ 'Place listing', 'Event listing' ], $this->get_listing_titles( [] ), 'No type looks up all listing types.' );
		$this->assertEqualSets( [ 'Place listing', 'Event listing' ], $this->get_listing_titles( [ 'query' => [ 'type' => 'any' ] ] ), 'The "any" query type looks up all listing types.' );
		$this->assertSame( [ 'Event listing' ], $this->get_listing_titles( [ 'type' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['event'] ] ] ), 'A listing type looks up that type.' );
		$this->assertSame( [ 'Place listing' ], $this->get_listing_titles( [ 'query' => [ 'type' => Core::NEWSPACK_LISTINGS_POST_TYPES['place'] ] ] ), 'A listing query type looks up that type.' );
	}

	/**
	 * A Curated List query whose type names no listing type finds nothing.
	 */
	public function test_curated_list_query_without_listing_type_finds_nothing() {
		$this->create_posts();
		$this->create_post( 'post', 'Regular post' );

		$query = new WP_Query( Api::build_listings_query( [ 'type' => self::OTHER_POST_TYPE ] ) );

		$this->assertSame( [], wp_list_pluck( $query->posts, 'post_title' ), 'Neither the other type, listings, nor regular posts are found.' );
	}
}
