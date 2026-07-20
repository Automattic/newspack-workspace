<?php
/**
 * Tests for Group_Subscription manager roles (NPPD-1815 / NPPD-1753).
 *
 * Covers the manager data layer (promote/demote, reverse lookups, membership-removal
 * cleanup), the per-request cache, the shared peer-removal predicate, and the two
 * places that enforce it: the My Account admin-post handler and the REST endpoint.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_API;
use Newspack\Group_Subscription_MyAccount;
use Newspack\Group_Subscription_Settings;

/**
 * Test the manager role: data layer, cache invalidation, and authorization.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Test_Group_Subscription_Managers extends WP_UnitTestCase {

	/**
	 * User IDs to clean up.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Include WC and filter_input mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
		// The admin-post handlers read POST data through Newspack\filter_input(),
		// which is empty under PHPUnit without this shim.
		require_once dirname( __DIR__, 4 ) . '/mocks/filter-input-mock.php';
	}

	/**
	 * Reset state between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		Group_Subscription::reset_cache();
	}

	/**
	 * Reset state between tests.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a reader user (member fixture).
	 *
	 * @return int User ID.
	 */
	private function create_reader(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'user-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'user-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture user creation should succeed.' );
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, '_newspack_reader', true );
		return $user_id;
	}

	/**
	 * Create a reader with the manage_woocommerce cap (a store admin).
	 *
	 * @return int User ID.
	 */
	private function create_store_admin(): int {
		$user_id = $this->create_reader();
		get_user_by( 'id', $user_id )->add_cap( 'manage_woocommerce' );
		return $user_id;
	}

	/**
	 * Create an active, group-enabled subscription owned by $owner_id.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id ) {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		return $subscription;
	}

	/**
	 * Run handle_set_manager_role() with POST data populated and a redirect
	 * interceptor, so the exiting admin-post handler can be asserted on.
	 *
	 * @param int    $subscription_id Subscription posted by the form.
	 * @param int    $member_id       Target member.
	 * @param string $role            'manager' to promote, 'member' to demote.
	 *
	 * @throws \Exception Re-throws anything other than the deliberate redirect interception.
	 */
	private function invoke_set_manager_role_handler( int $subscription_id, int $member_id, string $role ): void {
		$original_request_method = $_SERVER['REQUEST_METHOD'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$_POST                     = [];
		$_POST['subscription_id']  = (string) $subscription_id;
		$_POST['member_id']        = (string) $member_id;
		$_POST['role']             = $role;
		$_POST['_wpnonce']         = wp_create_nonce( Group_Subscription_MyAccount::SET_MANAGER_ROLE_NONCE_ACTION );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Accept the $location the wp_redirect filter passes, matching the hook
		// signature; the destination isn't asserted, only that a redirect happened.
		$capture    = function ( $location ) {
			throw new \Exception( 'redirect_intercepted' );
		};
		$allow_host = fn( $hosts ) => array_merge( $hosts, [ 'example.com' ] );
		add_filter( 'wp_redirect', $capture, 1 );
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			Group_Subscription_MyAccount::handle_set_manager_role();
		} catch ( \Exception $e ) {
			if ( 'redirect_intercepted' !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_POST    = [];
			$_REQUEST = [];
			if ( null === $original_request_method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $original_request_method;
			}
		}
	}

	/**
	 * Run handle_remove_member() with POST data populated and a redirect
	 * interceptor, exercising the admin-post removal path end to end.
	 *
	 * @param int $subscription_id Subscription posted by the form.
	 * @param int $member_id       Target member to remove.
	 *
	 * @throws \Exception Re-throws anything other than the deliberate redirect interception.
	 */
	private function invoke_remove_member_handler( int $subscription_id, int $member_id ): void {
		$original_request_method = $_SERVER['REQUEST_METHOD'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$_POST                     = [];
		$_POST['subscription_id']  = (string) $subscription_id;
		$_POST['member_id']        = (string) $member_id;
		$_POST['_wpnonce']         = wp_create_nonce( Group_Subscription_MyAccount::REMOVE_MEMBER_NONCE_ACTION );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Accept the $location the wp_redirect filter passes, matching the hook
		// signature; the destination isn't asserted, only that a redirect happened.
		$capture    = function ( $location ) {
			throw new \Exception( 'redirect_intercepted' );
		};
		$allow_host = fn( $hosts ) => array_merge( $hosts, [ 'example.com' ] );
		add_filter( 'wp_redirect', $capture, 1 );
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			Group_Subscription_MyAccount::handle_remove_member();
		} catch ( \Exception $e ) {
			if ( 'redirect_intercepted' !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			$_POST    = [];
			$_REQUEST = [];
			if ( null === $original_request_method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $original_request_method;
			}
		}
	}

	// ---- Data layer: promote / demote / reverse lookups ----

	/**
	 * Promoting an existing member via add_manager(): get_managers() returns
	 * the owner plus the manager, and user_is_manager() flips to true.
	 */
	public function test_add_manager_promotes_a_member() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );

		$this->assertFalse( Group_Subscription::user_is_manager( $member_id, $subscription ), 'A plain member is not a manager.' );

		$result = Group_Subscription::add_manager( $subscription, $member_id );

		$this->assertTrue( $result, 'Promoting a member should succeed.' );
		$this->assertEqualsCanonicalizing( [ $owner_id, $member_id ], Group_Subscription::get_managers( $subscription ), 'Managers are the owner plus the promoted member.' );
		$this->assertTrue( Group_Subscription::user_is_manager( $member_id, $subscription ), 'The promoted member is a manager.' );
	}

	/**
	 * The owner (implicit manager) and non-members are rejected by add_manager().
	 */
	public function test_add_manager_rejects_owner_and_non_members() {
		$owner_id     = $this->create_reader();
		$outsider_id  = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );

		$this->assertWPError( Group_Subscription::add_manager( $subscription, $owner_id ), 'The owner cannot be stored as a manager.' );
		$this->assertWPError( Group_Subscription::add_manager( $subscription, $outsider_id ), 'A non-member cannot be made a manager.' );
		$this->assertSame( [ $owner_id ], Group_Subscription::get_managers( $subscription ), 'Managers remain owner-only.' );
	}

	/**
	 * Demoting via remove_manager() returns the manager to a plain member; the
	 * owner is protected.
	 */
	public function test_remove_manager_demotes() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		Group_Subscription::add_manager( $subscription, $member_id );

		$this->assertTrue( Group_Subscription::remove_manager( $subscription, $member_id ), 'Demoting a manager should succeed.' );
		$this->assertSame( [ $owner_id ], Group_Subscription::get_managers( $subscription ), 'Managers are back to owner-only.' );
		$this->assertTrue( Group_Subscription::user_is_member( $member_id, $subscription ), 'The demoted manager remains a member.' );
		$this->assertWPError( Group_Subscription::remove_manager( $subscription, $owner_id ), 'The owner cannot be demoted.' );
	}

	/**
	 * Demoting someone who isn't a manager errors, rather than silently
	 * succeeding — remove_manager() rejects a non-manager target.
	 */
	public function test_remove_manager_errors_on_non_manager() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );

		$result = Group_Subscription::remove_manager( $subscription, $member_id );

		$this->assertWPError( $result, 'Demoting a plain member is an error.' );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null, 'The error carries a 400 status.' );
	}

	/**
	 * Removing a member from the group clears their manager meta too.
	 */
	public function test_removing_a_member_clears_their_manager_role() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		Group_Subscription::add_manager( $subscription, $member_id );

		Group_Subscription::update_members( $subscription, [], [ $member_id ] );

		$this->assertSame( [ $owner_id ], Group_Subscription::get_managers( $subscription ), 'Leaving the group ends the manager role.' );
		$this->assertEmpty(
			get_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY, false ),
			'No orphaned manager meta remains.'
		);
	}

	/**
	 * A promoted manager's managed-subscriptions lookup includes the group they
	 * manage without owning — this is what lights up their My Account access.
	 */
	public function test_managed_subscriptions_include_manager_of_groups() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );

		Group_Subscription::reset_cache();
		$this->assertNotContains( $subscription->get_id(), Group_Subscription::get_managed_subscriptions_for_user( $member_id, true ), 'A plain member manages nothing.' );

		Group_Subscription::add_manager( $subscription, $member_id );

		$this->assertContains( $subscription->get_id(), Group_Subscription::get_managed_subscriptions_for_user( $member_id, true ), 'A promoted manager manages the group.' );

		Group_Subscription::remove_manager( $subscription, $member_id );

		$this->assertNotContains( $subscription->get_id(), Group_Subscription::get_managed_subscriptions_for_user( $member_id, true ), 'A demoted manager loses the group.' );
	}

	/**
	 * The per-request get_managers() cache is busted by a promotion — a second
	 * read reflects the change without a manual reset_cache().
	 */
	public function test_get_managers_cache_invalidated_by_role_change() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );

		// Prime the cache.
		$this->assertSame( [ $owner_id ], Group_Subscription::get_managers( $subscription ) );

		Group_Subscription::add_manager( $subscription, $member_id );
		$this->assertEqualsCanonicalizing( [ $owner_id, $member_id ], Group_Subscription::get_managers( $subscription ), 'Promotion invalidates the cache.' );

		Group_Subscription::remove_manager( $subscription, $member_id );
		$this->assertSame( [ $owner_id ], Group_Subscription::get_managers( $subscription ), 'Demotion invalidates the cache.' );
	}

	// ---- Shared peer-removal predicate ----

	/**
	 * The owner (and store admins) may remove any non-owner member, but never
	 * the owner.
	 */
	public function test_can_remove_member_owner_and_admin() {
		$owner_id     = $this->create_reader();
		$manager_id   = $this->create_reader();
		$member_id    = $this->create_reader();
		$admin_id     = $this->create_store_admin();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $manager_id, $member_id ] );
		Group_Subscription::add_manager( $subscription, $manager_id );

		$this->assertTrue( Group_Subscription::can_actor_remove_member( $owner_id, $member_id, $subscription ), 'Owner removes a plain member.' );
		$this->assertTrue( Group_Subscription::can_actor_remove_member( $owner_id, $manager_id, $subscription ), 'Owner removes a manager.' );
		$this->assertTrue( Group_Subscription::can_actor_remove_member( $admin_id, $manager_id, $subscription ), 'Store admin removes a manager.' );
		$this->assertFalse( Group_Subscription::can_actor_remove_member( $owner_id, $owner_id, $subscription ), 'The owner is never removable.' );
		$this->assertFalse( Group_Subscription::can_actor_remove_member( $admin_id, $owner_id, $subscription ), 'Even a store admin cannot remove the owner.' );
	}

	/**
	 * A manager may remove plain members but never a peer manager — the crux of
	 * NPPD-1815.
	 */
	public function test_can_remove_member_manager_peer_rule() {
		$owner_id     = $this->create_reader();
		$manager_a    = $this->create_reader();
		$manager_b    = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $manager_a, $manager_b, $member_id ] );
		Group_Subscription::add_manager( $subscription, $manager_a );
		Group_Subscription::add_manager( $subscription, $manager_b );

		$this->assertTrue( Group_Subscription::can_actor_remove_member( $manager_a, $member_id, $subscription ), 'A manager removes a plain member.' );
		$this->assertFalse( Group_Subscription::can_actor_remove_member( $manager_a, $manager_b, $subscription ), 'A manager cannot remove a peer manager.' );
		$this->assertFalse( Group_Subscription::can_actor_remove_member( $manager_a, $owner_id, $subscription ), 'A manager cannot remove the owner.' );
	}

	/**
	 * Plain members and outsiders can remove no one.
	 */
	public function test_can_remove_member_plain_members_cannot() {
		$owner_id     = $this->create_reader();
		$member_a     = $this->create_reader();
		$member_b     = $this->create_reader();
		$outsider_id  = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_a, $member_b ] );

		$this->assertFalse( Group_Subscription::can_actor_remove_member( $member_a, $member_b, $subscription ), 'A plain member cannot remove a peer.' );
		$this->assertFalse( Group_Subscription::can_actor_remove_member( $outsider_id, $member_a, $subscription ), 'An outsider cannot remove a member.' );
	}

	// ---- REST endpoint enforces the peer-removal rule ----

	/**
	 * The REST members endpoint rejects a manager removing a peer manager with a
	 * 403, even though the shared permission_callback would let the manager write.
	 */
	public function test_api_rejects_manager_removing_peer_manager() {
		$owner_id     = $this->create_reader();
		$manager_a    = $this->create_reader();
		$manager_b    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $manager_a, $manager_b ] );
		Group_Subscription::add_manager( $subscription, $manager_a );
		Group_Subscription::add_manager( $subscription, $manager_b );
		wp_set_current_user( $manager_a );

		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/members' );
		$request->set_param( 'subscription_id', $subscription->get_id() );
		$request->set_param( 'members_to_remove', [ $manager_b ] );
		// rest_ensure_response() returns a WP_Error unchanged, so the error surfaces directly.
		$response = Group_Subscription_API::api_update_members( $request );

		$this->assertWPError( $response, 'Removing a peer manager is refused.' );
		$status = is_wp_error( $response ) ? ( $response->get_error_data()['status'] ?? null ) : null;
		$this->assertSame( 403, $status, 'The refusal is a 403.' );
		$this->assertTrue( Group_Subscription::user_is_member( $manager_b, $subscription ), 'The peer manager keeps their membership.' );
	}

	/**
	 * The REST members endpoint lets a manager remove a plain member.
	 */
	public function test_api_allows_manager_removing_plain_member() {
		$owner_id     = $this->create_reader();
		$manager_id   = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $manager_id, $member_id ] );
		Group_Subscription::add_manager( $subscription, $manager_id );
		wp_set_current_user( $manager_id );

		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/members' );
		$request->set_param( 'subscription_id', $subscription->get_id() );
		$request->set_param( 'members_to_remove', [ $member_id ] );
		$response = Group_Subscription_API::api_update_members( $request );

		$this->assertNotWPError( $response, 'Removing a plain member succeeds.' );
		$this->assertFalse( Group_Subscription::user_is_member( $member_id, $subscription ), 'The plain member is removed.' );
	}

	// ---- My Account handler gates role changes to owner / admin ----

	/**
	 * The owner can promote a member through the admin-post handler.
	 */
	public function test_handle_set_manager_role_owner_can_promote() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		wp_set_current_user( $owner_id );

		$this->invoke_set_manager_role_handler( $subscription->get_id(), $member_id, 'manager' );

		$this->assertTrue( Group_Subscription::user_is_manager( $member_id, $subscription ), 'The owner promoted the member.' );
	}

	/**
	 * A store admin can promote a member on the owner's behalf.
	 */
	public function test_handle_set_manager_role_admin_can_promote() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$admin_id     = $this->create_store_admin();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		wp_set_current_user( $admin_id );

		$this->invoke_set_manager_role_handler( $subscription->get_id(), $member_id, 'manager' );

		$this->assertTrue( Group_Subscription::user_is_manager( $member_id, $subscription ), 'The store admin promoted the member.' );
	}

	/**
	 * A plain member cannot use the handler to change roles.
	 */
	public function test_handle_set_manager_role_member_cannot_promote() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$other_id     = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id, $other_id ] );
		wp_set_current_user( $member_id );

		$this->invoke_set_manager_role_handler( $subscription->get_id(), $other_id, 'manager' );

		$this->assertFalse( Group_Subscription::user_is_manager( $other_id, $subscription ), 'A plain member cannot promote anyone.' );
	}

	/**
	 * A peer manager cannot change roles — promotion/demotion stays with the owner.
	 */
	public function test_handle_set_manager_role_peer_manager_cannot_promote() {
		$owner_id     = $this->create_reader();
		$manager_id   = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $manager_id, $member_id ] );
		Group_Subscription::add_manager( $subscription, $manager_id );
		wp_set_current_user( $manager_id );

		$this->invoke_set_manager_role_handler( $subscription->get_id(), $member_id, 'manager' );

		$this->assertFalse( Group_Subscription::user_is_manager( $member_id, $subscription ), 'A peer manager cannot promote a member.' );
	}

	/**
	 * The owner can demote a manager through the admin-post handler.
	 */
	public function test_handle_set_manager_role_owner_can_demote() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		Group_Subscription::add_manager( $subscription, $member_id );
		wp_set_current_user( $owner_id );

		$this->invoke_set_manager_role_handler( $subscription->get_id(), $member_id, 'member' );

		$this->assertFalse( Group_Subscription::user_is_manager( $member_id, $subscription ), 'The owner demoted the manager.' );
		$this->assertTrue( Group_Subscription::user_is_member( $member_id, $subscription ), 'The demoted manager remains a member.' );
	}

	// ---- My Account remove-member handler enforces the peer-removal rule ----

	/**
	 * A manager's forged POST to remove a peer manager is refused by the
	 * admin-post handler (the second enforcement site alongside REST).
	 */
	public function test_handle_remove_member_rejects_manager_removing_peer_manager() {
		$owner_id     = $this->create_reader();
		$manager_a    = $this->create_reader();
		$manager_b    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $manager_a, $manager_b ] );
		Group_Subscription::add_manager( $subscription, $manager_a );
		Group_Subscription::add_manager( $subscription, $manager_b );
		wp_set_current_user( $manager_a );

		$this->invoke_remove_member_handler( $subscription->get_id(), $manager_b );

		$this->assertTrue( Group_Subscription::user_is_member( $manager_b, $subscription ), 'The peer manager is not removed via the admin-post path.' );
	}

	/**
	 * A manager can remove a plain member through the admin-post handler.
	 */
	public function test_handle_remove_member_allows_manager_removing_plain_member() {
		$owner_id     = $this->create_reader();
		$manager_id   = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $manager_id, $member_id ] );
		Group_Subscription::add_manager( $subscription, $manager_id );
		wp_set_current_user( $manager_id );

		$this->invoke_remove_member_handler( $subscription->get_id(), $member_id );

		$this->assertFalse( Group_Subscription::user_is_member( $member_id, $subscription ), 'The plain member is removed via the admin-post path.' );
	}

	// ---- Seat accounting stays consistent once managers exist ----

	/**
	 * Promoting a member must not change the capacity denominator: a promoted
	 * manager keeps their member seat, so capacity stays the owner-inclusive limit
	 * and stays in step with the count and the add limit.
	 */
	public function test_capacity_and_count_stay_consistent_after_promotion() {
		$owner_id     = $this->create_reader();
		$member_a     = $this->create_reader();
		$member_b     = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner_id );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', '3' );
		Group_Subscription::update_members( $subscription, [ $member_a, $member_b ] );

		$capacity_before = Group_Subscription::get_member_capacity( $subscription );
		$count_before    = Group_Subscription::get_member_count( $subscription );
		$this->assertSame( 3, $capacity_before, 'Capacity is the owner-inclusive limit (3).' );
		$this->assertSame( 3, $count_before, 'Count is the owner plus two members, which fills the group.' );

		Group_Subscription::add_manager( $subscription, $member_a );

		$this->assertSame( 3, Group_Subscription::get_member_capacity( $subscription ), 'Promoting a member does not change capacity — a manager keeps their member seat.' );
		$this->assertSame( 3, Group_Subscription::get_member_count( $subscription ), 'Promoting a member does not change the headcount.' );
	}
}
