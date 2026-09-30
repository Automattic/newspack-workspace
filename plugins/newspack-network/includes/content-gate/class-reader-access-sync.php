<?php
/**
 * Newspack Network reader access sync.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Gate;

use Newspack_Network\Debugger;
use Newspack_Network\Hub\Reader_Access_Endpoint as Hub_Endpoint;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Node\Settings as Node_Settings;
use Newspack_Network\Site_Role;
use Newspack_Network\Utils\Requests;
use WP_Error;

/**
 * Keeps a copy, on the site being read, of what a reader holds on the other
 * network sites: the subscriptions they own, their seats on other people's
 * group subscriptions, and their paid one-time orders.
 *
 * Subscription events only reach a site that already has an account for the
 * reader, and orders and group seats only reach the hub, so this site asks the hub,
 * which keeps copies of every site's subscriptions, orders and group members. It asks on the reader's first gated check with nothing stored, which
 * covers readers who were logged in before this existed and accounts created
 * after the purchase; on the first gated check after each login; and in the
 * background once the copy is older than the refresh interval.
 */
class Reader_Access_Sync {

	/**
	 * User meta holding the reader's group seats and one-time orders on other sites.
	 *
	 * Owned subscriptions are stored in Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY
	 * instead, next to the records the subscription events write.
	 */
	const META_KEY = '_newspack_network_reader_access';

	/**
	 * Cron hook that refreshes a reader's copy in the background.
	 */
	const REFRESH_HOOK = 'newspack_network_refresh_reader_access';

	/**
	 * Action Scheduler group for background refreshes.
	 */
	const REFRESH_GROUP = 'newspack-network';

	/**
	 * Option holding the last failed pull's error, shown on the node settings screen.
	 */
	const LAST_ERROR_OPTION = 'newspack_network_reader_access_last_error';

	/**
	 * Seconds the hub may take to answer. A pull can hold up a page view, so this stays
	 * well under the default; the hub answers from its own database.
	 */
	const HUB_TIMEOUT = 5;

	/**
	 * Seconds after a failed pull before a gated check tries again, so an unreachable
	 * hub doesn't slow down every page view or queue a refresh on each one.
	 */
	const RETRY_AFTER = 10 * MINUTE_IN_SECONDS;

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'wp_login', [ __CLASS__, 'refresh_on_next_check' ], 10, 2 );
		add_action( self::REFRESH_HOOK, [ __CLASS__, 'sync' ] );
	}

	/**
	 * The last failed pull's error, or '' if the last pull succeeded.
	 *
	 * @return string
	 */
	public static function get_last_error() {
		return (string) get_option( self::LAST_ERROR_OPTION, '' );
	}

	/**
	 * Seconds a pulled copy is used before it is refreshed in the background.
	 *
	 * @return int
	 */
	public static function get_refresh_interval() {
		/**
		 * Filters how long a reader's pulled network data is used before it is refreshed.
		 *
		 * A seat removed from a group, an ended group subscription or a refunded order
		 * stops granting access once the copy is refreshed, so this bounds how long that takes.
		 * Refreshes are never closer together than Reader_Access_Sync::RETRY_AFTER.
		 *
		 * @param int $interval Seconds. Default 12 hours.
		 */
		return (int) apply_filters( 'newspack_network_reader_access_refresh_interval', 12 * HOUR_IN_SECONDS );
	}

	/**
	 * The stored snapshot for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array {
	 *     @type int   $synced_at    When the last successful pull finished; 0 when a refresh is due now.
	 *     @type int   $attempted_at When the last pull started.
	 *     @type array $sites        Site URL => [ 'groups' => array[], 'orders' => array[] ].
	 * }
	 */
	public static function get_snapshot( $user_id ) {
		$snapshot = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $snapshot ) ? $snapshot : [];
	}

	/**
	 * The reader's seats on group subscriptions owned on other sites.
	 *
	 * @param int $user_id User ID.
	 * @return array[] Each with site, id, status and network_ids.
	 */
	public static function get_group_seats( $user_id ) {
		return self::get_records( $user_id, 'groups' );
	}

	/**
	 * The reader's paid one-time orders on other sites.
	 *
	 * @param int $user_id User ID.
	 * @return array[] Each with site, id, date_created (Unix time) and network_ids.
	 */
	public static function get_orders( $user_id ) {
		return self::get_records( $user_id, 'orders' );
	}

	/**
	 * Flatten one kind of record across sites, tagging each with its site.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    'groups' or 'orders'.
	 * @return array[]
	 */
	private static function get_records( $user_id, $type ) {
		$snapshot = self::get_snapshot( $user_id );
		$records  = [];
		foreach ( (array) ( $snapshot['sites'] ?? [] ) as $site => $site_data ) {
			foreach ( (array) ( $site_data[ $type ] ?? [] ) as $record ) {
				if ( is_array( $record ) ) {
					$records[] = array_merge( $record, [ 'site' => $site ] );
				}
			}
		}
		return $records;
	}

	/**
	 * Refresh the reader's copy if it is due, before a gated check reads it.
	 *
	 * Only the current reader is pulled for, and only while they are being checked
	 * against a gate that accepts other sites' products, so reports and admin screens
	 * that check other users never make network requests.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function maybe_sync( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || get_current_user_id() !== $user_id || ! self::is_network_site() ) {
			return;
		}

		$snapshot  = self::get_snapshot( $user_id );
		$synced_at = (int) ( $snapshot['synced_at'] ?? 0 );

		if ( ! $synced_at ) {
			if ( time() - (int) ( $snapshot['attempted_at'] ?? 0 ) >= self::RETRY_AFTER ) {
				self::sync( $user_id );
			}
			return;
		}

		$refresh_due = time() - $synced_at > self::get_refresh_interval();
		$backed_off  = time() - (int) ( $snapshot['attempted_at'] ?? 0 ) >= self::RETRY_AFTER;
		if ( $refresh_due && $backed_off ) {
			self::schedule_refresh( $user_id );
		}
	}

	/**
	 * Refresh a reader's copy in the background, once.
	 *
	 * Action Scheduler (bundled with WooCommerce) queues these in its own table; a WP
	 * cron event per reader would pile up in the autoloaded cron option on a busy site.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function schedule_refresh( $user_id ) {
		$args = [ $user_id ];
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::REFRESH_HOOK, $args, self::REFRESH_GROUP ) ) {
				as_enqueue_async_action( self::REFRESH_HOOK, $args, self::REFRESH_GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::REFRESH_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::REFRESH_HOOK, $args );
		}
	}

	/**
	 * Make the reader's next gated check pull fresh data.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User.
	 * @return void
	 */
	public static function refresh_on_next_check( $user_login, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$snapshot = self::get_snapshot( $user->ID );
		if ( empty( $snapshot ) ) {
			return;
		}
		$snapshot['synced_at']    = 0;
		$snapshot['attempted_at'] = 0;
		update_user_meta( $user->ID, self::META_KEY, $snapshot );
	}

	/**
	 * Pull what the reader holds on the other network sites and store it.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public static function sync( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return new WP_Error( 'newspack_network_reader_access_no_user', __( 'User not found.', 'newspack-network' ) );
		}

		$snapshot                 = self::get_snapshot( $user->ID );
		$snapshot['synced_at']    = (int) ( $snapshot['synced_at'] ?? 0 );
		$snapshot['attempted_at'] = time();
		$snapshot['sites']        = (array) ( $snapshot['sites'] ?? [] );
		update_user_meta( $user->ID, self::META_KEY, $snapshot );

		$result = self::fetch( $user->user_email );
		if ( is_wp_error( $result ) ) {
			Debugger::log( sprintf( 'Reader access: pull for user %d failed: %s', $user->ID, $result->get_error_message() ) );
			update_option( self::LAST_ERROR_OPTION, sprintf( '%s UTC: %s', gmdate( 'Y-m-d H:i' ), $result->get_error_message() ), false );
			return $result;
		}
		if ( false !== get_option( self::LAST_ERROR_OPTION, false ) ) {
			delete_option( self::LAST_ERROR_OPTION );
		}

		self::store( $user->ID, $result );
		return true;
	}

	/**
	 * Store a pull's answer.
	 *
	 * Group seats and orders are replaced outright: the hub is their only source. A
	 * site's owned subscriptions replace what was stored for that site, dropping the
	 * ones it no longer reports; a site the answer doesn't name keeps the records its
	 * subscription events wrote.
	 *
	 * @param int   $user_id User ID.
	 * @param array $sites   Site URL => what the reader holds there.
	 * @return void
	 */
	private static function store( $user_id, $sites ) {
		$subscriptions = get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );
		$subscriptions = is_array( $subscriptions ) ? $subscriptions : [];
		$stored_sites  = [];

		foreach ( $sites as $site => $site_data ) {
			$site_subscriptions = (array) ( $site_data['subscriptions'] ?? [] );
			if ( empty( $site_subscriptions ) ) {
				unset( $subscriptions[ $site ] );
			} else {
				$subscriptions[ $site ] = $site_subscriptions;
			}
			$stored_sites[ $site ] = [
				'groups' => array_values( (array) ( $site_data['groups'] ?? [] ) ),
				'orders' => array_values( (array) ( $site_data['orders'] ?? [] ) ),
			];
		}
		if ( empty( $subscriptions ) ) {
			delete_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY );
		} else {
			update_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, $subscriptions );
		}
		update_user_meta(
			$user_id,
			self::META_KEY,
			[
				'synced_at'    => time(),
				'attempted_at' => time(),
				'sites'        => $stored_sites,
			]
		);
	}

	/**
	 * Ask the hub what the reader holds on every other site.
	 *
	 * @param string $email Reader email.
	 * @return array|WP_Error Site URL => what the reader holds there.
	 */
	private static function fetch( $email ) {
		if ( Site_Role::is_hub() ) {
			return Hub_Endpoint::collect( $email, 0 );
		}
		if ( ! self::is_network_site() ) {
			return new WP_Error( 'newspack_network_reader_access_not_connected', __( 'This site is not connected to a network.', 'newspack-network' ) );
		}

		$request_id = wp_generate_uuid4();
		$response   = Requests::request_to_hub(
			'wp-json/newspack-network/v1/reader-access',
			[
				'site'       => get_bloginfo( 'url' ),
				'email'      => $email,
				'request_id' => $request_id,
			],
			'POST',
			self::HUB_TIMEOUT
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'newspack_network_reader_access_status', sprintf( 'HTTP %d', wp_remote_retrieve_response_code( $response ) ) );
		}

		$payload = Reader_Access_Envelope::open( json_decode( wp_remote_retrieve_body( $response ), true ), Node_Settings::get_secret_key(), $email, $request_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		return array_filter( (array) ( $payload['sites'] ?? [] ), 'is_array' );
	}

	/**
	 * Whether this site can ask the network: a hub, or a node connected to one.
	 *
	 * @return bool
	 */
	private static function is_network_site() {
		return Site_Role::is_hub() || ( Site_Role::is_node() && Node_Settings::get_hub_url() );
	}
}
