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
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Contact_Pull;
use Newspack\Reader_Activation\Integrations\ESP;
use Newspack\Reader_Activation\Sync\Metadata;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Content_Gate as Content_Gate_Metadata;
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
	 * Number of contacts that must hit an external provider before the next
	 * inter-batch pause. See batch_boundary_pause().
	 *
	 * @var int
	 */
	const PAUSE_EVERY_CONTACTS = 100;

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
	 * Contacts that performed external work since the last inter-batch pause.
	 *
	 * @var int
	 */
	protected static $unpaused_contacts = 0;

	/**
	 * Record the outcome of a single sync_contact() call in the results tally.
	 *
	 * A wet-run contact reached the integrations, so it counts toward the
	 * inter-batch pacing regardless of whether the push succeeded. A dry-run
	 * push short-circuits before any integration is called, so pacing it would
	 * only slow the preview without spacing any external request.
	 *
	 * @param true|\WP_Error $result     The value returned by Contact_Sync::sync_contact().
	 * @param bool           $is_dry_run Whether the run is a dry run (no provider traffic on push).
	 */
	protected static function record_result( $result, $is_dry_run = false ) {
		if ( ! $is_dry_run ) {
			static::$unpaused_contacts++;
		}
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
	 *   @type string      $config['context'] Context of the sync.
	 *   @type array       $config['options'] Sync options ( `skip_lists` bool, `fields`
	 *                     string[]|null, `integration_id` string|null to scope the push
	 *                     fan-out to a single integration ).
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
		static::$unpaused_contacts = 0;

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
				static::record_result( $result, $config['is_dry_run'] );

				// Get the next batch.
				if ( $config['migrated_only'] && empty( $config['subscription_ids'] ) ) {
					$batches++;

					if ( $config['max_batches'] && $batches >= $config['max_batches'] ) {
						break;
					}

					self::batch_boundary_pause();
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
				static::record_result( $result, $config['is_dry_run'] );
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
					static::record_result( $result, $config['is_dry_run'] );
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
					static::record_result( $result, $config['is_dry_run'] );
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
	private static function pull_contacts( $config ): array|\WP_Error {
		$default_config = [
			'active_only'              => false,
			'user_ids'                 => false,
			'batch_size'               => 10,
			'offset'                   => 0,
			'max_batches'              => 0,
			'is_dry_run'               => false,
			'integration_id'           => null,
			'resolved_incoming_fields' => [],
		];
		$config = \wp_parse_args( $config, $default_config );

		$integrations = Integrations::get_active_configured_integrations();
		if ( ! empty( $config['integration_id'] ) ) {
			$integrations = array_intersect_key( $integrations, [ $config['integration_id'] => true ] );
		}

		// Only integrations with an enabled pull and enabled incoming fields can be
		// pulled (matches Contact_Pull::pull_all() semantics); the rest are skipped
		// with a notice. The fields are resolved once per integration here and
		// threaded into every pull: resolution may hit the provider's API on
		// legacy-shaped settings, so re-resolving per reader would multiply
		// external requests.
		$pull_targets = [];
		foreach ( $integrations as $id => $integration ) {
			// Checked before resolving fields, which may itself call the provider.
			if ( ! $integration->is_pull_enabled() ) {
				static::log(
					sprintf(
						// Translators: 1: integration id, 2: the reason it is skipped.
						__( 'Skipping integration "%1$s": %2$s.', 'newspack-plugin' ),
						$id,
						$integration->supports_pull()
							? __( 'inbound sync is paused', 'newspack-plugin' )
							: __( 'integration does not support inbound sync', 'newspack-plugin' )
					)
				);
				continue;
			}

			// Reuse the pre-flight's resolution when the run came through
			// cli_backfill(): the pre-flight already paid this integration's
			// possible provider round-trip, so the run must not ask twice.
			$fields = array_key_exists( $id, $config['resolved_incoming_fields'] )
				? $config['resolved_incoming_fields'][ $id ]
				: $integration->get_enabled_incoming_fields();
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
				__( 'No active integrations with inbound sync enabled and incoming fields selected to pull from.', 'newspack-plugin' )
			);
		}

		$tally = [
			'processed' => 0,
			'errors'    => 0,
			'skipped'   => 0,
		];
		static::$unpaused_contacts = 0;

		if ( ! empty( $config['user_ids'] ) ) {
			static::log( __( 'Pulling by user ID...', 'newspack-plugin' ) );
			// Chunked like the all-readers loop so a very large --user-ids list gets
			// the same cache flush and provider pacing rather than hydrating every
			// user object into a cache that is never freed.
			$chunks     = array_chunk( $config['user_ids'], max( 1, (int) $config['batch_size'] ) );
			$last_chunk = count( $chunks ) - 1;
			foreach ( $chunks as $index => $chunk ) {
				foreach ( $chunk as $user_id ) {
					self::pull_contact( (int) $user_id, $pull_targets, $config, $tally );
				}
				// The pause spaces out requests still to come, so there is nothing to
				// space after the final chunk — sleeping there is pure wall-clock
				// waste on work already finished.
				if ( $index < $last_chunk ) {
					self::batch_boundary_pause();
				}
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
	private static function pull_contact( $user_id, $pull_targets, $config, &$tally ): void {
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

		// The reader is about to hit every target integration's provider, so it
		// counts toward the inter-batch pacing.
		static::$unpaused_contacts++;

		$errors    = 0;
		$not_found = 0;
		// Shared across this reader's integrations so a dry run accounts for the
		// keys earlier targets would have written. A wet run gets that for free —
		// the writes are real — so without this the preview would under-report a
		// reader that only crosses the key cap once every integration's fields
		// are counted together.
		$pending_keys = [];

		foreach ( $pull_targets as $id => $target ) {
			$result = Contact_Pull::pull_single_integration( $user_id, $target['integration'], $config['is_dry_run'], $target['fields'], $pending_keys );
			if ( \is_wp_error( $result ) ) {
				// The provider not knowing this reader is not a failure: no re-run
				// can make an absent contact appear, so tallying it as an error
				// would flip the exit status on exactly the partially-synced sites
				// a backfill exists for. Mirror the push leg's missing-entity skips.
				if ( Integration::CONTACT_NOT_FOUND_ERROR_CODE === $result->get_error_code() ) {
					$not_found++;
					static::log(
						sprintf(
							// Translators: 1: user ID, 2: integration id.
							__( 'No contact for user ID %1$d at "%2$s". Skipping.', 'newspack-plugin' ),
							$user_id,
							$id
						)
					);
					continue;
				}
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
		} elseif ( $not_found && count( $pull_targets ) === $not_found ) {
			// Every target came up empty-handed: no integration knows this reader.
			$tally['skipped']++;
		} else {
			$tally['processed']++;
		}
	}

	/**
	 * Inter-batch hygiene for the bulk contact loops (push and pull).
	 *
	 * A long CLI run accumulates every get_userdata() result in the runtime
	 * object cache and fires an unspaced external request stream (one per
	 * contact per integration) — and since pull errors are deliberately not
	 * retried, tripping a provider rate limit turns straight into tallied
	 * errors the operator must re-run. Free the cache at every batch boundary
	 * (cheap, and what keeps memory flat), but pace the external request
	 * stream on the work actually done rather than on the batch count: sleep
	 * one second for every PAUSE_EVERY_CONTACTS contacts that have hit an
	 * external provider since the last pause, carrying any remainder forward.
	 *
	 * Tying the pause to contacts rather than batches keeps its cost strictly
	 * proportional to provider traffic and independent of --batch-size: one
	 * second per 100 contacts whether the operator runs batches of 10 or 500.
	 * On a 100k-reader site that is ~17 minutes of added wall time rather than
	 * the ~2.8 hours a per-batch pause would cost at the historical default of
	 * 10 — so the legacy `esp sync` alias stays usable at its frozen defaults.
	 * Batches that only skipped contacts (e.g. --active-subs-only filtering
	 * everyone out) did no external work and never sleep.
	 *
	 * No-op outside a real WP-CLI runtime (the WP_CLI constant is not defined
	 * under PHPUnit), so tests are unaffected.
	 */
	private static function batch_boundary_pause(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		if ( function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
			\WP_CLI\Utils\wp_clear_object_cache();
		}
		// Static memos the object cache flush cannot reach: the published gate
		// list, and the per-reader subscriptions the Content Access source
		// labels are derived from. Both are request-scoped by design and would
		// otherwise speak for readers processed batches ago.
		Content_Gate_Metadata::reset_cache();
		$seconds = self::consume_pause_seconds();
		if ( $seconds > 0 ) {
			sleep( $seconds );
		}
	}

	/**
	 * Seconds this batch boundary owes, deducting them from the paced counter.
	 *
	 * Consumes the counter in whole increments rather than zeroing it: a
	 * boundary carrying 500 accrued contacts owes five seconds, not one.
	 * Discarding the overflow would degrade pacing to one second per
	 * --batch-size contacts, under-throttling exactly the large-batch runs that
	 * generate requests fastest. The remainder carries into the next boundary.
	 *
	 * @return int Whole seconds to sleep.
	 */
	private static function consume_pause_seconds(): int {
		$seconds                    = intdiv( static::$unpaused_contacts, self::PAUSE_EVERY_CONTACTS );
		static::$unpaused_contacts -= $seconds * self::PAUSE_EVERY_CONTACTS;
		return $seconds;
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
				'fields'      => 'ID',
				'number'      => $batch_size,
				'offset'      => $offset,
				'order'       => 'DESC',
				'orderby'     => 'registered',
				'role__in'    => $roles,
				// The loops page until a batch comes back empty and never read the
				// total, so skip the extra SQL_CALC_FOUND_ROWS COUNT(*) per batch.
				'count_total' => false,
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
	private static function build_sync_config( $assoc_args ): array {
		return [
			'is_dry_run'       => ! empty( $assoc_args['dry-run'] ),
			// `active-subs-only` is the flag on `integrations backfill`; `active-only`
			// is the legacy spelling kept on the `esp sync` alias.
			'active_only'      => ! empty( $assoc_args['active-subs-only'] ) || ! empty( $assoc_args['active-only'] ),
			'migrated_only'    => ! empty( $assoc_args['migrated-subscriptions'] ) ? $assoc_args['migrated-subscriptions'] : false,
			'subscription_ids' => ! empty( $assoc_args['subscription-ids'] ) ? explode( ',', $assoc_args['subscription-ids'] ) : false,
			'user_ids'         => ! empty( $assoc_args['user-ids'] ) ? explode( ',', $assoc_args['user-ids'] ) : false,
			'order_ids'        => ! empty( $assoc_args['order-ids'] ) ? explode( ',', $assoc_args['order-ids'] ) : false,
			// Floored: WP_User_Query applies no LIMIT when `number` is non-positive,
			// so every batch would return the entire reader set and the paging
			// loops — which run until a batch comes back empty — would never
			// terminate. A negative offset builds an invalid LIMIT clause instead:
			// the query returns nothing and the run reads as a clean success over
			// a window that was never covered. A negative max-batches is truthy
			// against the batch counter, so it would silently stop the run after
			// the first batch; flooring it to 0 keeps it meaning "no cap".
			// (`! empty()` runs on the raw string, so a literal `0` keeps the
			// default but a non-numeric value reaches intval().) Matches the
			// --user-ids chunk clamp in pull_contacts().
			'batch_size'       => ! empty( $assoc_args['batch-size'] ) ? max( 1, intval( $assoc_args['batch-size'] ) ) : 10,
			'offset'           => ! empty( $assoc_args['offset'] ) ? max( 0, intval( $assoc_args['offset'] ) ) : 0,
			'max_batches'      => ! empty( $assoc_args['max-batches'] ) ? max( 0, intval( $assoc_args['max-batches'] ) ) : 0,
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
	private static function format_summary( $tally, $is_dry_run, $direction ): string {
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
	 * : Number of subscriptions to query/process at once. Defaults to 10. Batch boundaries free the object cache, and pause for one second per 100 contacts that reached an integration, to space out the external request stream.
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
	 * : Number of contacts to query/process at once. Defaults to 10. Batch boundaries free the object cache, and pause for one second per 100 contacts that reached an integration, to space out the external request stream.
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
	 * integration with inbound sync enabled and incoming fields selected; this
	 * is validated in the pre-flight, before any push work runs.
	 *
	 * Both legs honor the per-direction toggles: the push leg skips
	 * integrations whose outbound sync is paused (or unsupported), and the pull
	 * leg skips those whose inbound sync is. A run scoped with `--integration`
	 * to such an integration fails the pre-flight rather than reporting a
	 * successful run that touched nobody.
	 *
	 * Pull failures are NOT auto-retried via ActionScheduler (a bulk run against
	 * a flaky API would flood the queue). Re-run the affected `--offset` window
	 * instead. Push retry behavior is unchanged from `wp newspack esp sync`,
	 * including the no-retry rule for `--skip-lists`/`--fields` runs.
	 *
	 * Readers the provider has no contact for are tallied as skipped, not as
	 * errors: a pull cannot create the missing contact, so re-running the
	 * window could never clear them — and a partially-synced site (the usual
	 * backfill candidate) would otherwise never exit 0.
	 *
	 * A run that tallies any error exits with status 1 and prints the summary as
	 * a warning, so an unattended runbook can detect partial failure without
	 * parsing output. A clean run exits 0. (The legacy `esp sync` alias still
	 * exits 0 even when errors are tallied, as it always has; a pre-flight
	 * failure exits 1 on both commands via `WP_CLI::error()`.)
	 *
	 * A `--dry-run` pull evaluates the deterministic reader-data write
	 * rejections without persisting, so its error tally previews what a real
	 * run would report.
	 *
	 * ## EXAMPLES
	 *
	 *     # Re-push all readers to every active integration (same as the legacy `esp sync`).
	 *     wp newspack integrations backfill
	 *
	 *     # Pull enabled incoming fields for all readers from one integration.
	 *     wp newspack integrations backfill --direction=pull --integration=esp
	 *
	 *     # Fully catch up one integration, 500 readers per batch.
	 *     wp newspack integrations backfill --direction=both --integration=esp --batch-size=500
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_backfill( $args, $assoc_args ): void {
		$backfill = self::parse_backfill_options( $assoc_args );
		if ( \is_wp_error( $backfill ) ) {
			WP_CLI::error( $backfill->get_error_message() );
			return;
		}
		$direction      = $backfill['direction'];
		$integration_id = $backfill['integration_id'];
		$config         = self::build_sync_config( $assoc_args );
		$summaries      = [];
		$total_errors   = 0;

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
			$summaries[]   = self::format_summary( $push_results, $config['is_dry_run'], 'push' );
			$total_errors += $push_results['errors'];
		}

		if ( in_array( $direction, [ 'pull', 'both' ], true ) ) {
			static::log( __( 'Running integrations contact pull...', 'newspack-plugin' ) );
			$pull_results = self::pull_contacts(
				[
					'active_only'              => $config['active_only'],
					'user_ids'                 => $config['user_ids'],
					'batch_size'               => $config['batch_size'],
					'offset'                   => $config['offset'],
					'max_batches'              => $config['max_batches'],
					'is_dry_run'               => $config['is_dry_run'],
					'integration_id'           => $integration_id,
					'resolved_incoming_fields' => $backfill['resolved_incoming_fields'],
				]
			);
			if ( \is_wp_error( $pull_results ) ) {
				WP_CLI::error( $pull_results->get_error_message() );
				return;
			}
			$summaries[]   = self::format_summary( $pull_results, $config['is_dry_run'], 'pull' );
			$total_errors += $pull_results['errors'];
		}

		WP_CLI::line( "\n" );
		$summary = implode( ' ', $summaries );

		// Partial failure must be detectable from the exit status. An unattended
		// runbook cannot parse the summary string, so an unconditional success
		// would hide systematic failures and the documented recovery — re-run the
		// affected --offset window — would never trigger. This command is new and
		// carries no compatibility constraint; the frozen `esp sync` alias keeps
		// its unconditional success.
		if ( $total_errors > 0 ) {
			WP_CLI::warning( $summary );
			WP_CLI::halt( 1 );
			return;
		}

		WP_CLI::success( $summary );
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
		//
		// The guard only concerns the ESP integration, so a run scoped to some
		// other integration via --integration is unaffected by the site's ESP
		// and must not be blocked by it. Skip the guard only when the target is
		// positively known not to be the ESP; an unresolvable id keeps the
		// guard (parse_backfill_options() rejects those before they get here).
		$scoped_integration = empty( $integration_id ) ? null : Integrations::get_integration( $integration_id );
		$esp_takes_part     = empty( $integration_id ) || ! $scoped_integration || $scoped_integration instanceof ESP;
		if (
			$options['skip_lists'] &&
			$esp_takes_part &&
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
		// Loop variable stays `$id`: `$integration_id` is the run's scope parameter.
		foreach ( $integrations as $id => $integration ) {
			// The sync run itself skips integrations without an (enabled) push, so
			// their field selection must not block the backfill of the others.
			if ( ! $integration->is_push_enabled() ) {
				continue;
			}
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
	 * integration with inbound sync enabled and incoming fields selected —
	 * the same predicate pull_contacts() uses to build its targets, so the
	 * pre-flight and the run agree. Surfacing it here keeps a `--direction=both`
	 * run from completing a full push before discovering the pull leg has
	 * nothing to do.
	 *
	 * @param array $assoc_args Associative CLI args.
	 *
	 * @return array|\WP_Error `[ 'direction' => 'push'|'pull'|'both', 'integration_id' => string|null ]` or WP_Error.
	 */
	private static function parse_backfill_options( $assoc_args ): array|\WP_Error {
		$direction                = isset( $assoc_args['direction'] ) ? (string) $assoc_args['direction'] : 'push';
		$resolved_incoming_fields = [];
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

			// A scoped push must check the *target* integration's syncability. The
			// push leg's gate is the global has_one_syncable_integration(), which a
			// syncable sibling satisfies — so a run scoped to a non-syncable
			// integration would otherwise proceed and either tally an error per
			// contact or report "Synced 0 contacts" instead of naming the reason.
			if ( 'pull' !== $direction ) {
				$target = $active[ $integration_id ];

				// Same failure mode via the per-direction toggle: push_to_integrations()
				// skips push-disabled integrations, so a scoped run against one would
				// push to nobody and still report success.
				if ( ! $target->is_push_enabled() ) {
					return new \WP_Error(
						'newspack_backfill_integration_cannot_sync',
						sprintf(
							// Translators: 1: the integration id passed to --integration, 2: the reason it cannot push.
							__( 'Integration "%1$s" cannot be pushed to: %2$s.', 'newspack-plugin' ),
							$integration_id,
							$target->supports_push()
								? __( 'outbound sync is paused', 'newspack-plugin' )
								: __( 'integration does not support outbound sync', 'newspack-plugin' )
						)
					);
				}

				$can_sync = $target->can_sync( true );
				if ( \is_wp_error( $can_sync ) && $can_sync->has_errors() ) {
					return new \WP_Error(
						'newspack_backfill_integration_cannot_sync',
						sprintf(
							// Translators: 1: the integration id passed to --integration, 2: the reason it cannot sync.
							__( 'Integration "%1$s" cannot sync: %2$s', 'newspack-plugin' ),
							$integration_id,
							$can_sync->get_error_message()
						)
					);
				}
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
			foreach ( $pull_scope as $id => $integration ) {
				// Mirrors pull_contacts()'s target selection, so the pre-flight and
				// the run agree on what counts as a viable target. Each resolution
				// is kept and threaded into the run: resolving may hit the
				// provider's API, so the run must not ask the same integration a
				// second time.
				if ( ! $integration->is_pull_enabled() ) {
					continue;
				}
				$resolved_incoming_fields[ $id ] = $integration->get_enabled_incoming_fields();
				if ( ! empty( $resolved_incoming_fields[ $id ] ) ) {
					$has_pull_target = true;
					break;
				}
			}
			if ( ! $has_pull_target ) {
				return new \WP_Error(
					'newspack_backfill_no_pull_targets',
					__( 'No active integrations with inbound sync enabled and incoming fields selected to pull from.', 'newspack-plugin' )
				);
			}
		}

		return [
			'direction'                => $direction,
			'integration_id'           => '' !== $integration_id ? $integration_id : null,
			'resolved_incoming_fields' => $resolved_incoming_fields,
		];
	}
}
