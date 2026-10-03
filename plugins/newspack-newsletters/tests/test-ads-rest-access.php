<?php
/**
 * Class Test Ads REST Access
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Ads;

/**
 * Who can read newsletter ads through the core posts REST routes.
 */
class Ads_REST_Access_Test extends WP_UnitTestCase {
	/**
	 * A published ad with commercial meta.
	 *
	 * @var int
	 */
	private $ad_id;

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
}
