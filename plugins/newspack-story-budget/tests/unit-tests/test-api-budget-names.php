<?php
/**
 * Tests for budget names sent to the budget REST routes.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * A budget name sent as JSON, as the app sends it, is stored character for character.
 */
class Test_API_Budget_Names extends \WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Dispatch a request with a JSON body, as the app does.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route, relative to the API namespace.
	 * @param array  $body   Request body.
	 *
	 * @return \WP_REST_Response
	 */
	private function rest( $method, $route, $body ) {
		$request = new \WP_REST_Request( $method, '/' . API::NAMESPACE . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Read a budget's name back from the database.
	 *
	 * @param int $term_id Budget term ID.
	 *
	 * @return string
	 */
	private function stored_name( $term_id ) {
		clean_term_cache( $term_id, Budgets::TAXONOMY );
		return get_term( $term_id, Budgets::TAXONOMY )->name;
	}

	/**
	 * Budget names, as the reader typed them.
	 *
	 * @return array[]
	 */
	public function names() {
		return [
			'one backslash'      => [ 'Archive C:\\2026' ],
			'run of backslashes' => [ 'Archive C:\\\\2026' ],
			'trailing backslash' => [ 'Notes D:\\' ],
			'no backslash'       => [ 'Archive 2026' ],
		];
	}

	/**
	 * Creating a budget through `POST /budgets` keeps the name as typed.
	 *
	 * @dataProvider names
	 *
	 * @param string $name Budget name.
	 */
	public function test_create_keeps_name( $name ) {
		$response = $this->rest( 'POST', '/budgets', [ 'name' => $name ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $name, $response->get_data()['name'] );
		$this->assertSame( $name, $this->stored_name( $response->get_data()['id'] ) );
	}

	/**
	 * Renaming a budget through `PUT /budgets/{id}` keeps the name as typed.
	 *
	 * @dataProvider names
	 *
	 * @param string $name Budget name.
	 */
	public function test_rename_keeps_name( $name ) {
		$budget_id = self::factory()->term->create(
			[
				'taxonomy' => Budgets::TAXONOMY,
				'name'     => 'Original name',
			]
		);

		$response = $this->rest( 'PUT', '/budgets/' . $budget_id, [ 'name' => $name ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $name, $response->get_data()['name'] );
		$this->assertSame( $name, $this->stored_name( $budget_id ) );
	}
}
