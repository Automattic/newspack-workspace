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
	 * @param string|null $role   Role slug, or null for a logged-out request.
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
	 * IDs that are not budgets: one with no term row, one that is a term in another taxonomy.
	 *
	 * @return array
	 */
	public function non_budget_ids() {
		return [
			'unknown ID'               => [ 'unknown' ],
			'term in another taxonomy' => [ 'category' ],
		];
	}

	/**
	 * An ID that is not a budget falls back to the floor: managers still get the
	 * handler's 404, non-managers get 403, and no term is renamed.
	 *
	 * @dataProvider non_budget_ids
	 *
	 * @param string $kind Which kind of non-budget ID to send.
	 */
	public function test_update_non_budget_id_keeps_404_for_managers( $kind ) {
		$id = 'category' === $kind ? self::factory()->category->create() : 999999;

		$response = $this->dispatch_as( 'editor', 'PUT', '/budgets/' . $id, [ 'name' => 'Ghost' ] );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'budget_not_found', $response->get_data()['code'] );
		$this->assertStringContainsString( (string) $id, $response->get_data()['message'] );
		$this->assertNotSame( 'Ghost', get_term( $id )->name ?? null );

		$response = $this->dispatch_as( 'contributor', 'PUT', '/budgets/' . $id, [ 'name' => 'Ghost' ] );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Reordering skips IDs that are not budgets instead of writing order meta onto them.
	 */
	public function test_reorder_skips_ids_that_are_not_budgets() {
		$category_id = self::factory()->category->create();
		$ordered     = array_reverse( self::$budgets );

		$response = $this->dispatch_as( 'editor', 'POST', '/budgets/order', [ 'ids' => [ $category_id, $ordered[0], 999999, $ordered[1] ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( get_term_meta( $category_id, Budget::ORDER_META_KEY, true ) );
		$this->assertEmpty( get_term_meta( 999999, Budget::ORDER_META_KEY, true ) );
		$this->assertSame( 1, (int) get_term_meta( $ordered[0], Budget::ORDER_META_KEY, true ) );
		$this->assertSame( 2, (int) get_term_meta( $ordered[1], Budget::ORDER_META_KEY, true ) );
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
	 * The app reads the same floor from the stories meta, so its controls and the routes agree.
	 */
	public function test_stories_meta_reports_budget_management_capability() {
		$response = $this->dispatch_as( 'contributor', 'GET', '/stories/meta' );
		$this->assertFalse( $response->get_data()['can_manage_budgets'] );

		$response = $this->dispatch_as( 'editor', 'GET', '/stories/meta' );
		$this->assertTrue( $response->get_data()['can_manage_budgets'] );
	}
}
