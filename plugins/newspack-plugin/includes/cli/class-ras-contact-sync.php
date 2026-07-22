<?php
/**
 * CLI tools for the RAS Contact Sync.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Contact_Pull;
use Newspack\Reader_Activation\Sync\Metadata;
use Newspack_Subscription_Migrations\CSV_Importers\CSV_Importer;
use Newspack_Subscription_Migrations\Stripe_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * RAS Contact Sync CLI Class.
 */
class RAS_Contact_Sync {

	/**
	 * Context of the sync.
	 *
	 * @var string
	 */
	protected static $context = 'Contact sync manually triggered via CLI';

	/**
	 * The final results object.
	 *
	 * @var array
	 */
	protected static $results = [
		'processed' => 0,
		'errors'    => 0,
		'skipped'   => 0,
	];

	/**
	 * Record the outcome of a single sync_contact() call in the results tally.
	 *
	 * @param true|\WP_Error $result The value returned by Contact_Sync::sync_contact().
	 */
	protected static function record_result( $result ) {
		if ( \is_wp_error( $result ) ) {
			static::$results['errors']++;
		} else {
			static::$results['processed']++;
		}
	}

	/**
	 * Log to WP CLI.
	 *
	 * @param string $message The message to log.
	 * @param array  $data    Optional. Additional data to log.
	 */
	protected static function log( $message, $data = [] ) {
		WP_CLI::log( $message );
		if ( ! empty( $data ) ) {
			WP_CLI::log(
				wp_json_encode( $data )
			);
		}
	}

	/**
	 * Sync reader contact data to the connected integrations.
	 *
	 * @param array $config {
	 *   Configuration options.
	 *
	 *   @type bool        $config['is_dry_run'] True if a dry run.
	 *   @type bool        $config['active_only'] True if only active subscriptions should be synced.
	 *   @type string|bool $config['migrated_only'] If set, only sync subscriptions migrated from the given source.
	 *   @type array|bool  $config['subscription_ids'] If set, only sync the given subscription IDs.
	 *   @type array|bool  $config['user_ids'] If set, only sync the given user IDs.
	 *   @type array|bool  $config['order_ids'] If set, only sync the given order IDs.
	 *   @type int         $config['batch_size'] Number of contacts to sync per batch.
	 *   @type int         $config['offset'] Number of contacts to skip.
	 *   @type int         $config['max_batches'] Maximum number of batches to process.
	 *   @type bool        $config['is_dry_run'] True if a dry run.
	 *   @type string      $config['context'] Context of the sync.
	 *   @type array       $config['options'] Sync options ( `skip_lists` bool, `fields` string[]|null ).
	 * }
	 *
	 * @return array|\WP_Error Results tally ( `processed`, `errors`, `skipped` ) or WP_Error.
	 */
	private static function sync_contacts( $config ) {
		$default_config = [
			'active_only'      => false,
			'migrated_only'    => false,
			'subscription_ids' => false,
			'user_ids'         => false,
			'order_ids'        => false,
			'batch_size'       => 10,
			'offset'           => 0,
			'max_batches'      => 0,
			'is_dry_run'       => false,
			'context'          => static::$context,
			'options'          => [],
		];
		$config  = \wp_parse_args( $config, $default_config );
		$options = $config['options'];

		// Reset the tally at entry so the counts reflect this run only (the class is
		// static, so a second call in the same process would otherwise accumulate).
		static::$results = [
			'processed' => 0,
			'errors'    => 0,
			'skipped'   => 0,
		];

		static::$context = $config['context'];

		static::log( __( 'Running ESP contact sync...', 'newspack-plugin' ) );

		$can_sync = Contact_Sync::has_one_syncable_integration( true );
		if ( ! $config['is_dry_run'] && $can_sync->has_errors() ) {
			return $can_sync;
		}

		// If syncing only migrated subscriptions.
		if ( $config['migrated_only'] ) {
			$config['subscription_ids'] = self::get_migrated_subscriptions( $config['migrated_only'], $config['batch_size'], $config['offset'], $config['active_only'] );
			if ( \is_wp_error( $config['subscription_ids'] ) ) {
				return $config['subscription_ids'];
			}
			$batches = 0;
		}

		if ( ! empty( $config['subscription_ids'] ) ) {
			static::log( __( 'Syncing by subscription ID...', 'newspack-plugin' ) );

			while ( ! empty( $config['subscription_ids'] ) ) {
				$subscription_id = array_shift( $config['subscription_ids'] );
				$subscription    = \wcs_get_subscription( $subscription_id );

				if ( \is_wp_error( $subscription ) ) {
					static::log(
						sprintf(
							// Translators: %d is the subscription ID arg passed to the script.
							__( 'No subscription with ID %d. Skipping.', 'newspack-plugin' ),
							$subscription_id
						)
					);
					static::$results['skipped']++;

					continue;
				}

				$result = Contact_Sync::sync_contact( $subscription, self::$context, $config['is_dry_run'], $options );
				if ( \is_wp_error( $result ) ) {
					static::log(
						sprintf(
							// Translators: %1$d is the subscription ID arg passed to the script. %2$s is the error message.
							__( 'Error syncing contact info for subscription ID %1$d. %2$s', 'newspack-plugin' ),
							$subscription_id,
							$result->get_error_message()
						)
					);
				}
				static::record_result( $result );

				// Get the next batch.
				if ( $config['migrated_only'] && empty( $config['subscription_ids'] ) ) {
					$batches++;

					if ( $config['max_batches'] && $batches >= $config['max_batches'] ) {
						break;
					}

					$next_batch_offset = $config['offset'] + ( $batches * $config['batch_size'] );
					$config['subscription_ids'] = self::get_migrated_subscriptions( $config['migrated_only'], $config['batch_size'], $next_batch_offset, $config['active_only'] );
				}
			}
		}

		// If order-ids flag is passed, sync contacts for those orders.
		if ( ! empty( $config['order_ids'] ) ) {
			static::log( __( 'Syncing by order ID...', 'newspack-plugin' ) );
			foreach ( $config['order_ids'] as $order_id ) {
				$order = new \WC_Order( $order_id );

				if ( \is_wp_error( $order ) ) {
					static::log(
						sprintf(
							// Translators: %d is the order ID.
							__( 'No order with ID %d. Skipping.', 'newspack-plugin' ),
							$order_id
						)
					);
					static::$results['skipped']++;

					continue;
				}

				$result = Contact_Sync::sync_contact( $order, self::$context, $config['is_dry_run'], $options );
				if ( \is_wp_error( $result ) ) {
					static::log(
						sprintf(
							// Translators: %1$d is the order ID arg passed to the script. %2$s is the error message.
							__( 'Error syncing contact info for order ID %1$d. %2$s', 'newspack-plugin' ),
							$order_id,
							$result->get_error_message()
						)
					);
				}
				static::record_result( $result );
			}
		}

		// If user-ids flag is passed, sync those users.
		if ( ! empty( $config['user_ids'] ) ) {
			static::log( __( 'Syncing by customer user ID...', 'newspack-plugin' ) );
			foreach ( $config['user_ids'] as $user_id ) {
				if ( ! $config['active_only'] || self::user_has_active_subscriptions( $user_id ) ) {
					$result = Contact_Sync::sync_contact( $user_id, self::$context, $config['is_dry_run'], $options );
					if ( \is_wp_error( $result ) ) {
						static::log(
							sprintf(
								// Translators: %1$d is the user ID arg passed to the script. %2$s is the error message.
								__( 'Error syncing contact info for user ID %1$d. %2$s', 'newspack-plugin' ),
								$user_id,
								$result->get_error_message()
							)
						);
					}
					static::record_result( $result );
				} else {
					static::$results['skipped']++;
				}
			}
		}

		// Default behavior: sync all readers.
		if (
			false === $config['user_ids'] &&
			false === $config['order_ids'] &&
			false === $config['subscription_ids'] &&
			false === $config['migrated_only']
		) {
			if ( $config['active_only'] ) {
				static::log( __( 'Syncing all readers with active subscriptions...', 'newspack-plugin' ) );
			} else {
				static::log( __( 'Syncing all readers...', 'newspack-plugin' ) );
			}
			$user_ids = self::get_batch_of_readers( $config['batch_size'], $config['offset'] );
			$batches  = 0;

			while ( $user_ids ) {
				$user_id = array_shift( $user_ids );
				if ( ! $config['active_only'] || self::user_has_active_subscriptions( $user_id ) ) {
					$result = Contact_Sync::sync_contact( $user_id, self::$context, $config['is_dry_run'], $options );
					if ( \is_wp_error( $result ) ) {
						static::log(
							sprintf(
								// Translators: %1$d is the contact's user ID. %2$s is the error message.
								__( 'Error syncing contact info for user ID %1$d. %2$s', 'newspack-plugin' ),
								$user_id,
								$result->get_error_message()
							)
						);
					}
					static::record_result( $result );
				} else {
					static::$results['skipped']++;
				}

				// Get the next batch.
				if ( empty( $user_ids ) ) {
					$batches++;

					if ( $config['max_batches'] && $batches >= $config['max_batches'] ) {
						break;
					}

					self::batch_boundary_pause();
					$user_ids = self::get_batch_of_readers( $config['batch_size'], $config['offset'] + ( $batches * $config['batch_size'] ) );
				}
			}
		}

		return static::$results;
	}

	/**
	 * Pull incoming contact data from integrations for a batch of readers.
	 *
	 * Unlike the organic pull pipeline (Contact_Pull::pull_all()), this batch
	 * driver never schedules ActionScheduler retries: a bulk run against a flaky
	 * API would flood the queue with per-user retry chains. Errors are tallied
	 * and logged; operators re-run the affected --offset window instead. Readers
	 * with pending organic pull retries are still pulled — Reader_Data writes
	 * are idempotent and a comprehensive backfill beats hole-avoidance.
	 *
	 * @param array $config {
	 *   Configuration options.
	 *
	 *   @type bool        $config['active_only'] True to only pull readers with active subscriptions.
	 *   @type array|bool  $config['user_ids'] If set, only pull the given user IDs.
	 *   @type int         $config['batch_size'] Number of readers to query/process at once.
	 *   @type int         $config['offset'] Number of readers to skip.
	 *   @type int         $config['max_batches'] Maximum number of batches to process.
	 *   @type bool        $config['is_dry_run'] True if a dry run (fetch, no persistence).
	 *   @type string|null $config['integration_id'] Only pull from this integration.
	 * }
	 *
	 * @return array|\WP_Error Results tally ( `processed`, `errors`, `skipped` ) or WP_Error.
	 */
	private static function pull_contacts( $config ) {
		$default_config = [
			'active_only'    => false,
			'user_ids'       => false,
			'batch_size'     => 10,
			'offset'         => 0,
			'max_batches'    => 0,
			'is_dry_run'     => false,
			'integration_id' => null,
		];
		$config = \wp_parse_args( $config, $default_config );

		$integrations = Integrations::get_active_configured_integrations();
		if ( ! empty( $config['integration_id'] ) ) {
			$integrations = array_intersect_key( $integrations, [ $config['integration_id'] => true ] );
		}

		// Only integrations with enabled incoming fields can be pulled (matches
		// Contact_Pull::pull_all() semantics); the rest are skipped with a notice.
		// The fields are resolved once per integration here and threaded into
		// every pull: resolution may hit the provider's API on legacy-shaped
		// settings, so re-resolving per reader would multiply external requests.
		$pull_targets = [];
		foreach ( $integrations as $id => $integration ) {
			$fields = $integration->get_enabled_incoming_fields();
			if ( empty( $fields ) ) {
				static::log(
					sprintf(
						// Translators: %s is the integration id.
						__( 'Skipping integration "%s": no enabled incoming fields.', 'newspack-plugin' ),
						$id
					)
				);
				continue;
			}
			$pull_targets[ $id ] = [
				'integration' => $integration,
				'fields'      => $fields,
			];
		}

		if ( empty( $pull_targets ) ) {
			return new \WP_Error(
				'newspack_backfill_no_pull_targets',
				__( 'No active integrations with enabled incoming fields to pull from.', 'newspack-plugin' )
			);
		}

		$tally = [
			'processed' => 0,
			'errors'    => 0,
			'skipped'   => 0,
		];

		if ( ! empty( $config['user_ids'] ) ) {
			static::log( __( 'Pulling by user ID...', 'newspack-plugin' ) );
			foreach ( $config['user_ids'] as $user_id ) {
				self::pull_contact( (int) $user_id, $pull_targets, $config, $tally );
			}
			return $tally;
		}

		static::log( __( 'Pulling all readers...', 'newspack-plugin' ) );
		$user_ids = self::get_batch_of_readers( $config['batch_size'], $config['offset'] );
		$batches  = 0;

		while ( $user_ids ) {
			$user_id = array_shift( $user_ids );
			self::pull_contact( $user_id, $pull_targets, $config, $tally );

			// Get the next batch.
			if ( empty( $user_ids ) ) {
				$batches++;

				if ( $config['max_batches'] && $batches >= $config['max_batches'] ) {
					break;
				}

				self::batch_boundary_pause();
				$user_ids = self::get_batch_of_readers( $config['batch_size'], $config['offset'] + ( $batches * $config['batch_size'] ) );
			}
		}

		return $tally;
	}

	/**
	 * Pull a single reader from every target integration and record the outcome.
	 *
	 * A reader counts as an error if any target integration's pull failed,
	 * mirroring the push leg where a contact is an error if any integration
	 * rejected it.
	 *
	 * @param int   $user_id      WordPress user ID.
	 * @param array $pull_targets Pull targets keyed by integration id: `integration`
	 *                            (the Integration instance) and `fields` (its
	 *                            pre-resolved enabled incoming fields).
	 * @param array $config       Batch configuration (active_only, is_dry_run).
	 * @param array $tally        Results tally, passed by reference.
	 */
	private static function pull_contact( $user_id, $pull_targets, $config, &$tally ) {
		if ( ! \get_userdata( $user_id ) ) {
			static::log(
				sprintf(
					// Translators: %d is the user ID.
					__( 'No user with ID %d. Skipping.', 'newspack-plugin' ),
					$user_id
				)
			);
			$tally['skipped']++;
			return;
		}

		if ( $config['active_only'] && ! self::user_has_active_subscriptions( $user_id ) ) {
			$tally['skipped']++;
			return;
		}

		$errors = 0;
		foreach ( $pull_targets as $id => $target ) {
			$result = Contact_Pull::pull_single_integration( $user_id, $target['integration'], $config['is_dry_run'], $target['fields'] );
			if ( \is_wp_error( $result ) ) {
				static::log(
					sprintf(
						// Translators: 1: integration id, 2: user ID, 3: error message.
						__( 'Error pulling contact data from "%1$s" for user ID %2$d. %3$s', 'newspack-plugin' ),
						$id,
						$user_id,
						$result->get_error_message()
					)
				);
				$errors++;
			}
		}

		if ( $errors ) {
			$tally['errors']++;
		} else {
			$tally['processed']++;
		}
	}

	/**
	 * Inter-batch hygiene for the bulk reader loops (push and pull).
	 *
	 * A long CLI run accumulates every get_userdata() result in the runtime
	 * object cache and fires an unspaced external request stream (one per
	 * reader per integration) — and since pull errors are deliberately not
	 * retried, tripping a provider rate limit turns straight into tallied
	 * errors the operator must re-run. Free the cache and pause for a second
	 * at each batch boundary. The pause costs one second per batch, so large
	 * runs should raise --batch-size to keep the total negligible. No-op
	 * outside a real WP-CLI runtime (the WP_CLI constant is not defined under
	 * PHPUnit), so tests are unaffected.
	 */
	private static function batch_boundary_pause() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		if ( function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
			\WP_CLI\Utils\wp_clear_object_cache();
		}
		sleep( 1 );
	}

	/**
	 * Does the given user have any subscriptions with an active status?
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 */
	private static function user_has_active_subscriptions( $user_id ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return false;
		}
		$subcriptions = array_reduce(
			array_keys( \wcs_get_users_subscriptions( $user_id ) ),
			function( $acc, $subscription_id ) {
				$subscription = \wcs_get_subscription( $subscription_id );
				if ( $subscription->has_status( [ 'active', 'pending', 'pending-cancel' ] ) ) {
					$acc[] = $subscription_id;
				}
				return $acc;
			},
			[]
		);

		return ! empty( $subcriptions );
	}

	/**
	 * Get a batch of migrated subscriptions.
	 *
	 * This method requires the Newspack_Subscription_Migrations plugin to be
	 * installed and active, otherwise it will return a WP_Error.
	 *
	 * @param string $source The source of the subscriptions. One of 'stripe', 'piano-csv', 'stripe-csv'.
	 * @param int    $batch_size Number of subscriptions to get.
	 * @param int    $offset Number to skip.
	 * @param bool   $active_only Whether to get only active subscriptions.
	 *
	 * @return array|\WP_Error Array of subscription IDs or WP_Error.
	 */
	private static function get_migrated_subscriptions( $source, $batch_size, $offset, $active_only ) {
		if (
			! class_exists( '\Newspack_Subscription_Migrations\Stripe_Sync' ) ||
			! class_exists( '\Newspack_Subscription_Migrations\CSV_Importers\CSV_Importer' )
		) {
			return new \WP_Error(
				'newspack_esp_sync_contact',
				__( 'The migrated-subscriptions flag requires the Newspack_Subscription_Migrations plugin to be installed and active.', 'newspack-plugin' )
			);
		}
		$subscription_ids = [];
		switch ( $source ) {
			case 'stripe':
				$subscription_ids = Stripe_Sync::get_migrated_subscriptions( $batch_size, $offset, $active_only );
				break;
			case 'piano-csv':
				$subscription_ids = CSV_Importer::get_migrated_subscriptions( 'piano', $batch_size, $offset, $active_only );
				break;
			case 'stripe-csv':
				$subscription_ids = CSV_Importer::get_migrated_subscriptions( 'stripe', $batch_size, $offset, $active_only );
				break;
			default:
				return new \WP_Error(
					'newspack_esp_sync_contact',
					sprintf(
						// Translators: %s is the source of the subscriptions.
						__( 'Invalid subscription migration type: %s', 'newspack-plugin' ),
						$source
					)
				);
		}
		return $subscription_ids;
	}

	/**
	 * Get a batch of readers' IDs.
	 *
	 * @param int $batch_size Number of readers to get.
	 * @param int $offset     Number to skip.
	 *
	 * @return array|false Array of user IDs, or false if no more to fetch.
	 */
	private static function get_batch_of_readers( $batch_size, $offset = 0 ) {
		$roles = Reader_Activation::get_reader_roles();
		$query = new \WP_User_Query(
			[
				'fields'   => 'ID',
				'number'   => $batch_size,
				'offset'   => $offset,
				'order'    => 'DESC',
				'orderby'  => 'registered',
				'role__in' => $roles,
			]
		);
		$results = $query->get_results();
		return ! empty( $results ) ? $results : false;
	}

	/**
	 * Build the batch-sync config array from CLI associative args.
	 *
	 * Shared by `wp newspack integrations backfill` and the `wp newspack esp sync` alias.
	 *
	 * @param array $assoc_args Associative CLI args.
	 * @return array Batch config for sync_contacts() (sync `options` not included).
	 */
	private static function build_sync_config( $assoc_args ) {
		return [
			'is_dry_run'       => ! empty( $assoc_args['dry-run'] ),
			// `active-subs-only` is the flag on `integrations backfill`; `active-only`
			// is the legacy spelling kept on the `esp sync` alias.
			'active_only'      => ! empty( $assoc_args['active-subs-only'] ) || ! empty( $assoc_args['active-only'] ),
			'migrated_only'    => ! empty( $assoc_args['migrated-subscriptions'] ) ? $assoc_args['migrated-subscriptions'] : false,
			'subscription_ids' => ! empty( $assoc_args['subscription-ids'] ) ? explode( ',', $assoc_args['subscription-ids'] ) : false,
			'user_ids'         => ! empty( $assoc_args['user-ids'] ) ? explode( ',', $assoc_args['user-ids'] ) : false,
			'order_ids'        => ! empty( $assoc_args['order-ids'] ) ? explode( ',', $assoc_args['order-ids'] ) : false,
			'batch_size'       => ! empty( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : 10,
			'offset'           => ! empty( $assoc_args['offset'] ) ? intval( $assoc_args['offset'] ) : 0,
			'max_batches'      => ! empty( $assoc_args['max-batches'] ) ? intval( $assoc_args['max-batches'] ) : 0,
			'context'          => ! empty( $assoc_args['sync-context'] ) ? $assoc_args['sync-context'] : static::$context,
		];
	}

	/**
	 * Format the summary line for one direction's results tally.
	 *
	 * The push wording matches the historical `esp sync` output exactly (a verb
	 * spliced into the shared template) so operator tooling that greps the
	 * summary keeps working. The pull wording is new and carries no such
	 * freeze, so it uses full-sentence strings that translators can reorder.
	 *
	 * @param array  $tally      Results tally ( `processed`, `errors`, `skipped` ).
	 * @param bool   $is_dry_run Whether the run was a dry run.
	 * @param string $direction  Either 'push' or 'pull'.
	 * @return string
	 */
	private static function format_summary( $tally, $is_dry_run, $direction ) {
		if ( 'pull' === $direction ) {
			if ( $is_dry_run ) {
				// Translators: 1: processed count, 2: error count, 3: skipped count.
				$template = __( 'Would pull %1$d contacts (%2$d errors, %3$d skipped).', 'newspack-plugin' );
			} else {
				// Translators: 1: processed count, 2: error count, 3: skipped count.
				$template = __( 'Pulled %1$d contacts (%2$d errors, %3$d skipped).', 'newspack-plugin' );
			}
			return sprintf( $template, $tally['processed'], $tally['errors'], $tally['skipped'] );
		}

		$verb = $is_dry_run ? __( 'Would sync', 'newspack-plugin' ) : __( 'Synced', 'newspack-plugin' );
		return sprintf(
			// Translators: 1: verb (Synced/Would sync), 2: processed count, 3: error count, 4: skipped count.
			__( '%1$s %2$d contacts (%3$d errors, %4$d skipped).', 'newspack-plugin' ),
			$verb,
			$tally['processed'],
			$tally['errors'],
			$tally['skipped']
		);
	}

	/**
	 * Sync Reader Activation contact data to the connected ESP for all customers, migrated subscriptions, or specific customers/subscriptions/orders.
	 *
	 * Legacy alias of `wp newspack integrations backfill` (push direction). New
	 * capabilities (--direction, --integration) live on that command; this alias
	 * keeps the historical flag surface unchanged.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If passed, output results but do not execute the sync. When combined with `--skip-lists`/`--fields`, the preview runs the `newspack_esp_sync_contact` filter for fidelity, so a third-party filter that performs I/O would still run under a dry run.
	 *
	 * [--active-only]
	 * : If passed, only sync users who have active subscriptions, otherwise resync all users.
	 *
	 * [--migrated-subscriptions=<stripe|piano-csv|stripe-csv>]
	 * : If passed, will only query for subscriptions that were migrated via the Newspack Subscription Migrations plugin using the Stripe/Piano CSV importers, or the legacy Stripe migrator. The Newspack Subscription Migrations plugin must be active to use this flag.
	 *
	 * [--subscription-ids=<id1,id2,etc>]
	 * : Comma-delimited list of subscription IDs. If passed, will only process those specific subscriptions.
	 *
	 * [--user-ids=<id1,id2,etc>]
	 * : Comma-delimited list of user IDs. If passed, will only process subscriptions associated with those specific users.
	 *
	 * [--order-ids=<id1,id2,etc>]
	 * : Comma-delimited list of order IDs. If passed, will only process subscriptions associated with those specific orders.
	 *
	 * [--batch-size=<number>]
	 * : Number of subscriptions to query/process at once. Defaults to 10. Each batch boundary pauses for one second, so raise this on large runs (e.g. 500) to keep the added wall time negligible.
	 *
	 * [--max-batches=<number>]
	 * : Maximum number of batches to process.
	 *
	 * [--offset=<number>]
	 * : Offset value passed to the subscription query. Use with `--batch-size` and `--max-batches` to run multiple processes in parallel.
	 *
	 * [--sync-context=<string>]
	 * : Label recorded as the sync context (e.g. in ESP activity logs). Defaults to a generic CLI context.
	 *
	 * [--skip-lists]
	 * : Upsert each contact WITHOUT a master list, so an unsubscribed contact is not resubscribed. Missing contacts are still created (list-less). Use for backfills that must not alter list membership. Honored only by integrations that read the sync options (the built-in ESP integration does); a third-party integration implementing the 3-argument `push_contact_data()` contract will still add to its own lists. Not supported on Mailchimp, which rejects a list-less upsert before writing any metadata — the pre-flight errors out.
	 *
	 * [--fields=<name1,name2>]
	 * : Comma-delimited metadata fields (raw keys or display labels, any case) to sync. Restricts both what is computed and what is pushed to just these fields; all other metadata — and the reader's name — is left untouched. Every requested field must be enabled as an outgoing field on each active integration. The `newspack_esp_sync_contact` filter still runs, but any metadata it adds outside `--fields` is dropped.
	 *
	 * ## NOTES
	 *
	 * When `--skip-lists` or `--fields` is passed, failed pushes are NOT auto-retried
	 * (the retry path would rebuild the full contact and push it with the master list,
	 * undoing the intent). Re-run the affected `--offset` window instead.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_sync_contacts( $args, $assoc_args ) {
		// The alias notice goes to STDERR (warning) so the alias's STDOUT stays
		// byte-identical for operator tooling that pipes or parses it.
		WP_CLI::warning( __( '`wp newspack esp sync` is a legacy alias of `wp newspack integrations backfill`.', 'newspack-plugin' ) );

		$config  = self::build_sync_config( $assoc_args );
		$options = self::parse_sync_options( $assoc_args );
		if ( \is_wp_error( $options ) ) {
			WP_CLI::error( $options->get_error_message() );
			return;
		}
		$config['options'] = $options;

		$results = self::sync_contacts( $config );

		if ( \is_wp_error( $results ) ) {
			WP_CLI::error( $results->get_error_message() );
			return;
		}
		WP_CLI::line( "\n" );
		WP_CLI::success( self::format_summary( $results, $config['is_dry_run'], 'push' ) );
	}

	/**
	 * Backfill reader contact data to and/or from the active integrations.
	 *
	 * Generic successor of `wp newspack esp sync`: pushes reader data out to
	 * integrations, pulls enabled incoming fields back in, or both — optionally
	 * scoped to a single integration.
	 *
	 * ## OPTIONS
	 *
	 * [--direction=<push|pull|both>]
	 * : Sync direction. `push` sends reader data to the integrations (same as the legacy `wp newspack esp sync`); `pull` fetches enabled incoming fields from the integrations into Newspack reader data; `both` runs push then pull. Defaults to `push`.
	 *
	 * [--integration=<id>]
	 * : Restrict the backfill to a single active, configured integration (e.g. `esp`). By default every active, configured integration takes part.
	 *
	 * [--dry-run]
	 * : Output results but do not persist anything. NOTE: a pull dry-run still performs the external API reads (that is what previewing a pull means); it only skips writing reader data. On the push side, combined with `--skip-lists`/`--fields`, the preview runs the `newspack_esp_sync_contact` filter for fidelity.
	 *
	 * [--active-subs-only]
	 * : Only process users who have active WooCommerce subscriptions (statuses: active, pending, pending-cancel). Requires WooCommerce Subscriptions — without it, every reader is skipped. (The legacy `esp sync` alias spells this `--active-only`.)
	 *
	 * [--user-ids=<id1,id2,etc>]
	 * : Comma-delimited list of user IDs to process.
	 *
	 * [--subscription-ids=<id1,id2,etc>]
	 * : (push only) Comma-delimited list of subscription IDs to process.
	 *
	 * [--order-ids=<id1,id2,etc>]
	 * : (push only) Comma-delimited list of order IDs to process.
	 *
	 * [--migrated-subscriptions=<stripe|piano-csv|stripe-csv>]
	 * : (push only) Only process subscriptions migrated via the Newspack Subscription Migrations plugin. That plugin must be active.
	 *
	 * [--batch-size=<number>]
	 * : Number of contacts to query/process at once. Defaults to 10. Each batch boundary pauses for one second, so raise this on large runs (e.g. 500) to keep the added wall time negligible.
	 *
	 * [--max-batches=<number>]
	 * : Maximum number of batches to process.
	 *
	 * [--offset=<number>]
	 * : Offset value passed to the reader/subscription query. Use with `--batch-size` and `--max-batches` to run multiple processes in parallel.
	 *
	 * [--sync-context=<string>]
	 * : Label recorded as the sync context on the push leg (e.g. in ESP activity logs); the pull leg does not record a context. Defaults to a generic CLI context.
	 *
	 * [--skip-lists]
	 * : (push only) Upsert each contact WITHOUT a master list, so an unsubscribed contact is not resubscribed. Not supported on Mailchimp, which rejects a list-less upsert before writing any metadata — the pre-flight errors out.
	 *
	 * [--fields=<name1,name2>]
	 * : (push only) Comma-delimited metadata fields (raw keys or display labels, any case) to sync. Each field must be enabled as an outgoing field on every integration taking part in the run (just the `--integration` target when scoped).
	 *
	 * ## NOTES
	 *
	 * Push-only options hard-error when `--direction` includes `pull` — run a
	 * separate `--direction=push` command for them.
	 *
	 * A direction that includes `pull` also requires at least one in-scope
	 * integration with enabled incoming fields; this is validated in the
	 * pre-flight, before any push work runs.
	 *
	 * Pull failures are NOT auto-retried via ActionScheduler (a bulk run against
	 * a flaky API would flood the queue). Re-run the affected `--offset` window
	 * instead. Push retry behavior is unchanged from `wp newspack esp sync`,
	 * including the no-retry rule for `--skip-lists`/`--fields` runs.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_backfill( $args, $assoc_args ) {
		$backfill = self::parse_backfill_options( $assoc_args );
		if ( \is_wp_error( $backfill ) ) {
			WP_CLI::error( $backfill->get_error_message() );
			return;
		}
		$direction      = $backfill['direction'];
		$integration_id = $backfill['integration_id'];
		$config         = self::build_sync_config( $assoc_args );
		$summaries      = [];

		if ( in_array( $direction, [ 'push', 'both' ], true ) ) {
			$options = self::parse_sync_options( $assoc_args, $integration_id );
			if ( \is_wp_error( $options ) ) {
				WP_CLI::error( $options->get_error_message() );
				return;
			}
			$options['integration_id'] = $integration_id;

			$push_config            = $config;
			$push_config['options'] = $options;

			$push_results = self::sync_contacts( $push_config );
			if ( \is_wp_error( $push_results ) ) {
				WP_CLI::error( $push_results->get_error_message() );
				return;
			}
			$summaries[] = self::format_summary( $push_results, $config['is_dry_run'], 'push' );
		}

		if ( in_array( $direction, [ 'pull', 'both' ], true ) ) {
			static::log( __( 'Running integrations contact pull...', 'newspack-plugin' ) );
			$pull_results = self::pull_contacts(
				[
					'active_only'    => $config['active_only'],
					'user_ids'       => $config['user_ids'],
					'batch_size'     => $config['batch_size'],
					'offset'         => $config['offset'],
					'max_batches'    => $config['max_batches'],
					'is_dry_run'     => $config['is_dry_run'],
					'integration_id' => $integration_id,
				]
			);
			if ( \is_wp_error( $pull_results ) ) {
				WP_CLI::error( $pull_results->get_error_message() );
				return;
			}
			$summaries[] = self::format_summary( $pull_results, $config['is_dry_run'], 'pull' );
		}

		WP_CLI::line( "\n" );
		WP_CLI::success( implode( ' ', $summaries ) );
	}

	/**
	 * Parse and validate the `--skip-lists` / `--fields` options (pre-flight).
	 *
	 * Runs even under `--dry-run` so misconfiguration surfaces before any batch.
	 * When `--fields` is set, tokens are resolved to canonical labels and each must
	 * be enabled as an outgoing field on every active, configured integration —
	 * disabled fields are silently dropped downstream, so a run would otherwise
	 * "succeed" while pushing empty metadata.
	 *
	 * @param array       $assoc_args     Associative CLI args.
	 * @param string|null $integration_id Optional. Restrict the enabled-outgoing-fields
	 *                                    validation to this integration (set when
	 *                                    `--integration` scopes the run).
	 *
	 * @return array|\WP_Error `[ 'skip_lists' => bool, 'fields' => string[]|null ]` or WP_Error.
	 */
	private static function parse_sync_options( $assoc_args, $integration_id = null ): array|\WP_Error {
		$options = [
			'skip_lists' => ! empty( $assoc_args['skip-lists'] ),
			'fields'     => null,
		];

		// Mailchimp cannot do a list-less upsert: its upsert_contact() override
		// returns a "No lists found." WP_Error before writing any merge fields, so a
		// --skip-lists backfill on Mailchimp would push metadata for no one (every
		// contact tallied as an error). Fail the pre-flight with an actionable message
		// rather than letting the whole run fail contact-by-contact.
		if (
			$options['skip_lists'] &&
			class_exists( 'Newspack_Newsletters' ) &&
			'mailchimp' === \Newspack_Newsletters::service_provider()
		) {
			return new \WP_Error(
				'newspack_esp_sync_skip_lists_mailchimp',
				__( 'The --skip-lists option is not supported on Mailchimp: a list-less upsert is rejected before any metadata is written, so no fields would be synced. Mailchimp requires each contact to belong to an audience.', 'newspack-plugin' )
			);
		}

		if ( empty( $assoc_args['fields'] ) ) {
			return $options;
		}

		$labels = Metadata::resolve_field_labels( explode( ',', $assoc_args['fields'] ) );
		if ( \is_wp_error( $labels ) ) {
			return $labels;
		}
		if ( empty( $labels ) ) {
			return new \WP_Error( 'newspack_esp_sync_no_fields', __( 'No valid fields were provided to --fields.', 'newspack-plugin' ) );
		}
		$options['fields'] = $labels;

		// Deliberately fail if ANY active configured integration lacks a requested
		// field: a disabled outgoing field is silently dropped downstream, so a run
		// that "succeeds" while pushing empty metadata to one integration is worse
		// than a hard error the operator can resolve by enabling the field.
		$integrations = Integrations::get_active_configured_integrations();
		if ( ! empty( $integration_id ) ) {
			$integrations = array_intersect_key( $integrations, [ $integration_id => true ] );
		}
		foreach ( $integrations as $id => $integration ) {
			$enabled = $integration->get_enabled_outgoing_fields();
			$missing = array_values( array_diff( $labels, $enabled ) );
			if ( ! empty( $missing ) ) {
				return new \WP_Error(
					'newspack_esp_sync_fields_not_enabled',
					sprintf(
						// Translators: 1: integration id, 2: comma-separated field labels.
						__( 'These fields are not enabled as outgoing fields for integration "%1$s": %2$s. Enable them under Audience > Access control / metadata settings, then re-run.', 'newspack-plugin' ),
						$id,
						implode( ', ', $missing )
					)
				);
			}
		}

		return $options;
	}

	/**
	 * Parse and validate the `--direction` / `--integration` backfill options (pre-flight).
	 *
	 * Runs even under `--dry-run` so misconfiguration surfaces before any batch.
	 * Push-only flags are rejected outright when the direction includes pull —
	 * silently applying them to just the push leg would be surprising; operators
	 * run a separate `--direction=push` command instead.
	 *
	 * When the direction includes pull, also requires at least one in-scope
	 * integration with enabled incoming fields: surfacing that here keeps a
	 * `--direction=both` run from completing a full push before discovering
	 * the pull leg has nothing to do.
	 *
	 * @param array $assoc_args Associative CLI args.
	 *
	 * @return array|\WP_Error `[ 'direction' => 'push'|'pull'|'both', 'integration_id' => string|null ]` or WP_Error.
	 */
	private static function parse_backfill_options( $assoc_args ): array|\WP_Error {
		$direction = isset( $assoc_args['direction'] ) ? (string) $assoc_args['direction'] : 'push';
		if ( ! in_array( $direction, [ 'push', 'pull', 'both' ], true ) ) {
			return new \WP_Error(
				'newspack_backfill_invalid_direction',
				sprintf(
					// Translators: %s is the value passed to --direction.
					__( 'Invalid --direction "%s". Supported values: push, pull, both.', 'newspack-plugin' ),
					$direction
				)
			);
		}

		$integration_id = '';
		if ( isset( $assoc_args['integration'] ) ) {
			// WP-CLI passes a bare `--integration` (no value) as boolean true, which
			// would otherwise cast to the baffling id "1"; an explicit empty value
			// is equally meaningless. Ask for an id instead.
			if ( ! is_string( $assoc_args['integration'] ) || '' === $assoc_args['integration'] ) {
				return new \WP_Error(
					'newspack_backfill_invalid_integration',
					__( '--integration requires an integration id, e.g. --integration=esp.', 'newspack-plugin' )
				);
			}
			$integration_id = $assoc_args['integration'];
		}
		if ( '' !== $integration_id ) {
			$active = Integrations::get_active_configured_integrations();
			if ( ! isset( $active[ $integration_id ] ) ) {
				$available = implode( ', ', array_keys( $active ) );
				return new \WP_Error(
					'newspack_backfill_invalid_integration',
					sprintf(
						// Translators: 1: the integration id passed to --integration, 2: comma-separated list of valid ids.
						__( 'Integration "%1$s" is not active and configured. Active configured integrations: %2$s.', 'newspack-plugin' ),
						$integration_id,
						$available ? $available : __( '(none)', 'newspack-plugin' )
					)
				);
			}
		}

		if ( 'push' !== $direction ) {
			$push_only_flags = [ 'subscription-ids', 'order-ids', 'migrated-subscriptions', 'skip-lists', 'fields' ];
			foreach ( $push_only_flags as $flag ) {
				if ( ! empty( $assoc_args[ $flag ] ) ) {
					return new \WP_Error(
						'newspack_backfill_push_only_flag',
						sprintf(
							// Translators: 1: the push-only flag name, 2: the requested direction.
							__( '--%1$s is a push-only option and cannot be combined with --direction=%2$s. Run a separate --direction=push command for it.', 'newspack-plugin' ),
							$flag,
							$direction
						)
					);
				}
			}

			// Fail fast when the pull leg has no viable target. Without this,
			// --direction=both would complete the entire push leg (real ESP
			// writes, potentially hours) before pull_contacts() surfaced this
			// deterministic, configuration-only error — and WP_CLI::error()
			// would then discard the accumulated push summary.
			$pull_scope = Integrations::get_active_configured_integrations();
			if ( '' !== $integration_id ) {
				$pull_scope = array_intersect_key( $pull_scope, [ $integration_id => true ] );
			}
			$has_pull_target = false;
			foreach ( $pull_scope as $integration ) {
				if ( ! empty( $integration->get_enabled_incoming_fields() ) ) {
					$has_pull_target = true;
					break;
				}
			}
			if ( ! $has_pull_target ) {
				return new \WP_Error(
					'newspack_backfill_no_pull_targets',
					__( 'No active integrations with enabled incoming fields to pull from.', 'newspack-plugin' )
				);
			}
		}

		return [
			'direction'      => $direction,
			'integration_id' => '' !== $integration_id ? $integration_id : null,
		];
	}
}
