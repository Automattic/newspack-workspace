<?php
/**
 * Newspack Network data for the Subscribers dashboard.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce_Subscriptions;

use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Utils\Sites;
use Newspack_Network\Utils\Users;

/**
 * Supplies the Subscribers dashboard in the Newspack plugin with the subscriptions readers hold on other network sites.
 *
 * The Newspack plugin never reads the network's user meta itself. It asks through three filters, and this class answers them.
 */
class Subscribers_Dashboard {

	/**
	 * How many meta rows to read per query when building the index.
	 *
	 * @var int
	 */
	const INDEX_PAGE_SIZE = 500;

	/**
	 * Runs the initialization.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'newspack_subscribers_network_subscriptions', [ __CLASS__, 'filter_network_subscriptions' ], 10, 2 );
		add_filter( 'newspack_subscribers_network_subscription_index', [ __CLASS__, 'filter_index' ], 10, 2 );
		add_filter( 'newspack_subscribers_network_reader_origin', [ __CLASS__, 'filter_network_reader_origin' ], 10, 2 );
	}

	/**
	 * Add a reader's subscriptions on other network sites to a list of records.
	 *
	 * @param array $records The records so far.
	 * @param int   $user_id The reader's user ID.
	 *
	 * @return array The records, with the reader's network subscriptions added.
	 */
	public static function filter_network_subscriptions( $records, $user_id ) {
		return array_merge( is_array( $records ) ? $records : [], self::get_records_for_user( (int) $user_id ) );
	}

	/**
	 * Add the network subscriptions of the readers who have any to a list of index rows.
	 *
	 * Each row is a subscription record plus the ID of the user it belongs to.
	 *
	 * Nothing here keeps the result, so every call scans again.
	 *
	 * The scan covers users with the subscriptions meta key, not only this site's members, until $max_users of them
	 * have added records. That is harmless: newspack-network has no multisite paths, and on a single site extra rows
	 * exist only for a user removed without wp_delete_user(), which deletes the user's meta.
	 *
	 * @param array $rows      The rows so far.
	 * @param int   $max_users The most users to add rows for.
	 *
	 * @return array The rows, with the network subscriptions added.
	 */
	public static function filter_index( $rows, $max_users ) {
		global $wpdb;

		$rows         = is_array( $rows ) ? $rows : [];
		$max_users    = (int) $max_users;
		$indexed      = 0;
		$last_user_id = 0;

		while ( $indexed < $max_users ) {
			// A bounded, admin-only scan of one meta key, which must not fill the object cache.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC, umeta_id ASC LIMIT %d",
					Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
					$last_user_id,
					self::INDEX_PAGE_SIZE
				)
			);

			foreach ( $meta_rows as $meta_row ) {
				$user_id = (int) $meta_row->user_id;
				// Rows come in user order, so a user's extra rows for the key follow their first one and are skipped.
				// The first is kept because it has the lowest umeta_id, the row get_user_meta() returns for a single
				// value, so the index agrees with get_records_for_user().
				if ( $user_id === $last_user_id ) {
					continue;
				}
				$last_user_id = $user_id;

				$records = self::records_from_meta( maybe_unserialize( $meta_row->meta_value ) );
				if ( empty( $records ) ) {
					continue;
				}
				foreach ( $records as $record ) {
					$rows[] = array_merge( $record, [ 'user_id' => $user_id ] );
				}
				$indexed++;
				if ( $indexed >= $max_users ) {
					break 2;
				}
			}

			// A short page is the last page.
			if ( count( $meta_rows ) < self::INDEX_PAGE_SIZE ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * The network site a reader first signed up on, when that isn't this one.
	 *
	 * @param array|null $origin  The origin so far.
	 * @param int        $user_id The reader's user ID.
	 *
	 * @return array|null The site's name and URL, or the origin passed in when the reader signed up here.
	 */
	public static function filter_network_reader_origin( $origin, $user_id ) {
		$site_url = (string) get_user_meta( $user_id, Users::USER_META_REMOTE_SITE, true );
		if ( '' === $site_url || Sites::is_current_site( $site_url ) ) {
			return $origin;
		}
		return [
			'name' => Sites::get_site_name( $site_url ),
			'url'  => $site_url,
		];
	}

	/**
	 * Get the subscriptions a reader holds on other network sites.
	 *
	 * Every subscription the network recorded is included, donations too. Entries for this site and entries that
	 * aren't shaped like a subscription are left out.
	 *
	 * @param int $user_id The reader's user ID.
	 *
	 * @return array[] Records with id, site_url, site_name, status, plan, edit_url and billing.
	 */
	public static function get_records_for_user( $user_id ) {
		return self::records_from_meta( get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true ), true );
	}

	/**
	 * Turn a reader's subscriptions meta, already unserialized, into records, with the checks get_records_for_user() describes.
	 *
	 * The index leaves billing out: its filters never read it, and it would be carried for every reader.
	 *
	 * @param mixed $meta         The meta value, keyed by site URL.
	 * @param bool  $with_billing Whether to include each subscription's billing details.
	 *
	 * @return array[] Records with id, site_url, site_name, status, plan and edit_url, plus billing when asked for.
	 */
	private static function records_from_meta( $meta, $with_billing = false ) {
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$records = [];
		foreach ( $meta as $site_url => $subscriptions ) {
			$site_url = (string) $site_url;
			if ( '' === $site_url || ! is_array( $subscriptions ) || Sites::is_current_site( $site_url ) ) {
				continue;
			}

			foreach ( $subscriptions as $subscription ) {
				if ( ! is_array( $subscription ) || ! isset( $subscription['id'] ) || ! is_numeric( $subscription['id'] ) || (int) $subscription['id'] <= 0 || ! isset( $subscription['status'] ) || ! is_string( $subscription['status'] ) ) {
					continue;
				}

				$id     = (int) $subscription['id'];
				$record = [
					'id'        => $id,
					'site_url'  => $site_url,
					'site_name' => Sites::get_site_name( $site_url ),
					'status'    => $subscription['status'],
					'plan'      => self::get_plan( $subscription ),
					'edit_url'  => Sites::get_subscription_edit_url( $site_url, $id ),
				];
				if ( $with_billing ) {
					// Subscriptions recorded before billing was stored have none until the next change or a backfill.
					$record['billing'] = isset( $subscription['billing'] ) && is_array( $subscription['billing'] ) ? $subscription['billing'] : [];
				}
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Get a subscription's plan, which is the names of its products.
	 *
	 * @param array $subscription The subscription as the network recorded it.
	 *
	 * @return string The product names, separated by commas.
	 */
	private static function get_plan( $subscription ) {
		$products = $subscription['products'] ?? [];
		if ( ! is_array( $products ) ) {
			return '';
		}

		$names = [];
		foreach ( $products as $product ) {
			if ( is_array( $product ) && isset( $product['name'] ) && is_string( $product['name'] ) && '' !== $product['name'] ) {
				$names[] = $product['name'];
			}
		}

		return implode( ', ', $names );
	}
}
