<?php
/**
 * Tests for the Premium_Newsletters class.
 *
 * @package Newspack\Tests\Content_Gate
 * @group premium-newsletters
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;
use Newspack\Content_Rules;
use Newspack\Data_Events;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Premium_Newsletters;
use Newspack\Reader_Activation;

/**
 * Tests for the Premium_Newsletters class.
 *
 * @group premium-newsletters
 */
class Newspack_Test_Premium_Newsletters extends \WP_UnitTestCase {

	use \Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

	/**
	 * Product ID the one-time purchase gates in Group I name.
	 */
	const ONE_TIME_PRODUCT_ID = 300;

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
	 * Callbacks hooked to wcs_get_users_subscriptions by create_gifted_subscription().
	 *
	 * @var callable[]
	 */
	private $gift_filters = [];

	/**
	 * Set up before all tests.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		// Gating is flag-gated, and these tests exercise enforcement. Defined here
		// rather than relied on from another class: constants are process-wide, so
		// without this the group passes only when an alphabetically-earlier class
		// happens to define it first, and `n test-php --group ...` fails on its own.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-namespaced-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-gifting-mock.php';
		if ( ! post_type_exists( \Newspack\Newsletters\Subscription_Lists::CPT ) ) {
			register_post_type( \Newspack\Newsletters\Subscription_Lists::CPT );
		}
	}

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Post IDs are reused across test cases (each case is rolled back), so a
		// gate lookup cached for the same ID by an earlier case would otherwise be
		// served here — reporting "no gates" for a post that has one.
		$this->reset_restriction_cache();
		// The gifting stub's recipient map is static and shared with other test files.
		\WCS_Gifting::$recipients = [];
		global $orders_database;
		$orders_database = [];
		\Newspack_Newsletters_Contacts::reset_calls();
		\Newspack_Newsletters_Subscription::reset_calls();
		$prop = new \ReflectionProperty( Premium_Newsletters::class, 'restricted_lists' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
		$gates_prop = new \ReflectionProperty( Premium_Newsletters::class, 'gates' );
		$gates_prop->setAccessible( true );
		$gates_prop->setValue( null, null );
		$refusal_prop = new \ReflectionProperty( Premium_Newsletters::class, 'sign_up_refusal' );
		$refusal_prop->setAccessible( true );
		$refusal_prop->setValue( null, '' );
		$refusal_email_prop = new \ReflectionProperty( Premium_Newsletters::class, 'sign_up_refusal_email' );
		$refusal_email_prop->setAccessible( true );
		$refusal_email_prop->setValue( null, '' );

		// Content_Restriction_Control memoizes per post for the life of the request.
		// PHPUnit shares one process across the suite, so without this the maps
		// carry entries keyed by post IDs that later tests reuse, and a test passes
		// or fails depending on where its fixtures land in the auto-increment
		// sequence. Five sibling test files in this directory reset the same four.
		foreach ( [ 'post_gate_id_map', 'post_gate_layout_id_map', 'post_gates_map', 'term_descendants_map' ] as $cache_property ) {
			$cache_property_reflection = new \ReflectionProperty( Content_Restriction_Control::class, $cache_property );
			$cache_property_reflection->setAccessible( true );
			$cache_property_reflection->setValue( null, [] );
		}
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
		foreach ( $this->gift_filters as $gift_filter ) {
			remove_filter( 'wcs_get_users_subscriptions', $gift_filter, 10 );
		}
		$this->gift_filters       = [];
		\WCS_Gifting::$recipients = [];
		global $subscriptions_database, $orders_database;
		$subscriptions_database = [];
		$orders_database        = [];
		wp_clear_scheduled_hook( Premium_Newsletters::SCHEDULED_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Premium_Newsletters::SCHEDULED_HOOK, [], 'newspack' );
		}
		// Clear alloptions cache after clearing scheduled events so that after the
		// DB transaction rolls back, Memcached does not retain the now-stale cron entry.
		wp_cache_delete( 'alloptions', 'options' );
		delete_option( Premium_Newsletters::QUEUE_OPTION );
		delete_option( Premium_Newsletters::ONE_TIME_PURCHASE_SWEEP_OPTION );
		$this->set_sweep_page_size( null );
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

	/**
	 * Create a reader, optionally with a subscription that satisfies the gates
	 * create_gated_list() makes.
	 *
	 * @param bool $with_access Whether the reader has access to gated lists.
	 *
	 * @return int User ID.
	 */
	private function create_reader( bool $with_access = false ): int {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		if ( $with_access ) {
			wcs_create_subscription(
				[
					'customer_id' => $user_id,
					'status'      => 'active',
					'products'    => [ 100 ],
				]
			);
		}
		return $user_id;
	}

	/**
	 * Create a list restricted by a premium newsletter gate.
	 *
	 * @return int List post ID.
	 */
	private function create_gated_list(): int {
		$list_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_id ] );
		return $list_id;
	}

	/**
	 * Run the sign-up filter for a list and an address.
	 *
	 * @param int    $list_id List post ID.
	 * @param string $email   Address being signed up.
	 *
	 * @return string[] The lists the filter kept.
	 */
	private function filter_sign_up( int $list_id, string $email ): array {
		return Premium_Newsletters::filter_subscribe_lists( [ 'newspack-' . $list_id ], [ 'email' => $email ] );
	}

	/**
	 * Ask for the reader-facing message after a sign-up produced the error that
	 * newsletters returns when every requested list was refused.
	 *
	 * The callback gets the details both real callers pass, so a callback that
	 * chose its words from the submitted address's account would be caught.
	 *
	 * @param int    $list_id List post ID that was requested.
	 * @param string $email   Address that was signed up.
	 *
	 * @return string The message.
	 */
	private function get_refusal_message( int $list_id, string $email ): string {
		return Premium_Newsletters::filter_sign_up_error_message(
			'Generic.',
			[
				'email' => $email,
				'lists' => [ 'newspack-' . $list_id ],
			],
			new \WP_Error( 'newspack_newsletters_lists_not_allowed', 'x' )
		);
	}

	// =========================================================================
	// filter_subscription_lists() — no-gates guard
	// =========================================================================

	/**
	 * With no premium newsletter gates, the filter returns every list untouched
	 * without evaluating per-list restriction.
	 */
	public function test_filter_subscription_lists_returns_all_when_no_gates() {
		$list_id          = self::factory()->post->create(
			[
				'post_type'   => \Newspack\Newsletters\Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);
		$this->post_ids[] = $list_id;
		$list             = new \Newspack\Newsletters\Subscription_List( $list_id );

		$result = Premium_Newsletters::filter_subscription_lists( [ $list ] );

		$this->assertCount( 1, $result );
		$this->assertSame( $list_id, $result[0]->get_id() );
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
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
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
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];

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
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];

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
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
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
			'woo_order_updated'             => 'handle_woo_order_updated',
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
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
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
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];

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
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
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
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
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
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];
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
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );

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

	// =========================================================================
	// Group subscription members
	// =========================================================================

	/**
	 * Create an active group subscription covering the given products.
	 *
	 * @param int   $owner_id    Owner user ID.
	 * @param array $product_ids Product IDs the subscription covers.
	 *
	 * @return \WC_Subscription
	 */
	private function create_group_subscription( int $owner_id, array $product_ids ) {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => $product_ids,
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		return $subscription;
	}

	/**
	 * Create a reader who is eligible to join a group subscription.
	 *
	 * @return int User ID.
	 */
	private function create_group_member(): int {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, '_newspack_reader', true );
		return $user_id;
	}

	/**
	 * Return the ESP calls made for one email address.
	 *
	 * @param string $email Email address.
	 *
	 * @return array[]
	 */
	private function get_list_calls_for( string $email ): array {
		return array_values(
			array_filter(
				\Newspack_Newsletters_Contacts::$add_and_remove_lists_calls,
				function ( $call ) use ( $email ) {
					return $call['email'] === $email;
				}
			)
		);
	}

	/**
	 * Return the queue source recorded for a user, or null when the user isn't queued.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string|null
	 */
	private function get_queued_source( int $user_id ) {
		foreach ( (array) get_option( Premium_Newsletters::QUEUE_OPTION, [] ) as $entry ) {
			if ( is_array( $entry ) && (int) ( $entry['user_id'] ?? 0 ) === $user_id ) {
				return (string) ( $entry['source'] ?? '' );
			}
		}
		return null;
	}

	/**
	 * Ways a group subscription lapses. On hold still grants access while a payment
	 * retry is pending, so moving from On hold to Expired or Cancelled ends access
	 * too. On hold to Expired is how a group lapses after its payment retries run
	 * out.
	 *
	 * @return array[]
	 */
	public function data_group_lapse_transitions() {
		return [
			'active to expired'    => [ 'active', 'expired' ],
			'on-hold to expired'   => [ 'on-hold', 'expired' ],
			'on-hold to cancelled' => [ 'on-hold', 'cancelled' ],
		];
	}

	/**
	 * When a group subscription lapses, its members lose the premium lists it paid for.
	 *
	 * @dataProvider data_group_lapse_transitions
	 *
	 * @param string $status_before Status before the lapse.
	 * @param string $status_after  Status after the lapse.
	 */
	public function test_lapsed_group_subscription_removes_member_lists( $status_before, $status_after ) {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		\Newspack_Newsletters_Subscription::$contact_lists[ $member_email ] = [ 'newspack-' . $list_post_id ];
		$subscription->set_status( $status_after );

		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => $status_before,
				'status_after'    => $status_after,
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $member_email );
		$this->assertCount( 1, $calls, 'A member of a lapsed group must be removed from its premium lists.' );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
		$this->assertEmpty( $calls[0]['lists_to_add'] );
	}

	/**
	 * With auto-signup on, members of a group subscription that comes back to Active
	 * gain its premium lists.
	 */
	public function test_reactivated_group_subscription_adds_member_lists_with_auto_signup() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'on-hold',
				'status_after'    => 'active',
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $member_email );
		$this->assertCount( 1, $calls, 'With auto-signup on, a member of an active group must be added to its premium lists.' );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
		$this->assertEmpty( $calls[0]['lists_to_remove'] );
	}

	/**
	 * With auto-signup off, a member check never adds lists, exactly as for any
	 * other reader: an entitled member who isn't on a premium list stays off it.
	 */
	public function test_reactivated_group_subscription_does_not_add_member_lists_without_auto_signup() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'on-hold',
				'status_after'    => 'active',
			],
			null
		);
		$this->assertContains( $member_id, $this->get_queued_user_ids(), 'The member must still be checked, so a lapse can remove lists.' );
		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty( $this->get_list_calls_for( $member_email ), 'With auto-signup off, a member check must not add lists.' );
	}

	/**
	 * A status change that leaves the group's access as it was, like the owner
	 * cancelling at the end of the term (Active to Pending cancel), doesn't check
	 * the members. With auto-signup on, that check would re-add premium lists
	 * members had left.
	 */
	public function test_status_change_that_keeps_access_does_not_queue_members() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		// The member left the premium list on their own.
		\Newspack_Newsletters_Subscription::$contact_lists[ $member_email ] = [];
		$subscription->set_status( 'pending-cancel' );

		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'pending-cancel',
			],
			null
		);

		$this->assertNotContains( $member_id, $this->get_queued_user_ids(), 'A change that keeps access must not queue members.' );
		Premium_Newsletters::process_access_check_queue();
		$this->assertEmpty( $this->get_list_calls_for( $member_email ) );
	}

	/**
	 * A plan switch, including a seat-count change, reports the same status before
	 * and after. Members get a remove-only check, so with auto-signup on, a switch
	 * doesn't re-add a premium list a member left.
	 */
	public function test_plan_switch_does_not_readd_member_who_unsubscribed() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		// The member left the premium list on their own.
		\Newspack_Newsletters_Subscription::$contact_lists[ $member_email ] = [];

		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'active',
			],
			null
		);

		$this->assertSame( Premium_Newsletters::SOURCE_PLAN_SWITCH, $this->get_queued_source( $member_id ), 'A plan switch must give members a remove-only check.' );
		Premium_Newsletters::process_access_check_queue();
		$this->assertEmpty( $this->get_list_calls_for( $member_email ), 'A plan switch must not re-add a list a member left.' );
	}

	/**
	 * A switch to a plan that no longer covers a premium list removes it from the
	 * members: their check is remove-only, not skipped.
	 */
	public function test_plan_switch_removes_member_lists_the_new_plan_does_not_cover() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		\Newspack_Newsletters_Subscription::$contact_lists[ $member_email ] = [ 'newspack-' . $list_post_id ];
		$subscription->products = [ 200 ];

		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'active',
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $member_email );
		$this->assertCount( 1, $calls, 'A downgrade must remove the list from the member.' );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
		$this->assertEmpty( $calls[0]['lists_to_add'] );
	}

	/**
	 * A seat change soon after someone joins doesn't replace the new member's
	 * pending check, so auto-signup still adds the lists the group covers.
	 */
	public function test_plan_switch_keeps_a_pending_join_check() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		// The member joins, then the owner changes the seat count before the queue runs.
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'active',
			],
			null
		);

		$this->assertSame( Premium_Newsletters::SOURCE_GROUP_MEMBERSHIP, $this->get_queued_source( $member_id ), 'A plan switch must not replace a pending join check.' );
		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $member_email );
		$this->assertCount( 1, $calls, 'A new member must still be added to the group\'s premium lists.' );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
	}

	/**
	 * Joining a group queues a check, so the new member gains premium lists when
	 * auto-signup is on.
	 */
	public function test_member_added_to_group_gains_lists_with_auto_signup() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		Group_Subscription::update_members( $subscription, [ $member_id ] );
		$this->assertContains( $member_id, $this->get_queued_user_ids(), 'Joining a group must queue an access check for the new member.' );
		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $member_email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
	}

	/**
	 * Leaving a group queues a check, so the former member loses its premium lists.
	 */
	public function test_member_removed_from_group_loses_lists() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );
		\Newspack_Newsletters_Subscription::$contact_lists[ $member_email ] = [ 'newspack-' . $list_post_id ];

		Group_Subscription::update_members( $subscription, [], [ $member_id ] );
		$this->assertContains( $member_id, $this->get_queued_user_ids(), 'Leaving a group must queue an access check for the former member.' );
		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $member_email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
		$this->assertEmpty( $calls[0]['lists_to_add'] );
	}

	/**
	 * A renewal snapshots each member's lists as it does the owner's, so a member who
	 * unsubscribed from a premium list isn't re-added by auto-signup when the renewal
	 * moves the group through On hold and back to Active.
	 */
	public function test_renewal_does_not_readd_member_who_unsubscribed() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$owner_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$member_id    = $this->create_group_member();
		$member_email = get_userdata( $member_id )->user_email;

		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );

		$subscription = $this->create_group_subscription( $owner_id, [ 100 ] );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		delete_option( Premium_Newsletters::QUEUE_OPTION );

		// The member left the premium list on their own.
		\Newspack_Newsletters_Subscription::$contact_lists[ $member_email ] = [];

		$event_data = [
			'user_id'         => $owner_id,
			'subscription_id' => $subscription->get_id(),
		];
		Premium_Newsletters::set_subscribed_lists( time(), $event_data, null );
		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			array_merge(
				$event_data,
				[
					'status_before' => 'active',
					'status_after'  => 'on-hold',
				]
			),
			null
		);

		$this->assertSame( Premium_Newsletters::SOURCE_RENEWAL, $this->get_queued_source( $member_id ), 'A renewal must queue members with the renewal source, like the owner.' );
		$this->assertSame( [], get_user_meta( $member_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ), "A renewal must snapshot the member's lists." );

		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty( $this->get_list_calls_for( $member_email ), 'A member who unsubscribed must not be re-added on renewal.' );
	}

	// =========================================================================
	// Gifted subscriptions
	// =========================================================================

	/**
	 * Create an active subscription bought by one reader as a gift for another.
	 *
	 * Mirrors WCS Gifting in production: the subscription stays owned by the
	 * purchaser, and the recipient sees it in wcs_get_users_subscriptions().
	 *
	 * @param int   $purchaser_id Purchaser user ID.
	 * @param int   $recipient_id Recipient user ID.
	 * @param array $product_ids  Product IDs the subscription covers.
	 *
	 * @return \WC_Subscription
	 */
	private function create_gifted_subscription( int $purchaser_id, int $recipient_id, array $product_ids ) {
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $purchaser_id,
				'status'      => 'active',
				'products'    => $product_ids,
			]
		);
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;

		$gift_filter = function ( $subscriptions, $user_id ) use ( $subscription, $recipient_id ) {
			if ( (int) $user_id === $recipient_id ) {
				$subscriptions[ $subscription->get_id() ] = $subscription;
			}
			return $subscriptions;
		};
		add_filter( 'wcs_get_users_subscriptions', $gift_filter, 10, 2 );
		$this->gift_filters[] = $gift_filter;

		return $subscription;
	}

	/**
	 * Hand a gifted subscription to a different recipient, as an admin does by
	 * editing the subscription. Updates the stub's recipient and the filter that
	 * lists the gift under its holder, then fires the hook WCS Gifting fires.
	 *
	 * @param \WC_Subscription $subscription     The gifted subscription.
	 * @param int              $new_recipient_id The new recipient's user ID.
	 * @param bool             $fire_hook        Whether to fire the recipient-changed hook.
	 *
	 * @return void
	 */
	private function reassign_gifted_subscription( $subscription, int $new_recipient_id, bool $fire_hook = true ): void {
		$old_recipient_id = (int) \WCS_Gifting::$recipients[ $subscription->get_id() ];
		foreach ( $this->gift_filters as $gift_filter ) {
			remove_filter( 'wcs_get_users_subscriptions', $gift_filter, 10 );
		}
		$this->gift_filters = [];
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $new_recipient_id;

		$gift_filter = function ( $subscriptions, $user_id ) use ( $subscription, $new_recipient_id ) {
			if ( (int) $user_id === $new_recipient_id ) {
				$subscriptions[ $subscription->get_id() ] = $subscription;
			}
			return $subscriptions;
		};
		add_filter( 'wcs_get_users_subscriptions', $gift_filter, 10, 2 );
		$this->gift_filters[] = $gift_filter;

		if ( $fire_hook ) {
			do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $new_recipient_id, $old_recipient_id );
		}
	}

	/**
	 * A subscription event carries the purchaser of a gift, who doesn't hold the
	 * subscription. The recipient does, so they must be queued too.
	 */
	public function test_gift_subscription_change_queues_recipient() {
		$purchaser_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_newsletter_gate( [ 100 ], [ $this->create_premium_list() ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $recipient_id, [ 100 ] );

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id'         => $purchaser_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'pending',
				'status_after'    => 'active',
			],
			0,
			Premium_Newsletters::SOURCE_SUBSCRIPTION_CHANGED
		);

		$queued = $this->get_queued_user_ids();
		$this->assertContains( $purchaser_id, $queued, 'The purchaser is still checked: they may lose lists.' );
		$this->assertContains( $recipient_id, $queued, 'The gift recipient must be checked.' );
		$this->assertSame( Premium_Newsletters::SOURCE_SUBSCRIPTION_CHANGED, $this->get_queued_source( $recipient_id ), 'The recipient is queued with the purchaser\'s source.' );
	}

	/**
	 * A gift's recipient is queued on every event, including a move between the
	 * statuses that both grant access, which an owner's group members skip.
	 */
	public function test_gift_status_change_that_keeps_access_still_queues_recipient() {
		$purchaser_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_newsletter_gate( [ 100 ], [ $this->create_premium_list() ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $recipient_id, [ 100 ] );

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id'         => $purchaser_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'pending-cancel',
			],
			0,
			Premium_Newsletters::SOURCE_SUBSCRIPTION_CHANGED
		);

		$this->assertContains( $recipient_id, $this->get_queued_user_ids() );
	}

	/**
	 * The recipient gets the premium lists a gifted subscription covers. The
	 * purchaser doesn't, because content gates grant a gift only to its recipient.
	 */
	public function test_gift_recipient_gets_lists_and_purchaser_does_not() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$purchaser_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$purchaser_email = get_userdata( $purchaser_id )->user_email;
		$recipient_email = get_userdata( $recipient_id )->user_email;

		$list_post_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $recipient_id, [ 100 ] );

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id'         => $purchaser_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'pending',
				'status_after'    => 'active',
			],
			0,
			Premium_Newsletters::SOURCE_SUBSCRIPTION_CHANGED
		);
		Premium_Newsletters::process_access_check_queue();

		$recipient_calls = $this->get_list_calls_for( $recipient_email );
		$this->assertCount( 1, $recipient_calls, 'The recipient gets one list update.' );
		$this->assertContains( 'newspack-' . $list_post_id, $recipient_calls[0]['lists_to_add'] );

		foreach ( $this->get_list_calls_for( $purchaser_email ) as $call ) {
			$this->assertNotContains( 'newspack-' . $list_post_id, $call['lists_to_add'], 'The purchaser must not gain lists from a subscription they gave away.' );
		}
	}

	/**
	 * A purchaser who also holds a subscription of their own keeps its lists, and
	 * the recipient still gains the gift's.
	 */
	public function test_purchaser_with_own_subscription_keeps_lists() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$purchaser_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$purchaser_email = get_userdata( $purchaser_id )->user_email;
		$recipient_email = get_userdata( $recipient_id )->user_email;

		$list_post_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );
		wcs_create_subscription(
			[
				'customer_id' => $purchaser_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);
		$gift = $this->create_gifted_subscription( $purchaser_id, $recipient_id, [ 100 ] );

		Premium_Newsletters::maybe_enqueue_access_check(
			time(),
			[
				'user_id'         => $purchaser_id,
				'subscription_id' => $gift->get_id(),
				'status_before'   => 'pending',
				'status_after'    => 'active',
			],
			0,
			Premium_Newsletters::SOURCE_SUBSCRIPTION_CHANGED
		);
		Premium_Newsletters::process_access_check_queue();

		foreach ( [ $purchaser_email, $recipient_email ] as $email ) {
			$calls = $this->get_list_calls_for( $email );
			$this->assertCount( 1, $calls, 'Both readers get one list update.' );
			$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
			$this->assertEmpty( $calls[0]['lists_to_remove'] );
		}
	}

	/**
	 * A renewal snapshots the gift recipient's lists as it does the purchaser's, so
	 * a recipient who left a premium list isn't re-added by auto-signup.
	 */
	public function test_gift_renewal_attempt_snapshots_recipient() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$purchaser_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_email = get_userdata( $recipient_id )->user_email;

		$this->create_newsletter_gate( [ 100 ], [ $this->create_premium_list() ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $recipient_id, [ 100 ] );

		\Newspack_Newsletters_Subscription::$contact_lists[ $recipient_email ] = [ 'public-list' ];

		Premium_Newsletters::set_subscribed_lists(
			time(),
			[
				'user_id'         => $purchaser_id,
				'subscription_id' => $subscription->get_id(),
			],
			0
		);

		$this->assertSame( [ 'public-list' ], get_user_meta( $recipient_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ), "A renewal must snapshot the recipient's lists." );
		$this->assertSame( Premium_Newsletters::SOURCE_RENEWAL, $this->get_queued_source( $recipient_id ), 'A renewal must queue the recipient with the renewal source.' );
	}

	/**
	 * When a gift lapses, its recipient loses the premium lists it paid for. The
	 * event names the purchaser, but the recipient holds the access.
	 */
	public function test_lapsed_gift_removes_recipient_lists() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );

		$purchaser_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_email = get_userdata( $recipient_id )->user_email;

		$list_post_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $recipient_id, [ 100 ] );

		\Newspack_Newsletters_Subscription::$contact_lists[ $recipient_email ] = [ 'newspack-' . $list_post_id ];
		$subscription->set_status( 'cancelled' );

		Premium_Newsletters::handle_product_subscription_changed(
			time(),
			[
				'user_id'         => $purchaser_id,
				'subscription_id' => $subscription->get_id(),
				'status_before'   => 'active',
				'status_after'    => 'cancelled',
			],
			null
		);
		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $recipient_email );
		$this->assertCount( 1, $calls, 'The recipient of a lapsed gift must be removed from its premium lists.' );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
		$this->assertEmpty( $calls[0]['lists_to_add'] );
	}

	/**
	 * Reassigning a gift fires no subscription event, so the hook WCS Gifting fires
	 * queues both readers: the new recipient to gain the lists, the old one to lose them.
	 */
	public function test_gift_recipient_change_queues_both_recipients() {
		$purchaser_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$old_recipient_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$new_recipient_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_newsletter_gate( [ 100 ], [ $this->create_premium_list() ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $old_recipient_id, [ 100 ] );

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $new_recipient_id, $old_recipient_id );

		$this->assertSame( Premium_Newsletters::SOURCE_GIFT_RECEIVED, $this->get_queued_source( $new_recipient_id ), 'The new recipient is checked for lists to add.' );
		$this->assertSame( Premium_Newsletters::SOURCE_GIFT_REMOVED, $this->get_queued_source( $old_recipient_id ), 'The old recipient is checked for lists to remove only.' );
		$this->assertNotContains( $purchaser_id, $this->get_queued_user_ids(), 'The purchaser is not affected by a change of recipient.' );
	}

	/**
	 * A gift with no previous recipient (or none now) queues only the reader who
	 * exists, and a gift handed back to the same reader queues them once.
	 */
	public function test_gift_recipient_change_skips_missing_recipients() {
		$purchaser_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$recipient_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_newsletter_gate( [ 100 ], [ $this->create_premium_list() ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $recipient_id, [ 100 ] );

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $recipient_id, 0 );
		$this->assertSame( [ $recipient_id ], $this->get_queued_user_ids(), 'A first recipient is queued alone.' );

		delete_option( Premium_Newsletters::QUEUE_OPTION );
		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, 0, $recipient_id );
		$this->assertSame( [ $recipient_id ], $this->get_queued_user_ids(), 'A removed recipient is queued alone.' );
		$this->assertSame( Premium_Newsletters::SOURCE_GIFT_REMOVED, $this->get_queued_source( $recipient_id ) );

		delete_option( Premium_Newsletters::QUEUE_OPTION );
		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $recipient_id, $recipient_id );
		$this->assertSame( [ $recipient_id ], $this->get_queued_user_ids(), 'An unchanged recipient is queued once.' );
		$this->assertSame( Premium_Newsletters::SOURCE_GIFT_RECEIVED, $this->get_queued_source( $recipient_id ), 'The gain wins when old and new are the same reader.' );
	}

	/**
	 * End to end: after a gift is reassigned, the new recipient gains the gated
	 * list and the old one loses it, with auto-signup on.
	 */
	public function test_reassigned_gift_moves_lists_to_new_recipient() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$purchaser_id        = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$old_recipient_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$new_recipient_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$old_recipient_email = get_userdata( $old_recipient_id )->user_email;
		$new_recipient_email = get_userdata( $new_recipient_id )->user_email;

		$list_post_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $old_recipient_id, [ 100 ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $old_recipient_email ] = [ 'newspack-' . $list_post_id ];

		$this->reassign_gifted_subscription( $subscription, $new_recipient_id );
		Premium_Newsletters::process_access_check_queue();

		$new_calls = $this->get_list_calls_for( $new_recipient_email );
		$this->assertCount( 1, $new_calls, 'The new recipient gets one list update.' );
		$this->assertContains( 'newspack-' . $list_post_id, $new_calls[0]['lists_to_add'] );
		$this->assertEmpty( $new_calls[0]['lists_to_remove'] );

		$old_calls = $this->get_list_calls_for( $old_recipient_email );
		$this->assertCount( 1, $old_calls, 'The old recipient gets one list update.' );
		$this->assertContains( 'newspack-' . $list_post_id, $old_calls[0]['lists_to_remove'] );
		$this->assertEmpty( $old_calls[0]['lists_to_add'], 'Losing a gift must not add lists.' );
	}

	/**
	 * Losing a gift only takes lists away: a reader who still has access some other
	 * way, and who had left the list, isn't re-added by auto-signup.
	 */
	public function test_reassigned_gift_does_not_readd_lists_the_old_recipient_left() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$purchaser_id        = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$old_recipient_id    = $this->create_reader( true );
		$new_recipient_id    = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$old_recipient_email = get_userdata( $old_recipient_id )->user_email;

		$list_post_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_post_id ] );
		$subscription = $this->create_gifted_subscription( $purchaser_id, $old_recipient_id, [ 100 ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $old_recipient_email ] = [];

		$this->reassign_gifted_subscription( $subscription, $new_recipient_id );
		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty( $this->get_list_calls_for( $old_recipient_email ), 'A reader who left the list must not be re-added when a gift moves away.' );
	}

	// =========================================================================
	// Group I — one-time purchases
	// =========================================================================

	/**
	 * Create a premium newsletter list.
	 *
	 * @return int List post ID.
	 */
	private function create_premium_list(): int {
		$list_post_id     = $this->factory->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );
		$this->post_ids[] = $list_post_id;
		return $list_post_id;
	}

	/**
	 * Create a newsletter gate whose access rule is a one-time purchase.
	 *
	 * @param array $rule_value One-time purchase rule value.
	 * @param array $list_ids   List post IDs for the newsletters content rule.
	 *
	 * @return int Gate post ID.
	 */
	private function create_one_time_purchase_gate( array $rule_value, array $list_ids ): int {
		$gate_id          = Content_Gate::create_gate( [ 'title' => 'One-time Purchase Newsletter Gate' ], Content_Gate::GATE_CPT, true );
		$this->gate_ids[] = $gate_id;

		Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'one_time_purchase',
							'value' => $rule_value,
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

	/**
	 * A one-time purchase rule granting 30 days of access.
	 *
	 * @param int[] $product_ids Product IDs the rule names.
	 *
	 * @return array
	 */
	private function thirty_day_rule( array $product_ids = [ self::ONE_TIME_PRODUCT_ID ] ): array {
		return [
			'product_ids'    => $product_ids,
			'duration_value' => 30,
			'duration_unit'  => 'days',
		];
	}

	/**
	 * Create an order for the one-time product.
	 *
	 * @param int    $customer_id  Customer user ID; 0 for a guest order.
	 * @param string $status       Order status.
	 * @param int    $date_created Order creation time.
	 * @param array  $overrides    Order data overrides.
	 *
	 * @return \WC_Order
	 */
	private function create_order( int $customer_id, string $status, int $date_created, array $overrides = [] ) {
		return wc_create_order(
			array_merge(
				[
					'customer_id'   => $customer_id,
					'billing_email' => $customer_id ? get_userdata( $customer_id )->user_email : '',
					'status'        => $status,
					'total'         => 10,
					'date_created'  => gmdate( 'Y-m-d H:i:s', $date_created ),
					'date_paid'     => gmdate( 'Y-m-d H:i:s', $date_created ),
					'items'         => [ new \WC_Order_Item_Product( [ 'product_id' => self::ONE_TIME_PRODUCT_ID ] ) ],
				],
				$overrides
			)
		);
	}

	/**
	 * Move an order to a new status and hand the handler the `woo_order_updated`
	 * events the Data Events listener would build for it.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $status_to New status.
	 */
	private function change_order_status( $order, string $status_to ): void {
		$status_from           = $order->get_status();
		$order->data['status'] = $status_to;
		foreach ( \Newspack\Data_Events\Utils::get_woo_order_updated_payloads( $order, $status_to, $status_from ) as $payload ) {
			Premium_Newsletters::handle_woo_order_updated( time(), $payload, null );
		}
	}

	/**
	 * Record that the lapse sweep for the 30-day rule has checked orders created
	 * up to the given time.
	 *
	 * @param int $created Order creation time, as a Unix timestamp.
	 */
	private function set_sweep_position( int $created ): void {
		update_option( Premium_Newsletters::ONE_TIME_PURCHASE_SWEEP_OPTION, [ '30 days' => $created ], false );
	}

	/**
	 * Set how many orders the lapse sweep reads per page.
	 *
	 * @param int|null $page_size Orders per page; null restores the class default.
	 */
	private function set_sweep_page_size( ?int $page_size ): void {
		$sweep_page_size = new \ReflectionProperty( Premium_Newsletters::class, 'sweep_page_size' );
		$sweep_page_size->setAccessible( true );
		$sweep_page_size->setValue( null, $page_size ?? $sweep_page_size->getDefaultValue() );
	}

	/**
	 * Paying for a product a premium newsletter gate's one-time purchase rule
	 * names adds the buyer to the gated lists.
	 */
	public function test_paid_one_time_purchase_adds_premium_lists() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );

		$order = $this->create_order( $user_id, 'pending', time() );
		$this->change_order_status( $order, 'processing' );

		$this->assertSame( Premium_Newsletters::SOURCE_ONE_TIME_PURCHASE, $this->get_queued_source( $user_id ), 'A paid order for a gated product must queue a check for the buyer.' );

		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
	}

	/**
	 * The rule counts a guest order toward the account whose email matches the
	 * billing email, so that account is the one checked.
	 */
	public function test_guest_one_time_purchase_queues_account_with_billing_email() {
		$user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'guest-buyer@example.test',
			]
		);
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $this->create_premium_list() ] );

		$order = $this->create_order( 0, 'pending', time(), [ 'billing_email' => 'guest-buyer@example.test' ] );
		$this->change_order_status( $order, 'completed' );

		$this->assertContains( $user_id, $this->get_queued_user_ids() );
	}

	/**
	 * Variations of a product whose gate names one of them.
	 *
	 * @return array[]
	 */
	public function data_variation_purchases() {
		return [
			'the gated variation'    => [ true ],
			'an ungated sibling one' => [ false ],
		];
	}

	/**
	 * A gate can name one variation while the order event carries only the parent
	 * product's ID. Buying that variation queues a check; buying a sibling doesn't,
	 * since its check could re-add lists the reader had left.
	 *
	 * @dataProvider data_variation_purchases
	 *
	 * @param bool $buys_gated_variation Whether the order is for the variation the gate names.
	 */
	public function test_variation_purchase_queues_check_only_for_the_gated_variation( $buys_gated_variation ) {
		$user_id              = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$parent_id            = $this->factory->post->create( [ 'post_type' => 'product' ] );
		$gated_variation_id   = $this->factory->post->create(
			[
				'post_type'   => 'product_variation',
				'post_parent' => $parent_id,
			]
		);
		$sibling_variation_id = $this->factory->post->create(
			[
				'post_type'   => 'product_variation',
				'post_parent' => $parent_id,
			]
		);
		array_push( $this->post_ids, $parent_id, $gated_variation_id, $sibling_variation_id );
		$this->create_one_time_purchase_gate( $this->thirty_day_rule( [ $gated_variation_id ] ), [ $this->create_premium_list() ] );

		$variation_item = new \WC_Order_Item_Product(
			[
				'product_id'   => $parent_id,
				'variation_id' => $buys_gated_variation ? $gated_variation_id : $sibling_variation_id,
			]
		);
		$order          = $this->create_order( $user_id, 'pending', time(), [ 'items' => [ $variation_item ] ] );
		$this->change_order_status( $order, 'processing' );

		$this->assertSame( $buys_gated_variation, in_array( $user_id, $this->get_queued_user_ids(), true ) );
	}

	/**
	 * An order for a product no premium newsletter gate names queues nothing.
	 */
	public function test_order_for_ungated_product_does_not_queue_check() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $this->create_premium_list() ] );

		$ungated_item = new \WC_Order_Item_Product( [ 'product_id' => self::ONE_TIME_PRODUCT_ID + 1 ] );
		$order        = $this->create_order( $user_id, 'pending', time(), [ 'items' => [ $ungated_item ] ] );
		$this->change_order_status( $order, 'completed' );

		$this->assertNotContains( $user_id, $this->get_queued_user_ids() );
	}

	/**
	 * Order transitions that neither start nor stop an order counting as paid.
	 *
	 * @return array[]
	 */
	public function data_order_transitions_that_leave_paid_status_unchanged() {
		return [
			'pending to failed'       => [ 'pending', 'failed' ],
			'pending to on-hold'      => [ 'pending', 'on-hold' ],
			'processing to completed' => [ 'processing', 'completed' ],
		];
	}

	/**
	 * Only a move into or out of a paid status changes what the order grants, so
	 * other transitions queue nothing.
	 *
	 * @dataProvider data_order_transitions_that_leave_paid_status_unchanged
	 *
	 * @param string $status_from Status before the transition.
	 * @param string $status_to   Status after the transition.
	 */
	public function test_order_transition_that_leaves_paid_status_unchanged_does_not_queue_check( $status_from, $status_to ) {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $this->create_premium_list() ] );

		$order = $this->create_order( $user_id, $status_from, time() );
		$this->change_order_status( $order, $status_to );

		$this->assertNotContains( $user_id, $this->get_queued_user_ids() );
	}

	/**
	 * A refund ends the access the purchase granted, so the buyer leaves the gated
	 * lists without waiting for the access window to close.
	 */
	public function test_refunded_one_time_purchase_removes_premium_lists() {
		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];

		$order = $this->create_order( $user_id, 'completed', time() );
		$this->change_order_status( $order, 'refunded' );

		$this->assertSame( Premium_Newsletters::SOURCE_ONE_TIME_PURCHASE_ENDED, $this->get_queued_source( $user_id ), 'A refund must queue a remove-only check.' );

		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
	}

	/**
	 * Once the access window closes, the next queue run finds the purchase that
	 * crossed the cutoff and removes the buyer from the gated lists.
	 */
	public function test_lapsed_one_time_purchase_removes_premium_lists() {
		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];

		// Bought 30 days and 30 minutes ago, so access ran out since the last run an hour ago.
		$this->create_order( $user_id, 'completed', strtotime( '-30 days -30 minutes' ) );
		$this->set_sweep_position( strtotime( '-30 days -1 hour' ) );

		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
	}

	/**
	 * The first sweep for a duration starts from that moment: it records its
	 * position and checks no earlier purchase.
	 */
	public function test_first_sweep_records_its_position_without_checking_earlier_lapses() {
		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];
		$this->create_order( $user_id, 'completed', strtotime( '-30 days -30 minutes' ) );
		$run_started = time();

		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty( $this->get_list_calls_for( $email ) );
		$positions = get_option( Premium_Newsletters::ONE_TIME_PURCHASE_SWEEP_OPTION );
		$this->assertGreaterThanOrEqual( strtotime( '-30 days', $run_started ), $positions['30 days'] ?? 0 );
	}

	/**
	 * The rule grants only to orders created after the cutoff, so an order created
	 * one second after the last position is the first one the next sweep owes a
	 * check.
	 */
	public function test_sweep_reaches_an_order_created_one_second_after_its_last_position() {
		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];
		$last_position = strtotime( '-30 days -1 hour' );
		$this->create_order( $user_id, 'completed', $last_position + 1 );
		$this->set_sweep_position( $last_position );

		Premium_Newsletters::enqueue_lapsed_one_time_purchases();

		$this->assertContains( $user_id, $this->get_queued_user_ids() );
	}

	/**
	 * Default order queries return refunds too, and a line-item refund carries
	 * the gated product with no customer. The sweep has to step over it and reach
	 * the lapsed orders after it.
	 */
	public function test_sweep_steps_over_refunds_of_gated_purchases() {
		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];
		new \WC_Order_Refund(
			[
				'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days -40 minutes' ) ),
				'items'        => [ new \WC_Order_Item_Product( [ 'product_id' => self::ONE_TIME_PRODUCT_ID ] ) ],
			]
		);
		$this->create_order( $user_id, 'completed', strtotime( '-30 days -20 minutes' ) );
		$this->set_sweep_position( strtotime( '-30 days -1 hour' ) );

		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
	}

	/**
	 * A sweep that reaches its page cap picks up on the next run from the second
	 * of the last order it read, so an order created in that same second after
	 * the cap is still checked.
	 */
	public function test_capped_sweep_resumes_from_the_second_it_stopped_in() {
		$this->set_sweep_page_size( 1 );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $this->create_premium_list() ] );
		$last_position = strtotime( '-30 days -2 hours' );
		$this->set_sweep_position( $last_position );

		// Other buyers' orders fill every page the first run reads. The last of them
		// shares its creation second with the reader's order, which comes after it.
		$cap_second = $last_position + Premium_Newsletters::SWEEP_MAX_PAGES * MINUTE_IN_SECONDS;
		for ( $i = 1; $i <= Premium_Newsletters::SWEEP_MAX_PAGES; $i++ ) {
			$this->create_order( 0, 'completed', $last_position + $i * MINUTE_IN_SECONDS, [ 'billing_email' => "other-buyer-$i@example.test" ] );
		}
		$this->create_order( $user_id, 'completed', $cap_second );

		Premium_Newsletters::enqueue_lapsed_one_time_purchases();
		$this->assertNotContains( $user_id, $this->get_queued_user_ids(), 'The first run stops at its cap before this order.' );

		Premium_Newsletters::enqueue_lapsed_one_time_purchases();
		$this->assertContains( $user_id, $this->get_queued_user_ids(), 'The next run must pick up within the second the first stopped in.' );
	}

	/**
	 * When a single second holds more orders than one run reads, the sweep moves
	 * past that second rather than re-reading the same orders on every run.
	 */
	public function test_capped_sweep_moves_on_when_one_second_fills_a_run() {
		$this->set_sweep_page_size( 1 );
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $this->create_premium_list() ] );
		$last_position = strtotime( '-30 days -2 hours' );
		$this->set_sweep_position( $last_position );
		for ( $i = 0; $i <= Premium_Newsletters::SWEEP_MAX_PAGES; $i++ ) {
			$this->create_order( 0, 'completed', $last_position + 1, [ 'billing_email' => "bulk-buyer-$i@example.test" ] );
		}

		Premium_Newsletters::enqueue_lapsed_one_time_purchases();

		$positions = get_option( Premium_Newsletters::ONE_TIME_PURCHASE_SWEEP_OPTION );
		$this->assertGreaterThan( $last_position, $positions['30 days'] ?? 0 );
	}

	/**
	 * A reader who bought again before the first purchase ran out keeps the lists
	 * when the first purchase lapses.
	 */
	public function test_repurchase_keeps_premium_lists_when_earlier_purchase_lapses() {
		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];
		$this->create_order( $user_id, 'completed', strtotime( '-30 days -30 minutes' ) );
		$this->create_order( $user_id, 'completed', strtotime( '-5 days' ) );
		$this->set_sweep_position( strtotime( '-30 days -1 hour' ) );

		Premium_Newsletters::enqueue_lapsed_one_time_purchases();
		$this->assertSame( Premium_Newsletters::SOURCE_ONE_TIME_PURCHASE_ENDED, $this->get_queued_source( $user_id ), 'The lapse of the first purchase must still queue a check.' );

		Premium_Newsletters::process_access_check_queue();

		$this->assertEmpty( $this->get_list_calls_for( $email ), 'The newer purchase must keep the reader on the list.' );
	}

	/**
	 * A lapse only takes access away, so its check never re-adds a list the reader
	 * left, even one another gate still entitles them to.
	 */
	public function test_lapse_check_does_not_readd_list_reader_left() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id               = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email                 = get_userdata( $user_id )->user_email;
		$one_time_list_id      = $this->create_premium_list();
		$subscription_list_id  = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $one_time_list_id ] );
		$this->create_newsletter_gate( [ 100 ], [ $subscription_list_id ] );
		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 100 ],
			]
		);

		// Subscribed to the one-time list; left the subscription list on their own.
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $one_time_list_id ];
		$this->create_order( $user_id, 'completed', strtotime( '-30 days -30 minutes' ) );
		$this->set_sweep_position( strtotime( '-30 days -1 hour' ) );

		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $one_time_list_id, $calls[0]['lists_to_remove'] );
		$this->assertEmpty( $calls[0]['lists_to_add'], 'A lapse must not re-add a list the reader left.' );
	}

	/**
	 * A reader who pays again in the same hour an older purchase lapses keeps the
	 * check the payment queued, so the lapse can't stop them gaining the lists.
	 */
	public function test_lapse_does_not_replace_a_pending_purchase_check() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		$this->create_order( $user_id, 'completed', strtotime( '-30 days -30 minutes' ) );
		$this->set_sweep_position( strtotime( '-30 days -1 hour' ) );

		$order = $this->create_order( $user_id, 'pending', time() );
		$this->change_order_status( $order, 'processing' );
		Premium_Newsletters::enqueue_lapsed_one_time_purchases();

		$this->assertSame( Premium_Newsletters::SOURCE_ONE_TIME_PURCHASE, $this->get_queued_source( $user_id ) );

		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_add'] );
	}

	/**
	 * Lapses come from order dates, not stored events, so the sweep keeps its
	 * position while access control stands down and catches up when it returns.
	 */
	public function test_sweep_catches_up_on_lapses_from_while_access_control_was_off() {
		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];
		$this->create_order( $user_id, 'completed', strtotime( '-30 days -2 days' ) );
		$this->set_sweep_position( strtotime( '-30 days -3 days' ) );
		Premium_Newsletters::register_access_check_event();

		// Access control stands down, and a sweep attempted meanwhile does nothing.
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		Premium_Newsletters::register_access_check_event();
		Premium_Newsletters::enqueue_lapsed_one_time_purchases();
		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );

		Premium_Newsletters::process_access_check_queue();

		$calls = $this->get_list_calls_for( $email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
	}

	/**
	 * Re-arming the hourly event runs on `init`, so in a page load. The lapse
	 * sweep waits for the scheduled run instead, since after an off window its
	 * catch-up can cover days of orders.
	 */
	public function test_rearming_the_event_leaves_the_sweep_to_cron() {
		global $wc_mocks_get_orders_calls;
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $this->create_premium_list() ] );
		$this->set_sweep_position( strtotime( '-30 days -3 days' ) );
		wp_clear_scheduled_hook( Premium_Newsletters::SCHEDULED_HOOK );
		$wc_mocks_get_orders_calls = 0;

		Premium_Newsletters::register_access_check_event();

		$this->assertNotFalse( wp_next_scheduled( Premium_Newsletters::SCHEDULED_HOOK ) );
		$this->assertSame( 0, (int) $wc_mocks_get_orders_calls, 'Re-arming must not walk orders.' );
	}

	/**
	 * Checks already queued run before the sweep starts, so a sweep that dies,
	 * even on a fatal error no catch can stop, can't hold them up. A sweep that
	 * fails keeps its position so the next run retries the same orders.
	 */
	public function test_queued_checks_run_before_a_failing_sweep() {
		$user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$email        = get_userdata( $user_id )->user_email;
		$list_post_id = $this->create_premium_list();
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $list_post_id ] );
		\Newspack_Newsletters_Subscription::$contact_lists[ $email ] = [ 'newspack-' . $list_post_id ];
		$last_position = strtotime( '-30 days -1 hour' );
		$this->set_sweep_position( $last_position );

		// An order in the sweep's window whose line items can't be read. It records
		// how many ESP calls had happened by the time the sweep reached it.
		$lapsed_at        = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days -30 minutes' ) );
		$unreadable_order = new class(
			[
				'status'       => 'completed',
				'date_created' => $lapsed_at,
				'date_paid'    => $lapsed_at,
			]
		) extends \WC_Order {
			/**
			 * ESP calls made before the sweep read this order.
			 *
			 * @var int|null
			 */
			public static $esp_calls_before_sweep = null;

			/**
			 * Fail the way an unreadable order would.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function get_items() {
				self::$esp_calls_before_sweep = count( \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls );
				throw new \RuntimeException( 'Unreadable order.' );
			}
		};
		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], null );

		Premium_Newsletters::process_access_check_queue();

		$this->assertSame( 1, $unreadable_order::$esp_calls_before_sweep, 'The queued check must run before the sweep starts.' );
		$calls = $this->get_list_calls_for( $email );
		$this->assertCount( 1, $calls );
		$this->assertContains( 'newspack-' . $list_post_id, $calls[0]['lists_to_remove'] );
		$positions = get_option( Premium_Newsletters::ONE_TIME_PURCHASE_SWEEP_OPTION );
		$this->assertSame( $last_position, $positions['30 days'] ?? null, 'A failed sweep must keep its position.' );
	}

	/**
	 * While Woo Memberships is active it still owns list membership, so a purchase
	 * queues nothing and the lapse sweep doesn't look for orders.
	 *
	 * Runs isolated because WC_Memberships, once declared, would make
	 * Memberships::is_active() true for every later test in this process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_one_time_purchases_queue_nothing_while_memberships_is_active() {
		require dirname( __DIR__, 2 ) . '/mocks/wc-memberships-active-mock.php';
		global $wc_mocks_get_orders_calls;

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->create_one_time_purchase_gate( $this->thirty_day_rule(), [ $this->create_premium_list() ] );

		$order = $this->create_order( $user_id, 'pending', time() );
		$this->change_order_status( $order, 'completed' );
		$this->assertEmpty( get_option( Premium_Newsletters::QUEUE_OPTION, [] ), 'A purchase must not queue a check while Memberships is active.' );

		$this->create_order( $user_id, 'completed', strtotime( '-30 days -30 minutes' ) );
		$this->set_sweep_position( strtotime( '-30 days -1 hour' ) );
		$wc_mocks_get_orders_calls = 0;

		Premium_Newsletters::enqueue_lapsed_one_time_purchases();

		$this->assertSame( 0, (int) $wc_mocks_get_orders_calls, 'The sweep must not query orders while Memberships is active.' );
		$this->assertEmpty( get_option( Premium_Newsletters::QUEUE_OPTION, [] ) );
	}

	// =========================================================================
	// filter_list_gates() — gate names for the subscribe block
	// =========================================================================

	/**
	 * A gated list maps to its gate; an ungated list is absent.
	 */
	public function test_filter_list_gates_maps_gated_lists_to_their_gates() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$list_id = $this->create_premium_list();
		$this->create_premium_list();
		$gate_id = $this->create_newsletter_gate( [ 100 ], [ $list_id ] );

		$result = Premium_Newsletters::filter_list_gates( [] );

		$this->assertSame( [ 'newspack-' . $list_id ], array_keys( $result ) );
		$this->assertSame(
			[
				[
					'id'       => $gate_id,
					'title'    => 'Newsletter Gate',
					'edit_url' => admin_url( 'admin.php?page=newspack-premium-newsletters#/edit/' . $gate_id ),
				],
			],
			$result[ 'newspack-' . $list_id ]
		);
	}

	/**
	 * A user who can't open the wizard gets the gate's name without a link.
	 */
	public function test_filter_list_gates_omits_edit_url_without_capability() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );
		$list_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_id ] );

		$result = Premium_Newsletters::filter_list_gates( [] );

		$this->assertArrayNotHasKey( 'edit_url', $result[ 'newspack-' . $list_id ][0] );
		$this->assertSame( 'Newsletter Gate', $result[ 'newspack-' . $list_id ][0]['title'] );
	}

	/**
	 * A draft gate restricts nothing, so it marks nothing.
	 */
	public function test_filter_list_gates_excludes_draft_gates() {
		$list_id = $this->create_premium_list();
		$gate_id = $this->create_newsletter_gate( [ 100 ], [ $list_id ] );
		wp_update_post(
			[
				'ID'          => $gate_id,
				'post_status' => 'draft',
			]
		);

		$this->assertSame( [], Premium_Newsletters::filter_list_gates( [] ) );
	}

	/**
	 * A published gate with neither registration nor paid access switched on
	 * restricts nothing either.
	 */
	public function test_filter_list_gates_excludes_gates_with_no_active_access() {
		$list_id = $this->create_premium_list();
		$gate_id = $this->create_newsletter_gate( [ 100 ], [ $list_id ] );
		Content_Gate::update_custom_access_settings( $gate_id, [ 'active' => false ] );

		$this->assertSame( [], Premium_Newsletters::filter_list_gates( [] ) );
	}

	/**
	 * A list marked exempt is open to everyone, so the editor must not mark it as
	 * gated. The marker has to mean what the sign-up check means.
	 */
	public function test_filter_list_gates_skips_an_exempt_list() {
		$exempt_id = $this->create_gated_list();
		$gated_id  = $this->create_gated_list();
		update_post_meta( $exempt_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true );

		$result = Premium_Newsletters::filter_list_gates( [] );

		$this->assertSame( [ 'newspack-' . $gated_id ], array_keys( $result ) );
	}

	/**
	 * With Audience Management off, no list is reported as gated.
	 */
	public function test_filter_list_gates_is_empty_when_gating_is_inactive() {
		$list_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_id ] );

		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		$result = Premium_Newsletters::filter_list_gates( [] );
		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );

		$this->assertSame( [], $result );
	}

	/**
	 * While Woo Memberships is active it still decides who can join a list, so no
	 * list is reported as gated by Access Control.
	 *
	 * Runs isolated because WC_Memberships, once declared, would make
	 * Memberships::is_active() true for every later test in this process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_list_gates_is_empty_while_memberships_is_active() {
		require dirname( __DIR__, 2 ) . '/mocks/wc-memberships-active-mock.php';

		$list_id = $this->create_premium_list();
		$this->create_newsletter_gate( [ 100 ], [ $list_id ] );

		$this->assertSame( [], Premium_Newsletters::filter_list_gates( [] ) );
	}

	/**
	 * With no premium newsletter gate, the input comes back unchanged and the
	 * lists are never read. Forms call this on every front-end render, so a site
	 * with no gate must not pay for a per-list check.
	 */
	public function test_filter_list_gates_passes_the_input_through_without_gates() {
		global $newsletter_lists_query_count;
		$this->create_premium_list();
		$input                        = [
			'x' => [
				[
					'id'    => 1,
					'title' => 't',
				],
			],
		];
		$newsletter_lists_query_count = 0;

		$this->assertSame( $input, Premium_Newsletters::filter_list_gates( $input ) );
		$this->assertSame( 0, $newsletter_lists_query_count, 'No gate means no list is read.' );
	}

	/**
	 * The callback is hooked.
	 */
	public function test_filter_list_gates_is_hooked() {
		$this->assertSame( 10, has_filter( 'newspack_newsletters_subscription_list_gates', [ Premium_Newsletters::class, 'filter_list_gates' ] ) );
	}

	// =========================================================================
	// filter_subscribe_lists() — sign-up enforcement
	// =========================================================================

	/**
	 * A logged-in reader with access can sign their own address up for a gated list.
	 */
	public function test_filter_subscribe_lists_keeps_gated_list_for_own_address_with_access() {
		$list_id = $this->create_gated_list();
		$user_id = $this->create_reader( true );
		wp_set_current_user( $user_id );

		$this->assertSame( [ 'newspack-' . $list_id ], $this->filter_sign_up( $list_id, get_userdata( $user_id )->user_email ) );
	}

	/**
	 * A logged-in reader without access can't, even for their own address.
	 */
	public function test_filter_subscribe_lists_removes_gated_list_for_own_address_without_access() {
		$list_id = $this->create_gated_list();
		$user_id = $this->create_reader();
		wp_set_current_user( $user_id );

		$this->assertSame( [], $this->filter_sign_up( $list_id, get_userdata( $user_id )->user_email ) );
	}

	/**
	 * A signed-out request is refused whatever address it submits. The answer
	 * must not depend on whether that address belongs to someone with access, or
	 * a sign-up attempt would reveal who is a paying subscriber.
	 */
	public function test_filter_subscribe_lists_refuses_a_signed_out_request_whatever_the_address() {
		$list_id    = $this->create_gated_list();
		$entitled   = get_userdata( $this->create_reader( true ) )->user_email;
		$unentitled = get_userdata( $this->create_reader() )->user_email;
		wp_set_current_user( 0 );

		$this->assertSame( [], $this->filter_sign_up( $list_id, $entitled ) );
		$this->assertSame( [], $this->filter_sign_up( $list_id, $unentitled ) );
		$this->assertSame( [], $this->filter_sign_up( $list_id, 'nobody@example.com' ) );
	}

	/**
	 * A logged-in reader with access can't sign someone else's address up, and
	 * again the answer is the same whether or not that other person has access.
	 */
	public function test_filter_subscribe_lists_refuses_another_readers_address() {
		$list_id    = $this->create_gated_list();
		$entitled   = get_userdata( $this->create_reader( true ) )->user_email;
		$unentitled = get_userdata( $this->create_reader() )->user_email;
		wp_set_current_user( $this->create_reader( true ) );

		$this->assertSame( [], $this->filter_sign_up( $list_id, $entitled ) );
		$this->assertSame( [], $this->filter_sign_up( $list_id, $unentitled ) );
	}

	/**
	 * An administrator can edit every list, so judging by the session alone would
	 * let them subscribe anyone. They are refused for other people's addresses too.
	 */
	public function test_filter_subscribe_lists_refuses_an_administrator_signing_up_someone_else() {
		$list_id  = $this->create_gated_list();
		$entitled = get_userdata( $this->create_reader( true ) )->user_email;
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( [], $this->filter_sign_up( $list_id, $entitled ) );
	}

	/**
	 * A form submits the ESP's own ID for a remote list, not the local `newspack-`
	 * ID. The refusal has to hold for that ID too, or anyone could join a gated
	 * list by naming it that way.
	 */
	public function test_filter_subscribe_lists_refuses_a_gated_remote_list_by_its_esp_id() {
		$list_id = $this->create_gated_list();
		update_post_meta( $list_id, '_remote_id', 'esp-list-1' );
		wp_set_current_user( 0 );

		$this->assertSame( [], Premium_Newsletters::filter_subscribe_lists( [ 'esp-list-1' ], [ 'email' => 'nobody@example.com' ] ) );
	}

	/**
	 * A list no gate restricts stays open to anyone, for any address.
	 */
	public function test_filter_subscribe_lists_keeps_ungated_lists_for_anyone() {
		$gated_id = $this->create_gated_list();
		$free_id  = $this->create_premium_list();
		wp_set_current_user( 0 );

		$this->assertSame( [ 'newspack-' . $free_id ], $this->filter_sign_up( $free_id, 'nobody@example.com' ) );
		$this->assertSame(
			[ 'newspack-' . $free_id ],
			Premium_Newsletters::filter_subscribe_lists(
				[ 'newspack-' . $gated_id, 'newspack-' . $free_id ],
				[ 'email' => 'nobody@example.com' ]
			),
			'A refused list is dropped and the rest are reindexed.'
		);
	}

	/**
	 * Only subscription lists are judged. A public list ID can name any post, and
	 * judging an ordinary gated article here would let a sign-up attempt reveal
	 * who can read it. The site keeps a gated list so the no-gates shortcut does
	 * not answer before the post type is read.
	 */
	public function test_filter_subscribe_lists_does_not_judge_other_posts_on_a_site_with_newsletter_gates() {
		$this->create_gated_list();
		$post_id          = $this->factory->post->create();
		$this->post_ids[] = $post_id;
		$gate_id          = Content_Gate::create_gate( [ 'title' => 'Paywall' ] );
		$this->gate_ids[] = $gate_id;
		Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'subscription',
							'value' => [ 100 ],
						],
					],
				],
			]
		);
		Content_Rules::update_gate_content_rules(
			$gate_id,
			[
				[
					'slug'  => 'specific_posts',
					'value' => [ $post_id ],
				],
			]
		);
		wp_set_current_user( 0 );

		$this->assertTrue( Content_Restriction_Control::post_has_restrictions( false, $post_id ), 'The article must be gated for this test to mean anything.' );
		$this->assertSame( [ 'newspack-' . $post_id ], $this->filter_sign_up( $post_id, 'nobody@example.com' ) );
	}

	/**
	 * An ID that resolves to nothing is left for upsert() to report.
	 */
	public function test_filter_subscribe_lists_leaves_unknown_ids_alone() {
		$this->create_gated_list();
		wp_set_current_user( 0 );

		$this->assertSame(
			[ 'not-a-list' ],
			Premium_Newsletters::filter_subscribe_lists( [ 'not-a-list' ], [ 'email' => 'nobody@example.com' ] )
		);
	}

	/**
	 * With Audience Management off, Access Control restricts nothing.
	 */
	public function test_filter_subscribe_lists_stands_down_when_gating_is_inactive() {
		$list_id = $this->create_gated_list();
		wp_set_current_user( 0 );

		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		$result = $this->filter_sign_up( $list_id, 'nobody@example.com' );
		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );

		$this->assertSame( [ 'newspack-' . $list_id ], $result );
	}

	/**
	 * The submitted address is compared with the session reader's own whatever its
	 * case. A reader who types their address in capitals is still signing up their
	 * own, and the comparison must not lean on the database's collation to say so.
	 */
	public function test_filter_subscribe_lists_matches_own_address_whatever_its_case() {
		$list_id = $this->create_gated_list();
		$user_id = $this->create_reader( true );
		wp_set_current_user( $user_id );

		$this->assertSame( [ 'newspack-' . $list_id ], $this->filter_sign_up( $list_id, strtoupper( get_userdata( $user_id )->user_email ) ) );
	}

	/**
	 * An address that isn't a string matches no one, and doesn't throw. Contact data
	 * can come from a request, where a caller can send an array.
	 */
	public function test_filter_subscribe_lists_ignores_a_non_string_address() {
		$list_id = $this->create_gated_list();
		wp_set_current_user( $this->create_reader( true ) );

		$result = Premium_Newsletters::filter_subscribe_lists( [ 'newspack-' . $list_id ], [ 'email' => [ 'x@example.com' ] ] );

		$this->assertSame( [], $result );
	}

	/**
	 * A list marked exempt is open to anyone, whatever address they submit.
	 */
	public function test_filter_subscribe_lists_keeps_an_exempt_list_for_anyone() {
		$list_id = $this->create_gated_list();
		update_post_meta( $list_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true );
		wp_set_current_user( 0 );

		$this->assertFalse( Content_Restriction_Control::post_has_restrictions( false, $list_id ), 'The exemption must open the list for this test to mean anything.' );
		$this->assertSame( [ 'newspack-' . $list_id ], $this->filter_sign_up( $list_id, 'nobody@example.com' ) );
	}

	/**
	 * While Woo Memberships is active it still decides who can join a list, so the
	 * sign-up check leaves every list alone.
	 *
	 * Runs isolated because WC_Memberships, once declared, would make
	 * Memberships::is_active() true for every later test in this process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_subscribe_lists_stands_down_while_memberships_is_active() {
		require dirname( __DIR__, 2 ) . '/mocks/wc-memberships-active-mock.php';

		$list_id = $this->create_gated_list();
		wp_set_current_user( 0 );

		$this->assertSame( [ 'newspack-' . $list_id ], $this->filter_sign_up( $list_id, 'nobody@example.com' ) );
	}

	/**
	 * With no premium newsletter gates, no list can be gated, so the sign-up passes
	 * through whatever the lists are. An ID that names no list at all is simply
	 * returned too.
	 */
	public function test_filter_subscribe_lists_does_nothing_without_gates() {
		$list_id = $this->create_premium_list();
		wp_set_current_user( 0 );

		$this->assertSame( [ 'newspack-' . $list_id ], $this->filter_sign_up( $list_id, 'nobody@example.com' ) );
		$this->assertSame(
			[ 'newspack-999999' ],
			Premium_Newsletters::filter_subscribe_lists( [ 'newspack-999999' ], [ 'email' => 'nobody@example.com' ] )
		);
	}

	/**
	 * The callback is hooked where newsletters applies the filter.
	 */
	public function test_filter_subscribe_lists_is_hooked() {
		$this->assertSame( 10, has_filter( 'newspack_newsletters_subscribe_lists', [ Premium_Newsletters::class, 'filter_subscribe_lists' ] ) );

		$list_id = $this->create_gated_list();
		wp_set_current_user( 0 );
		$result = apply_filters( 'newspack_newsletters_subscribe_lists', [ 'newspack-' . $list_id ], [ 'email' => 'nobody@example.com' ], 'Test' );

		$this->assertSame( [], $result );
	}

	/**
	 * A gate published after the first sign-up check is seen by the next one. The
	 * no-gates shortcut must not trust a memo that nothing clears: in a process
	 * that lives across requests, a publisher who adds a premium gate would
	 * otherwise find their list still open to everyone.
	 *
	 * Within one test the Premium_Newsletters gate memo persists (set_up() resets
	 * it only between tests), which is what lets this catch the stale read.
	 */
	public function test_filter_subscribe_lists_sees_a_gate_published_after_the_first_check() {
		$list_id = $this->create_premium_list();
		wp_set_current_user( 0 );

		$this->assertSame( [ 'newspack-' . $list_id ], $this->filter_sign_up( $list_id, 'nobody@example.com' ), 'With no gate, the list is open.' );

		$this->create_newsletter_gate( [ 100 ], [ $list_id ] );

		$this->assertSame( [], $this->filter_sign_up( $list_id, 'nobody@example.com' ), 'Once a gate names the list, the next check must refuse it.' );
	}

	// =========================================================================
	// filter_sign_up_error_message() — telling a refused reader what to do
	// =========================================================================

	/**
	 * The message must not depend on who the submitted address belongs to. If it
	 * did, a signed-out request could tell a paying subscriber's address from
	 * anyone else's, which is what the sign-up rule was built to prevent.
	 */
	public function test_sign_up_error_message_is_the_same_whoever_the_address_belongs_to() {
		$list_id    = $this->create_gated_list();
		$entitled   = get_userdata( $this->create_reader( true ) )->user_email;
		$unentitled = get_userdata( $this->create_reader() )->user_email;
		wp_set_current_user( 0 );

		$messages = [];
		foreach ( [ $entitled, $unentitled, 'nobody@example.com' ] as $email ) {
			$this->filter_sign_up( $list_id, $email );
			$messages[] = $this->get_refusal_message( $list_id, $email );
		}

		$sign_in = 'Sign in with this email address to subscribe to premium newsletters.';
		$this->assertSame( [ $sign_in, $sign_in, $sign_in ], $messages );
	}

	/**
	 * A signed-in reader signing up their own address, without access, is told
	 * their account lacks it. This is about their own account, so it reveals
	 * nothing about anyone else.
	 */
	public function test_sign_up_error_message_tells_a_reader_without_access_so() {
		$list_id = $this->create_gated_list();
		$user_id = $this->create_reader();
		$email   = get_userdata( $user_id )->user_email;
		wp_set_current_user( $user_id );

		$this->filter_sign_up( $list_id, $email );

		$this->assertSame( 'This account doesn\'t include access to premium newsletters.', $this->get_refusal_message( $list_id, $email ) );
	}

	/**
	 * A signed-in reader with access who submits someone else's address is told to
	 * sign in with that address, the same as a signed-out request. Their own
	 * access is not what was refused, and the answer is the same whether or not
	 * the other person has an account or access.
	 */
	public function test_sign_up_error_message_tells_a_reader_to_sign_in_for_another_address() {
		$list_id    = $this->create_gated_list();
		$entitled   = get_userdata( $this->create_reader( true ) )->user_email;
		$unentitled = get_userdata( $this->create_reader() )->user_email;
		wp_set_current_user( $this->create_reader( true ) );

		$messages = [];
		foreach ( [ $entitled, $unentitled, 'nobody@example.com' ] as $email ) {
			$this->filter_sign_up( $list_id, $email );
			$messages[] = $this->get_refusal_message( $list_id, $email );
		}

		$sign_in = 'Sign in with this email address to subscribe to premium newsletters.';
		$this->assertSame( [ $sign_in, $sign_in, $sign_in ], $messages );
	}

	/**
	 * An error this class did not cause keeps its message, and so does a value that
	 * is not an error at all.
	 */
	public function test_sign_up_error_message_leaves_other_errors_alone() {
		$list_id = $this->create_gated_list();
		wp_set_current_user( 0 );
		$this->filter_sign_up( $list_id, 'nobody@example.com' );

		$this->assertSame( 'Generic.', Premium_Newsletters::filter_sign_up_error_message( 'Generic.', [], new \WP_Error( 'some_other_error', 'x' ) ) );
		$this->assertSame( 'Generic.', Premium_Newsletters::filter_sign_up_error_message( 'Generic.', [], 'newspack_newsletters_lists_not_allowed' ) );
		$this->assertSame( 'Generic.', Premium_Newsletters::filter_sign_up_error_message( 'Generic.', [], null ) );
		$this->assertSame( 'Generic.', Premium_Newsletters::filter_sign_up_error_message( 'Generic.' ) );
	}

	/**
	 * The same error code is also returned when a sign-up names no usable list, or
	 * when something else refused it. Access Control claims only the refusals it
	 * made, and a later sign-up that refused nothing does not inherit an earlier
	 * one's reason.
	 */
	public function test_sign_up_error_message_only_claims_its_own_refusals() {
		$gated_id = $this->create_gated_list();
		$free_id  = $this->create_premium_list();
		wp_set_current_user( 0 );

		$this->filter_sign_up( $free_id, 'nobody@example.com' );
		$this->assertSame( 'Generic.', $this->get_refusal_message( $free_id, 'nobody@example.com' ), 'Nothing was refused, so the generic message stands.' );

		$this->filter_sign_up( $gated_id, 'nobody@example.com' );
		$this->assertNotSame( 'Generic.', $this->get_refusal_message( $gated_id, 'nobody@example.com' ), 'The refusal must be claimed for this test to mean anything.' );

		$this->filter_sign_up( $free_id, 'nobody@example.com' );
		$this->assertSame( 'Generic.', $this->get_refusal_message( $free_id, 'nobody@example.com' ), 'The earlier sign-up\'s reason must not be read for this one.' );
	}

	/**
	 * A reason speaks only for the address it was recorded for. A sign-up that
	 * never reaches the filter can still return the same error code, and it must
	 * not pick up a reason left by an earlier sign-up for someone else.
	 */
	public function test_sign_up_error_message_ignores_a_refusal_recorded_for_another_address() {
		$list_id = $this->create_gated_list();
		wp_set_current_user( 0 );

		$this->filter_sign_up( $list_id, 'First@example.com' );

		$this->assertNotSame( 'Generic.', $this->get_refusal_message( $list_id, 'First@example.com' ), 'The refusal must be claimed for this test to mean anything.' );
		$this->assertNotSame( 'Generic.', $this->get_refusal_message( $list_id, 'FIRST@EXAMPLE.COM' ), 'The same address in another case is the same address.' );
		$this->assertSame( 'Generic.', $this->get_refusal_message( $list_id, 'second@example.com' ) );
	}

	/**
	 * A sign-up that returns before judging any list also resets the reason, so a
	 * refusal is never read for a request that did not make one.
	 */
	public function test_sign_up_error_message_is_reset_by_a_sign_up_that_returns_early() {
		$list_id = $this->create_gated_list();
		wp_set_current_user( 0 );

		$this->filter_sign_up( $list_id, 'nobody@example.com' );
		$this->assertNotSame( 'Generic.', $this->get_refusal_message( $list_id, 'nobody@example.com' ), 'The refusal must be claimed for this test to mean anything.' );
		Premium_Newsletters::filter_subscribe_lists( [], [ 'email' => 'nobody@example.com' ] );
		$this->assertSame( 'Generic.', $this->get_refusal_message( $list_id, 'nobody@example.com' ), 'An empty sign-up refused nothing.' );

		$this->filter_sign_up( $list_id, 'nobody@example.com' );
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		$this->filter_sign_up( $list_id, 'nobody@example.com' );
		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );
		$this->assertSame( 'Generic.', $this->get_refusal_message( $list_id, 'nobody@example.com' ), 'A sign-up while gating is off refused nothing.' );
	}

	/**
	 * The callback runs after the provider's own, which hooks at 10, and is handed
	 * all three arguments the filter passes.
	 */
	public function test_sign_up_error_message_is_hooked() {
		$this->assertSame( 20, has_filter( 'newspack_newsletters_add_contact_reader_error_message', [ Premium_Newsletters::class, 'filter_sign_up_error_message' ] ) );

		$list_id = $this->create_gated_list();
		wp_set_current_user( 0 );
		$this->filter_sign_up( $list_id, 'nobody@example.com' );
		$result = apply_filters(
			'newspack_newsletters_add_contact_reader_error_message',
			'Generic.',
			[
				'email' => 'nobody@example.com',
				'lists' => [ 'newspack-' . $list_id ],
			],
			new \WP_Error( 'newspack_newsletters_lists_not_allowed', 'x' )
		);

		$this->assertSame( 'Sign in with this email address to subscribe to premium newsletters.', $result );
	}
}
