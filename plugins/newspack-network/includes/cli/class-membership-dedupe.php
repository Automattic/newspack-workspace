<?php
/**
 * Membership De-Duplication scripts.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use WP_CLI;

/**
 * Membership De-Duplication class.
 */
class Membership_Dedupe {

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_commands' ] );
	}

	/**
	 * Register the WP-CLI commands
	 *
	 * @return void
	 */
	public static function register_commands() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command(
				'newspack-network clean-up-duplicate-memberships',
				[ __CLASS__, 'clean_duplicate_memberships' ],
				[
					'shortdesc' => __( 'Clean up users with multiple of the same membership' ),
					'synopsis'  => [
						[
							'type'     => 'assoc',
							'name'     => 'plan-id',
							'optional' => true,
						],
						[
							'type'     => 'flag',
							'name'     => 'live',
							'optional' => true,
						],
						[
							'type'     => 'flag',
							'name'     => 'csv',
							'optional' => true,
						],
					],
				]
			);
		}
	}

	/**
	 * Handler for the CLI command.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network clean-up-duplicate-memberships --plan-id=1234
	 *
	 * ## OPTIONS
	 *
	 * [--plan-id]
	 * : Membership plan ID to clean up. If not set, all synchronized plans will be cleaned up.
	 *
	 * [--live]
	 * : Run the command in live mode, updating the users.
	 *
	 * [--csv]
	 * : Output CSV.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args and flags.
	 */
	public static function clean_duplicate_memberships( $args, $assoc_args ) {
		WP_CLI::line( '' );

		$live = isset( $assoc_args['live'] );
		if ( ! $live ) {
			WP_CLI::line( 'Running in dry-run mode. Use --live flag to run in live mode.' );
			WP_CLI::line( '' );
		}

		$csv = isset( $assoc_args['csv'] );

		$plan_id_from_args = isset( $assoc_args['plan-id'] ) ? $assoc_args['plan-id'] : null;
		$plan_ids = [];
		if ( $plan_id_from_args ) {
			if ( ! is_numeric( $plan_id_from_args ) ) {
				WP_CLI::error( 'Membership plan ID must be numeric' );
			}
			$plan_ids = [ (int) $plan_id_from_args ];
		} else {
			// Get all network-sync'd membership plans.
			$plan_ids = get_posts(
				[
					'post_type'  => 'wc_membership_plan',
					'fields'     => 'ids',
					'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'     => \Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_ID_META_KEY,
							'compare' => 'EXISTS',
						],
					],
				]
			);
		}

		$user_ids = [];
		foreach ( $plan_ids as $plan_id ) {
			WP_CLI::line( sprintf( 'Checking plan #%d', $plan_id ) );

			$user_ids = array_merge( $user_ids, self::get_users_with_duplicate_membership( $plan_id ) );
			WP_CLI::line( sprintf( '%d users found with duplicate memberships', count( $user_ids ) ) );

			$duplicates = [];
			foreach ( $user_ids as $user_id ) {
				$memberships = get_posts(
					[
						'author'      => $user_id,
						'post_type'   => 'wc_user_membership',
						'post_status' => 'any',
						'post_parent' => $plan_id,
					]
				);

				foreach ( $memberships as $membership ) {
					$user = get_user_by( 'id', $membership->post_author );
					if ( $user === false ) {
						continue;
					}
					$duplicates[] = [
						'user'         => $membership->post_author,
						'email'        => $user->user_email,
						'membership'   => $membership->ID,
						'subscription' => get_post_meta( $membership->ID, '_subscription_id', true ),
						'status'       => $membership->post_status,
						'remote'       => get_post_meta( $membership->ID, '_remote_site_url', true ),
					];
				}
			}

			if ( $csv && ! empty( $duplicates ) ) {
				WP_CLI::line( 'COPY AND PASTE THIS CSV: ' );
				WP_CLI::line();
				WP_CLI\Utils\format_items( 'csv', $duplicates, array_keys( $duplicates[0] ) );
				WP_CLI::line();
			}

			self::deduplicate_memberships( $duplicates, $live );
			WP_CLI::line( '' );
		}

		WP_CLI::success( 'Done' );
		WP_CLI::line( '' );
	}

	/**
	 * Find users that have duplicate memberships.
	 *
	 * @param int $plan_id WC Memberships membership plan ID.
	 * @return array Array of user IDs that have more than one membership of the input plan.
	 */
	private static function get_users_with_duplicate_membership( $plan_id ) {
		global $wpdb;

		$query_results = $wpdb->get_results( $wpdb->prepare( "SELECT count(*), post_author FROM $wpdb->posts WHERE post_type = 'wc_user_membership' AND post_parent = %d GROUP BY post_author", $plan_id ), ARRAY_A ); // phpcs:ignore

		$users_with_duplicates = [];
		foreach ( $query_results as $query_result ) {
			if ( (int) $query_result['count(*)'] > 1 ) {
				$users_with_duplicates[] = $query_result['post_author'];
			}
		}

		return $users_with_duplicates;
	}

	/**
	 * De-duplicate memberships so that users only have one membership of a plan.
	 *
	 * @param array $duplicates Analyzed data from ::clean_duplicate_memberships.
	 * @param bool  $live Whether to actually delete the duplicates.
	 */
	private static function deduplicate_memberships( $duplicates, $live ) {
		if ( $live ) {
			WP_CLI::line( 'Deleting duplicates' );
		}
		$userdata = [];

		foreach ( $duplicates as $duplicate ) {
			if ( ! isset( $userdata[ $duplicate['email'] ] ) ) {
				$userdata[ $duplicate['email'] ] = [];
			}

			$userdata[ $duplicate['email'] ][] = $duplicate;
		}

		foreach ( $userdata as $email => $duplicates ) {
			WP_CLI::line( sprintf( 'Processing %s', $email ) );
			if ( count( $duplicates ) < 2 ) {
				WP_CLI::line( '  - User has multiple memberships, but no duplicates' );
			}

			$memberships_to_delete = array_slice( $duplicates, 1 );
			foreach ( $memberships_to_delete as $duplicate ) {
				if ( $live ) {
					wp_delete_post( $duplicate['membership'], true );
					WP_CLI::line( sprintf( '  - Deleted extra membership %d', $duplicate['membership'] ) );
				} else {
					WP_CLI::line( sprintf( '  - Would have deleted extra membership %d', $duplicate['membership'] ) );
				}
			}
		}
	}
}
