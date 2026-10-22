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
	 * How many users to load per page when building the index.
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
		add_filter( 'newspack_subscribers_is_network_reader', [ __CLASS__, 'filter_is_network_reader' ], 10, 2 );
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
	 * @param array $rows      The rows so far.
	 * @param int   $max_users The most users to add rows for.
	 *
	 * @return array The rows, with the network subscriptions added.
	 */
	public static function filter_index( $rows, $max_users ) {
		$rows      = is_array( $rows ) ? $rows : [];
		$max_users = (int) $max_users;
		$indexed   = 0;
		$page      = 1;

		while ( $indexed < $max_users ) {
			$user_ids = array_map(
				'intval',
				get_users(
					[
						'meta_key'     => Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_compare' => 'EXISTS',
						'fields'       => 'ID',
						'number'       => self::INDEX_PAGE_SIZE,
						'paged'        => $page,
						'orderby'      => 'ID',
					]
				)
			);

			// Load every user's meta in one query, so reading it per user below costs nothing.
			update_meta_cache( 'user', $user_ids );

			foreach ( $user_ids as $user_id ) {
				$records = self::get_records_for_user( $user_id );
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
			if ( count( $user_ids ) < self::INDEX_PAGE_SIZE ) {
				break;
			}
			$page++;
		}

		return $rows;
	}

	/**
	 * Whether a reader belongs to the network, which is the case if they first registered on another site.
	 *
	 * @param bool $is      Whether the reader is a network reader so far.
	 * @param int  $user_id The reader's user ID.
	 *
	 * @return bool
	 */
	public static function filter_is_network_reader( $is, $user_id ) {
		return $is || '' !== (string) get_user_meta( $user_id, Users::USER_META_REMOTE_SITE, true );
	}

	/**
	 * Get the subscriptions a reader holds on other network sites.
	 *
	 * Every subscription the network recorded is included, donations too. Entries for this site and entries that
	 * aren't shaped like a subscription are left out.
	 *
	 * @param int $user_id The reader's user ID.
	 *
	 * @return array[] Records with id, site_url, site_name, status, plan and edit_url.
	 */
	public static function get_records_for_user( $user_id ) {
		$meta = get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );
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
				if ( ! is_array( $subscription ) || ! isset( $subscription['id'] ) || ! is_numeric( $subscription['id'] ) || ! isset( $subscription['status'] ) || ! is_string( $subscription['status'] ) ) {
					continue;
				}

				$id        = (int) $subscription['id'];
				$records[] = [
					'id'        => $id,
					'site_url'  => $site_url,
					'site_name' => Sites::get_site_name( $site_url ),
					'status'    => $subscription['status'],
					'plan'      => self::get_plan( $subscription ),
					'edit_url'  => Sites::get_subscription_edit_url( $site_url, $id ),
				];
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
