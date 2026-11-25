<?php
/**
 * Tests for Contact_Sync_Connector handler registration.
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
 * Tests for Contact_Sync_Connector::register_handlers().
 *
 * Deletion routes through one handler on every site: `reader_delete_sync`,
 * which reaches Contact_Sync::handle_account_deletion() and honors each
 * integration's own account_deletion_handling setting.
 */
class Newspack_Test_Contact_Sync_Connector extends \WP_UnitTestCase {

	/**
	 * Snapshot of Data_Events::$actions taken in set_up so tear_down can restore
	 * the full action+handler map. Without restore, reset_data_events_handlers()
	 * would wipe handlers registered by other test classes and make the suite
	 * order-dependent.
	 *
	 * @var array<string,callable[]>|null
	 */
	private $actions_snapshot = null;

	/**
	 * Load the WooCommerce doubles. Content Access fields exist only with gates on.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-gifting-mock.php';
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Set up: register a syncable Sample_Integration so register_handlers() does
	 * not bail out via Contact_Sync::has_one_syncable_integration().
	 */
	public function set_up() {
		parent::set_up();
		// Allow sync on the test (non-production) site so Sync::can_sync() does
		// not bail out. The filter is scoped (removed in tear_down) and does not
		// pollute later tests, unlike defining NEWSPACK_ALLOW_READER_SYNC globally.
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		$this->actions_snapshot = $this->snapshot_data_events_actions();
		$this->reset_integrations();
		Integrations::register( new Sample_Integration( 'contact-sync-connector-test', 'Contact Sync Connector Test' ) );
		// Mark the integration as enabled so it counts as an active syncable integration.
		update_option( Integrations::OPTION_NAME, [ 'contact-sync-connector-test' ] );
	}

	/**
	 * Tear down: restore baseline state for shared static registries.
	 */
	public function tear_down() {
		as_unschedule_all_actions( 'newspack_scheduled_esp_sync' );
		wp_unschedule_hook( 'newspack_scheduled_esp_sync' );
		$queued_syncs = new \ReflectionProperty( Contact_Sync::class, 'queued_syncs' );
		$queued_syncs->setAccessible( true );
		$queued_syncs->setValue( null, [] );
		\WCS_Gifting::$recipients = [];
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		delete_option( Integrations::OPTION_NAME );
		$this->reset_integrations();
		Integrations::register_integrations();
		if ( null !== $this->actions_snapshot ) {
			$this->restore_data_events_actions( $this->actions_snapshot );
			$this->actions_snapshot = null;
		}
		parent::tear_down();
	}

	/**
	 * Capture the full Data_Events::$actions map (action_name => handlers[]).
	 *
	 * @return array
	 */
	private function snapshot_data_events_actions() {
		$reflection = new \ReflectionClass( Data_Events::class );
		$property   = $reflection->getProperty( 'actions' );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/**
	 * Restore a previously-snapshot'd Data_Events::$actions map.
	 *
	 * @param array $snapshot The actions map to restore.
	 */
	private function restore_data_events_actions( $snapshot ) {
		$reflection = new \ReflectionClass( Data_Events::class );
		$property   = $reflection->getProperty( 'actions' );
		$property->setAccessible( true );
		$property->setValue( null, $snapshot );
	}

	/**
	 * Clear the integrations registry via reflection.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Clear handler callables for all registered data event actions while
	 * preserving the action keys themselves (which are required by
	 * Data_Events::register_handler).
	 */
	private function reset_data_events_handlers() {
		$reflection = new \ReflectionClass( Data_Events::class );
		$property   = $reflection->getProperty( 'actions' );
		$property->setAccessible( true );
		$actions = $property->getValue();
		foreach ( $actions as $action_name => $handlers ) {
			$actions[ $action_name ] = [];
		}
		$property->setValue( null, $actions );
	}

	/**
	 * Return the names of data event actions that currently have at least one
	 * registered handler.
	 *
	 * @return string[]
	 */
	private function get_registered_handler_action_names() {
		$reflection = new \ReflectionClass( Data_Events::class );
		$property   = $reflection->getProperty( 'actions' );
		$property->setAccessible( true );
		$action_names = [];
		foreach ( $property->getValue() as $action_name => $handlers ) {
			if ( ! empty( $handlers ) ) {
				$action_names[] = $action_name;
			}
		}
		return $action_names;
	}

	/**
	 * Only the `reader_delete_sync` handler is registered now, not the
	 * pre-coexistence `reader_deleted` one — running both caused
	 * double-processing during user deletion.
	 */
	public function test_registers_reader_delete_sync_only() {
		$this->reset_data_events_handlers();

		Contact_Sync_Connector::register_handlers();

		$actions = $this->get_registered_handler_action_names();
		$this->assertContains( 'reader_delete_sync', $actions );
		$this->assertNotContains( 'reader_deleted', $actions );
	}

	/**
	 * End-to-end: reader_delete_sync should route through the Contact_Sync
	 * dispatcher and call delete_contact() on a registered integration
	 * configured to handle deletion via the 'delete' mode.
	 */
	public function test_reader_delete_sync_routes_to_handle_account_deletion() {
		// Pre-condition: one spy integration in 'delete' mode.
		$reflection = new \ReflectionClass( \Newspack\Reader_Activation\Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );

		$spy = new \Deletion_Spy_Integration( 'spy-e2e', 'Spy E2E' );
		\Newspack\Reader_Activation\Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		\Newspack\Reader_Activation\Integrations::enable( 'spy-e2e' );

		\Newspack\Data_Events\Connectors\Contact_Sync_Connector::reader_delete_sync(
			time(),
			[
				'email'   => 'deleted@example.com',
				'user_id' => 0,
			],
			'client-id'
		);

		$this->assertCount( 1, $spy->delete_calls );
		$this->assertSame( 'deleted@example.com', $spy->delete_calls[0]['email'] );
	}

	/**
	 * Register only the connector's handlers, with the integration sending the given fields.
	 *
	 * @param string[] $fields Outgoing field labels.
	 */
	private function register_connector( array $fields = [ 'Content Access' ] ) {
		update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'contact-sync-connector-test', $fields );
		$this->reset_data_events_handlers();
		Contact_Sync_Connector::register_handlers();
	}

	/**
	 * Create a group subscription with the given members.
	 *
	 * @param int ...$members Member user IDs.
	 *
	 * @return \WC_Subscription
	 */
	private function create_group( int ...$members ) {
		$subscription = wcs_create_subscription(
			[
				'customer_id' => self::factory()->user->create(),
				'status'      => 'active',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		foreach ( $members as $member_id ) {
			add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		}
		Group_Subscription::reset_cache();
		return $subscription;
	}

	/**
	 * Run a data event's handlers as Data_Events::handle() does, so syncs are queued.
	 *
	 * @param string $action_name Data event name.
	 * @param array  $data        Event data.
	 */
	private function handle_event( string $action_name, array $data ) {
		$current_event = new \ReflectionProperty( Data_Events::class, 'current_event' );
		$current_event->setAccessible( true );
		$current_event->setValue( null, $action_name );
		foreach ( Data_Events::get_action_handlers( $action_name ) as $handler ) {
			call_user_func( $handler, time(), $data, 'test-client' );
		}
		$current_event->setValue( null, null );
	}

	/**
	 * Handle a subscription's status change.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $status_before Status before.
	 * @param string           $status_after  Status after.
	 */
	private function change_status( $subscription, string $status_before, string $status_after ) {
		$user_id         = $subscription->get_customer_id();
		$subscription_id = $subscription->get_id();
		$this->handle_event( 'product_subscription_changed', compact( 'user_id', 'subscription_id', 'status_before', 'status_after' ) );
	}

	/**
	 * Emails Contact_Sync has queued to push at the end of the request.
	 *
	 * @return string[]
	 */
	private function get_queued_sync_emails() {
		$queued_syncs = new \ReflectionProperty( Contact_Sync::class, 'queued_syncs' );
		$queued_syncs->setAccessible( true );
		return array_keys( $queued_syncs->getValue() );
	}

	/**
	 * Arguments of the pending background syncs, as [ user ID, context ] pairs.
	 *
	 * @return array[]
	 */
	private function get_scheduled_syncs() {
		$query = [
			'hook'     => 'newspack_scheduled_esp_sync',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
		];
		return array_values( array_map( fn( $action ) => $action->get_args(), as_get_scheduled_actions( $query ) ) );
	}

	/**
	 * Status changes and outgoing fields, as [ before, after, fields, whether members are synced ].
	 *
	 * @return array[]
	 */
	public function data_group_status_changes() {
		return [
			'cancelled, Content Access sent'    => [ 'active', 'cancelled', [ 'Content Access' ], true ],
			'cancelled, only its source sent'   => [ 'active', 'cancelled', [ 'Content Access Source' ], true ],
			'cancelled, only its group sent'    => [ 'active', 'cancelled', [ 'Content Access Group' ], true ],
			'cancelled, no Content Access sent' => [ 'active', 'cancelled', [ 'Registration Date' ], false ],
			'access kept'                       => [ 'active', 'pending-cancel', [ 'Content Access' ], false ],
		];
	}

	/**
	 * A status change syncs a gift's recipient like its buyer. A group's members get
	 * one background sync each, when the change can affect access and a Content
	 * Access field is sent.
	 *
	 * @dataProvider data_group_status_changes
	 *
	 * @param string   $before         Status before.
	 * @param string   $after          Status after.
	 * @param string[] $fields         Outgoing field labels.
	 * @param bool     $members_synced Whether the members are synced.
	 */
	public function test_status_change_syncs_the_recipient_and_the_members( $before, $after, $fields, $members_synced ) {
		$this->register_connector( $fields );
		$member_ids   = self::factory()->user->create_many( 2 );
		$subscription = $this->create_group( ...$member_ids );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = self::factory()->user->create( [ 'user_email' => 'recipient@example.test' ] );

		$this->change_status( $subscription, $before, $after );

		$this->assertContains( 'recipient@example.test', $this->get_queued_sync_emails() );
		$context = sprintf( 'RAS Group subscription %d changed', $subscription->get_id() );
		$this->assertEqualsCanonicalizing( $members_synced ? [ [ $member_ids[0], $context ], [ $member_ids[1], $context ] ] : [], $this->get_scheduled_syncs() );
	}

	/**
	 * A renewal puts a group on hold and back; the second event finds each member's
	 * sync still pending and adds none.
	 */
	public function test_pending_member_sync_is_not_duplicated() {
		$this->register_connector();
		$member_id    = self::factory()->user->create();
		$subscription = $this->create_group( $member_id );

		$this->change_status( $subscription, 'active', 'on-hold' );
		$this->change_status( $subscription, 'on-hold', 'active' );

		$this->assertSame( [ $member_id ], array_column( $this->get_scheduled_syncs(), 0 ) );
	}

	/**
	 * Readers a change of holder names, as [ whether they are the group's members, fields, whether synced ].
	 *
	 * @return array[]
	 */
	public function data_readers_changed() {
		return [
			'gift readers, Content Access sent'     => [ false, [ 'Content Access' ], true ],
			'gift readers, no Content Access sent'  => [ false, [ 'Registration Date' ], true ],
			'group members, Content Access sent'    => [ true, [ 'Content Access' ], true ],
			'group members, no Content Access sent' => [ true, [ 'Registration Date' ], false ],
		];
	}

	/**
	 * The readers a change of holder names are synced in the background. A group's
	 * members only when a Content Access field is sent: a toggle changes nothing else.
	 *
	 * @dataProvider data_readers_changed
	 *
	 * @param bool     $are_members Whether the readers are the group's members.
	 * @param string[] $fields      Outgoing field labels.
	 * @param bool     $synced      Whether the readers are synced.
	 */
	public function test_readers_changed_schedules_their_syncs( $are_members, $fields, $synced ) {
		$this->register_connector( $fields );
		$reader_ids   = self::factory()->user->create_many( 2 );
		$subscription = $this->create_group( ...( $are_members ? $reader_ids : [] ) );

		do_action( 'newspack_subscription_readers_changed', $reader_ids, $subscription );

		$this->assertEqualsCanonicalizing( $synced ? $reader_ids : [], array_column( $this->get_scheduled_syncs(), 0 ) );
	}

	/**
	 * Joining or leaving a group can change only a reader's Content Access, so they
	 * are synced only when an integration sends it.
	 */
	public function test_membership_change_schedules_the_member_when_content_access_is_sent() {
		$member_id = self::factory()->user->create();
		$this->register_connector( [ 'Registration Date' ] );
		do_action( 'newspack_group_subscription_memberships_changed', $member_id );
		$this->assertSame( [], $this->get_scheduled_syncs() );

		$this->register_connector();
		do_action( 'newspack_group_subscription_memberships_changed', $member_id );
		$this->assertSame( [ $member_id ], array_column( $this->get_scheduled_syncs(), 0 ) );
	}

	/**
	 * A renewal's follow-up sync reaches a gift's recipient too, not only the buyer.
	 */
	public function test_renewal_schedules_the_gift_recipient() {
		$this->register_connector();
		$buyer_id     = self::factory()->user->create();
		$recipient_id = self::factory()->user->create();
		$gift         = wcs_create_subscription( [ 'customer_id' => $buyer_id ] );
		\WCS_Gifting::$recipients[ $gift->get_id() ] = $recipient_id;
		$data = [
			'user_id'         => $buyer_id,
			'email'           => 'buyer@example.test',
			'subscription_id' => $gift->get_id(),
		];

		Contact_Sync_Connector::subscription_renewal_attempt( time(), $data, 'test-client' );

		$scheduled = array_merge( ...array_map( fn( $hooks ) => $hooks['newspack_scheduled_esp_sync'] ?? [], _get_cron_array() ) );
		$this->assertEqualsCanonicalizing( [ $buyer_id, $recipient_id ], array_map( fn( $event ) => $event['args'][0], array_values( $scheduled ) ) );
	}

	/**
	 * Login syncs a reader without orders who holds a group seat, and still skips
	 * one who holds nothing.
	 */
	public function test_login_syncs_a_group_member_without_orders() {
		$this->register_connector();
		$member_id = self::factory()->user->create( [ 'user_email' => 'member@example.test' ] );
		$reader_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$this->create_group( $member_id );

		foreach ( [ $member_id, $reader_id ] as $user_id ) {
			$email = get_userdata( $user_id )->user_email;
			$this->handle_event( 'reader_logged_in', compact( 'user_id', 'email' ) );
		}

		$this->assertSame( [ 'member@example.test' ], $this->get_queued_sync_emails() );
	}
}
