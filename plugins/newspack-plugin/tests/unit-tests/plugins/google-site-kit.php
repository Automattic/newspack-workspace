<?php
/**
 * Tests for the GoogleSiteKit integration's GA4 custom event parameters.
 *
 * @package Newspack\Tests
 */

use Newspack\GoogleSiteKit;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Institution;
use Newspack\Reader_Activation;
use Newspack\Content_Gate\IP_Access_Rule;

/**
 * Test the `group` GA4 custom event parameter.
 *
 * @group GoogleSiteKit_Group_Param
 */
class Newspack_Test_GoogleSiteKit_Group_Param extends WP_UnitTestCase {

	/**
	 * Test user ID (the "current" user during the test).
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Owner user ID for group subscriptions.
	 *
	 * @var int
	 */
	private static $owner_id;

	/**
	 * Institution post IDs to delete during tear_down.
	 *
	 * @var int[]
	 */
	private $institution_ids = [];

	/**
	 * Enable the content gating feature flag and load WC mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Reset mock WC databases.
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];

		// Reset the per-request name/match caches so tests are order-independent.
		Group_Subscription::reset_cache();
		Institution::reset_matching_cache();

		self::$user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
		Reader_Activation::set_reader_verified( self::$user_id );

		self::$owner_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'owner@example.com',
			]
		);

		// Make the reader the current user so get_custom_event_parameters() picks them up.
		wp_set_current_user( self::$user_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		delete_user_meta( self::$user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY );

		// Delete any institution posts created during the test so they don't leak
		// into later tests (Institution::create() inserts real posts not tracked by $this->factory).
		foreach ( $this->institution_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->institution_ids = [];
		delete_transient( Institution::TRANSIENT_KEY );

		Group_Subscription::reset_cache();
		Institution::reset_matching_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Helper: create a group subscription owned by $owner_id (with the user as a member if $member_id is given).
	 *
	 * @param int      $owner_id   Owner user ID.
	 * @param int|null $member_id  Member user ID, or null for none.
	 * @param int      $product_id Product ID.
	 * @param string   $name       Group name.
	 * @param string   $status     Subscription status.
	 * @return \WC_Subscription
	 */
	private function create_group_subscription( $owner_id, $member_id, $product_id, $name, $status = 'active' ) {
		$sub = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => $status,
				'billing_period' => 'month',
				'products'       => [ $product_id ],
			]
		);
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', $name );
		if ( $member_id ) {
			add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $sub->get_id() );
		}
		return $sub;
	}

	/**
	 * Helper: create an institution post and refresh the cache.
	 *
	 * @param string $title Institution title.
	 * @param array  $rules Institution rules.
	 * @return int Institution post ID.
	 */
	private function create_institution( $title, $rules ) {
		$id = Institution::create( $title, '', $rules );
		$this->institution_ids[] = $id;
		Institution::invalidate_cache();
		return $id;
	}

	/**
	 * With no group subscriptions or institutions, `group` defaults to "none".
	 */
	public function test_group_defaults_to_none() {
		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertArrayHasKey( 'group', $params );
		$this->assertEquals( 'none', $params['group'] );
	}

	/**
	 * Owned group subscription contributes its anonymized ID label.
	 */
	public function test_group_includes_owned_group_subscription() {
		$sub = $this->create_group_subscription( self::$user_id, null, 600, 'Owner Group' );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( 'Group ' . $sub->get_id(), $params['group'] );
	}

	/**
	 * Group membership (non-owner) contributes the anonymized ID label.
	 */
	public function test_group_includes_member_group_subscription() {
		$sub = $this->create_group_subscription( self::$owner_id, self::$user_id, 601, 'Member Group' );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( 'Group ' . $sub->get_id(), $params['group'] );
	}

	/**
	 * Matching institution (via verified email domain) contributes the anonymized ID label.
	 */
	public function test_group_includes_matching_institution() {
		$inst_id = $this->create_institution( 'Test University', [ 'email_domain' => 'example.com' ] );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( 'Institution ' . $inst_id, $params['group'] );
	}

	/**
	 * Matching institution (via IP-based access rules) contributes the anonymized ID label
	 * for logged-out users, too.
	 */
	public function test_group_includes_matching_institution_while_logged_out() {
		// Ensure logged-out user.
		wp_set_current_user( 0 );
		$inst_id = $this->create_institution( 'Test University', [ 'ip_range' => '192.168.1.0/24' ] );

		// Mock a session in the whitelisted IP range.
		$_SERVER['REMOTE_ADDR'] = '192.168.1.50'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		$_COOKIE[ IP_Access_Rule::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$this->assertTrue( IP_Access_Rule::is_cookie_set() );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( 'Institution ' . $inst_id, $params['group'] );

		// Unset whitelisted IP and cookie.
		unset( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		unset( $_COOKIE[ IP_Access_Rule::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Group sub names / institution titles never appear in the GA4 `group` value —
	 * confirms the anonymized-ID contract that exists to keep PII out of GA4.
	 */
	public function test_group_never_emits_publisher_facing_names() {
		$this->create_group_subscription( self::$user_id, null, 610, 'Acme Corp Engineering Team' );
		$this->create_group_subscription( self::$owner_id, self::$user_id, 611, "John Doe's Group" );
		$this->create_institution( 'State University', [ 'email_domain' => 'example.com' ] );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertStringNotContainsString( 'Acme Corp', $params['group'] );
		$this->assertStringNotContainsString( 'John Doe', $params['group'] );
		$this->assertStringNotContainsString( 'State University', $params['group'] );
	}

	/**
	 * Multiple group subscriptions and an institution all surface, sorted naturally.
	 */
	public function test_group_combines_owned_member_and_institution_sorted() {
		$owned    = $this->create_group_subscription( self::$user_id, null, 602, 'Zeta Group' );
		$member   = $this->create_group_subscription( self::$owner_id, self::$user_id, 603, 'Beta Group' );
		$inst_id  = $this->create_institution( 'Alpha University', [ 'email_domain' => 'example.com' ] );
		$expected = [
			'Group ' . $owned->get_id(),
			'Group ' . $member->get_id(),
			'Institution ' . $inst_id,
		];
		sort( $expected, SORT_NATURAL | SORT_FLAG_CASE );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( implode( ', ', $expected ), $params['group'] );
	}

	/**
	 * Inactive group subscriptions do not contribute a label.
	 */
	public function test_group_excludes_cancelled_group_subscription() {
		$active    = $this->create_group_subscription( self::$user_id, null, 604, 'Active Owned', 'active' );
		$cancelled = $this->create_group_subscription( self::$owner_id, self::$user_id, 605, 'Cancelled Member', 'cancelled' );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( 'Group ' . $active->get_id(), $params['group'] );
		$this->assertStringNotContainsString( (string) $cancelled->get_id(), $params['group'] );
	}

	/**
	 * Anonymous (non-logged-in) requests get `group` = "none".
	 */
	public function test_group_for_anonymous_user_is_none() {
		wp_set_current_user( 0 );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( 'none', $params['group'] );
	}

	/**
	 * Owner who is also listed as a member is not double-counted.
	 */
	public function test_group_dedupes_owner_who_is_also_member() {
		$sub = $this->create_group_subscription( self::$user_id, null, 606, 'Self Group' );
		// Add the owner as a member too.
		add_user_meta( self::$user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $sub->get_id() );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( 'Group ' . $sub->get_id(), $params['group'] );
	}

	/**
	 * Two distinct group subscriptions sharing the same display name appear as two
	 * distinct anonymized labels — confirms each subscription has its own identity
	 * in the GA4 payload, regardless of name collisions.
	 */
	public function test_group_distinct_subs_with_same_name_get_distinct_ids() {
		$sub_a    = $this->create_group_subscription( self::$user_id, null, 607, 'Shared Name' );
		$sub_b    = $this->create_group_subscription( self::$owner_id, self::$user_id, 608, 'Shared Name' );
		$expected = [ 'Group ' . $sub_a->get_id(), 'Group ' . $sub_b->get_id() ];
		sort( $expected, SORT_NATURAL | SORT_FLAG_CASE );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertEquals( implode( ', ', $expected ), $params['group'] );
	}
}
