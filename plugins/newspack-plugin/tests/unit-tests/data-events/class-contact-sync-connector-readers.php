<?php
/**
 * Tests for the readers Contact_Sync_Connector syncs besides a subscription's customer.
 *
 * @package Newspack\Tests\Data_Events
 */

namespace Newspack\Tests\Data_Events;

use Newspack\Data_Events;
use Newspack\Data_Events\Connectors\Contact_Sync_Connector;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Sample_Integration;

/**
 * A gift's recipient and a group's members hold a subscription without being its
 * customer, so the ESP must hear about them when the subscription or their place
 * in it changes.
 */
class Newspack_Test_Contact_Sync_Connector_Readers extends \WP_UnitTestCase {

	/**
	 * ID of the syncable integration each test registers.
	 */
	const INTEGRATION_ID = 'contact-sync-readers-test';

	/**
	 * Action Scheduler hook that syncs a batch of readers.
	 */
	const SYNC_READERS_HOOK = 'newspack_contact_sync_readers';

	/**
	 * Snapshot of Data_Events::$actions, restored in tear_down so resetting the
	 * handlers here doesn't remove other classes' handlers.
	 *
	 * @var array<string,callable[]>|null
	 */
	private $actions_snapshot = null;

	/**
	 * Load the WooCommerce, gifting and integration doubles.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-gifting-mock.php';
		require_once dirname( __DIR__ ) . '/integrations/class-sample-integration.php';
		// The Content Access fields exist only with the feature on. The define
		// leaks process-wide, as it does in the other suites that need them.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Register one syncable integration that sends Content Access, and only the
	 * connector's handlers.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		\WCS_Gifting::$recipients = [];
		Group_Subscription::reset_cache();

		$this->actions_snapshot = $this->get_data_events_actions();
		$this->set_integrations( [] );
		Integrations::register( new Sample_Integration( self::INTEGRATION_ID, 'Contact Sync Readers Test' ) );
		update_option( Integrations::OPTION_NAME, [ self::INTEGRATION_ID ] );
		$this->set_outgoing_fields( [ 'Content Access', 'Content Access Source', 'Content Access Group' ] );

		$this->set_data_events_actions( array_map( '__return_empty_array', $this->actions_snapshot ) );
		Contact_Sync_Connector::register_handlers();

		$this->clear_scheduled_syncs();
	}

	/**
	 * Restore the shared registries and drop anything a test left queued.
	 */
	public function tear_down() {
		// Flush whatever a test queued for the end of the request, then discard it.
		$this->run_connector_shutdown();
		$this->clear_scheduled_syncs();
		$this->set_queued_syncs( [] );

		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		delete_option( Integrations::OPTION_NAME );
		delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . self::INTEGRATION_ID );
		$this->set_integrations( [] );
		Integrations::register_integrations();
		if ( null !== $this->actions_snapshot ) {
			$this->set_data_events_actions( $this->actions_snapshot );
			$this->actions_snapshot = null;
		}
		\WCS_Gifting::$recipients = [];
		parent::tear_down();
	}

	/**
	 * Cancelling a group subscription queues an ESP sync for every member, not
	 * only for the owner who pays for it.
	 */
	public function test_cancelled_group_queues_every_member() {
		$owner_id     = $this->create_reader( 'owner@example.test' );
		$member_ids   = [ $this->create_reader( 'member-1@example.test' ), $this->create_reader( 'member-2@example.test' ) ];
		$subscription = $this->create_subscription( $owner_id, true );
		foreach ( $member_ids as $member_id ) {
			$this->add_group_member( $member_id, $subscription );
		}

		$this->handle_event( 'product_subscription_changed', $this->get_status_change_data( $subscription, 'active', 'cancelled' ) );
		$this->run_connector_shutdown();

		$this->assertEqualsCanonicalizing( $member_ids, $this->get_queued_reader_ids() );
	}

	/**
	 * A large group is synced in batches of 25 readers, one background action each.
	 */
	public function test_large_group_is_queued_in_batches() {
		$owner_id     = $this->create_reader( 'owner@example.test' );
		$subscription = $this->create_subscription( $owner_id, true );
		$member_ids   = range( 900001, 900060 );
		add_filter(
			'newspack_group_subscription_members',
			function () use ( $member_ids ) {
				return $member_ids;
			}
		);

		$this->handle_event( 'product_subscription_changed', $this->get_status_change_data( $subscription, 'active', 'on-hold' ) );
		$this->run_connector_shutdown();

		$batches = array_column( $this->get_sync_readers_actions(), 0 );
		$this->assertCount( 3, $batches );
		$this->assertSame( [ 10, 25, 25 ], $this->get_sorted_counts( $batches ) );
		$this->assertEqualsCanonicalizing( $member_ids, array_merge( ...array_values( $batches ) ) );
	}

	/**
	 * A move between two statuses that both grant access, like Active to Pending
	 * cancel, leaves members' access alone, so none of them are queued.
	 */
	public function test_status_change_that_keeps_access_queues_no_members() {
		$owner_id     = $this->create_reader( 'owner@example.test' );
		$subscription = $this->create_subscription( $owner_id, true );
		$this->add_group_member( $this->create_reader( 'member@example.test' ), $subscription );

		$this->handle_event( 'product_subscription_changed', $this->get_status_change_data( $subscription, 'active', 'pending-cancel' ) );
		$this->run_connector_shutdown();

		$this->assertSame( [], $this->get_sync_readers_actions() );
	}

	/**
	 * Members are queued only because their Content Access may change, so nothing
	 * is queued for them while no integration sends that field.
	 */
	public function test_members_are_not_queued_when_no_integration_sends_content_access() {
		$this->set_outgoing_fields( [ 'Registration Date' ] );
		$owner_id     = $this->create_reader( 'owner@example.test' );
		$subscription = $this->create_subscription( $owner_id, true );
		$this->add_group_member( $this->create_reader( 'member@example.test' ), $subscription );

		$this->handle_event( 'product_subscription_changed', $this->get_status_change_data( $subscription, 'active', 'cancelled' ) );
		Group_Subscription::update_members( $subscription, [ $this->create_reader( 'joiner@example.test' ) ] );
		$this->run_connector_shutdown();

		$this->assertSame( [], $this->get_sync_readers_actions() );
	}

	/**
	 * A gift's status change syncs its recipient, who holds it, as well as the buyer.
	 */
	public function test_gift_status_change_syncs_the_recipient() {
		$purchaser_id = $this->create_reader( 'buyer@example.test' );
		$recipient_id = $this->create_reader( 'recipient@example.test' );
		$subscription = $this->create_subscription( $purchaser_id );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;

		$this->handle_event( 'product_subscription_changed', $this->get_status_change_data( $subscription, 'active', 'cancelled' ) );

		$this->assertContains( 'recipient@example.test', $this->get_queued_sync_emails() );
	}

	/**
	 * Joining a group queues the new member's ESP sync.
	 */
	public function test_joining_a_group_queues_the_member() {
		$subscription = $this->create_subscription( $this->create_reader( 'owner@example.test' ), true );
		$member_id    = $this->create_reader( 'joiner@example.test' );

		Group_Subscription::update_members( $subscription, [ $member_id ] );
		$this->run_connector_shutdown();

		$this->assertSame( [ $member_id ], $this->get_queued_reader_ids() );
	}

	/**
	 * Leaving a group queues the former member's ESP sync.
	 */
	public function test_leaving_a_group_queues_the_member() {
		$subscription = $this->create_subscription( $this->create_reader( 'owner@example.test' ), true );
		$member_id    = $this->create_reader( 'leaver@example.test' );
		$this->add_group_member( $member_id, $subscription );

		Group_Subscription::update_members( $subscription, [], [ $member_id ] );
		$this->run_connector_shutdown();

		$this->assertSame( [ $member_id ], $this->get_queued_reader_ids() );
	}

	/**
	 * Handing a gift to someone else schedules a sync for the new recipient and
	 * the one who lost it.
	 */
	public function test_recipient_change_schedules_both_recipients() {
		$purchaser_id     = $this->create_reader( 'buyer@example.test' );
		$old_recipient_id = $this->create_reader( 'old-recipient@example.test' );
		$new_recipient_id = $this->create_reader( 'new-recipient@example.test' );
		$subscription     = $this->create_subscription( $purchaser_id );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $new_recipient_id;

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $new_recipient_id, $old_recipient_id );

		$this->assertEqualsCanonicalizing( [ $new_recipient_id, $old_recipient_id ], $this->get_scheduled_sync_user_ids() );
	}

	/**
	 * A gift whose recipient account was set for the first time was held by its
	 * buyer until then, so the buyer is synced in place of a previous recipient.
	 */
	public function test_first_recipient_schedules_the_buyer() {
		$purchaser_id = $this->create_reader( 'buyer@example.test' );
		$recipient_id = $this->create_reader( 'recipient@example.test' );
		$subscription = $this->create_subscription( $purchaser_id );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $recipient_id, 0 );

		$this->assertEqualsCanonicalizing( [ $recipient_id, $purchaser_id ], $this->get_scheduled_sync_user_ids() );
	}

	/**
	 * WCS fires the recipient-changed hook on every save of a gift, so a save
	 * that keeps the same recipient schedules nothing.
	 */
	public function test_unchanged_recipient_schedules_nothing() {
		$recipient_id = $this->create_reader( 'recipient@example.test' );
		$subscription = $this->create_subscription( $this->create_reader( 'buyer@example.test' ) );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $recipient_id, $recipient_id );

		$this->assertSame( [], $this->get_scheduled_sync_user_ids() );
	}

	/**
	 * A gift that loses its recipient account without a recipient change schedules
	 * a sync for the former recipient and for the buyer it falls back to.
	 */
	public function test_cleared_recipient_schedules_previous_recipient_and_buyer() {
		$purchaser_id = $this->create_reader( 'buyer@example.test' );
		$recipient_id = $this->create_reader( 'recipient@example.test' );
		$subscription = $this->create_subscription( $purchaser_id );

		do_action( 'newspack_subscription_gift_recipient_cleared', $subscription, $recipient_id );

		$this->assertEqualsCanonicalizing( [ $recipient_id, $purchaser_id ], $this->get_scheduled_sync_user_ids() );
	}

	/**
	 * A renewal's follow-up sync reaches the gift's recipient too, not only the buyer.
	 */
	public function test_renewal_schedules_the_gift_recipient() {
		$purchaser_id = $this->create_reader( 'buyer@example.test' );
		$recipient_id = $this->create_reader( 'recipient@example.test' );
		$subscription = $this->create_subscription( $purchaser_id );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;

		Contact_Sync_Connector::subscription_renewal_attempt(
			time(),
			[
				'user_id'         => $purchaser_id,
				'email'           => 'buyer@example.test',
				'subscription_id' => $subscription->get_id(),
			],
			'test-client'
		);

		$this->assertEqualsCanonicalizing( [ $purchaser_id, $recipient_id ], $this->get_scheduled_sync_user_ids() );
	}

	/**
	 * A group member with no orders and no subscription of their own still holds
	 * access through the group, so logging in syncs them.
	 */
	public function test_login_syncs_a_group_member_without_orders() {
		$subscription = $this->create_subscription( $this->create_reader( 'owner@example.test' ), true );
		$member_id    = $this->create_reader( 'member@example.test' );
		$this->add_group_member( $member_id, $subscription );

		$this->log_in( $member_id, 'member@example.test' );

		$this->assertSame( [ 'member@example.test' ], $this->get_queued_sync_emails() );
	}

	/**
	 * A reader with no orders, no subscription and no group seat is still not
	 * synced on login.
	 */
	public function test_login_skips_a_reader_with_nothing_to_sync() {
		$reader_id = $this->create_reader( 'reader@example.test' );

		$this->log_in( $reader_id, 'reader@example.test' );

		$this->assertSame( [], $this->get_queued_sync_emails() );
	}

	/**
	 * The background batch syncs each reader who still has an account and skips
	 * the rest, such as a member deleted since the batch was queued.
	 */
	public function test_sync_readers_batch_syncs_existing_readers_only() {
		$reader_ids = [ $this->create_reader( 'reader-1@example.test' ), $this->create_reader( 'reader-2@example.test' ) ];
		$pushed     = [];
		add_filter(
			'newspack_esp_sync_contact',
			function ( $contact ) use ( &$pushed ) {
				$pushed[] = $contact['email'];
				return $contact;
			}
		);

		do_action( self::SYNC_READERS_HOOK, [ $reader_ids[0], 987654321, $reader_ids[1] ], 'Test batch' );

		$this->assertSame( [ 'reader-1@example.test', 'reader-2@example.test' ], $pushed );
	}

	/**
	 * Create a reader account.
	 *
	 * @param string $email Email address.
	 *
	 * @return int User ID.
	 */
	private function create_reader( string $email ): int {
		return $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => $email,
			]
		);
	}

	/**
	 * Create an active subscription.
	 *
	 * @param int  $customer_id Customer who bought it.
	 * @param bool $is_group    Whether it is a group subscription.
	 *
	 * @return \WC_Subscription
	 */
	private function create_subscription( int $customer_id, bool $is_group = false ) {
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $customer_id,
				'status'      => 'active',
				'products'    => [ 101 ],
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', $is_group ? 'yes' : 'no' );
		return $subscription;
	}

	/**
	 * Add a member to a group subscription without announcing it.
	 *
	 * @param int              $user_id      User ID.
	 * @param \WC_Subscription $subscription Group subscription.
	 */
	private function add_group_member( int $user_id, $subscription ): void {
		add_user_meta( $user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		Group_Subscription::reset_cache();
	}

	/**
	 * The payload `product_subscription_changed` carries for a status change.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @param string           $before       Status before.
	 * @param string           $after        Status after.
	 *
	 * @return array
	 */
	private function get_status_change_data( $subscription, string $before, string $after ): array {
		return [
			'user_id'         => $subscription->get_customer_id(),
			'email'           => 'buyer@example.test',
			'subscription_id' => $subscription->get_id(),
			'product_ids'     => [ 101 ],
			'status_before'   => $before,
			'status_after'    => $after,
		];
	}

	/**
	 * Run the connector's handlers for a data event the way Data_Events::handle()
	 * does, without the global handlers other features register.
	 *
	 * @param string $action_name Data event name.
	 * @param array  $data        Event payload.
	 */
	private function handle_event( string $action_name, array $data ): void {
		$this->in_data_event(
			$action_name,
			function () use ( $action_name, $data ) {
				foreach ( Data_Events::get_action_handlers( $action_name ) as $handler ) {
					call_user_func( $handler, time(), $data, 'test-client' );
				}
			}
		);
	}

	/**
	 * Run the login handler for a reader.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Email address.
	 */
	private function log_in( int $user_id, string $email ): void {
		$this->in_data_event(
			'reader_logged_in',
			function () use ( $user_id, $email ) {
				Contact_Sync_Connector::reader_logged_in(
					time(),
					[
						'user_id' => $user_id,
						'email'   => $email,
					],
					'test-client'
				);
			}
		);
	}

	/**
	 * Run a callback while a data event is being handled, which is when
	 * Contact_Sync queues syncs for the end of the request instead of pushing.
	 *
	 * @param string   $action_name Data event name.
	 * @param callable $callback    Callback.
	 */
	private function in_data_event( string $action_name, callable $callback ): void {
		$current_event = new \ReflectionProperty( Data_Events::class, 'current_event' );
		$current_event->setAccessible( true );
		$current_event->setValue( null, $action_name );
		try {
			$callback();
		} finally {
			$current_event->setValue( null, null );
		}
	}

	/**
	 * Run the connector's own shutdown callbacks, as the end of the request does.
	 * Firing `shutdown` itself would make every later sync in the process push
	 * at once instead of queueing.
	 */
	private function run_connector_shutdown(): void {
		global $wp_filter;
		if ( empty( $wp_filter['shutdown'] ) ) {
			return;
		}
		foreach ( $wp_filter['shutdown']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && is_string( $callback['function'][0] ) && Contact_Sync_Connector::class === ltrim( $callback['function'][0], '\\' ) ) {
					call_user_func( $callback['function'] );
				}
			}
		}
	}

	/**
	 * Arguments of the pending background actions that sync batches of readers.
	 *
	 * @return array[] One `[ user IDs, context ]` pair per action.
	 */
	private function get_sync_readers_actions(): array {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => self::SYNC_READERS_HOOK,
				'group'    => 'newspack',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		return array_values(
			array_map(
				function ( $action ) {
					return $action->get_args();
				},
				$actions
			)
		);
	}

	/**
	 * Every user ID queued for a background reader sync.
	 *
	 * @return int[]
	 */
	private function get_queued_reader_ids(): array {
		$user_ids = [];
		foreach ( $this->get_sync_readers_actions() as $args ) {
			$user_ids = array_merge( $user_ids, $args[0] );
		}
		return $user_ids;
	}

	/**
	 * Emails Contact_Sync has queued to push at the end of the request.
	 *
	 * @return string[]
	 */
	private function get_queued_sync_emails(): array {
		$queued_syncs = new \ReflectionProperty( Contact_Sync::class, 'queued_syncs' );
		$queued_syncs->setAccessible( true );
		return array_keys( $queued_syncs->getValue() );
	}

	/**
	 * Replace Contact_Sync's end-of-request queue.
	 *
	 * @param array $value Queue.
	 */
	private function set_queued_syncs( array $value ): void {
		$queued_syncs = new \ReflectionProperty( Contact_Sync::class, 'queued_syncs' );
		$queued_syncs->setAccessible( true );
		$queued_syncs->setValue( null, $value );
	}

	/**
	 * User IDs with a sync scheduled through Contact_Sync::schedule_sync().
	 *
	 * @return int[]
	 */
	private function get_scheduled_sync_user_ids(): array {
		$user_ids = [];
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks['newspack_scheduled_esp_sync'] ?? [] as $event ) {
				$user_ids[] = (int) $event['args'][0];
			}
		}
		return $user_ids;
	}

	/**
	 * Drop scheduled single-reader syncs and pending reader batches.
	 */
	private function clear_scheduled_syncs(): void {
		wp_unschedule_hook( 'newspack_scheduled_esp_sync' );
		as_unschedule_all_actions( self::SYNC_READERS_HOOK );
	}

	/**
	 * Sizes of a list of batches, smallest first.
	 *
	 * @param array[] $batches Batches of user IDs.
	 *
	 * @return int[]
	 */
	private function get_sorted_counts( array $batches ): array {
		$counts = array_map( 'count', $batches );
		sort( $counts );
		return $counts;
	}

	/**
	 * Set the fields the test integration sends.
	 *
	 * @param string[] $labels Field labels.
	 */
	private function set_outgoing_fields( array $labels ): void {
		update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . self::INTEGRATION_ID, $labels );
	}

	/**
	 * Read Data_Events::$actions (action name => handlers).
	 *
	 * @return array
	 */
	private function get_data_events_actions(): array {
		$property = new \ReflectionProperty( Data_Events::class, 'actions' );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/**
	 * Replace Data_Events::$actions.
	 *
	 * @param array $actions Action name => handlers.
	 */
	private function set_data_events_actions( array $actions ): void {
		$property = new \ReflectionProperty( Data_Events::class, 'actions' );
		$property->setAccessible( true );
		$property->setValue( null, $actions );
	}

	/**
	 * Replace the integrations registry.
	 *
	 * @param array $integrations Integrations keyed by ID.
	 */
	private function set_integrations( array $integrations ): void {
		$property = new \ReflectionProperty( Integrations::class, 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, $integrations );
	}
}
