<?php
/**
 * Tests the subscriber-only products REST API.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Subscriber_Only_Products;

/**
 * The endpoint gates purchases, so what a *partial* payload means matters: an
 * omitted field must never be read as "start enforcing". These dispatch real
 * REST requests rather than calling the callbacks, because the answer lives in
 * the argument schema the dispatcher applies.
 *
 * @group subscriber-commerce
 * @group Subscriber_Only_Products_API
 */
class Test_Subscriber_Only_Products_API extends \WP_UnitTestCase {

	const ROUTE = '/newspack/v1/wizard/newspack-audience-subscriptions/restrictions';

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
	 * Build a REST server here rather than reusing whichever one the process
	 * already has: the routes only register once the flag above is defined, and
	 * a server built before that would be missing them.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Reset the server and the stored rules.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		delete_option( Subscriber_Only_Products::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A payload that says nothing about `active` leaves the restriction paused.
	 * Defaulting it to true in the schema would let an incomplete save start
	 * blocking purchases on its own.
	 */
	public function test_saving_without_active_leaves_the_restriction_paused() {
		$this->assertFalse( $this->save_restriction( [] )['active'] );
	}

	/**
	 * Saying so still turns it on, so the fail-safe costs the UI nothing.
	 */
	public function test_saving_with_active_enforces_the_restriction() {
		$this->assertTrue( $this->save_restriction( [ 'active' => true ] )['active'] );
	}

	/**
	 * POST a restriction and return it as stored.
	 *
	 * @param array $params Extra request params, merged over a minimal rule.
	 *
	 * @return array The saved restriction.
	 */
	private function save_restriction( $params ) {
		$request = new \WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body_params(
			array_merge(
				[
					'subscription_product_ids' => [ 1 ],
					'product_ids'              => [ 2 ],
				],
				$params
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$restrictions = $response->get_data()['restrictions'];
		$this->assertCount( 1, $restrictions );
		return $restrictions[0];
	}
}
