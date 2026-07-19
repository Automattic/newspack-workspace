<?php
/**
 * Newspack Network Node integrity check endpoints.
 *
 * @package Newspack
 */

namespace Newspack_Network\Node;

use Newspack_Network\Integrity_Check_Utils;

/**
 * Class that registers the integrity check endpoints for nodes
 */
class Integrity_Check_Endpoints {
	/**
	 * Runs the initialization.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the routes for the integrity check endpoints.
	 */
	public static function register_routes() {
		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/sync-status',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_sync_status_request' ],
					'permission_callback' => [ __CLASS__, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/hash',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_hash_request' ],
					'permission_callback' => [ __CLASS__, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/memberships',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_memberships_request' ],
					'permission_callback' => [ __CLASS__, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/managed-memberships',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_managed_memberships_request' ],
					'permission_callback' => [ __CLASS__, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/range-hash',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_range_hash_request' ],
					'permission_callback' => [ __CLASS__, 'check_permission' ],
					'args'                => [
						'start' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'end'   => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'max'   => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/range-data',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_range_data_request' ],
					'permission_callback' => [ __CLASS__, 'check_permission' ],
					'args'                => [
						'start' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'end'   => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'max'   => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	/**
	 * Handles the hash request.
	 * 
	 * Returns hash for memberships within a specific email range, enabling range-based
	 * chunking that avoids the shifting problem of positional chunks.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_hash_request( $request ) {
		$max_records = $request->get_param( 'max' );
		$membership_data = Integrity_Check_Utils::get_membership_data( $max_records );
		$hash = Integrity_Check_Utils::generate_hash( $membership_data );

		return rest_ensure_response(
			[
				'hash'  => $hash,
				'count' => count( $membership_data ),
			]
		);
	}

	/**
	 * Handles the memberships request.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_memberships_request( $request ) {
		$membership_data = Integrity_Check_Utils::get_membership_data();

		return rest_ensure_response(
			[
				'memberships' => $membership_data,
				'count'       => count( $membership_data ),
			]
		);
	}

	/**
	 * Returns all network-managed memberships.
	 *
	 * Each membership entry includes: email, status, network_id, remote_id,
	 * remote_site_url, post_modified (GMT), and membership_id.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_managed_memberships_request( $request ) {
		global $wpdb;

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_status, p.post_modified_gmt as post_modified,
					u.user_email,
					pm_remote.meta_value as remote_id,
					pm_site.meta_value as remote_site_url,
					pm_network.meta_value as network_id,
					CASE WHEN pm_sub.meta_value IS NOT NULL AND pm_sub.meta_value != '' THEN 1 ELSE 0 END as has_subscription
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
				INNER JOIN {$wpdb->postmeta} pm_managed ON p.ID = pm_managed.post_id AND pm_managed.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_remote ON p.ID = pm_remote.post_id AND pm_remote.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_site ON p.ID = pm_site.post_id AND pm_site.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_network ON p.post_parent = pm_network.post_id AND pm_network.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_sub ON p.ID = pm_sub.post_id AND pm_sub.meta_key = '_subscription_id'
				WHERE p.post_type = 'wc_user_membership'
				AND p.post_status != 'trash'",
				\Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_MANAGED_META_KEY,
				\Newspack_Network\Woocommerce_Memberships\Admin::REMOTE_ID_META_KEY,
				\Newspack_Network\Woocommerce_Memberships\Admin::SITE_URL_META_KEY,
				\Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_ID_META_KEY
			)
		);

		$memberships = [];
		foreach ( $results as $row ) {
			$memberships[] = [
				'email'            => strtolower( $row->user_email ),
				'status'           => $row->post_status,
				'network_id'       => $row->network_id ?? '',
				'remote_id'        => (int) $row->remote_id,
				'remote_site_url'  => $row->remote_site_url ?? '',
				'post_modified'    => $row->post_modified,
				'membership_id'    => (int) $row->ID,
				'has_subscription' => (bool) $row->has_subscription,
			];
		}

		return rest_ensure_response( [ 'memberships' => $memberships ] );
	}

	/**
	 * Handles the range hash request.
	 * 
	 * Returns hash for memberships within a specific email range, enabling range-based
	 * chunking that avoids the shifting problem of positional chunks.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_range_hash_request( $request ) {
		$start_email = strtolower( $request->get_param( 'start' ) );
		$end_email = strtolower( $request->get_param( 'end' ) );
		$max_records = $request->get_param( 'max' );

		$range_data = Integrity_Check_Utils::get_membership_data_range( $start_email, $end_email, $max_records );
		$hash = Integrity_Check_Utils::generate_hash( $range_data );

		return rest_ensure_response(
			[
				'hash'  => $hash,
				'start' => $start_email,
				'end'   => $end_email,
				'count' => count( $range_data ),
			]
		);
	}

	/**
	 * Handles the range data request.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_range_data_request( $request ) {
		$start_email = strtolower( $request->get_param( 'start' ) );
		$end_email = strtolower( $request->get_param( 'end' ) );
		$max_records = $request->get_param( 'max' );

		$range_data = Integrity_Check_Utils::get_membership_data_range( $start_email, $end_email, $max_records );

		return rest_ensure_response(
			[
				'memberships' => $range_data,
				'start'       => $start_email,
				'end'         => $end_email,
				'count'       => count( $range_data ),
			]
		);
	}

	/**
	 * Returns the node's last processed event ID for sync lag detection.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_sync_status_request( $request ) {
		global $wpdb;

		// Collect plan network IDs available on this node.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$plan_network_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE p.post_type = %s AND pm.meta_key = %s
				AND pm.meta_value IS NOT NULL AND pm.meta_value != ''",
				\Newspack_Network\Woocommerce_Memberships\Admin::MEMBERSHIP_PLANS_CPT,
				\Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_ID_META_KEY
			)
		);

		return rest_ensure_response(
			[
				'last_processed_id' => (int) Pulling::get_last_processed_id(),
				'plan_network_ids'  => ! empty( $plan_network_ids ) ? $plan_network_ids : [],
			]
		);
	}

	/**
	 * Permission callback for all integrity check endpoints.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return bool
	 */
	public static function check_permission( $request ) {
		return \Newspack_Network\Rest_Authenticaton::verify_signature( $request, 'integrity-check', Settings::get_secret_key() );
	}
}
