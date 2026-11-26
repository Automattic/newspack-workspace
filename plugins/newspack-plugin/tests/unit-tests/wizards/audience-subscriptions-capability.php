<?php
/**
 * Tests who may use the Subscriptions wizard's endpoints.
 *
 * @package Newspack\Tests\Wizards
 */

namespace Newspack\Tests\Wizards;

/**
 * Subscriber discounts and subscriber-only products are store settings, which
 * store staff (`manage_woocommerce`) manage as they manage plan discounts in
 * WooCommerce Memberships. A route left on a stricter check breaks the screen for
 * store staff halfway through: the page loads and one tab fails.
 *
 * @group wizards
 * @group Audience_Subscriptions_Capability
 */
class Test_Audience_Subscriptions_Capability extends \WP_UnitTestCase {

	const ROUTE_BASE = '/newspack/v1/wizard/newspack-audience-subscriptions/';

	/**
	 * Enable the content gates flag and load the WooCommerce mocks, which the
	 * routes' availability check reads.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Register every route the wizard's tabs call on a fresh server. The tabs
	 * register theirs on `rest_api_init`; `Wizards::init()` only constructs the
	 * wizard where its admin is available, so its own routes are registered directly.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		( new \Newspack\Audience_Subscriptions() )->register_api_endpoints();
	}

	/**
	 * Reset the server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The users that pass every permission check on each of the wizard's routes.
	 *
	 * Calls the registered checks rather than dispatching requests, so no route
	 * callback, which would need the full WooCommerce stack, runs.
	 *
	 * @param array<string, int> $users Label => user ID.
	 *
	 * @return array<string, string[]> Route pattern => labels of the users allowed on it.
	 */
	private function users_allowed_per_route( array $users ): array {
		$allowed = [];
		foreach ( rest_get_server()->get_routes() as $route => $route_handlers ) {
			if ( ! str_starts_with( $route, self::ROUTE_BASE ) ) {
				continue;
			}
			$allowed[ $route ] = [];
			foreach ( $users as $label => $user_id ) {
				wp_set_current_user( $user_id );
				$passes_every_check = true;
				foreach ( $route_handlers as $route_handler ) {
					$passes_every_check = $passes_every_check && true === call_user_func( $route_handler['permission_callback'], new \WP_REST_Request() );
				}
				if ( $passes_every_check ) {
					$allowed[ $route ][] = $label;
				}
			}
		}
		return $allowed;
	}

	/**
	 * Store staff can use every route behind the wizard; a role without
	 * `manage_woocommerce` can use none of them.
	 */
	public function test_store_staff_can_use_every_wizard_route() {
		// A subscriber given only `manage_woocommerce`, like a custom store role.
		$store_staff = $this->factory->user->create_and_get( [ 'role' => 'subscriber' ] );
		$store_staff->add_cap( 'manage_woocommerce' );
		$allowed = $this->users_allowed_per_route(
			[
				'store staff' => $store_staff->ID,
				'editor'      => $this->factory->user->create( [ 'role' => 'editor' ] ),
			]
		);

		// One route from each class that registers routes, so a tab whose routes
		// failed to register can't pass by being absent.
		foreach ( [ 'primary-product', 'discounts', 'restrictions' ] as $route ) {
			$this->assertArrayHasKey( self::ROUTE_BASE . $route, $allowed );
		}
		foreach ( $allowed as $route => $labels ) {
			$this->assertSame( [ 'store staff' ], $labels, $route );
		}
	}
}
