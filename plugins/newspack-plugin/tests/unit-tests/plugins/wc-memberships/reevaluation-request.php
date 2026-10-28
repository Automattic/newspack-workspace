<?php
/**
 * Tests for the WooCommerce Memberships "Reevaluate status" meta box action.
 *
 * @package Newspack\Tests
 */

use Newspack\Memberships;

require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';

/**
 * The action copies a subscription's status onto a user membership. Only a
 * store manager may run it, from the meta box link, and only against the
 * subscription the membership is linked to.
 *
 * Every test runs in its own process: the mock declares wc_memberships() and
 * wc_memberships_get_user_membership(), which would otherwise leak into the
 * rest of the suite.
 *
 * @group wc-memberships
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Memberships_Reevaluation_Request extends WP_UnitTestCase {

	const MEMBERSHIP_ID          = 101;
	const LINKED_SUBSCRIPTION_ID = 201;
	const OTHER_SUBSCRIPTION_ID  = 202;

	/**
	 * Register a cancelled membership linked to an active subscription, plus an
	 * active subscription it is not linked to.
	 */
	public function set_up() {
		parent::set_up();
		require_once dirname( __DIR__, 3 ) . '/mocks/wc-memberships-reevaluation-mock.php';

		global $subscriptions_database, $products_database, $orders_database;
		$products_database      = [];
		$orders_database        = [];
		$subscriptions_database = [];
		foreach ( [ self::LINKED_SUBSCRIPTION_ID, self::OTHER_SUBSCRIPTION_ID ] as $subscription_id ) {
			wcs_create_subscription(
				[
					'id'     => $subscription_id,
					'status' => 'active',
				]
			);
		}

		wc_memberships()->user_memberships[ self::MEMBERSHIP_ID ] = new Newspack_Test_WCM_Reevaluation_User_Membership( self::MEMBERSHIP_ID, 'cancelled' );
		$this->integration()->links[ self::MEMBERSHIP_ID ]        = self::LINKED_SUBSCRIPTION_ID;
	}

	/**
	 * Clear the request the test built.
	 */
	public function tear_down() {
		$_GET     = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	/**
	 * The Subscriptions integration the handler resolves.
	 *
	 * @return Newspack_Test_WCM_Reevaluation_Subscriptions_Integration
	 */
	private function integration() {
		return wc_memberships()->get_integrations_instance()->get_subscriptions_instance();
	}

	/**
	 * Log in a user who can manage the store.
	 */
	private function log_in_store_manager() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		// WooCommerce grants manage_woocommerce, and it isn't loaded here.
		get_user_by( 'id', $user_id )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );
	}

	/**
	 * A valid nonce for the current user and the test membership.
	 *
	 * @return string
	 */
	private function nonce() {
		return wp_create_nonce( Memberships::REEVALUATE_NONCE_ACTION . self::MEMBERSHIP_ID );
	}

	/**
	 * Run the handler for an edit screen request naming the given subscription.
	 *
	 * @param int         $subscription_id Subscription named in the request.
	 * @param string|null $nonce           Nonce sent with the request, or null for none.
	 *
	 * @return string|null Redirect location, or null when the handler returned without redirecting.
	 *
	 * @throws \RuntimeException If the handler fails for a reason other than the redirect.
	 */
	private function run_request( $subscription_id, $nonce = null ) {
		$query = [
			'post'       => (string) self::MEMBERSHIP_ID,
			'action'     => 'edit',
			'reevaluate' => (string) $subscription_id,
		];
		if ( null !== $nonce ) {
			$query['_wpnonce'] = $nonce;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$_GET                   = $query;
		$_REQUEST               = $query;
		$_SERVER['REQUEST_URI'] = add_query_arg( $query, '/wp-admin/post.php' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$location = null;
		$capture  = function ( $redirect ) use ( &$location ) {
			$location = $redirect;
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $capture, 1 );

		try {
			Memberships::handle_reevaluation_request();
		} catch ( \RuntimeException $e ) {
			if ( 'redirected' !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
		}

		return $location;
	}

	/**
	 * A logged-out request changes nothing.
	 */
	public function test_logged_out_request_is_ignored() {
		wp_set_current_user( 0 );

		$this->assertNull( $this->run_request( self::LINKED_SUBSCRIPTION_ID ) );
		$this->assertSame( [], $this->integration()->updates );
	}

	/**
	 * A user who cannot manage the store changes nothing, even with a valid nonce.
	 */
	public function test_user_without_store_capability_is_ignored() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertNull( $this->run_request( self::LINKED_SUBSCRIPTION_ID, $this->nonce() ) );
		$this->assertSame( [], $this->integration()->updates );
	}

	/**
	 * A store manager's request without a valid nonce stops before anything changes.
	 */
	public function test_request_without_valid_nonce_is_stopped() {
		$this->log_in_store_manager();

		try {
			$this->run_request( self::LINKED_SUBSCRIPTION_ID, 'not-a-nonce' );
			$this->fail( 'Expected the nonce check to stop the request.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( [], $this->integration()->updates );
		}
	}

	/**
	 * A store manager naming a subscription the membership isn't linked to changes nothing.
	 */
	public function test_unlinked_subscription_is_ignored() {
		$this->log_in_store_manager();

		$this->assertNull( $this->run_request( self::OTHER_SUBSCRIPTION_ID, $this->nonce() ) );
		$this->assertSame( [], $this->integration()->updates );
	}

	/**
	 * A store manager's request for the linked subscription copies its status onto
	 * the membership, then returns to the edit screen without the action arguments.
	 */
	public function test_linked_subscription_resyncs_membership() {
		$this->log_in_store_manager();

		$location = $this->run_request( self::LINKED_SUBSCRIPTION_ID, $this->nonce() );

		$this->assertSame( [ [ self::LINKED_SUBSCRIPTION_ID, self::MEMBERSHIP_ID, 'active' ] ], $this->integration()->updates );
		$this->assertIsString( $location );
		$this->assertStringNotContainsString( 'reevaluate=', $location );
		$this->assertStringNotContainsString( '_wpnonce=', $location );
	}

	/**
	 * The meta box link names the linked subscription and carries a nonce the handler accepts.
	 */
	public function test_meta_box_link_carries_nonce() {
		$this->log_in_store_manager();

		$actions = Memberships::user_membership_meta_box_actions( [], self::MEMBERSHIP_ID );
		$query   = [];
		wp_parse_str( (string) wp_parse_url( $actions['reevaluate']['link'], PHP_URL_QUERY ), $query );

		$this->assertSame( (string) self::MEMBERSHIP_ID, $query['post'] );
		$this->assertSame( (string) self::LINKED_SUBSCRIPTION_ID, $query['reevaluate'] );
		$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'], Memberships::REEVALUATE_NONCE_ACTION . self::MEMBERSHIP_ID ) );
	}
}
