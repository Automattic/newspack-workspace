<?php
/**
 * Data Backfill scripts.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use WP_CLI;

/**
 * Data Backfill class.
 */
class Data_Backfill {
	/**
	 * The final results object.
	 *
	 * @var array
	 */
	private static $results = [];

	/**
	 * WP_CLI progress handler.
	 *
	 * @var class
	 */
	public static $progress = false;

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
			WP_CLI::add_command( 'newspack-network data-backfill', [ __CLASS__, 'data_backfill' ] );
		}
	}

	/**
	 * Increment the results counter.
	 *
	 * @param string $action The action.
	 * @param string $counter The counter.
	 */
	public static function increment_results_counter( $action, $counter ) {
		if ( ! isset( self::$results[ $action ] ) ) {
			self::$results[ $action ] = [
				'processed' => 0,
				'duplicate' => 0,
				'skipped'   => 0,
			];
		}
		self::$results[ $action ][ $counter ]++;
	}

	/**
	 * Backfill data for a specific action.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : The action to backfill. Choose "all" to backfill all actions.
	 *
	 * [--start=<start>]
	 * : The start date for the backfill.
	 *
	 * [--end=<end>]
	 * : The end date for the backfill.
	 *
	 * [--live]
	 * : Run the backfill in live mode, which will process the events.
	 *
	 * [--verbose]
	 * : Output more verbose information.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network data-backfill reader_registered --start=2020-01-01 --end=2020-12-31 --live
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command options.
	 * @return void
	 */
	public static function data_backfill( $args, $assoc_args ) { // phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle
		$action = $args[0];
		$start = isset( $assoc_args['start'] ) ? $assoc_args['start'] : null;
		$end = isset( $assoc_args['end'] ) ? $assoc_args['end'] : null;
		$live = isset( $assoc_args['live'] ) ? true : false;
		$verbose = isset( $assoc_args['verbose'] ) ? true : false;

		WP_CLI::line( '' );

		if ( $action !== 'all' && ! in_array( $action, array_keys( Accepted_Actions::ACTIONS ) ) ) {
			WP_CLI::error( 'Invalid action.' );
		}

		$is_hub = Site_Role::is_hub();
		$is_node = Site_Role::is_node();
		if ( ! $is_hub && ! $is_node ) {
			WP_CLI::error( 'This command can only be run on a Hub or Node site.' );
		}

		if ( $is_hub ) {
			WP_CLI::line( 'Running on a Hub site – will create events for the event log.' );
		} else {
			WP_CLI::line( 'Running on a Node site – will create webhook requests. This may result in duplicate requests if the request queue was cleared already, but the Hub will handle the duplicates.' );
		}
		WP_CLI::line( '' );

		if ( $live ) {
			WP_CLI::line( '⚡️ Heads up! Running live, data will be updated.' );
		} else {
			WP_CLI::line( 'Running in dry-run mode, data will not be updated. Use --live flag to run in live mode.' );
		}

		WP_CLI::line( '' );
		if ( $action === 'all' ) {
			WP_CLI::line( sprintf( 'Backfilling data for all supported actions, from %s to %s.', $start, $end ) );
		} else {
			WP_CLI::line( sprintf( 'Backfilling data for action %s, from %s to %s.', $action, $start, $end ) );
		}
		WP_CLI::line( '' );

		if ( 'all' === $action ) {
			$actions = array_keys( Accepted_Actions::ACTIONS );
		} else {
			$actions = [ $action ];
		}

		foreach ( $actions as $action_name ) {
			$class_name = 'Newspack_Network\\Backfillers\\' . Accepted_Actions::ACTIONS[ $action_name ];
			if ( ! class_exists( $class_name ) ) {
				if ( 'all' !== $action ) {
					WP_CLI::error( sprintf( 'Backfilling data for %s is not supported yet.', $action_name ) );
				}
				continue;
			}
			$backfiller = new $class_name( $start, $end, $live, $verbose );
			$backfiller->process_events();
		}

		if ( self::$progress ) {
			self::$progress->finish();
		}

		if ( $live ) {
			WP_CLI::line( '' );
			foreach ( self::$results as $action_key => $result ) {
				WP_CLI::success(
					sprintf(
						'Processed %d %s events, ignored %d as duplicates and skipped %d.',
						$result['processed'],
						$action_key,
						$result['duplicate'],
						$result['skipped']
					)
				);
			}
		}
		WP_CLI::line( '' );
	}
}
