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

					$user_ids = self::get_batch_of_readers( $config['batch_size'], $config['offset'] + ( $batches * $config['batch_size'] ) );
				}
			}
		}

		return static::$results;
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
	 * Sync Reader Activation contact data to the connected ESP for all customers, migrated subscriptions, or specific customers/subscriptions/orders.
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
	 * : Number of subscriptions to query/process at once.
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
		$config = [];
		$config['is_dry_run']       = ! empty( $assoc_args['dry-run'] );
		$config['active_only']      = ! empty( $assoc_args['active-only'] );
		$config['migrated_only']    = ! empty( $assoc_args['migrated-subscriptions'] ) ? $assoc_args['migrated-subscriptions'] : false;
		$config['subscription_ids'] = ! empty( $assoc_args['subscription-ids'] ) ? explode( ',', $assoc_args['subscription-ids'] ) : false;
		$config['user_ids']         = ! empty( $assoc_args['user-ids'] ) ? explode( ',', $assoc_args['user-ids'] ) : false;
		$config['order_ids']        = ! empty( $assoc_args['order-ids'] ) ? explode( ',', $assoc_args['order-ids'] ) : false;
		$config['batch_size']       = ! empty( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : 10;
		$config['offset']           = ! empty( $assoc_args['offset'] ) ? intval( $assoc_args['offset'] ) : 0;
		$config['max_batches']      = ! empty( $assoc_args['max-batches'] ) ? intval( $assoc_args['max-batches'] ) : 0;
		$config['context']          = ! empty( $assoc_args['sync-context'] ) ? $assoc_args['sync-context'] : static::$context;

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
		WP_CLI::success(
			sprintf(
				// Translators: 1: verb (Synced/Would sync), 2: processed count, 3: error count, 4: skipped count.
				__( '%1$s %2$d contacts (%3$d errors, %4$d skipped).', 'newspack-plugin' ),
				$config['is_dry_run'] ? __( 'Would sync', 'newspack-plugin' ) : __( 'Synced', 'newspack-plugin' ),
				$results['processed'],
				$results['errors'],
				$results['skipped']
			)
		);
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
	 * @param array $assoc_args Associative CLI args.
	 *
	 * @return array|\WP_Error `[ 'skip_lists' => bool, 'fields' => string[]|null ]` or WP_Error.
	 */
	private static function parse_sync_options( $assoc_args ): array|\WP_Error {
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
		foreach ( $integrations as $integration_id => $integration ) {
			$enabled = $integration->get_enabled_outgoing_fields();
			$missing = array_values( array_diff( $labels, $enabled ) );
			if ( ! empty( $missing ) ) {
				return new \WP_Error(
					'newspack_esp_sync_fields_not_enabled',
					sprintf(
						// Translators: 1: integration id, 2: comma-separated field labels.
						__( 'These fields are not enabled as outgoing fields for integration "%1$s": %2$s. Enable them under Audience > Access control / metadata settings, then re-run.', 'newspack-plugin' ),
						$integration_id,
						implode( ', ', $missing )
					)
				);
			}
		}

		return $options;
	}
}
