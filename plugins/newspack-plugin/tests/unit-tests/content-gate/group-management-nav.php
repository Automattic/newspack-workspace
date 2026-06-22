<?php
/**
 * Tests for the My Account group-management nav work.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * Tests for the group-management nav label helper.
 *
 * @group group_management_nav
 */
class Test_Group_Management_Nav extends \WP_UnitTestCase {

	/**
	 * Setup before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Register the menu-items filter under test; production wires it in My_Account_UI_V1::init().
		add_filter( 'woocommerce_account_menu_items', [ \Newspack\My_Account_UI_V1::class, 'my_account_menu_items' ], 1001 );
	}

	/**
	 * Teardown after each test.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		remove_filter( 'woocommerce_account_menu_items', [ \Newspack\My_Account_UI_V1::class, 'my_account_menu_items' ], 1001 );
		delete_option( 'newspack_group_subscription_label_singular' );
		delete_option( 'newspack_group_subscription_label_plural' );
		parent::tear_down();
	}

	/**
	 * Default singular label is returned when the option is not set.
	 */
	public function test_get_label_returns_default_singular_when_option_unset() {
		$this->assertSame( 'Group', Group_Subscription::get_label( 'singular' ) );
	}

	/**
	 * Default plural label is returned when the option is not set.
	 */
	public function test_get_label_returns_default_plural_when_option_unset() {
		$this->assertSame( 'Groups', Group_Subscription::get_label( 'plural' ) );
	}

	/**
	 * Publisher singular override is returned when set.
	 */
	public function test_get_label_returns_publisher_override_singular() {
		update_option( 'newspack_group_subscription_label_singular', 'Tribe' );
		$this->assertSame( 'Tribe', Group_Subscription::get_label( 'singular' ) );
	}

	/**
	 * Publisher plural override is returned when set.
	 */
	public function test_get_label_returns_publisher_override_plural() {
		update_option( 'newspack_group_subscription_label_plural', 'Tribes' );
		$this->assertSame( 'Tribes', Group_Subscription::get_label( 'plural' ) );
	}

	/**
	 * Unknown variant falls back to the singular default.
	 */
	public function test_get_label_falls_back_to_default_for_unknown_variant() {
		$this->assertSame( 'Group', Group_Subscription::get_label( 'nonsense' ) );
	}

	/**
	 * An empty string override is treated as unset and returns the default.
	 */
	public function test_get_label_treats_empty_override_as_unset() {
		update_option( 'newspack_group_subscription_label_singular', '' );
		$this->assertSame( 'Group', Group_Subscription::get_label( 'singular' ) );
	}

	/**
	 * A whitespace-only override is treated as unset and returns the default.
	 */
	public function test_get_label_trims_whitespace_only_override() {
		update_option( 'newspack_group_subscription_label_singular', '   ' );
		$this->assertSame( 'Group', Group_Subscription::get_label( 'singular' ) );
	}

	/**
	 * An empty result is returned for a user with no subscriptions.
	 */
	public function test_get_managed_subscriptions_returns_empty_for_user_without_groups() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->assertSame( [], Group_Subscription::get_managed_subscriptions_for_user( $user_id ) );
	}

	/**
	 * Only group-enabled subscriptions owned by the user are returned.
	 */
	public function test_get_managed_subscriptions_returns_only_group_enabled_subs_owned_by_user() {
		$owner_id  = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$group_sub = $this->create_subscription( $owner_id, true );
		$plain_sub = $this->create_subscription( $owner_id, false );

		$result = Group_Subscription::get_managed_subscriptions_for_user( $owner_id );

		$ids = array_map( fn( $s ) => $s->get_id(), $result );
		$this->assertContains( $group_sub->get_id(), $ids );
		$this->assertNotContains( $plain_sub->get_id(), $ids );
	}

	/**
	 * Subscriptions owned by other users are excluded.
	 */
	public function test_get_managed_subscriptions_excludes_subs_owned_by_other_users() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$other_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_subscription( $other_id, true );

		$this->assertSame( [], Group_Subscription::get_managed_subscriptions_for_user( $owner_id ) );
	}

	/**
	 * Subscriptions injected via the wcs_get_users_subscriptions filter (e.g. when
	 * the user is only a member of a group sub, not its owner) must not be treated
	 * as managed. Regression test for the My Account v2 sidebar leaking the
	 * "Group" entry to non-managers.
	 */
	public function test_get_managed_subscriptions_excludes_injected_member_subs() {
		$owner_id  = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$group_sub = $this->create_subscription( $owner_id, true );

		// Simulate the production injection: a sub owned by someone else surfaces
		// through wcs_get_users_subscriptions() when the member visits an account page.
		$inject = function ( $subs, $user_id ) use ( $member_id, $group_sub ) {
			if ( $user_id === $member_id ) {
				$subs[ $group_sub->get_id() ] = $group_sub;
			}
			return $subs;
		};
		add_filter( 'wcs_get_users_subscriptions', $inject, 10, 2 );

		try {
			$this->assertSame( [], Group_Subscription::get_managed_subscriptions_for_user( $member_id ) );
		} finally {
			remove_filter( 'wcs_get_users_subscriptions', $inject, 10 );
		}
	}

	/**
	 * Passing $ids_only = true returns only subscription IDs.
	 */
	public function test_get_managed_subscriptions_returns_ids_only_when_requested() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$sub      = $this->create_subscription( $owner_id, true );

		$result = Group_Subscription::get_managed_subscriptions_for_user( $owner_id, true );

		$this->assertSame( [ $sub->get_id() ], $result );
	}

	/**
	 * The `group` query var is registered via the woocommerce_get_query_vars filter.
	 */
	public function test_group_endpoint_is_registered_as_query_var() {
		$query_vars = apply_filters( 'woocommerce_get_query_vars', [] );
		$this->assertArrayHasKey( 'group', $query_vars );
		$this->assertSame( 'group', $query_vars['group'] );
	}

	/**
	 * URL from get_group_url() contains the subscription ID and no tab query arg.
	 * (The top-level Subscription tab was removed; "View subscription" links straight
	 * to /my-account/view-subscription/{id}/ instead.)
	 */
	public function test_get_group_url_includes_subscription_id() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$sub      = $this->create_subscription( $owner_id, true );

		$url = \Newspack\Group_Subscription_MyAccount::get_group_url( $sub );

		$this->assertStringContainsString( '/group/' . $sub->get_id(), $url );
		$this->assertStringNotContainsString( 'tab=', $url );
	}

	/**
	 * Legacy manage-members endpoint redirects to the new group endpoint URL.
	 */
	public function test_legacy_manage_members_endpoint_redirects_to_group_endpoint() {
		global $wp;
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$sub      = $this->create_subscription( $owner_id, true );
		wp_set_current_user( $owner_id );
		$wp->query_vars[ \Newspack\Group_Subscription_MyAccount::MANAGE_MEMBERS_ENDPOINT ] = $sub->get_id();

		$redirect_to     = null;
		$redirect_status = null;
		$capture         = function( $location, $status ) use ( &$redirect_to, &$redirect_status ) {
			$redirect_to     = $location;
			$redirect_status = $status;
			throw new \Exception( 'redirect_intercepted' );
		};
		$allow_host = fn( $hosts ) => array_merge( $hosts, [ 'example.com' ] );
		add_filter( 'wp_redirect', $capture, 1, 2 );
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				\Newspack\Group_Subscription_MyAccount::redirect_legacy_manage_members();
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}
			$this->assertNotNull( $redirect_to );
			$this->assertStringContainsString( '/group/' . $sub->get_id(), $redirect_to );
			$this->assertSame( 308, $redirect_status );
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			unset( $wp->query_vars[ \Newspack\Group_Subscription_MyAccount::MANAGE_MEMBERS_ENDPOINT ] );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Group endpoint with no subscription ID renders the group page inline
	 * (no redirect) when the user manages exactly one group.
	 */
	public function test_group_endpoint_without_subscription_id_renders_when_user_has_one_group() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_subscription( $owner_id, true );
		wp_set_current_user( $owner_id );

		$redirected = false;
		$capture    = function( $location ) use ( &$redirected ) {
			$redirected = true;
			throw new \Exception( 'redirect_intercepted' );
		};
		$allow_host = fn( $hosts ) => array_merge( $hosts, [ 'example.com' ] );
		add_filter( 'wp_redirect', $capture, 1 );
		add_filter( 'allowed_redirect_hosts', $allow_host );

		ob_start();
		try {
			\Newspack\Group_Subscription_MyAccount::resolve_group_landing( '' );
			$this->assertFalse( $redirected, 'Single-group landing should render inline, not redirect.' );
		} finally {
			ob_end_clean();
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * A user with zero managed groups is redirected to the My Account dashboard.
	 */
	public function test_group_endpoint_redirects_to_dashboard_when_user_has_no_groups() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$redirect_to = null;
		$capture     = function( $location ) use ( &$redirect_to ) {
			$redirect_to = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		$allow_host = fn( $hosts ) => array_merge( $hosts, [ 'example.com' ] );
		add_filter( 'wp_redirect', $capture, 1 );
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				\Newspack\Group_Subscription_MyAccount::resolve_group_landing( '' );
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}
			$this->assertNotNull( $redirect_to );
			$this->assertStringNotContainsString( '/group/', $redirect_to );
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Group endpoint with a subscription ID the current user does not manage redirects with an error.
	 */
	public function test_group_endpoint_redirects_when_user_does_not_manage_subscription() {
		$owner_id   = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$other_id   = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$sub        = $this->create_subscription( $other_id, true );
		wp_set_current_user( $owner_id );

		$redirect_to = null;
		$capture     = function( $location ) use ( &$redirect_to ) {
			$redirect_to = $location;
			throw new \Exception( 'redirect_intercepted' );
		};
		$allow_host = fn( $hosts ) => array_merge( $hosts, [ 'example.com' ] );
		add_filter( 'wp_redirect', $capture, 1 );
		add_filter( 'allowed_redirect_hosts', $allow_host );

		try {
			try {
				\Newspack\Group_Subscription_MyAccount::resolve_group_landing( (string) $sub->get_id() );
				$this->fail( 'Expected redirect exception' );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}
			$this->assertNotNull( $redirect_to );
			$this->assertStringContainsString( 'is_error=1', $redirect_to );
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Menu item is not added when the current user manages no group subscriptions.
	 */
	public function test_menu_item_not_added_when_user_has_no_managed_groups() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$items = apply_filters(
			'woocommerce_account_menu_items',
			[
				'dashboard'     => 'Dashboard',
				'subscriptions' => 'Subscriptions',
			]
		);

		$this->assertArrayNotHasKey( 'group', $items );
	}

	/**
	 * Menu item is added with the singular label when the user manages exactly one group.
	 */
	public function test_menu_item_added_when_user_has_one_managed_group() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_subscription( $owner_id, true );
		wp_set_current_user( $owner_id );

		$items = apply_filters(
			'woocommerce_account_menu_items',
			[
				'dashboard'     => 'Dashboard',
				'subscriptions' => 'Subscriptions',
			]
		);

		$this->assertArrayHasKey( 'group', $items );
		$this->assertSame( 'Group', $items['group'] );
	}

	/**
	 * Menu item label is plural when the user manages more than one group.
	 */
	public function test_menu_item_label_is_plural_when_user_has_multiple_managed_groups() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_subscription( $owner_id, true );
		$this->create_subscription( $owner_id, true );
		wp_set_current_user( $owner_id );

		$items = apply_filters(
			'woocommerce_account_menu_items',
			[
				'dashboard'     => 'Dashboard',
				'subscriptions' => 'Subscriptions',
			]
		);

		$this->assertSame( 'Groups', $items['group'] );
	}

	/**
	 * Menu item uses the publisher override label when one is configured.
	 */
	public function test_menu_item_uses_publisher_override_label() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_subscription( $owner_id, true );
		update_option( 'newspack_group_subscription_label_singular', 'Tribe' );
		wp_set_current_user( $owner_id );

		$items = apply_filters( 'woocommerce_account_menu_items', [ 'dashboard' => 'Dashboard' ] );

		$this->assertSame( 'Tribe', $items['group'] );
	}

	/**
	 * Verify the multi-group picker is rendered when the manager has 2+ groups
	 * and visits the group endpoint without a subscription ID.
	 */
	public function test_group_endpoint_without_subscription_id_renders_picker_for_multi_group_manager() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_subscription( $owner_id, true );
		$this->create_subscription( $owner_id, true );
		wp_set_current_user( $owner_id );

		ob_start();
		\Newspack\Group_Subscription_MyAccount::resolve_group_landing( '' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'newspack-my-account__group-picker', $output );
	}

	/**
	 * Verify the redundant `manage_members` action is no longer added to
	 * the per-subscription kebab dropdown on the view-subscription page.
	 */
	public function test_view_subscription_actions_no_longer_includes_manage_members() {
		$owner_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$sub      = $this->create_subscription( $owner_id, true );
		wp_set_current_user( $owner_id );

		// Simulate the WC My Account page context so the filter actually runs.
		$GLOBALS['newspack_test_is_account_page'] = true;
		$actions                                  = apply_filters( 'wcs_view_subscription_actions', [], $sub, $owner_id );
		unset( $GLOBALS['newspack_test_is_account_page'] );

		$this->assertArrayNotHasKey( 'manage_members', $actions );
	}

	/**
	 * Create a WC_Subscription owned by the given user, optionally with group settings enabled.
	 *
	 * @param int  $user_id       Owner user ID.
	 * @param bool $group_enabled Whether the subscription is group-enabled.
	 *
	 * @return \WC_Subscription
	 */
	private function create_subscription( $user_id, $group_enabled ) {
		$sub = wcs_create_subscription(
			[
				'customer_id'      => $user_id,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
			]
		);
		if ( $group_enabled ) {
			$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		}
		return $sub;
	}
}
