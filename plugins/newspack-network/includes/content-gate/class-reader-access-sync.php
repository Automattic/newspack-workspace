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
 * reader, and no event carries orders or group seats, so this site asks the hub
 * instead. It asks on the reader's first gated check with nothing stored, which
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
	 * Seconds the hub may take to answer. The hub asks each node in turn, and a pull
	 * can hold up a page view, so this stays well under the default.
	 */
	const HUB_TIMEOUT = 15;

	/**
	 * Seconds after a failed pull before a gated check tries again, so an unreachable
	 * hub doesn't slow down every page view.
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

		if ( time() - $synced_at > self::get_refresh_interval() && ! wp_next_scheduled( self::REFRESH_HOOK, [ $user_id ] ) ) {
			wp_schedule_single_event( time(), self::REFRESH_HOOK, [ $user_id ] );
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
			return $result;
		}

		self::store( $user->ID, $result['sites'], $result['failed_sites'] );
		return true;
	}

	/**
	 * Store a pull's answers.
	 *
	 * Each site that answered replaces what was stored for it, so records it no longer
	 * reports are dropped. A site that didn't answer keeps what was stored, so a node
	 * being down never takes away access.
	 *
	 * @param int      $user_id      User ID.
	 * @param array    $sites        Site URL => what the reader holds there.
	 * @param string[] $failed_sites Sites that didn't answer.
	 * @return void
	 */
	private static function store( $user_id, $sites, $failed_sites ) {
		$subscriptions = get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );
		$subscriptions = is_array( $subscriptions ) ? $subscriptions : [];
		$previous      = (array) ( self::get_snapshot( $user_id )['sites'] ?? [] );
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
		foreach ( $failed_sites as $site ) {
			if ( isset( $previous[ $site ] ) ) {
				$stored_sites[ $site ] = $previous[ $site ];
			}
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
	 * Ask the network what the reader holds on every other site.
	 *
	 * @param string $email Reader email.
	 * @return array|WP_Error With 'sites' and 'failed_sites'.
	 */
	private static function fetch( $email ) {
		if ( Site_Role::is_hub() ) {
			return Hub_Endpoint::collect( $email );
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
		return [
			'sites'        => array_filter( (array) ( $payload['sites'] ?? [] ), 'is_array' ),
			'failed_sites' => array_filter( (array) ( $payload['failed_sites'] ?? [] ), 'is_string' ),
		];
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
