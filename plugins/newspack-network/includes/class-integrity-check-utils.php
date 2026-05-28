<?php
/**
 * Integrity Check utility functions.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use Newspack_Network\Woocommerce_Memberships\Admin as Memberships_Admin;

/**
 * Shared utility functions for integrity check operations.
 */
class Integrity_Check_Utils {

	/**
	 * Build the base membership query
	 *
	 * @param string|null $start_email Optional start email for range filtering.
	 * @param string|null $end_email Optional end email for range filtering.
	 * @return string The SQL query string
	 */
	private static function build_membership_query( $start_email = null, $end_email = null ) {
		global $wpdb;

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		$query = "
			SELECT 
				u.user_email,
				p.post_status as status,
				pm_network.meta_value as network_id
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
			INNER JOIN {$wpdb->postmeta} pm_network ON p.post_parent = pm_network.post_id AND pm_network.meta_key = %s
			INNER JOIN (
				SELECT 
					p2.post_author,
					pm2.meta_value,
					MAX(p2.post_date) as max_date
				FROM {$wpdb->posts} p2
				INNER JOIN {$wpdb->postmeta} pm2 ON p2.post_parent = pm2.post_id AND pm2.meta_key = %s
				WHERE p2.post_type = 'wc_user_membership'
				AND pm2.meta_value IS NOT NULL
				AND pm2.meta_value != ''
				GROUP BY p2.post_author, pm2.meta_value
			) latest ON p.post_author = latest.post_author 
				AND pm_network.meta_value = latest.meta_value 
				AND p.post_date = latest.max_date
			WHERE p.post_type = 'wc_user_membership'
			AND pm_network.meta_value IS NOT NULL
			AND pm_network.meta_value != ''";

		// Add range filtering if provided.
		if ( $start_email !== null && $end_email !== null ) {
			$query .= '
			AND LOWER(u.user_email) >= %s
			AND LOWER(u.user_email) <= %s';
		}

		$query .= '
			ORDER BY LOWER(u.user_email) ASC';
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users

		return $query;
	}

	/**
	 * Execute membership query and format results
	 *
	 * @param string   $query The SQL query.
	 * @param array    $prepare_args Arguments for wpdb->prepare.
	 * @param int|null $max_records Maximum number of records to return.
	 * @return array Array of (email, status, network_id) data
	 */
	private static function execute_membership_query( $query, $prepare_args, $max_records = null ) {
		global $wpdb;

		if ( $max_records ) {
			$query .= $wpdb->prepare( ' LIMIT %d', $max_records );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, ...$prepare_args ) );

		$membership_data = [];
		foreach ( $results as $result ) {
			$membership_data[] = [
				'email'      => strtolower( $result->user_email ),
				'status'     => $result->status,
				'network_id' => $result->network_id,
			];
		}

		return $membership_data;
	}

	/**
	 * Get all membership data
	 *
	 * @param int|null $max_records Maximum number of records to return (for testing).
	 * @return array Array of (email, status, network_id) data
	 */
	public static function get_membership_data( $max_records = null ) {
		if ( ! class_exists( 'WC_Memberships_User_Membership' ) ) {
			return [];
		}

		$query = self::build_membership_query();
		$prepare_args = [
			Memberships_Admin::NETWORK_ID_META_KEY,
			Memberships_Admin::NETWORK_ID_META_KEY,
		];

		return self::execute_membership_query( $query, $prepare_args, $max_records );
	}

	/**
	 * Get membership data within an email range
	 *
	 * Filters memberships by email address range rather than positional offset.
	 * This enables range-based chunking that's resilient to data shifts when
	 * memberships are added/removed from the beginning or middle of the dataset.
	 *
	 * @param string   $start_email The start email (inclusive).
	 * @param string   $end_email The end email (inclusive).
	 * @param int|null $max_records Maximum number of records to return (for testing).
	 * @return array Array of (email, status, network_id) data
	 */
	public static function get_membership_data_range( $start_email, $end_email, $max_records = null ) {
		if ( ! class_exists( 'WC_Memberships_User_Membership' ) ) {
			return [];
		}

		$query = self::build_membership_query( $start_email, $end_email );
		$prepare_args = [
			Memberships_Admin::NETWORK_ID_META_KEY,
			Memberships_Admin::NETWORK_ID_META_KEY,
			$start_email,
			$end_email,
		];

		return self::execute_membership_query( $query, $prepare_args, $max_records );
	}

	/**
	 * Generate a hash from membership data
	 *
	 * @param array $data Array of (email, status, network_id) data.
	 * @return string SHA-256 hash
	 */
	public static function generate_hash( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		// Create a string representation of the data for hashing.
		$hash_string = '';
		foreach ( $data as $item ) {
			$hash_string .= $item['email'] . ':' . $item['status'] . ':' . $item['network_id'] . "\n";
		}

		return hash( 'sha256', $hash_string );
	}

	/**
	 * Filter membership data by email range
	 *
	 * @param array  $data Membership data.
	 * @param string $start_email Start email (inclusive).
	 * @param string $end_email End email (inclusive).
	 * @return array Filtered data
	 */
	public static function filter_data_by_range( $data, $start_email, $end_email ) {
		$filtered = [];
		$start_email = strtolower( $start_email );
		$end_email = strtolower( $end_email );
		
		foreach ( $data as $item ) {
			$email = strtolower( $item['email'] );
			if ( $email >= $start_email && $email <= $end_email ) {
				$filtered[] = $item;
			}
		}
		return $filtered;
	}
}
