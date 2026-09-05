<?php
/**
 * Tests batched author lookups on the authors REST endpoint.
 *
 * @package Newspack_Blocks
 */

/**
 * Batched lookups let the Author Profile block load every author on a page with one request.
 */
class AuthorsBatchTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Boot a REST server and act as a user who may read the endpoint.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// The endpoint reads guest authors from Co-Authors Plus's post type, which the test suite's
		// CAP mock does not register.
		if ( ! post_type_exists( 'guest-author' ) ) {
			register_post_type( 'guest-author', [ 'public' => true ] );
			$this->registered_guest_author_type = true;
		}
	}

	/**
	 * Whether this test registered the guest-author post type itself.
	 *
	 * @var bool
	 */
	private $registered_guest_author_type = false;

	/**
	 * Undo the post type registration so it cannot leak into other tests.
	 */
	public function tear_down() {
		if ( $this->registered_guest_author_type ) {
			unregister_post_type( 'guest-author' );
			$this->registered_guest_author_type = false;
		}
		parent::tear_down();
	}

	/**
	 * Create a guest author the way Co-Authors Plus stores one: a `guest-author` post.
	 *
	 * @param string $name Display name.
	 * @return int Post ID.
	 */
	private function create_guest_author_post( $name ) {
		return self::factory()->post->create(
			[
				'post_type'  => 'guest-author',
				'post_title' => $name,
			]
		);
	}

	/**
	 * Request the authors endpoint with the given query params and return the decoded items.
	 *
	 * @param array $params Query params.
	 * @return array Response data.
	 */
	private function request_authors( $params ) {
		$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/authors' );
		$request->set_query_params( array_merge( [ 'fields' => 'id,name,url' ], $params ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'The authors endpoint did not return a successful response.' );

		return $response->get_data();
	}

	/**
	 * Index a response by "u:<id>" / "g:<id>" so assertions don't depend on ordering.
	 *
	 * @param array $data Response data.
	 * @return array Records keyed by kind and id.
	 */
	private function index_by_kind( $data ) {
		$indexed = [];
		foreach ( $data as $record ) {
			$indexed[ ( ! empty( $record['is_guest'] ) ? 'g:' : 'u:' ) . $record['id'] ] = $record;
		}
		return $indexed;
	}

	public function test_author_ids_returns_every_requested_user_in_one_response() {
		$ids = [
			self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'First Author' ] ),
			self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'Second Author' ] ),
			self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'Third Author' ] ),
		];

		$records = $this->index_by_kind( $this->request_authors( [ 'author_ids' => implode( ',', $ids ) ] ) );

		$this->assertCount( 3, $records );
		foreach ( $ids as $id ) {
			$this->assertArrayHasKey( 'u:' . $id, $records, "User $id was missing from the batched response." );
			$this->assertFalse( $records[ 'u:' . $id ]['is_guest'] );
		}
		$this->assertSame( 'Second Author', $records[ 'u:' . $ids[1] ]['name'] );
	}

	public function test_guest_author_ids_returns_every_requested_guest_author() {
		$ids = [ $this->create_guest_author_post( 'Guest One' ), $this->create_guest_author_post( 'Guest Two' ) ];

		$records = $this->index_by_kind(
			$this->request_authors(
				[
					'guest_author_ids' => implode( ',', $ids ),
					'fields'           => 'id,name',
				]
			)
		);

		$this->assertCount( 2, $records );
		foreach ( $ids as $id ) {
			$this->assertArrayHasKey( 'g:' . $id, $records, "Guest author $id was missing from the batched response." );
			$this->assertTrue( $records[ 'g:' . $id ]['is_guest'] );
		}
		$this->assertSame( 'Guest Two', $records[ 'g:' . $ids[1] ]['name'] );
	}

	public function test_user_and_guest_author_ids_are_looked_up_in_their_own_namespaces() {
		$user_id  = self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'A User' ] );
		$guest_id = $this->create_guest_author_post( 'A Guest' );

		$records = $this->index_by_kind(
			$this->request_authors(
				[
					'author_ids'       => (string) $user_id,
					'guest_author_ids' => (string) $guest_id,
					'fields'           => 'id',
				]
			)
		);

		$this->assertCount( 2, $records );
		$this->assertArrayHasKey( 'u:' . $user_id, $records );
		$this->assertArrayHasKey( 'g:' . $guest_id, $records );
	}

	/**
	 * Blocks saved before the `isGuestAuthor` attribute existed default to a guest lookup for every
	 * author, so a guest id that matches no guest author has to resolve to the WP user with that id,
	 * the way the single-id path (`is_guest_author=1`) and the front-end renderer still do.
	 */
	public function test_a_guest_id_with_no_guest_author_falls_back_to_the_wp_user() {
		$user_id = self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'Legacy User' ] );

		$records = $this->index_by_kind(
			$this->request_authors(
				[
					'guest_author_ids' => (string) $user_id,
					'fields'           => 'id,name',
				]
			)
		);

		$this->assertCount( 1, $records );
		$this->assertArrayHasKey( 'u:' . $user_id, $records );
		$this->assertArrayNotHasKey( 'g:' . $user_id, $records );
		$this->assertSame( 'Legacy User', $records[ 'u:' . $user_id ]['name'] );
	}

	public function test_batched_guest_lookup_of_a_user_matches_the_single_lookup() {
		$id = self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'Same Author' ] );

		$single  = $this->request_authors(
			[
				'author_id'       => $id,
				'is_guest_author' => 1,
			]
		);
		$batched = $this->request_authors( [ 'guest_author_ids' => (string) $id ] );

		$this->assertCount( 1, $single );
		$this->assertCount( 1, $batched );
		$this->assertSame( $single[0], $batched[0] );
	}

	public function test_batched_user_record_matches_the_single_lookup() {
		$id = self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'Same Author' ] );

		$single  = $this->request_authors(
			[
				'author_id'       => $id,
				'is_guest_author' => 0,
			]
		);
		$batched = $this->request_authors( [ 'author_ids' => (string) $id ] );

		$this->assertCount( 1, $single );
		$this->assertCount( 1, $batched );
		$this->assertSame( $single[0], $batched[0] );
	}

	public function test_batch_query_count_does_not_grow_with_the_number_of_users() {
		$one  = [ self::factory()->user->create( [ 'role' => 'author' ] ) ];
		$five = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$five[] = self::factory()->user->create( [ 'role' => 'author' ] );
		}
		$controller = new WP_REST_Newspack_Authors_Controller();
		// The field set the Author Profile block requests in the editor.
		$fields     = [ 'id', 'name', 'bio', 'email', 'social', 'avatar', 'url' ];

		wp_cache_flush();
		$before = get_num_queries();
		$controller->get_authors_by_ids( $one, [], $fields, false );
		$queries_for_one = get_num_queries() - $before;

		wp_cache_flush();
		$before = get_num_queries();
		$controller->get_authors_by_ids( $five, [], $fields, false );
		$queries_for_five = get_num_queries() - $before;

		$this->assertSame( $queries_for_one, $queries_for_five, 'Looking up five uncached users must not cost more queries than looking up one.' );
	}

	public function test_unknown_ids_are_skipped_without_failing_the_batch() {
		$id = self::factory()->user->create( [ 'role' => 'author' ] );

		$records = $this->index_by_kind( $this->request_authors( [ 'author_ids' => $id . ',999999' ] ) );

		$this->assertCount( 1, $records );
		$this->assertArrayHasKey( 'u:' . $id, $records );
	}

	public function test_a_list_with_no_valid_ids_returns_an_empty_response() {
		// An author exists, so falling through to the recent-authors listing would return something.
		self::factory()->user->create( [ 'role' => 'author' ] );

		$this->assertSame( [], $this->request_authors( [ 'author_ids' => 'abc' ] ) );
	}

	public function test_id_lists_are_capped() {
		$sanitized = WP_REST_Newspack_Authors_Controller::sanitize_id_list( implode( ',', range( 1, 150 ) ) );

		$this->assertSame( range( 1, 100 ), $sanitized );
	}

	public function test_format_guest_author_skips_a_post_co_authors_plus_cannot_resolve() {
		$orphan = (object) [
			'ID'        => 999999,
			'post_date' => '2026-01-01 00:00:00',
			'post_name' => 'orphan',
		];

		$this->assertNull( WP_REST_Newspack_Authors_Controller::format_guest_author( $orphan, [ 'id', 'name' ], false ) );
	}
}
