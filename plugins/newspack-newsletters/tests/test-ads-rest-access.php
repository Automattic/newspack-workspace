<?php
/**
 * Class Test Ads REST Access
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Ads;
use Newspack_Newsletters\Ads_Placements;

/**
 * Who can read newsletter ads, advertisers and ad placements through the core REST routes.
 */
class Ads_REST_Access_Test extends WP_UnitTestCase {
	/**
	 * A published ad with commercial meta.
	 *
	 * @var int
	 */
	private $ad_id;

	/**
	 * An advertiser term and an ad placement term, keyed by REST base.
	 *
	 * @var int[]
	 */
	private $term_ids = [];

	/**
	 * Create the ad and reset the REST server so routes register fresh.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;
		Ads::register_meta();
		$this->ad_id = self::factory()->post->create(
			[
				'post_type'   => Ads::CPT,
				'post_status' => 'publish',
				'meta_input'  => [ 'price' => 250 ],
			]
		);
		$this->term_ids = [
			Ads::ADVERTISER_TAX => self::factory()->term->create(
				[
					'taxonomy' => Ads::ADVERTISER_TAX,
					'name'     => 'Advertiser Term Name',
				]
			),
			'ad_placement'      => self::factory()->term->create(
				[
					'taxonomy' => Ads_Placements::TAXONOMY,
					'name'     => 'Placement Term Name',
				]
			),
		];
	}

	/**
	 * Reset the REST server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * The collection and item routes for ads.
	 */
	private function routes() {
		return [
			'/wp/v2/' . Ads::CPT,
			'/wp/v2/' . Ads::CPT . '/' . $this->ad_id,
			// Core matches routes case-insensitively, so this row is what holds the guard to the dispatched controller rather than the route text.
			'/wp/v2/' . strtoupper( Ads::CPT ) . '/' . $this->ad_id,
		];
	}

	/**
	 * Roles that cannot edit posts, and the status each should get.
	 */
	public function reader_provider() {
		return [
			'logged out' => [ null, 401 ],
			'subscriber' => [ 'subscriber', 403 ],
		];
	}

	/**
	 * Readers without the ability to edit ads get no ad data.
	 *
	 * @dataProvider reader_provider
	 * @param string|null $role     Role, or null for a logged-out request.
	 * @param int         $expected Expected status.
	 */
	public function test_ads_are_not_readable_without_edit_access( $role, $expected ) {
		wp_set_current_user( $role ? self::factory()->user->create( [ 'role' => $role ] ) : 0 );
		foreach ( $this->routes() as $route ) {
			foreach ( [ 'GET', 'HEAD' ] as $method ) {
				$response = rest_do_request( new WP_REST_Request( $method, $route ) );
				$this->assertSame( $expected, $response->get_status(), "$method $route" );
				$this->assertStringNotContainsString( '250', wp_json_encode( $response->get_data() ), "$method $route" );
			}
		}
	}

	/**
	 * Roles that can edit posts, down to the lowest one the ad editor serves.
	 */
	public function editor_provider() {
		return [
			'contributor' => [ 'contributor' ],
			'author'      => [ 'author' ],
			'editor'      => [ 'editor' ],
		];
	}

	/**
	 * Anyone who can edit posts still reads ads, meta included, for the block editor
	 * and the ads list.
	 *
	 * @dataProvider editor_provider
	 * @param string $role Role.
	 */
	public function test_users_who_can_edit_posts_can_read_ads( $role ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
		foreach ( $this->routes() as $route ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 200, $response->get_status(), $route );
			$data = $response->get_data();
			$item = isset( $data[0] ) ? $data[0] : $data;
			$this->assertEquals( 250, $item['meta']['price'], $route );
		}
	}

	/**
	 * The collection and item routes for advertiser and ad placement terms.
	 */
	private function term_routes() {
		$routes = [];
		foreach ( $this->term_ids as $rest_base => $term_id ) {
			$routes[] = '/wp/v2/' . $rest_base;
			$routes[] = '/wp/v2/' . $rest_base . '/' . $term_id;
		}
		return $routes;
	}

	/**
	 * Readers without the ability to edit ads get no advertiser or placement terms.
	 *
	 * @dataProvider reader_provider
	 * @param string|null $role     Role, or null for a logged-out request.
	 * @param int         $expected Expected status.
	 */
	public function test_ad_terms_are_not_readable_without_edit_access( $role, $expected ) {
		wp_set_current_user( $role ? self::factory()->user->create( [ 'role' => $role ] ) : 0 );
		foreach ( $this->term_routes() as $route ) {
			foreach ( [ 'GET', 'HEAD' ] as $method ) {
				$response = rest_do_request( new WP_REST_Request( $method, $route ) );
				$this->assertSame( $expected, $response->get_status(), "$method $route" );
				$this->assertStringNotContainsString( 'Term Name', wp_json_encode( $response->get_data() ), "$method $route" );
			}
		}
	}

	/**
	 * Anyone who can edit posts still reads the terms, for the ad editor's placement
	 * picker and the advertisers list.
	 *
	 * @dataProvider editor_provider
	 * @param string $role Role.
	 */
	public function test_users_who_can_edit_posts_can_read_ad_terms( $role ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
		foreach ( $this->term_routes() as $route ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 200, $response->get_status(), $route );
			$this->assertStringContainsString( 'Term Name', wp_json_encode( $response->get_data() ), $route );
		}
	}

	/**
	 * Term searches through the core search route, by subtype.
	 *
	 * @param string $subtype Taxonomy name, or 'any'.
	 */
	private function search_terms( $subtype ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_query_params(
			[
				'type'    => 'term',
				'subtype' => $subtype,
			]
		);
		return rest_do_request( $request );
	}

	/**
	 * The search route doesn't list advertiser or placement names to readers who
	 * cannot edit ads, including a search that asks only for those taxonomies.
	 *
	 * @dataProvider reader_provider
	 * @param string|null $role Role, or null for a logged-out request.
	 */
	public function test_ad_terms_are_not_searchable_without_edit_access( $role ) {
		wp_set_current_user( $role ? self::factory()->user->create( [ 'role' => $role ] ) : 0 );
		foreach ( [ 'any', Ads::ADVERTISER_TAX, Ads_Placements::TAXONOMY ] as $subtype ) {
			$response = $this->search_terms( $subtype );
			$this->assertSame( 200, $response->get_status(), $subtype );
			$this->assertStringNotContainsString( 'Term Name', wp_json_encode( $response->get_data() ), $subtype );
		}
		foreach ( [ Ads::ADVERTISER_TAX, Ads_Placements::TAXONOMY ] as $subtype ) {
			$this->assertSame( [], $this->search_terms( $subtype )->get_data(), $subtype );
		}
	}

	/**
	 * Anyone who can edit posts still finds the terms through search.
	 *
	 * @dataProvider editor_provider
	 * @param string $role Role.
	 */
	public function test_users_who_can_edit_posts_can_search_ad_terms( $role ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
		$titles = wp_list_pluck( $this->search_terms( 'any' )->get_data(), 'title' );
		$this->assertContains( 'Advertiser Term Name', $titles );
		$this->assertContains( 'Placement Term Name', $titles );
	}

	/**
	 * Terms of other taxonomies stay readable and searchable, so the check covers only
	 * the ad taxonomies.
	 */
	public function test_other_terms_stay_readable() {
		wp_set_current_user( 0 );
		$term_id  = self::factory()->category->create( [ 'name' => 'Ordinary Category' ] );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/categories/' . $term_id ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'Ordinary Category', wp_list_pluck( $this->search_terms( 'any' )->get_data(), 'title' ) );
	}
}
