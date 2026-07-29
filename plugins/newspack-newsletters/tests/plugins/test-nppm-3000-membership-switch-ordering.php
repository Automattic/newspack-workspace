<?php // phpcs:disable Squiz.Commenting, Universal.Files, Generic.Files, WordPress.DB
/**
 * NPPM-3000: Premium-newsletter (WC Memberships) contact-sync event ordering.
 *
 * When a reader switches between two membership plans that share a Subscription
 * List, the shared list must survive the switch. The bug: the "add new plan's
 * lists" op (which diffs against the reader's current lists and therefore SKIPS
 * the already-present shared list) runs before the "remove old plan's lists" op
 * (which removes ALL of the old plan's lists, including the shared one) -- so the
 * shared list is dropped.
 *
 * This test drives the two real integration entry points in the order the
 * reporter observed (add-before-remove) against a stateful in-memory Mailchimp
 * mock, and asserts the shared list is retained. It FAILS on unpatched code and
 * should PASS once the ordering / removal logic is fixed.
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Plugins\Woocommerce_Memberships;
use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;

class NPPM_3000_Membership_Switch_Ordering_Test extends WP_UnitTestCase {

	const AUDIENCE_ID = 'aud1';

	// Reader user ID.
	private $user_id;

	// Subscription List post IDs.
	private $list_shared;
	private $list_monthly;
	private $list_annual;

	// Membership post IDs.
	private $monthly_membership_id;
	private $annual_membership_id;

	/**
	 * Stateful in-memory Mailchimp group ( "interest" ) membership for the reader,
	 * keyed by group id => bool subscribed. This is what get_contact_data returns
	 * and what member PUTs mutate, so add/remove ops compound realistically.
	 *
	 * @var array<string,bool>
	 */
	private static $mc_interests = [];

	/** Reader email under test. */
	private static $email = 'switcher@example.com';

	public function set_up() {
		parent::set_up();

		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );

		// Reader.
		$this->user_id = wp_insert_user(
			[
				'user_login' => 'switcher',
				'user_pass'  => '123',
				'user_email' => self::$email,
				'role'       => 'subscriber',
			]
		);

		// Three local ( group-backed ) Subscription Lists in one audience.
		$this->list_shared  = $this->create_local_list( 'Shared Premium', 'grp_shared' );
		$this->list_monthly = $this->create_local_list( 'Monthly Only', 'grp_monthly' );
		$this->list_annual  = $this->create_local_list( 'Annual Only', 'grp_annual' );

		// Two plans, each sharing the shared list.
		$monthly_plan = $this->create_plan( 100, [ $this->list_shared, $this->list_monthly ] );
		$annual_plan  = $this->create_plan( 200, [ $this->list_shared, $this->list_annual ] );
		global $test_wc_memberships;
		$test_wc_memberships = [ $monthly_plan, $annual_plan ];

		// Membership posts ( author = reader ), both active.
		$this->monthly_membership_id = $this->create_membership( 100 );
		$this->annual_membership_id  = $this->create_membership( 200 );

		// Reader starts subscribed to the shared + monthly groups.
		self::$mc_interests = [
			'grp_shared'  => true,
			'grp_monthly' => true,
			'grp_annual'  => false,
		];

		add_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get' ], 10, 3 );
		add_filter( 'mailchimp_mock_put', [ __CLASS__, 'mock_put' ], 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get' ], 10 );
		remove_filter( 'mailchimp_mock_put', [ __CLASS__, 'mock_put' ], 10 );
		global $test_wc_memberships;
		$test_wc_memberships = [];
		self::$mc_interests  = [];
		parent::tear_down();
	}

	private function create_local_list( $title, $group_id ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => $title,
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta(
			$post_id,
			Subscription_List::META_KEY,
			[
				'mailchimp' => [
					'list'     => self::AUDIENCE_ID,
					'tag_id'   => $group_id,
					'tag_name' => $title,
				],
			]
		);
		return $post_id;
	}

	private function create_plan( $plan_id, $list_post_ids ) {
		$rule = new WC_Memberships_Membership_Plan_Rule(
			[
				'content_type_name' => Subscription_Lists::CPT,
				'object_id_rules'   => $list_post_ids,
			]
		);
		$plan = new WC_Memberships_Membership_Plan( $plan_id );
		$plan->set_content_restriction_rules( [ $rule ] );
		return $plan;
	}

	private function create_membership( $plan_id ) {
		return wp_insert_post(
			[
				'post_title'  => 'Membership ' . $plan_id,
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_author' => $this->user_id,
				'meta_input'  => [
					'_membership_plan_id' => $plan_id,
					'_start_date'         => current_time( 'mysql' ),
				],
			]
		);
	}

	/** Public id ( newspack-<postID> ) for a local list. */
	private function public_id( $post_id ) {
		return ( new Subscription_List( $post_id ) )->get_public_id();
	}

	/**
	 * Stateful search-members mock: returns the reader with current group state.
	 */
	public static function mock_get( $response, $endpoint, $args = [] ) {
		if ( 'search-members' === $endpoint && isset( $args['query'] ) && self::$email === $args['query'] ) {
			$response['exact_matches']['members'][] = [
				'id'            => md5( strtolower( self::$email ) ),
				'contact_id'    => 'contact-1',
				'full_name'     => 'Switcher',
				'email_address' => self::$email,
				'status'        => 'subscribed',
				'list_id'       => self::AUDIENCE_ID,
				'interests'     => self::$mc_interests,
				'tags'          => [],
			];
		}
		return $response;
	}

	/**
	 * Stateful member PUT mock: applies interest ( group ) changes to state.
	 */
	public static function mock_put( $response, $endpoint, $args = [] ) {
		if ( preg_match( '#lists/[^/]+/members/#', $endpoint ) ) {
			if ( ! empty( $args['interests'] ) && is_array( $args['interests'] ) ) {
				foreach ( $args['interests'] as $group_id => $subscribed ) {
					self::$mc_interests[ $group_id ] = (bool) $subscribed;
				}
			}
			return [ 'status' => 'subscribed' ];
		}
		return $response;
	}

	/**
	 * Reproduce a monthly -> annual switch in the reported (buggy) order:
	 * the new plan is added first, the old plan is removed second.
	 */
	public function test_shared_list_survives_membership_switch() {
		// Sanity: reader starts in shared + monthly, not annual.
		$before = \Newspack_Newsletters_Subscription::get_contact_lists( self::$email );
		$this->assertContains( $this->public_id( $this->list_shared ), $before, 'Precondition: reader is in the shared list.' );
		$this->assertContains( $this->public_id( $this->list_monthly ), $before, 'Precondition: reader is in the monthly list.' );

		// 1) ADD: annual membership saved/activated.
		Woocommerce_Memberships::add_user_to_lists(
			$this->annual_membership_plan(),
			[
				'user_id'            => $this->user_id,
				'user_membership_id' => $this->annual_membership_id,
				'is_update'          => false,
			]
		);

		// 2) REMOVE: monthly membership transitions to an inactive status.
		$this->set_post_status_raw( $this->monthly_membership_id, 'wcm-cancelled' );
		Woocommerce_Memberships::handle_membership_status_change(
			new WC_Memberships_User_Membership( $this->monthly_membership_id ),
			'active',
			'cancelled'
		);

		// Assert the end state: reader retains the shared + annual lists, dropped monthly.
		$after = \Newspack_Newsletters_Subscription::get_contact_lists( self::$email );

		$this->assertContains(
			$this->public_id( $this->list_shared ),
			$after,
			'Shared list must survive a membership switch (NPPM-3000).'
		);
		$this->assertContains(
			$this->public_id( $this->list_annual ),
			$after,
			'Reader should be added to the new (annual) list.'
		);
		$this->assertNotContains(
			$this->public_id( $this->list_monthly ),
			$after,
			'Reader should be removed from the old (monthly) list.'
		);
	}

	/**
	 * Control test: performing the REMOVE before the ADD also yields the correct
	 * end state (shared + annual retained, monthly dropped). This is NOT expected
	 * to fail pre-fix — with the still-active annual membership re-granting the
	 * shared list, remove-before-add was already correct — so it is a companion
	 * that documents the ordering is not the failure path, not a second regression
	 * guard (test_shared_list_survives_membership_switch is the guard).
	 */
	public function test_reorder_remove_before_add_preserves_shared_list() {
		// 1) REMOVE first: monthly membership transitions to inactive.
		$this->set_post_status_raw( $this->monthly_membership_id, 'wcm-cancelled' );
		Woocommerce_Memberships::handle_membership_status_change(
			new WC_Memberships_User_Membership( $this->monthly_membership_id ),
			'active',
			'cancelled'
		);

		// 2) ADD second: annual membership saved/activated.
		Woocommerce_Memberships::add_user_to_lists(
			$this->annual_membership_plan(),
			[
				'user_id'            => $this->user_id,
				'user_membership_id' => $this->annual_membership_id,
				'is_update'          => false,
			]
		);

		$after = \Newspack_Newsletters_Subscription::get_contact_lists( self::$email );
		$this->assertContains( $this->public_id( $this->list_shared ), $after, 'Remove-before-add preserves the shared list.' );
		$this->assertContains( $this->public_id( $this->list_annual ), $after, 'Annual list added.' );
		$this->assertNotContains( $this->public_id( $this->list_monthly ), $after, 'Monthly list removed.' );
	}

	/**
	 * A list the reader opted OUT of must not be silently re-added when the
	 * membership that grants it is paused and then reactivated (e.g. a
	 * subscription renewal), while another active membership keeps a shared list.
	 *
	 * Regression guard for the deactivation-history interaction: if the shared
	 * list is excluded from removal, the saved history must still record the
	 * reader's real subscriptions so reactivation restores only those -- not the
	 * whole plan. See NPPM-3000.
	 */
	public function test_optout_list_survives_membership_pause_reactivate() {
		// Reader has opted out of the monthly-only list while keeping the shared list.
		// The annual membership still grants the shared list.
		self::$mc_interests['grp_monthly'] = false;

		$before = \Newspack_Newsletters_Subscription::get_contact_lists( self::$email );
		$this->assertContains( $this->public_id( $this->list_shared ), $before, 'Precondition: reader is in the shared list.' );
		$this->assertNotContains( $this->public_id( $this->list_monthly ), $before, 'Precondition: reader opted out of the monthly list.' );

		// 1) PAUSE the monthly membership ( active -> paused ): triggers removal.
		$this->set_post_status_raw( $this->monthly_membership_id, 'wcm-paused' );
		Woocommerce_Memberships::handle_membership_status_change(
			new WC_Memberships_User_Membership( $this->monthly_membership_id ),
			'active',
			'paused'
		);

		// 2) REACTIVATE it ( paused -> active ): set previous_status, then re-add.
		$this->set_post_status_raw( $this->monthly_membership_id, 'wcm-active' );
		Woocommerce_Memberships::handle_membership_status_change(
			new WC_Memberships_User_Membership( $this->monthly_membership_id ),
			'paused',
			'active'
		);
		Woocommerce_Memberships::add_user_to_lists(
			$this->monthly_membership_plan(),
			[
				'user_id'            => $this->user_id,
				'user_membership_id' => $this->monthly_membership_id,
				'is_update'          => true,
			]
		);

		$after = \Newspack_Newsletters_Subscription::get_contact_lists( self::$email );
		$this->assertContains(
			$this->public_id( $this->list_shared ),
			$after,
			'Shared list is retained across the pause/reactivate.'
		);
		$this->assertNotContains(
			$this->public_id( $this->list_monthly ),
			$after,
			'Opted-out list must NOT be re-added on reactivation (NPPM-3000).'
		);
	}

	private function monthly_membership_plan() {
		global $test_wc_memberships;
		foreach ( $test_wc_memberships as $plan ) {
			if ( 100 === (int) $plan->get_id() ) {
				return $plan;
			}
		}
		return null;
	}

	private function annual_membership_plan() {
		global $test_wc_memberships;
		foreach ( $test_wc_memberships as $plan ) {
			if ( 200 === (int) $plan->get_id() ) {
				return $plan;
			}
		}
		return null;
	}

	/**
	 * Flip a post_status directly, without triggering the WP transition hooks the
	 * mock environment doesn't fully support.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $status  The new post_status.
	 */
	private function set_post_status_raw( $post_id, $status ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_status' => $status ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
	}
}
