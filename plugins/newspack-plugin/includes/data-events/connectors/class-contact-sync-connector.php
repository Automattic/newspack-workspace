<?php
/**
 * Newspack Data Events Contact Sync Connector.
 *
 * @package Newspack
 */

namespace Newspack\Data_Events\Connectors;

use Newspack\Access_Rules;
use Newspack\Data_Events;
use Newspack\Group_Subscription;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\WooCommerce_Connection;
use Newspack\WooCommerce_Subscriptions;
use Newspack\Reader_Activation\Sync\WooCommerce as Sync_WooCommerce;
use Newspack\Reader_Activation\Sync\Metadata as Sync_Metadata;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Content_Gate as Content_Access_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Sync Connector Class.
 */
class Contact_Sync_Connector {
	/**
	 * Initialize hooks.
	 */
	public static function init_hooks() {
		add_action( 'init', [ __CLASS__, 'register_handlers' ] );
		// Store user data before deletion.
		add_action( 'delete_user', [ __CLASS__, 'store_user_data_before_deletion' ], 5 );
	}

	/**
	 * Register handlers.
	 */
	public static function register_handlers() {
		if ( ! Contact_Sync::has_one_syncable_integration() ) {
			return;
		}
		Data_Events::register_handler( [ __CLASS__, 'reader_registered' ], 'reader_registered' );
		// Deletion always routes through Contact_Sync::handle_account_deletion(),
		// which honors each integration's own account_deletion_handling setting.
		Data_Events::register_handler( [ __CLASS__, 'reader_delete_sync' ], 'reader_delete_sync' );
		Data_Events::register_handler( [ __CLASS__, 'reader_logged_in' ], 'reader_logged_in' );
		Data_Events::register_handler( [ __CLASS__, 'order_completed' ], 'order_completed' );
		Data_Events::register_handler( [ __CLASS__, 'subscription_updated' ], 'donation_subscription_changed' );
		Data_Events::register_handler( [ __CLASS__, 'subscription_updated' ], 'product_subscription_changed' );
		Data_Events::register_handler( [ __CLASS__, 'subscription_renewal_attempt' ], 'subscription_renewal_attempt' );
		Data_Events::register_handler( [ __CLASS__, 'newsletter_updated' ], 'newsletter_subscribed' );
		Data_Events::register_handler( [ __CLASS__, 'newsletter_updated' ], 'newsletter_updated' );

		if ( class_exists( 'Newspack_Network\Initializer' ) ) {
			Data_Events::register_handler( [ __CLASS__, 'network_new_reader' ], 'network_new_reader' );
		}

		// Changes to who holds a gift or a group seat that no data event reports.
		add_action( 'newspack_subscription_readers_changed', [ __CLASS__, 'subscription_readers_changed' ], 10, 2 );
		add_action( 'newspack_group_subscription_memberships_changed', [ __CLASS__, 'group_memberships_changed' ] );
	}

	/**
	 * Store user data before deletion.
	 *
	 * @param int $user_id User ID being deleted.
	 */
	public static function store_user_data_before_deletion( $user_id ) {
		if ( ! class_exists( 'WC_Customer' ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		// Get WooCommerce customer data.
		$customer = new \WC_Customer( $user_id );
		$contact = Sync_WooCommerce::get_contact_from_customer( $customer );

		$data_to_store = [
			'email'         => $user->user_email,
			'user_id'       => $user_id,
			'customer_data' => $contact,
		];
		set_transient( 'newspack_user_deletion_data_' . $user_id, $data_to_store, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Handle a reader registering.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function reader_registered( $timestamp, $data, $client_id ) {
		if ( empty( $data['user_id'] ) ) {
			return;
		}

		/**
		 * Filters the context of the contact sync a new reader's registration
		 * triggers, which Sync Activity shows as the push's trigger.
		 *
		 * @param string $context The context of the sync.
		 * @param array  $data    The event data: `user_id`, `email` and `metadata`.
		 */
		$context = \apply_filters( 'newspack_reader_registered_sync_context', 'RAS Reader registration', $data );
		Contact_Sync::sync_contact( $data['user_id'], $context );
	}

	/**
	 * Sync reader data on login.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function reader_logged_in( $timestamp, $data, $client_id ) {
		if ( empty( $data['email'] ) || empty( $data['user_id'] ) ) {
			return;
		}

		// Without WooCommerce there are no orders or subscriptions that could
		// make a login worth a resync, and WC_Customer does not exist.
		if ( ! class_exists( 'WC_Customer' ) ) {
			return;
		}

		$customer = new \WC_Customer( $data['user_id'] );

		// Skip a reader with no orders who holds no active subscription: their own, a
		// gift, or a group seat.
		if ( ! $customer->get_order_count() && empty( Access_Rules::get_active_subscription_ids( $data['user_id'], [], false, 1 ) ) ) {
			return;
		}

		Contact_Sync::sync_contact( $data['user_id'], 'RAS Reader login' );
	}

	/**
	 * Handle a completed order of any type.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function order_completed( $timestamp, $data, $client_id ) {
		if ( ! isset( $data['platform_data']['order_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		// If order is a subscription renewal, ignore it here as it's handled by
		// the subscription_updated event.
		if ( ! empty( $data['is_renewal'] ) ) {
			return;
		}

		$order_id = $data['platform_data']['order_id'];
		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		Contact_Sync::sync_contact( $order, 'RAS Order completed' );
	}

	/**
	 * Handle a change in subscription status.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function subscription_updated( $timestamp, $data, $client_id ) {
		if ( empty( $data['status_before'] ) || empty( $data['status_after'] ) || empty( $data['subscription_id'] ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		// A gift's recipient holds it like the customer does. A group's members can
		// only gain or lose Content Access, and a group can be large.
		$subscription_id = (int) $data['subscription_id'];
		$readers         = WooCommerce_Subscriptions::get_subscription_readers( $subscription_id );
		if ( $readers['recipient'] && $readers['recipient'] !== $readers['purchaser'] ) {
			Contact_Sync::sync_contact(
				$readers['recipient'],
				sprintf( 'RAS Gift subscription %d updated. Status changed from %s to %s', $subscription_id, $data['status_before'], $data['status_after'] )
			);
		}
		if ( WooCommerce_Connection::status_change_affects_access( $data['status_before'], $data['status_after'] ) && self::is_content_access_pushed() ) {
			self::schedule_syncs( $readers['members'], sprintf( 'RAS Group subscription %d changed', $subscription_id ) );
		}

		$order = \wcs_get_subscription( $data['subscription_id'] );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		Contact_Sync::sync_contact(
			$order,
			sprintf(
				'RAS Woo Subscription updated. Status changed from %s to %s',
				$data['status_before'],
				$data['status_after']
			)
		);
	}

	/**
	 * Handle a subscription renewal attempt.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function subscription_renewal_attempt( $timestamp, $data, $client_id ) {
		if ( empty( $data['subscription_id'] ) || empty( $data['user_id'] ) || empty( $data['email'] ) ) {
			return;
		}

		/**
		* When a renewal happens, it triggers two syncs to the ESP, one setting the subscription as on hold, and a
		* second one setting it back to active. This sometimes creates a race condition on the ESP side.
		* This third request will make sure the ESP always has the correct and most up to date data about the reader.
		*/
		Contact_Sync::schedule_sync(
			$data['user_id'],
			sprintf(
				// Translators: %d is the subscription ID and %s is the customer's email address.
				'RAS Woo subscription %d for %s renewed.',
				$data['subscription_id'],
				$data['email']
			),
			120 // Schedule an ESP sync in 2 minutes.
		);
		// A gift's recipient got the same two syncs, so they get the third one too.
		$recipient_id = WooCommerce_Subscriptions::get_subscription_readers( (int) $data['subscription_id'] )['recipient'];
		if ( $recipient_id && $recipient_id !== (int) $data['user_id'] ) {
			Contact_Sync::schedule_sync( $recipient_id, sprintf( 'RAS Gift subscription %d renewed.', $data['subscription_id'] ), 120 );
		}
	}

	/**
	 * Sync the readers a gift or group moved. A group's members only when Content
	 * Access is sent: it holds the only fields of theirs a toggle changes.
	 *
	 * @param int[]            $user_ids     Readers who may have gained or lost the subscription.
	 * @param \WC_Subscription $subscription The subscription.
	 */
	public static function subscription_readers_changed( $user_ids, $subscription ) {
		$members = self::is_content_access_pushed() ? [] : (array) Group_Subscription::get_members( $subscription );
		self::schedule_syncs( array_diff( (array) $user_ids, $members ), sprintf( 'RAS Subscription %d readers changed', $subscription->get_id() ) );
	}

	/**
	 * Sync a reader who joined or left a group, which can change their Content Access.
	 *
	 * @param int $user_id The reader.
	 */
	public static function group_memberships_changed( $user_id ) {
		if ( self::is_content_access_pushed() ) {
			self::schedule_syncs( [ $user_id ], 'RAS Group subscription membership changed' );
		}
	}

	/**
	 * Whether an integration sends any of the Content Access fields.
	 *
	 * @return bool
	 */
	private static function is_content_access_pushed() {
		return (bool) array_filter( array_keys( Content_Access_Metadata::get_fields() ), [ Sync_Metadata::class, 'is_field_push_enabled' ] );
	}

	/**
	 * Schedule a background ESP sync per reader on Contact_Sync's own hook, unless
	 * an identical one is pending. A context naming only the subscription lets a
	 * renewal's on-hold and active events share one.
	 *
	 * @param int[]  $user_ids Readers to sync.
	 * @param string $context  Why they need syncing.
	 */
	private static function schedule_syncs( array $user_ids, string $context ): void {
		foreach ( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) as $user_id ) {
			$args = [ $user_id, $context ];
			// Not AS's $unique flag, which ignores the arguments and would keep only the first reader.
			if ( ! \as_has_scheduled_action( 'newspack_scheduled_esp_sync', $args, 'newspack' ) ) {
				\as_enqueue_async_action( 'newspack_scheduled_esp_sync', $args, 'newspack' );
			}
		}
	}

	/**
	 * Handle a reader delete sync.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function reader_delete_sync( $timestamp, $data, $client_id ) {
		if ( empty( $data['email'] ) ) {
			return;
		}
		$email   = $data['email'];
		$user_id = $data['user_id'] ?? 0;

		$stored_data = $user_id ? get_transient( 'newspack_user_deletion_data_' . $user_id ) : false;
		if ( $stored_data && ! empty( $stored_data['customer_data'] ) ) {
			$contact = $stored_data['customer_data'];
		} else {
			$contact = [
				'email'    => $email,
				'metadata' => $user_id ? [ 'account' => $user_id ] : [],
			];
		}

		if ( $user_id ) {
			delete_transient( 'newspack_user_deletion_data_' . $user_id );
		}

		Contact_Sync::handle_account_deletion( $email, $contact, 'RAS Reader deletion' );
	}

	/**
	 * Handle a newsletter subscription change.
	 *
	 * The contact is rebuilt from stored data, so the "Newsletter Selection"
	 * field comes from the legacy metadata class rather than from this handler.
	 * The reader-data handler for the same event stores the lists that class
	 * reads, and the queued sync runs at shutdown, after both handlers.
	 *
	 * That field is the only synced field a list change affects, so the sync
	 * is skipped when no push-enabled integration sends it. It is part of the
	 * legacy era's default selection and off by default on new-schema sites,
	 * where a publisher can still enable it from the Legacy panel. Where it is
	 * sent, every list change syncs the reader, including bulk ones such as
	 * membership activation and the membership-tied subscribers CLI, which
	 * fan out one upsert per reader. That is what keeps the field current; a
	 * publisher who unticks the field everywhere pays nothing per change.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function newsletter_updated( $timestamp, $data ) {
		if ( empty( $data['user_id'] ) ) {
			return;
		}
		if ( ! Sync_Metadata::is_field_push_enabled( 'newsletter_selection' ) ) {
			return;
		}
		Contact_Sync::sync_contact( $data['user_id'], 'RAS Newsletter subscription updated' );
	}

	/**
	 * Handle a a new network added in the Newspack Network plugin.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function network_new_reader( $timestamp, $data, $client_id ) {
		$user_id = $data['user_id'];
		$registration_site = $data['registration_site'];

		$site_url = get_site_url();
		Contact_Sync::sync_contact( $user_id, sprintf( 'RAS Newspack Network: User propagated from another site in the network. Propagated from %s to %s.', $registration_site, $site_url ) );
	}
}
Contact_Sync_Connector::init_hooks();
