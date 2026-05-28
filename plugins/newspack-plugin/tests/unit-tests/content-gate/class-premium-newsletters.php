<?php
/**
 * Tests for the Premium_Newsletters class.
 *
 * @package Newspack\Tests\Content_Gate
 * @group premium-newsletters
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Rules;
use Newspack\Data_Events;
use Newspack\Premium_Newsletters;
use Newspack\Reader_Activation;

/**
 * Tests for the Premium_Newsletters class.
 *
 * @group premium-newsletters
 */
class Newspack_Test_Premium_Newsletters extends \WP_UnitTestCase {

	/**
	 * Gate IDs created during tests.
	 *
	 * @var int[]
	 */
	private $gate_ids = [];

	/**
	 * Post IDs created during tests.
	 *
	 * @var int[]
	 */
	private $post_ids = [];

	/**
	 * Set up before all tests.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-namespaced-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		if ( ! post_type_exists( \Newspack\Newsletters\Subscription_Lists::CPT ) ) {
			register_post_type( \Newspack\Newsletters\Subscription_Lists::CPT );
		}
	}

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		\Newspack_Newsletters_Contacts::reset_calls();
		\Newspack_Newsletters_Subscription::reset_calls();
		$prop = new \ReflectionProperty( Premium_Newsletters::class, 'restricted_lists' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
		$gates_prop = new \ReflectionProperty( Premium_Newsletters::class, 'gates' );
		$gates_prop->setAccessible( true );
		$gates_prop->setValue( null, null );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		foreach ( $this->gate_ids as $id ) {
			wp_delete_post( $id, true );
		}
		$this->gate_ids = [];
		foreach ( $this->post_ids as $id ) {
			wp_delete_post( $id, true );
		}
		$this->post_ids = [];
		global $subscriptions_database;
		$subscriptions_database = [];
		wp_clear_scheduled_hook( Premium_Newsletters::SCHEDULED_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Premium_Newsletters::SCHEDULED_HOOK, [], 'newspack' );
		}
		// Clear alloptions cache after clearing scheduled events so that after the
		// DB transaction rolls back, Memcached does not retain the now-stale cron entry.
		wp_cache_delete( 'alloptions', 'options' );
		delete_option( Premium_Newsletters::QUEUE_OPTION );
		parent::tear_down();
	}

	// =========================================================================
	// Private test helpers
	// =========================================================================

	/**
	 * Return the user IDs stored in the queue option.
	 *
	 * Queue entries are arrays of [ 'user_id' => int, 'source' => string ] but
	 * the option may also contain bare ints written by older code (or by the
	 * set_queue() helper below). Normalize both shapes down to a flat int[].
	 *
	 * @return int[]
	 */
	private function get_queued_user_ids(): array {
		$queue = (array) get_option( Premium_Newsletters::QUEUE_OPTION, [] );
		return array_map(
			function ( $entry ) {
				return is_array( $entry ) ? (int) ( $entry['user_id'] ?? 0 ) : (int) $entry;
			},
			$queue
		);
	}

	/**
	 * Write a flat user-ID queue directly, bypassing add_user_to_queue().
	 *
	 * @param int[] $user_ids User IDs to place in the queue.
	 *
	 * @return void
	 */
	private function set_queue( array $user_ids ): void {
		update_option( Premium_Newsletters::QUEUE_OPTION, $user_ids, false );
	}

	/**
	 * Create a newsletter gate with the given product IDs and list IDs.
	 *
	 * @param array $product_ids Product IDs for the subscription access rule.
	 * @param array $list_ids    List post IDs for the newsletters content rule.
	 *
	 * @return int Gate post ID.
	 */
	private function create_newsletter_gate( array $product_ids, array $list_ids ): int {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Newsletter Gate' ], Content_Gate::GATE_CPT, true );
		$this->gate_ids[] = $gate_id;

		Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'subscription',
							'value' => $product_ids,
						],
					],
				],
			]
		);

		Content_Rules::update_gate_content_rules(
			$gate_id,
			[
				[
					'slug'  => 'newsletters',
					'value' => $list_ids,
				],
			]
		);

		return $gate_id;
	}

	// =========================================================================
	// Group B — maybe_enqueue_access_check() — adding behaviour
	// =========================================================================

	/**
	 * Test that lists are added when user has an active subscription matching the gate's access rule.
	 */
	public function test_maybe_enqueue_access_check_adds_lists_when_user_has_access() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id' => $user_id,
				'email'   => $email,
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		$calls = \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls;
		$this->assertCount( 1, $calls );
		$this->assertEquals( $email, $calls[0]['email'] );
		$this->assertContains( 'list-' . $list_post_id, $calls[0]['lists_to_add'] );
		$this->assertEmpty( $calls[0]['lists_to_remove'] );
	}

	/**
	 * Test that no call is made when auto-signup is disabled, even when user has access.
	 */
	public function test_maybe_enqueue_access_check_does_not_add_when_auto_signup_disabled() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id' => $user_id,
				'email'   => $email,
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty( \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls );
	}

	/**
	 * Test that no call is made when user has access and auto-signup is on but is already subscribed.
	 */
	public function test_maybe_enqueue_access_check_skips_already_subscribed_lists() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		// Simulate user already subscribed to the list.
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'list-' . $list_post_id ];

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id' => $user_id,
				'email'   => $email,
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		// Production code calls get_contact_lists(), finds the list already present,
		// and exits early before calling add_and_remove_lists.
		$this->assertEmpty( \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls );
	}

	// =========================================================================
	// Group C — maybe_enqueue_access_check() — removing behaviour
	// =========================================================================

	/**
	 * Test that lists are removed when the user has no subscription matching the gate's access rule.
	 */
	public function test_maybe_enqueue_access_check_removes_lists_when_user_lacks_access() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 200 ], [ $list_post_id ] );

		// No WC subscription created — user has no access.

		// Simulate the user currently subscribed to the list in the ESP so that the
		// dedup check inside add_and_remove_lists() allows the removal to proceed.
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'list-' . $list_post_id ];

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id' => $user_id,
				'email'   => $email,
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		// The remove path has no auto_signup guard — it fires regardless of that option.
		$calls = \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls;
		$this->assertCount( 1, $calls );
		$this->assertContains( 'list-' . $list_post_id, $calls[0]['lists_to_remove'] );
		$this->assertEmpty( $calls[0]['lists_to_add'] );
	}

	// =========================================================================
	// Group D — filter_subscription_lists()
	// =========================================================================

	/**
	 * Test that unrestricted lists pass through.
	 */
	public function test_filter_subscription_lists_passes_unrestricted_lists() {
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// No gate covering this post.
		$mock_list = new \Newspack\Newsletters\Subscription_List( $post_id );

		$result = Premium_Newsletters::filter_subscription_lists( [ $mock_list ] );
		$this->assertCount( 1, $result );
		$this->assertSame( $mock_list, $result[0] );
	}

	/**
	 * Test that restricted lists are removed.
	 */
	public function test_filter_subscription_lists_removes_restricted_lists() {
		// Register the CPT so posts of this type can be created.
		if ( ! post_type_exists( \Newspack\Newsletters\Subscription_Lists::CPT ) ) {
			register_post_type( \Newspack\Newsletters\Subscription_Lists::CPT );
		}

		$post_id = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $post_id;

		$this->create_newsletter_gate( [ 100 ], [ $post_id ] );

		$mock_list = new \Newspack\Newsletters\Subscription_List( $post_id );

		$result = Premium_Newsletters::filter_subscription_lists( [ $mock_list ] );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that the result array is re-indexed after filtering.
	 */
	public function test_filter_subscription_lists_result_is_reindexed() {
		// Register the CPT.
		if ( ! post_type_exists( \Newspack\Newsletters\Subscription_Lists::CPT ) ) {
			register_post_type( \Newspack\Newsletters\Subscription_Lists::CPT );
		}

		// Restricted post.
		$restricted_post_id = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $restricted_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $restricted_post_id ] );

		// Unrestricted post.
		$unrestricted_post_id = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $unrestricted_post_id;

		$restricted_list   = new \Newspack\Newsletters\Subscription_List( $restricted_post_id );
		$unrestricted_list = new \Newspack\Newsletters\Subscription_List( $unrestricted_post_id );

		// Pass restricted first so index 0 would be missing without array_values.
		$result = Premium_Newsletters::filter_subscription_lists( [ $restricted_list, $unrestricted_list ] );
		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 0, $result, 'Result must be re-indexed (array_values applied).' );
		$this->assertSame( $unrestricted_list, $result[0] );
	}

	// =========================================================================
	// Group F — schedule_access_check() / process_access_check_queue() / clear_queue()
	// =========================================================================

	/**
	 * Test that calling maybe_enqueue_access_check appends the user ID to the queue option.
	 */
	public function test_schedule_adds_user_to_queue() {
		$user_id = $this->factory->user->create();

		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );

		$this->assertContains( $user_id, $this->get_queued_user_ids() );
	}

	/**
	 * Test that the same user ID is only stored once even when enqueued multiple times.
	 */
	public function test_schedule_deduplicates_user_ids() {
		$user_id = $this->factory->user->create();

		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );
		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );

		$this->assertCount( 1, $this->get_queued_user_ids() );
	}

	/**
	 * Test that register_access_check_event() schedules a recurring WP cron event when none exists.
	 */
	public function test_register_access_check_event_schedules_recurring_event() {
		wp_clear_scheduled_hook( Premium_Newsletters::SCHEDULED_HOOK );

		Premium_Newsletters::register_access_check_event();

		$this->assertNotFalse(
			wp_next_scheduled( Premium_Newsletters::SCHEDULED_HOOK ),
			'A recurring WP cron event should be scheduled for SCHEDULED_HOOK.'
		);
	}

	/**
	 * Test that process_access_check_queue() runs check_access for each queued user.
	 */
	public function test_process_queue_calls_check_access_for_each_user() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user1 = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$user2 = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		foreach ( [ $user1, $user2 ] as $uid ) {
			wcs_create_subscription(
				[
					'customer_id' => $uid,
					'status'      => 'active',
					'products'    => [ 100 ],
				]
			);
		}

		$this->set_queue( [ $user1, $user2 ] );

		Premium_Newsletters::process_access_check_queue();

		$this->assertCount( 2, \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls );
	}

	/**
	 * Test that process_access_check_queue() clears the queue option before processing.
	 */
	public function test_process_queue_clears_option_before_running() {
		$user_id = $this->factory->user->create();
		$this->set_queue( [ $user_id ] );

		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty( get_option( Premium_Newsletters::QUEUE_OPTION ) );
	}

	/**
	 * Test that process_access_check_queue() is a no-op when the queue is empty.
	 */
	public function test_process_queue_is_noop_when_empty() {
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty( \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls );
	}

	/**
	 * Test that clear_queue() deletes the queue option entirely.
	 */
	public function test_clear_queue_deletes_option() {
		$this->set_queue( [ $this->factory->user->create() ] );

		Premium_Newsletters::clear_queue();

		$this->assertFalse( get_option( Premium_Newsletters::QUEUE_OPTION, false ), 'Queue option must not exist after clear_queue().' );
	}

	// =========================================================================
	// Group E — register_handlers()
	// =========================================================================

	/**
	 * Test that all handlers are wired to the correct actions.
	 *
	 * Each non-renewal event uses its own thin wrapper so the queue entry can be
	 * tagged with a source — that source-tagging is what scopes the renewal
	 * snapshot to renewal-triggered checks. Asserting the wrapper-per-event wiring
	 * keeps that contract under test.
	 */
	public function test_register_handlers_wires_all_handlers() {
		$expected = [
			'product_subscription_changed'  => 'handle_product_subscription_changed',
			'donation_subscription_changed' => 'handle_donation_subscription_changed',
			'reader_verified'               => 'handle_reader_verified',
		];

		foreach ( $expected as $action => $method ) {
			$handlers = Data_Events::get_action_handlers( $action );
			$this->assertContains(
				[ 'Newspack\Premium_Newsletters', $method ],
				$handlers,
				"{$method} should be registered for {$action}"
			);
		}

		$renewal_handlers = Data_Events::get_action_handlers( 'subscription_renewal_attempt' );
		$this->assertContains(
			[ 'Newspack\Premium_Newsletters', 'set_subscribed_lists' ],
			$renewal_handlers,
			'set_subscribed_lists should be registered for subscription_renewal_attempt'
		);
	}

	// =========================================================================
	// Group G — reader_verified / reader_data_updated event handlers
	// =========================================================================

	/**
	 * Test that the reader_data_updated data shape — which carries no 'email' key —
	 * is accepted by maybe_enqueue_access_check without error and queues the user.
	 */
	public function test_reader_data_updated_data_shape_queues_user() {
		$user_id = $this->factory->user->create();

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id' => $user_id,
				'key'     => 'article_views',
				'value'   => '10',
			],
			null
		);

		$this->assertContains( $user_id, $this->get_queued_user_ids() );
	}

	/**
	 * Test that after email verification, a user whose email domain is whitelisted
	 * is added to the restricted newsletter lists.
	 *
	 * This is the primary motivation for hooking reader_verified: a user who just
	 * verified their email should immediately be enrolled in any lists their domain
	 * entitles them to, without having to trigger a subscription event.
	 */
	public function test_reader_verified_grants_list_access_for_whitelisted_domain() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
		// Mark email as verified so the email_domain access rule passes.
		update_user_meta( $user_id, Reader_Activation::EMAIL_VERIFIED, true );

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		// Gate that restricts the list to readers with an @example.com address.
		$gate_id          = Content_Gate::create_gate( [ 'title' => 'Domain Gate' ], Content_Gate::GATE_CPT, true );
		$this->gate_ids[] = $gate_id;

		Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'email_domain',
							'value' => 'example.com',
						],
					],
				],
			]
		);

		Content_Rules::update_gate_content_rules(
			$gate_id,
			[
				[
					'slug'  => 'newsletters',
					'value' => [ $list_post_id ],
				],
			]
		);

		// Simulate the reader_verified Data Event firing for this user.
		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id' => $user_id,
				'email'   => 'reader@example.com',
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		$calls = \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls;
		$this->assertCount( 1, $calls, 'One add_and_remove_lists call expected.' );
		$this->assertEquals( 'reader@example.com', $calls[0]['email'] );
		$this->assertContains( 'list-' . $list_post_id, $calls[0]['lists_to_add'] );
		$this->assertEmpty( $calls[0]['lists_to_remove'] );
	}

	// =========================================================================
	// Group H — set_subscribed_lists() / renewal flow
	// =========================================================================

	/**
	 * Test that set_subscribed_lists stores the user's current ESP list IDs in user meta.
	 */
	public function test_set_subscribed_lists_stores_subscribed_lists() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'list-100', 'list-200' ];

		Premium_Newsletters::set_subscribed_lists( time(), [ 'user_id' => $user_id ], null );

		$stored = get_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true );
		$this->assertSame( [ 'list-100', 'list-200' ], $stored );
	}

	/**
	 * Test that set_subscribed_lists returns early when user_id is absent.
	 */
	public function test_set_subscribed_lists_skips_missing_user_id() {
		Premium_Newsletters::set_subscribed_lists( time(), [], null );
		// No exception thrown and no meta written — an empty user_id should be silently ignored.
		$this->assertTrue( true );
	}

	/**
	 * Test that a contact who has unsubscribed from a premium newsletter list is NOT
	 * re-added when their subscription renews.
	 *
	 * Flow:
	 *  1. Renewal fires → set_subscribed_lists captures an empty contact list
	 *     (the user unsubscribed from the ESP list after their initial signup).
	 *  2. Access check runs → the empty snapshot signals that the user opted out;
	 *     the list is not added back despite the active subscription and auto-signup being on.
	 */
	public function test_renewal_does_not_readd_user_who_unsubscribed_from_list() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		// The user has no ESP subscriptions — they unsubscribed from the list.
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [];

		// Simulate the subscription_renewal_attempt Data Event.
		Premium_Newsletters::set_subscribed_lists( time(), [ 'user_id' => $user_id ], null );

		// Trigger the access check.
		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );
		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty(
			\Newspack_Newsletters_Contacts::$add_and_remove_lists_calls,
			'A contact who voluntarily unsubscribed from a list should not be re-added on renewal.'
		);
	}

	/**
	 * Test that a contact who is still subscribed to a premium newsletter list at the
	 * time of renewal but is dropped from the ESP list before the cron processes the
	 * access check IS re-added by the auto-signup branch.
	 *
	 * Flow:
	 *  1. Renewal fires → set_subscribed_lists captures the active ESP subscription.
	 *  2. ESP drops the user (simulated by zeroing out $contact_lists below).
	 *  3. Access check runs → the list appears in the renewal snapshot, so the user
	 *     is eligible for re-subscription and add_and_remove_lists is called.
	 */
	public function test_esp_drops_user_between_renewal_and_cron() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		// User is still subscribed to the list in the ESP.
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'list-' . $list_post_id ];

		// Simulate the subscription_renewal_attempt Data Event.
		Premium_Newsletters::set_subscribed_lists( time(), [ 'user_id' => $user_id ], null );

		// Clear the contact list mock so the dedup check inside add_and_remove_lists()
		// does not suppress the add call (simulates the user being dropped from the ESP
		// between the renewal attempt and the access check running).
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [];

		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );
		Premium_Newsletters::process_access_check_queue();

		$calls = \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls;
		$this->assertCount( 1, $calls, 'A contact who remained subscribed should be re-added on renewal.' );
		$this->assertContains( 'list-' . $list_post_id, $calls[0]['lists_to_add'] );
		$this->assertEmpty( $calls[0]['lists_to_remove'] );
	}

	/**
	 * Test that the SUBSCRIBED_LISTS_META_KEY user meta is deleted once the access check runs,
	 * so it cannot affect subsequent non-renewal access checks.
	 */
	public function test_subscribed_lists_meta_is_deleted_after_access_check() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [];
		Premium_Newsletters::set_subscribed_lists( time(), [ 'user_id' => $user_id ], null );

		// Confirm the meta was written.
		$this->assertIsArray( get_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ) );

		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );
		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty(
			get_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ),
			'SUBSCRIBED_LISTS_META_KEY meta must be deleted after the access check runs.'
		);
	}

	/**
	 * Test that a non-renewal access check (no snapshot meta) still auto-subscribes the user,
	 * confirming the meta guard does not interfere with ordinary events.
	 */
	public function test_non_renewal_access_check_adds_user_without_snapshot() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		// No set_subscribed_lists call — no snapshot meta exists.
		// The access check should add the user as it always did.
		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );
		Premium_Newsletters::process_access_check_queue();

		$calls = \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls;
		$this->assertCount( 1, $calls, 'Without a renewal snapshot, users should be auto-subscribed normally.' );
		$this->assertContains( 'list-' . $list_post_id, $calls[0]['lists_to_add'] );
	}

	/**
	 * Test that the renewal snapshot meta survives when the ESP call fails, so the
	 * next cron tick retries with the same snapshot rather than silently re-adding
	 * lists the reader unsubscribed from.
	 */
	public function test_subscribed_lists_meta_survives_provider_failure() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		// Snapshot the user as still subscribed in the ESP at renewal time.
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'list-' . $list_post_id ];
		Premium_Newsletters::set_subscribed_lists( time(), [ 'user_id' => $user_id ], null );

		// Confirm the snapshot was written.
		$this->assertIsArray( get_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ) );

		// Drop the user from the ESP so the dedup filter doesn't suppress the call,
		// then make the provider return a WP_Error to simulate a failed sync.
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [];
		\Newspack_Newsletters_Contacts::$next_return                 = new \WP_Error( 'esp_failure', 'Simulated ESP failure.' );

		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );
		Premium_Newsletters::process_access_check_queue();

		$this->assertNotEmpty(
			get_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ),
			'Snapshot meta must survive provider failure so retries respect the unsubscribe.'
		);
	}

	/**
	 * Test that set_subscribed_lists writes nothing when the auto_signup option is off,
	 * since the snapshot is only consulted by the auto-signup branch.
	 */
	public function test_set_subscribed_lists_skips_when_auto_signup_disabled() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email   = get_userdata( $user_id )->user_email;

		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'list-100' ];

		Premium_Newsletters::set_subscribed_lists( time(), [ 'user_id' => $user_id ], null );

		$this->assertEmpty(
			get_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ),
			'No snapshot should be written when auto-signup is disabled.'
		);
	}

	/**
	 * Test that a non-renewal event (e.g. reader_verified) does NOT consult the
	 * renewal snapshot, so a stale snapshot from a prior renewal cannot silently
	 * suppress auto-signup for unrelated event flows.
	 */
	public function test_non_renewal_event_does_not_consult_snapshot() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		// Plant a stale snapshot that, if consulted, would suppress auto-signup
		// for the restricted list (the snapshot says the user wasn't subscribed).
		update_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, [] );

		// Enqueue via reader_verified (non-renewal source).
		Premium_Newsletters::handle_reader_verified( time(), [ 'user_id' => $user_id ], null );
		Premium_Newsletters::process_access_check_queue();

		$calls = \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls;
		$this->assertCount( 1, $calls, 'A non-renewal event must auto-subscribe regardless of any snapshot.' );
		$this->assertContains( 'list-' . $list_post_id, $calls[0]['lists_to_add'] );

		// The snapshot must remain intact for any subsequent renewal-source check.
		$this->assertIsArray(
			get_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ),
			'A non-renewal access check must not clear the renewal snapshot.'
		);
	}

	/**
	 * Test that a thrown exception from one user's access check does not abort the
	 * rest of the queue — each entry is processed in its own try/catch.
	 */
	public function test_process_queue_continues_after_per_user_exception() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_a = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$user_b = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		foreach ( [ $user_a, $user_b ] as $uid ) {
			wcs_create_subscription(
				[
					'customer_id' => $uid,
					'status'      => 'active',
					'products'    => [ 100 ],
				]
			);
		}

		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_a ], null );
		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_b ], null );

		// First call into the contacts API throws; the second must still run.
		\Newspack_Newsletters_Contacts::$next_throw = new \RuntimeException( 'simulated provider explosion' );

		Premium_Newsletters::process_access_check_queue();

		$emails = array_column( \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls, 'email' );
		$this->assertCount( 2, $emails, 'Both users should have been attempted.' );
		$this->assertContains( get_userdata( $user_a )->user_email, $emails );
		$this->assertContains( get_userdata( $user_b )->user_email, $emails );

		// Queue is cleared after the loop so we don't loop forever on a permanently-bad user.
		$this->assertEmpty( $this->get_queued_user_ids(), 'Queue must be cleared after processing, even when an entry throws.' );
	}

	/**
	 * Test that a user whose email is NOT yet verified is not added to lists
	 * even when their domain is whitelisted — verification is a prerequisite.
	 */
	public function test_reader_verified_does_not_grant_access_when_email_unverified() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
		// Explicitly mark as unverified.
		update_user_meta( $user_id, Reader_Activation::EMAIL_VERIFIED, false );

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;

		$gate_id          = Content_Gate::create_gate( [ 'title' => 'Domain Gate' ], Content_Gate::GATE_CPT, true );
		$this->gate_ids[] = $gate_id;

		Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'email_domain',
							'value' => 'example.com',
						],
					],
				],
			]
		);

		Content_Rules::update_gate_content_rules(
			$gate_id,
			[
				[
					'slug'  => 'newsletters',
					'value' => [ $list_post_id ],
				],
			]
		);

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id' => $user_id,
				'email'   => 'reader@example.com',
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty(
			\Newspack_Newsletters_Contacts::$add_and_remove_lists_calls,
			'Unverified user must not be added to lists even with a matching domain.'
		);
	}
}
