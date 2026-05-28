<?php
/**
 * WooCommerce Memberships Import/Export.
 *
 * @package Newspack
 */

namespace Newspack\Memberships;

/**
 * WooCommerce Memberships Import/Export class.
 */
class Import_Export {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'wc_memberships_csv_import_user_memberships_data', [ __CLASS__, 'process_imported_data' ], 10, 5 );
		add_action( 'wc_memberships_csv_import_user_membership', [ __CLASS__, 'handle_membership_import' ], 10, 4 );
	}

	/**
	 * Is the woocommerce-memberships-for-teams plugin active?
	 */
	public static function is_wc_teams_active(): bool {
		return class_exists( 'SkyVerge\WooCommerce\Memberships\Teams\Team' );
	}

	/**
	 * Filter CSV User Membership import data before processing an import.
	 *
	 * @param array     $import_data the imported data as associative array.
	 * @param string    $action either 'create' or 'merge' (update) a User Membership.
	 * @param array     $columns CSV columns raw data.
	 * @param array     $row CSV row raw data.
	 * @param \stdClass $job import job.
	 */
	public static function process_imported_data( $import_data, $action, $columns, $row, $job ) {
		if ( ! self::is_wc_teams_active() ) {
			return $import_data;
		}
		foreach ( $columns as $column_name ) {
			if ( stripos( $column_name, 'team_' ) !== false && isset( $row[ $column_name ] ) ) {
				$import_data[ $column_name ] = $row[ $column_name ];
			}
		}
		return $import_data;
	}

	/**
	 * Fires upon creating or updating a User Membership from import data.
	 *
	 * @param \WC_Memberships_User_Membership $user_membership User Membership object.
	 * @param string                          $action either 'create' or 'merge' (update) a User Membership.
	 * @param array                           $data import data.
	 * @param \stdClass                       $job import job.
	 */
	public static function handle_membership_import( $user_membership, $action, $data, $job ) {
		if ( ! self::is_wc_teams_active() ) {
			return;
		}
		if ( isset(
			$data['user_id'],
			$data['team_id'],
			$data['team_name'],
			$data['team_role']
		) ) {
			$team = false;
			try {
				$team = new \SkyVerge\WooCommerce\Memberships\Teams\Team( $data['team_id'] );
			} catch ( \Throwable $th ) { // phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fail silently.
			}
			$user_id = $data['user_id'];
			if ( ! $team ) {
				// Create the team.
				try {
					$team = \wc_memberships_for_teams_create_team(
						[
							'owner_id'   => $user_id,
							'plan_id'    => $data['membership_plan_id'],
							'product_id' => $data['product_id'],
							'order_id'   => $data['order_id'],
							'name'       => $data['team_name'],
						]
					);
				} catch ( \Throwable $th ) { // phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					return;
				}
			}
			$member_added = false;
			try {
				$team->add_member( $user_id, $data['team_role'] );
				$member_added = true;
			} catch ( \Throwable $th ) {
				// Fail silently.
			}
		}
	}
}
Import_Export::init();
