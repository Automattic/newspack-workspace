<?php
/**
 * Test API permissions.
 *
 * @package Newspack_Story_Budget
 */

//phpcs:disable Squiz.Commenting.VariableComment.Missing

namespace Newspack_Story_Budget;

/**
 * Budget routes dispatched through the REST server, so the permission
 * callbacks run. The handler-level tests in test-api.php never reach them.
 */
class Test_API_Permissions extends \WP_UnitTestCase {

	protected static $budgets = [];

	/**
	 * WP setup before class.
	 */
	public static function wpSetUpBeforeClass() {
		self::$budgets = self::factory()->term->create_many(
			2,
			[
				'taxonomy' => Budgets::TAXONOMY,
			]
		);
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Dispatch a request through the REST server as a fresh user with the given role.
	 *
	 * @param string|null $role Role slug, or null for a logged-out request.
	 * @param string      $method HTTP method.
	 * @param string      $route  Route, relative to the API namespace.
	 * @param array       $params Request parameters.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatch_as( $role, $method, $route, $params = [] ) {
		wp_set_current_user( $role ? self::factory()->user->create( [ 'role' => $role ] ) : 0 );
		$request = new \WP_REST_Request( $method, '/' . API::NAMESPACE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Roles that hold edit_posts but not manage_categories.
	 *
	 * @return array
	 */
	public function roles_without_manage_categories() {
		return [
			'contributor' => [ 'contributor' ],
			'author'      => [ 'author' ],
		];
	}

	/**
	 * Creating a budget needs the taxonomy capability, not edit_posts.
	 *
	 * @dataProvider roles_without_manage_categories
	 *
	 * @param string $role Role slug.
	 */
	public function test_create_budget_is_forbidden_without_manage_categories( $role ) {
		$response = $this->dispatch_as( $role, 'POST', '/budgets', [ 'name' => 'Unauthorized budget' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertEmpty( term_exists( 'Unauthorized budget', Budgets::TAXONOMY ) );
	}

	/**
	 * Renaming or archiving a budget needs the taxonomy capability on that budget.
	 *
	 * @dataProvider roles_without_manage_categories
	 *
	 * @param string $role Role slug.
	 */
	public function test_update_budget_is_forbidden_without_manage_categories( $role ) {
		$budget_id = self::$budgets[0];
		$name      = get_term( $budget_id, Budgets::TAXONOMY )->name;

		$response = $this->dispatch_as(
			$role,
			'PUT',
			'/budgets/' . $budget_id,
			[
				'name'     => 'Renamed by ' . $role,
				'archived' => true,
			]
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( $name, get_term( $budget_id, Budgets::TAXONOMY )->name );
		$this->assertEmpty( get_term_meta( $budget_id, Budget::ARCHIVE_META_KEY, true ) );
	}

	/**
	 * Reordering budgets needs the taxonomy capability.
	 *
	 * @dataProvider roles_without_manage_categories
	 *
	 * @param string $role Role slug.
	 */
	public function test_reorder_budgets_is_forbidden_without_manage_categories( $role ) {
		$response = $this->dispatch_as( $role, 'POST', '/budgets/order', [ 'ids' => array_reverse( self::$budgets ) ] );

		$this->assertSame( 403, $response->get_status() );
		foreach ( self::$budgets as $budget_id ) {
			$this->assertEmpty( get_term_meta( $budget_id, Budget::ORDER_META_KEY, true ) );
		}
	}

	/**
	 * An editor holds manage_categories and keeps every budget write.
	 */
	public function test_editor_can_create_update_and_reorder_budgets() {
		$response = $this->dispatch_as( 'editor', 'POST', '/budgets', [ 'name' => 'Editor budget' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Editor budget', $response->get_data()['name'] );
		$this->assertNotEmpty( term_exists( 'Editor budget', Budgets::TAXONOMY ) );

		$budget_id = self::$budgets[0];
		$response  = $this->dispatch_as( 'editor', 'PUT', '/budgets/' . $budget_id, [ 'name' => 'Renamed by editor' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Renamed by editor', get_term( $budget_id, Budgets::TAXONOMY )->name );

		$ordered  = array_reverse( self::$budgets );
		$response = $this->dispatch_as( 'editor', 'POST', '/budgets/order', [ 'ids' => $ordered ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) get_term_meta( $ordered[0], Budget::ORDER_META_KEY, true ) );
		$this->assertSame( 2, (int) get_term_meta( $ordered[1], Budget::ORDER_META_KEY, true ) );
	}

	/**
	 * An ID that is not a budget falls back to the floor: managers still get the
	 * handler's 404, non-managers get 403.
	 */
	public function test_update_unknown_budget_keeps_404_for_managers() {
		$response = $this->dispatch_as( 'editor', 'PUT', '/budgets/999999', [ 'name' => 'Ghost' ] );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'budget_not_found', $response->get_data()['code'] );

		$response = $this->dispatch_as( 'contributor', 'PUT', '/budgets/999999', [ 'name' => 'Ghost' ] );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Read routes keep the edit_posts floor.
	 */
	public function test_contributor_can_read_budgets_and_fields() {
		$budget_id = self::$budgets[0];
		$routes    = [
			[ 'GET', '/budgets', [] ],
			[ 'GET', '/fields', [] ],
			[ 'POST', '/budgets/search', [ 's' => 'budget' ] ],
			[ 'GET', '/budgets/' . $budget_id . '/stories', [] ],
			[ 'POST', '/budgets/' . $budget_id . '/stories/search', [ 's' => 'story' ] ],
		];
		foreach ( $routes as list( $method, $route, $params ) ) {
			$response = $this->dispatch_as( 'contributor', $method, $route, $params );
			$this->assertSame( 200, $response->get_status(), "$method $route should stay readable for contributors." );
		}
	}

	/**
	 * The budget a write is authorized against is the one the write alters.
	 */
	public function test_update_budget_ignores_a_body_supplied_id() {
		list( $target, $other ) = self::$budgets;
		$other_name             = get_term( $other, Budgets::TAXONOMY )->name;

		$response = $this->dispatch_as(
			'editor',
			'PUT',
			'/budgets/' . $target,
			[
				'id'   => $other,
				'name' => 'Renamed through the URL',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $target, $response->get_data()['id'] );
		$this->assertSame( 'Renamed through the URL', get_term( $target, Budgets::TAXONOMY )->name );
		$this->assertSame( $other_name, get_term( $other, Budgets::TAXONOMY )->name );
	}

	/**
	 * Anonymous requests never reach the write routes.
	 */
	public function test_budget_writes_require_a_logged_in_user() {
		$budget_id = self::$budgets[0];
		$writes    = [
			[ 'POST', '/budgets', [ 'name' => 'Anonymous budget' ] ],
			[ 'PUT', '/budgets/' . $budget_id, [ 'name' => 'Anonymous rename' ] ],
			[ 'POST', '/budgets/order', [ 'ids' => array_reverse( self::$budgets ) ] ],
		];
		foreach ( $writes as list( $method, $route, $params ) ) {
			$response = $this->dispatch_as( null, $method, $route, $params );
			$this->assertSame( 401, $response->get_status(), "$method $route should require a logged-in user." );
		}
	}
}
