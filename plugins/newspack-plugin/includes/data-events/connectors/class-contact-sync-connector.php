<?php
/**
 * Newspack Data Events Contact Sync Connector.
 *
 * @package Newspack
 */

namespace Newspack\Data_Events\Connectors;

use Newspack\Data_Events;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack_Newsletters_Contacts;
use Newspack\WooCommerce_Connection;
use Newspack\Reader_Activation\Sync\WooCommerce as Sync_WooCommerce;
use Newspack\Reader_Activation\Sync\Metadata as Sync_Metadata;

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
		Data_Events::register_handler( [ __CLASS__, 'reader_deleted' ], 'reader_deleted' );
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

		Contact_Sync::sync_contact( $data['user_id'], 'RAS Reader registration' );
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

		$customer = new \WC_Customer( $data['user_id'] );

		// If user is not a Woo customer, don't need to sync them.
		if ( ! $customer->get_order_count() && empty( WooCommerce_Connection::get_active_subscriptions_for_user( $data['user_id'] ) ) ) {
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
	}

	/**
	 * Handle a user deletion.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function reader_deleted( $timestamp, $data, $client_id ) {
		if ( ! isset( $data['user']['data']['user_email'] ) ) {
			return;
		}
		if ( true === Reader_Activation::get_setting( 'sync_esp_delete' ) ) {
			$result = Newspack_Newsletters_Contacts::delete( $data['user']['data']['user_email'], 'RAS Reader deleted' );
		} else {
			$result = Newspack_Newsletters_Contacts::update_lists( $data['user']['data']['user_email'], [], 'Reader account deleted' );
		}
		return $result;
	}

	/**
	 * Handle a reader delete sync.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function reader_delete_sync( $timestamp, $data, $client_id ) {
		if ( empty( $data['user_id'] ) ) {
			return;
		}
		// Get the stored data from before the user was deleted.
		$stored_data = get_transient( 'newspack_user_deletion_data_' . $data['user_id'] );

		if ( $stored_data && ! empty( $stored_data['customer_data'] ) ) {
			$contact = $stored_data['customer_data'];
			$contact['metadata'] = array_merge(
				$contact['metadata'],
				[
					'membership_status' => 'user-deleted',
				]
			);
		} else {
			// If we don't have anything stored, send what we can.
			$contact = [
				'email'    => $data['user']['data']['user_email'] ?? '',
				'metadata' => [
					'account'           => $data['user_id'],
					'membership_status' => 'user-deleted',
				],
			];
		}

		// Clean up the transient.
		delete_transient( 'newspack_user_deletion_data_' . $data['user_id'] );

		// Sync contact information with ESP.
		$sync_result = Contact_Sync::sync( $contact, 'RAS Reader deletion' );
	}

	/**
	 * Handle newsletter subscription update.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function newsletter_updated( $timestamp, $data ) {
		if ( empty( $data['user_id'] ) || empty( $data['email'] ) || empty( $data['contact'] ) ) {
			return;
		}
		$contact          = $data['contact'];
		$subscribed_lists = \Newspack_Newsletters_Subscription::get_contact_lists( $data['email'] );
		if ( is_wp_error( $subscribed_lists ) || ! is_array( $subscribed_lists ) ) {
			return;
		}
		$lists = \Newspack_Newsletters_Subscription::get_lists();
		if ( is_wp_error( $lists ) ) {
			return;
		}
		$lists_names = [];
		foreach ( $subscribed_lists as $subscribed_list_id ) {
			foreach ( $lists as $list ) {
				if ( $list['id'] === $subscribed_list_id ) {
					$lists_names[] = $list['name'];
				}
			}
		}

		$contact['metadata'] = array_merge(
			$contact['metadata'],
			[
				'account'              => $data['user_id'],
				'newsletter_selection' => implode( ', ', $lists_names ),
			]
		);
		Contact_Sync::sync( $contact, 'Updating newsletter_selection field after a change in the subscription lists.' );
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
