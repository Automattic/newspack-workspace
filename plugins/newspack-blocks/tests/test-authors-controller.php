<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class AuthorsControllerTest
 *
 * @package Newspack_Blocks
 */

/**
 * Tests which requesters the author endpoints will hand contact details to.
 *
 * The author endpoints are editor-facing and open to anyone who can edit posts, while the
 * contact details they can return are the same ones WordPress core reserves for people who
 * can manage users. The front-end author blocks render the same data for visitors, so the
 * distinction has to be made per request rather than in the shared formatting helpers.
 */
class AuthorsControllerTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Email address given to the author whose record the tests look for.
	 */
	const SUBJECT_EMAIL = 'subject-author@example.test';

	/**
	 * ID of the author whose record the tests look for.
	 *
	 * @var int
	 */
	private $subject_id;

	/**
	 * Boot a REST server and create the author under test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->subject_id = self::factory()->user->create(
			[
				'role'         => 'administrator',
				'user_email'   => self::SUBJECT_EMAIL,
				'display_name' => 'Subject Author',
			]
		);
	}

	/**
	 * Request an author endpoint and return the decoded items.
	 *
	 * @param string $route REST route, relative to the block namespace.
	 * @return array Response data.
	 */
	private function request_authors( $route = '/newspack-blocks/v1/authors' ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params(
			[
				'fields'   => 'email,name',
				'perPage'  => 100,
			]
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'The author endpoint did not return a successful response.' );

		return $response->get_data();
	}

	/**
	 * Find the record for the author under test within a response.
	 *
	 * @param array $data Response data.
	 * @return array|null The matching record, or null.
	 */
	private function find_subject( $data ) {
		$items = isset( $data['authors'] ) ? $data['authors'] : $data;
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && (int) $item['id'] === $this->subject_id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Assert that a record carries no trace of the author's address.
	 *
	 * @param array  $record  The author record.
	 * @param string $message Failure message.
	 */
	private function assert_record_has_no_email( $record, $message ) {
		$this->assertArrayNotHasKey( 'email', $record, $message );
		$this->assertStringNotContainsString( self::SUBJECT_EMAIL, wp_json_encode( $record ), $message );
	}

	/**
	 * Someone who can only edit posts should not be able to read other people's addresses.
	 */
	public function test_authors_endpoint_withholds_email_from_post_editors() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$record = $this->find_subject( $this->request_authors() );

		$this->assertNotNull( $record, 'The author under test was missing from the response.' );
		$this->assert_record_has_no_email( $record, 'The authors endpoint disclosed an address to a contributor.' );
	}

	/**
	 * The same restriction applies to the author list endpoint, which shares the formatting.
	 */
	public function test_author_list_endpoint_withholds_email_from_post_editors() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$record = $this->find_subject( $this->request_authors( '/newspack-blocks/v1/author-list' ) );

		$this->assertNotNull( $record, 'The author under test was missing from the response.' );
		$this->assert_record_has_no_email( $record, 'The author list endpoint disclosed an address to a contributor.' );
	}

	/**
	 * Requesters who can manage users keep the field they already had access to.
	 */
	public function test_authors_endpoint_returns_email_to_user_managers() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$record = $this->find_subject( $this->request_authors() );

		$this->assertNotNull( $record, 'The author under test was missing from the response.' );
		$this->assertArrayHasKey( 'email', $record, 'An administrator was denied a field they can already read.' );
		$this->assertStringContainsString( self::SUBJECT_EMAIL, wp_json_encode( $record['email'] ) );
	}

	/**
	 * The author list block renders for visitors through the controller's own getter rather
	 * than through the REST route, so the request-level restriction must not reach it.
	 */
	public function test_front_end_author_list_still_includes_email() {
		wp_set_current_user( 0 );

		$authors = ( new WP_REST_Newspack_Author_List_Controller() )->get_all_authors(
			[
				'fields'   => [ 'id', 'name', 'email' ],
				'per_page' => 100,
			]
		);

		$record = $this->find_subject( $authors );

		$this->assertNotNull( $record, 'The author under test was missing from the front-end render.' );
		$this->assertArrayHasKey( 'email', $record, 'The author list block lost its contact link for visitors.' );
	}

	/**
	 * The front-end author blocks render for visitors and must keep showing the contact link
	 * a publisher has chosen to publish. The request-level restriction must not reach here.
	 */
	public function test_front_end_author_data_still_includes_email() {
		wp_set_current_user( 0 );

		$author_data = WP_REST_Newspack_Authors_Controller::fill_user_data(
			[ 'id' => $this->subject_id ],
			get_user_by( 'id', $this->subject_id )
		);

		$this->assertArrayHasKey( 'email', $author_data, 'Front-end author rendering lost its contact link.' );
		$this->assertStringContainsString( self::SUBJECT_EMAIL, wp_json_encode( $author_data['email'] ) );
	}
}
