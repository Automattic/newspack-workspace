<?php
/**
 * Tests what the author endpoints let an editing user see.
 *
 * @package Newspack_Blocks
 */

/**
 * Both author routes are open to anyone who can edit posts. They should only ever describe
 * authors, and only for content the caller can read.
 */
class AuthorsAccessTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Boot a REST server and act as a contributor, the lowest role the routes admit.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
	}

	/**
	 * Request an author route and return the decoded items.
	 *
	 * @param string $route  Route.
	 * @param array  $params Query params.
	 * @return array Response data.
	 */
	private function request( $route, $params ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( array_merge( [ 'fields' => 'id,name' ], $params ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * IDs of the WP users in a response.
	 *
	 * @param array $data Response data.
	 * @return int[]
	 */
	private function user_ids( $data ) {
		return array_values(
			array_map(
				function( $record ) {
					return $record['id'];
				},
				array_filter(
					$data,
					function( $record ) {
						return empty( $record['is_guest'] );
					}
				)
			)
		);
	}

	public function test_single_id_lookup_only_resolves_users_in_author_roles() {
		$author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertSame(
			[ $author ],
			$this->user_ids(
				$this->request(
					'/newspack-blocks/v1/authors',
					[
						'author_id'       => $author,
						'is_guest_author' => 0,
					]
				)
			)
		);
		$this->assertSame(
			[],
			$this->user_ids(
				$this->request(
					'/newspack-blocks/v1/authors',
					[
						'author_id'       => $subscriber,
						'is_guest_author' => 0,
					]
				)
			),
			'A subscriber is not an author and must not resolve by id.'
		);
	}

	public function test_batched_lookup_only_resolves_users_in_author_roles() {
		$author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$ids = $this->user_ids( $this->request( '/newspack-blocks/v1/authors', [ 'author_ids' => "$author,$subscriber" ] ) );

		$this->assertSame( [ $author ], $ids, 'The batch resolved a user outside the author roles.' );
	}

	public function test_post_authors_are_withheld_for_posts_the_caller_cannot_read() {
		$admin     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$private   = self::factory()->post->create(
			[
				'post_author' => $admin,
				'post_status' => 'private',
			]
		);
		$draft     = self::factory()->post->create(
			[
				'post_author' => $admin,
				'post_status' => 'draft',
			]
		);
		$published = self::factory()->post->create(
			[
				'post_author' => $admin,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( [], $this->request( '/newspack-blocks/v1/authors', [ 'post_id' => $private ] ), 'A private post revealed its author.' );
		$this->assertSame( [], $this->request( '/newspack-blocks/v1/authors', [ 'post_id' => $draft ] ), 'Someone else\'s draft revealed its author.' );
		$this->assertSame( [ $admin ], $this->user_ids( $this->request( '/newspack-blocks/v1/authors', [ 'post_id' => $published ] ) ) );
	}

	public function test_author_list_ignores_roles_outside_the_author_roles() {
		$author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$only_subscribers = $this->user_ids( $this->request( '/newspack-blocks/v1/author-list', [ 'author_roles' => [ 'subscriber' ] ] ) );
		$this->assertNotContains( $subscriber, $only_subscribers, 'A caller-chosen role outside the author roles listed its users.' );
		$this->assertSame( [], $only_subscribers, 'A role list with nothing usable must return no users, not every user.' );

		$this->assertContains( $author, $this->user_ids( $this->request( '/newspack-blocks/v1/author-list', [ 'author_roles' => [ 'author', 'subscriber' ] ] ) ) );
	}
}
