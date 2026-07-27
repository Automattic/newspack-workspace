<?php
/**
 * Reader contact data syncing with the active integrations.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;
use Newspack\Data_Events;
use Newspack\Logger;
use Newspack\Reader_Activation\Sync\Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Sync Class.
 */
class Contact_Sync extends Sync {
	/**
	 * Context of the sync.
	 *
	 * @var string
	 */
	protected static $context = 'Contact Sync';

	/**
	 * Queued syncs containing their contexts keyed by email address.
	 *
	 * @var array[]
	 */
	protected static $queued_syncs = [];

	/**
	 * Emails deleted during the current request, keyed for O(1) lookup.
	 *
	 * Populated by handle_account_deletion() and consulted by run_queued_syncs()
	 * so a sync queued by a later event in the same Action Scheduler batch cannot
	 * resurrect a just-deleted contact at shutdown, regardless of hook ordering.
	 *
	 * @var array<string, bool>
	 */
	protected static $deleted_emails = [];

	/**
	 * The ID of the currently-executing ActionScheduler action.
	 *
	 * @var int|null
	 */
	private static $current_as_action_id = null;

	/**
	 * Membership status value written to the ESP when a reader account is deleted.
	 *
	 * Preserved for backward compatibility with publisher automations that keyed on
	 * this value under the pre-integrations delete-sync path.
	 */
	const DELETED_MEMBERSHIP_STATUS = 'user-deleted';

	/**
	 * ActionScheduler hook for retrying a failed integration sync.
	 */
	const RETRY_HOOK = 'newspack_contact_sync_retry';

	/**
	 * ActionScheduler hook for retrying a failed deletion sync.
	 *
	 * Separate from RETRY_HOOK because the WP user is gone by the time we retry,
	 * so the retry payload keys on email + mode (delete/flag) instead of user_id.
	 */
	const RETRY_DELETION_HOOK = 'newspack_contact_sync_deletion_retry';

	/**
	 * Maximum number of retries for a failed integration sync.
	 */
	const MAX_RETRIES = 5;

	/**
	 * Backoff schedule in seconds for integration sync retries.
	 * 30s, 2min, 8min, 30min, 2h.
	 */
	const RETRY_BACKOFF = [ 30, 120, 480, 1800, 7200 ];

	/**
	 * Substring signatures (lowercase) that classify an ESP error message on
	 * the push/upsert direction.
	 *
	 * Matched in order against the lowercased error message. The HTTP status
	 * code that would identify these cleanly is discarded upstream by the ESP
	 * layer (only a "{Title}: {detail}" string survives), so classification is
	 * necessarily string-based. Extend the lists as ESP error copy evolves —
	 * they are private so they can do so without becoming public API (tests
	 * reach classify_error() via reflection).
	 *
	 *   - permanent_contact: bad contact data; retrying can never succeed.
	 *   - permanent_config:  site-level ESP account problem; actionable.
	 *   - benign:            the contact already exists; no retry needed.
	 *
	 * Anything not matched here is treated as 'transient' and retried.
	 */
	private const ERROR_SIGNATURES = [
		'permanent_contact' => [
			'was permanently deleted',
			'looks fake or invalid',
			'merge fields were invalid',
			'please provide a valid email',
			'contact email address is not valid',
		],
		'permanent_config'  => [
			'api access has been disabled',
			'payment required',
		],
		'benign'            => [
			'member exists',
			'already a list member',
		],
	];

	/**
	 * Signature map for the deletion direction, where two push-oriented classes
	 * invert their meaning:
	 *
	 *   - 'member exists' / 'already a list member' are deliberately absent: on
	 *     a deletion push they mean the ESP contact still exists WITHOUT the
	 *     account_deleted/membership_status flags, so the push must be retried
	 *     (they fall through to 'transient') rather than skipped as benign.
	 *   - 'was permanently deleted' is benign here: the contact is already gone
	 *     from the ESP, which is the deletion end-state for both modes.
	 */
	private const DELETION_ERROR_SIGNATURES = [
		'permanent_contact' => [
			'looks fake or invalid',
			'merge fields were invalid',
			'please provide a valid email',
			'contact email address is not valid',
		],
		'permanent_config'  => [
			'api access has been disabled',
			'payment required',
		],
		'benign'            => [
			'was permanently deleted',
		],
	];

	/**
	 * Initialize hooks.
	 */
	public static function init_hooks() {
		add_action( 'newspack_scheduled_esp_sync', [ __CLASS__, 'scheduled_sync' ], 10, 2 );
		add_action( 'shutdown', [ __CLASS__, 'run_queued_syncs' ] );
		add_action( self::RETRY_HOOK, [ __CLASS__, 'execute_integration_retry' ] );
		add_action( self::RETRY_DELETION_HOOK, [ __CLASS__, 'execute_deletion_retry' ] );
		add_action( 'action_scheduler_begin_execute', [ __CLASS__, 'set_current_as_action_id' ] );
		add_action( 'action_scheduler_after_execute', [ __CLASS__, 'clear_current_as_action_id' ] );
		add_filter( 'newspack_action_scheduler_hook_labels', [ __CLASS__, 'register_hook_labels' ] );
	}

	/**
	 * Register hook labels for Contact Sync actions.
	 *
	 * @param array $labels Existing labels.
	 * @return array
	 */
	public static function register_hook_labels( $labels ) {
		$labels[ self::RETRY_HOOK ]          = __( 'Contact Sync Retry', 'newspack-plugin' );
		$labels[ self::RETRY_DELETION_HOOK ] = __( 'Contact Sync Deletion Retry', 'newspack-plugin' );
		return $labels;
	}

	/**
	 * Set the current ActionScheduler action ID.
	 *
	 * @param int $action_id The AS action ID.
	 */
	public static function set_current_as_action_id( $action_id ) {
		self::$current_as_action_id = $action_id;
	}

	/**
	 * Clear the current ActionScheduler action ID.
	 */
	public static function clear_current_as_action_id() {
		self::$current_as_action_id = null;
	}

	/**
	 * Sync contact to the ESP.
	 *
	 * @param array  $contact          The contact data to sync.
	 * @param string $context          The context of the sync. Defaults to static::$context.
	 * @param array  $existing_contact Optional. Existing contact data to merge with. Defaults to null.
	 * @param array  $options          Optional. Sync options threaded to the integration push:
	 *                                 `skip_lists` (bool) and `fields` (string[]|null). These apply
	 *                                 only to the direct push path below — not the queued Data Events
	 *                                 branch, which never runs under WP-CLI.
	 *
	 * @return true|\WP_Error True if succeeded or WP_Error.
	 */
	public static function sync( $contact, $context = '', $existing_contact = null, $options = [] ) {
		$can_sync = static::can_sync( true );
		if ( $can_sync->has_errors() ) {
			return $can_sync;
		}

		if ( empty( $context ) ) {
			$context = static::$context;
		}

		// If we're running in a data event, queue the sync to run on shutdown.
		if ( Data_Events::current_event() ) {
			if ( ! isset( self::$queued_syncs[ $contact['email'] ] ) ) {
				self::$queued_syncs[ $contact['email'] ] = [
					'contexts'     => [],
					'contact'      => [],
					'as_action_id' => self::$current_as_action_id,
				];
			}
			if ( ! empty( self::$queued_syncs[ $contact['email'] ]['contact']['metadata'] ) ) {
				$contact['metadata'] = array_merge( self::$queued_syncs[ $contact['email'] ]['contact']['metadata'], $contact['metadata'] );
			}
			self::$queued_syncs[ $contact['email'] ]['contexts'][] = $context;
			self::$queued_syncs[ $contact['email'] ]['contact']    = $contact;
			if ( ! did_action( 'shutdown' ) ) {
				return true;
			}
		}

		// Added logging here to more easily monitor integration sync data. Can be removed once integrations are released.
		if ( 'legacy' !== Metadata::get_version() ) {
			Logger::log( sprintf( 'Syncing contact %s for context "%s".', $contact['email'] ?? 'unknown', $context ) );
			Logger::log( $contact );
		}

		return self::push_to_integrations( $contact, $context, $existing_contact, $options );
	}

	/**
	 * Whether the given sync options are the default (no CLI field/list scoping).
	 *
	 * @param array $options Sync options.
	 *
	 * @return bool True when neither `skip_lists` nor `fields` scoping is set.
	 */
	private static function options_are_default( $options ): bool {
		return empty( $options['skip_lists'] ) && empty( $options['fields'] );
	}

	/**
	 * Prepare a contact for a single integration, applying the integration's own
	 * `prepare_contact()` and then the CLI field/name scoping from `$options`.
	 *
	 * When `$options['fields']` is set: the reader `name` is dropped (so a
	 * field-scoped backfill can't rewrite reader names — ESPs only set first/last
	 * name when a name is present), and metadata is filtered to just the requested
	 * labels. Filtering runs after `prepare_contact()` so keys are already prefixed
	 * in both metadata modes; a key is kept when its de-prefixed remainder equals a
	 * requested label, or begins with a requested label ending in `': '` (the UTM
	 * label shape, e.g. `Signup UTM: source`). Everything else — including
	 * `status` / `status_if_new` — is dropped.
	 *
	 * @param \Newspack\Reader_Activation\Integration $integration The target integration.
	 * @param array                                   $contact     The contact data.
	 * @param array                                   $options     Sync options.
	 *
	 * @return array The prepared, scoped contact.
	 */
	private static function prepare_contact_for_integration( $integration, $contact, $options = [] ): array {
		$integration_contact = $integration->prepare_contact( $contact );

		if ( empty( $options['fields'] ) ) {
			return $integration_contact;
		}

		// Drop the reader name so a field-scoped backfill can't rewrite names (ESPs
		// only set first/last name when a name is present). Applied to the prepared
		// contact — after prepare_contact() — so an integration override that derives
		// a name can't re-introduce it and defeat the guarantee.
		unset( $integration_contact['name'] );

		$prefix   = $integration->get_metadata_prefix();
		$labels   = $options['fields'];
		$filtered = [];
		foreach ( $integration_contact['metadata'] ?? [] as $key => $value ) {
			$remainder = 0 === strpos( $key, $prefix ) ? substr( $key, strlen( $prefix ) ) : $key;
			foreach ( $labels as $label ) {
				if ( $remainder === $label ) {
					$filtered[ $key ] = $value;
					break;
				}
				// UTM-style labels end in ": " and match any suffixed key (e.g. "Signup UTM: source").
				// This trailing-": " shape is the contract defined by the UTM labels in
				// Legacy_Metadata::get_basic_fields() ("Signup UTM: ", "Payment UTM: ").
				if ( ': ' === substr( $label, -2 ) && 0 === strpos( $remainder, $label ) ) {
					$filtered[ $key ] = $value;
					break;
				}
			}
		}

		$integration_contact['metadata'] = $filtered;
		return $integration_contact;
	}

	/**
	 * Push contact data to all active integrations.
	 *
	 * Failed integrations are scheduled for retry via ActionScheduler
	 * with exponential backoff — unless `$options` carries CLI field/list scoping,
	 * in which case retries are suppressed (see below).
	 *
	 * @param array  $contact          The contact data to sync.
	 * @param string $context          The context of the sync.
	 * @param array  $existing_contact Optional. Existing contact data to merge with.
	 * @param array  $options          Optional. Sync options: `skip_lists` (bool) and
	 *                                 `fields` (string[]|null). When non-default, contacts
	 *                                 are field/name-scoped per integration and failed pushes
	 *                                 are NOT auto-retried — the AS retry handler rebuilds the
	 *                                 full contact and would push it with the master list,
	 *                                 undoing the list-less/field-scoped intent. Operators
	 *                                 re-run the affected `--offset` window instead.
	 *
	 * @return true|\WP_Error True if all succeeded, or WP_Error with combined messages.
	 */
	private static function push_to_integrations( $contact, $context, $existing_contact = null, $options = [] ) {
		/**
		 * Filters the contact data before syncing to the integration, allowing modifications or additions to the contact data.
		 *
		 * @param array  $contact The contact data to sync.
		 * @param string $context The context of the sync.
		 */
		$contact = \apply_filters( 'newspack_esp_sync_contact', $contact, $context );
		$integrations = Integrations::get_active_configured_integrations();
		$errors       = [];

		// Resolve user ID for retry scheduling.
		$user    = ! empty( $contact['email'] ) ? \get_user_by( 'email', $contact['email'] ) : false;
		$user_id = $user ? $user->ID : 0;

		// Preserve the previous email for retry when the contact's email has changed
		// (e.g. Email_Change context) so integrations can upsert against the old address.
		$previous_email = '';
		if ( ! empty( $existing_contact['email'] ) && $existing_contact['email'] !== $contact['email'] ) {
			$previous_email = $existing_contact['email'];
		}

		foreach ( $integrations as $integration_id => $integration ) {
			$integration_contact = self::prepare_contact_for_integration( $integration, $contact, $options );

			// Added logging here to more easily monitor integration sync data. Can be removed once integrations are released.
			if ( 'legacy' !== Metadata::get_version() ) {
				Logger::log( sprintf( 'Syncing contact %s for integration %s with context "%s".', $integration_contact['email'] ?? 'unknown', $integration_id, $context ) );
				Logger::log( $integration_contact );
			}

			$result = $integration->push_contact_data( $integration_contact, $context, $existing_contact, $options );
			if ( \is_wp_error( $result ) ) {
				/**
				 * Fires when a contact sync fails on the original attempt (before retries).
				 *
				 * Used by Alert_Manager to record failures for early pattern detection.
				 *
				 * @param array $failure_data {
				 *     Failure data.
				 *
				 *     @type string $integration_id The integration that failed.
				 *     @type array  $contact        The contact data that failed to sync.
				 *     @type string $context        The sync context.
				 *     @type string $reason         The error message.
				 *     @type string $error_class    Error classification — 'transient', 'benign',
				 *                                  'permanent_contact' or 'permanent_config' — so
				 *                                  consumers can keep never-fixable failures out
				 *                                  of pattern detection.
				 * }
				 */
				do_action(
					'newspack_sync_contact_failed',
					[
						'integration_id' => $integration_id,
						'contact'        => $contact,
						'context'        => $context,
						'reason'         => $result->get_error_message(),
						'error_class'    => self::classify_error( $result ),
					]
				);
				if ( self::options_are_default( $options ) ) {
					self::schedule_integration_retry( $integration_id, $user_id, $context, 0, $result, $previous_email );
				} else {
					static::log( sprintf( 'Retry skipped for integration "%s" sync of %s: CLI sync with custom options (skip-lists/fields). Re-run the affected batch to retry.', $integration_id, $contact['email'] ?? 'unknown' ) );
				}
				$errors[] = sprintf( '[%s] %s', $integration_id, $result->get_error_message() );
				if ( self::$current_as_action_id ) {
					\ActionScheduler_Logger::instance()->log(
						self::$current_as_action_id,
						sprintf( 'Sync failed for integration "%s" of %s: %s', $integration_id, $contact['email'] ?? 'unknown', $result->get_error_message() )
					);
				}
			} elseif ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					sprintf( 'Sync succeeded for integration "%s" of %s.', $integration_id, $contact['email'] ?? 'unknown' )
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'newspack_esp_sync_failed', implode( '; ', $errors ) );
		}

		return true;
	}

	/**
	 * Handle account deletion across all active integrations.
	 *
	 * Iterates active integrations and routes deletion per-integration based on
	 * the `sync_account_deletion` and `account_deletion_handling` settings:
	 *
	 * - sync_account_deletion=false → skip this integration entirely.
	 * - sync_account_deletion=true + handling='delete' → call $integration->delete_contact($email).
	 * - sync_account_deletion=true + handling='flag' → push the contact with the
	 *   `account_deleted` metadata field set to an ISO8601 timestamp.
	 *
	 * The WP user no longer exists by the time this runs, so the standard
	 * push_to_integrations() retry path (which keys retries on user_id) is
	 * not used. Transient ESP errors are retried via RETRY_DELETION_HOOK with
	 * a payload keyed on email + mode so a 5xx/429 doesn't strand the contact
	 * in undeleted state (a GDPR exposure for "right to be forgotten" flows).
	 *
	 * @param string $email   Email of the deleted reader.
	 * @param array  $contact Contact data to push in flag mode (email + metadata).
	 * @param string $context Optional context for logging.
	 *
	 * @return true|\WP_Error True on success, WP_Error if any integration returned an error.
	 */
	public static function handle_account_deletion( $email, $contact, $context = '' ) {
		$can_sync = static::can_sync( true );
		if ( $can_sync->has_errors() ) {
			return $can_sync;
		}
		if ( empty( $context ) ) {
			$context = static::$context;
		}

		// Drop any upsert queued earlier in this same request for the deleted email.
		// Without this, the shutdown queue (see sync()/run_queued_syncs()) would run
		// after the ESP delete below and recreate the contact — e.g. when WCS cancels
		// subscriptions during the delete_user cascade and fires subscription_updated,
		// which queues a Contact_Sync via the Data Event dispatcher.
		unset( self::$queued_syncs[ $email ] );
		// The unset() above only clears syncs already queued at this instant. Data
		// events for the same email can be dispatched *after* this deletion within the
		// same Action Scheduler batch (delete_user cascade ordering isn't guaranteed),
		// re-queueing an upsert that run_queued_syncs() would flush at shutdown —
		// silently undoing a right-to-be-forgotten deletion. Record the email so the
		// shutdown queue refuses to re-push it for the rest of the request.
		self::$deleted_emails[ $email ] = true;

		// Only act on integrations the admin has finished configuring. This path
		// performs real I/O (delete_contact / flag-mode upserts) and schedules AS
		// retries per integration, so it must use the same configured-only gate as
		// the sync path to avoid deleting against, or retrying on, an integration
		// whose external prerequisites are not set up.
		$integrations = Integrations::get_active_configured_integrations();
		$errors       = [];

		// Build the flag-mode contact once. The timestamp uses the same format constant
		// as peer datetime metadata fields (Sync\Metadata::DATE_FORMAT) so publishers can
		// apply consistent automation rules across reader events, and so the deletion
		// timestamp stays in lockstep if that format ever changes.
		$flag_contact          = $contact;
		$flag_contact['email'] = $email;
		$flag_contact['metadata'] = isset( $flag_contact['metadata'] ) ? $flag_contact['metadata'] : [];
		$flag_contact['metadata']['account_deleted'] = gmdate( Metadata::DATE_FORMAT );
		// Preserve the historical deletion signal: before the per-integration deletion
		// settings, the delete-sync path always wrote membership_status='user-deleted'
		// to the ESP. Publishers may have automations keyed on that value, so keep
		// emitting it in flag mode alongside the newer account_deleted timestamp.
		$flag_contact['metadata']['membership_status'] = self::DELETED_MEMBERSHIP_STATUS;

		/**
		 * Apply the same contact-data filter used by the regular sync path
		 * (push_to_integrations) so publishers' existing `newspack_esp_sync_contact`
		 * filters (e.g. Mailchimp `status_if_new`, custom metadata enrichment) keep
		 * running during deletion flag-mode upserts.
		 *
		 * This filter is documented in includes/reader-activation/sync/class-contact-sync.php.
		 */
		$flag_contact = \apply_filters( 'newspack_esp_sync_contact', $flag_contact, $context );

		foreach ( $integrations as $integration_id => $integration ) {
			if ( ! $integration->get_settings_field_value( 'sync_account_deletion' ) ) {
				continue;
			}
			$mode = $integration->get_settings_field_value( 'account_deletion_handling' );

			if ( 'delete' === $mode ) {
				$result = $integration->delete_contact( $email );
				if ( \is_wp_error( $result ) ) {
					$errors[] = sprintf( '[%s] %s', $integration_id, $result->get_error_message() );
					static::log( sprintf( 'Delete failed for integration "%s" of %s: %s', $integration_id, $email, $result->get_error_message() ) );
					$error_class = self::schedule_deletion_retry( $integration_id, 'delete', $email, [], $context, 0, $result );
					/**
					 * Fires when a contact deletion sync fails.
					 *
					 * Used by Alert_Manager to record failures for early pattern detection.
					 * The `mode` field carries the deletion handling mode (`delete` or
					 * `flag`) so consumers can distinguish them.
					 *
					 * @param array $failure_data {
					 *     Failure data.
					 *
					 *     @type string $integration_id The integration that failed.
					 *     @type array  $contact        The contact data that failed to sync.
					 *     @type string $context        The sync context.
					 *     @type string $reason         The error message.
					 *     @type string $mode           The deletion mode: 'delete' or 'flag'.
					 *     @type string $error_class    Error classification, from the
					 *                                  deletion-direction signature map.
					 * }
					 */
					do_action(
						'newspack_sync_contact_failed',
						[
							'integration_id' => $integration_id,
							'contact'        => [ 'email' => $email ],
							'context'        => $context,
							'reason'         => $result->get_error_message(),
							'mode'           => 'delete',
							'error_class'    => $error_class,
						]
					);
					if ( self::$current_as_action_id ) {
						\ActionScheduler_Logger::instance()->log(
							self::$current_as_action_id,
							sprintf( 'Delete failed for integration "%s" of %s: %s', $integration_id, $email, $result->get_error_message() )
						);
					}
				} else {
					static::log( sprintf( 'Delete succeeded for integration "%s" of %s.', $integration_id, $email ) );
					if ( self::$current_as_action_id ) {
						\ActionScheduler_Logger::instance()->log(
							self::$current_as_action_id,
							sprintf( 'Delete succeeded for integration "%s" of %s.', $integration_id, $email )
						);
					}
				}
			} elseif ( 'flag' === $mode ) {
				// Push through the integration's normal pipeline so prepare_contact applies metadata
				// prefixing and outgoing-field filtering to publisher-configured metadata. Then
				// re-inject the account_deleted signal: it's a system-level signal that must
				// always reach the ESP on deletion, independent of the integration's
				// outgoing-fields config. Apply the integration's prefix so the field is named
				// consistently with other metadata on the ESP side.
				$integration_contact = $integration->prepare_contact( $flag_contact );
				// Use Title_Case_With_Underscores to match the convention of peer prefixed
				// metadata fields (e.g. `NP_Registration_Date`, `NP_Last_Active`).
				$prefix              = $integration->get_metadata_prefix();
				$integration_contact['metadata'] = $integration_contact['metadata'] ?? [];
				$integration_contact['metadata'][ $prefix . 'Account_Deleted' ] = $flag_contact['metadata']['account_deleted'];
				// Re-inject the membership status as a system-level deletion signal too.
				// `membership_status` isn't a v1 outgoing field, so prepare_contact drops
				// it; add it back under the prefixed key so the historical 'user-deleted'
				// value always reaches the ESP regardless of outgoing-fields config.
				$integration_contact['metadata'][ $prefix . 'Membership_Status' ] = $flag_contact['metadata']['membership_status'];

				$result = $integration->push_contact_data( $integration_contact, $context );
				if ( \is_wp_error( $result ) ) {
					$errors[] = sprintf( '[%s] %s', $integration_id, $result->get_error_message() );
					static::log( sprintf( 'Flag-push failed for integration "%s" of %s: %s', $integration_id, $email, $result->get_error_message() ) );
					// Stash the already-prepared payload so the retry re-pushes the
					// exact contact (prefix + Account_Deleted re-injection) without
					// rebuilding it from a user that no longer exists.
					$error_class = self::schedule_deletion_retry( $integration_id, 'flag', $email, $integration_contact, $context, 0, $result );
					/** This action is documented above in the 'delete' branch of this method. */
					do_action(
						'newspack_sync_contact_failed',
						[
							'integration_id' => $integration_id,
							'contact'        => $flag_contact,
							'context'        => $context,
							'reason'         => $result->get_error_message(),
							'mode'           => 'flag',
							'error_class'    => $error_class,
						]
					);
					if ( self::$current_as_action_id ) {
						\ActionScheduler_Logger::instance()->log(
							self::$current_as_action_id,
							sprintf( 'Flag-push failed for integration "%s" of %s: %s', $integration_id, $email, $result->get_error_message() )
						);
					}
				} else {
					static::log( sprintf( 'Flag-push succeeded for integration "%s" of %s.', $integration_id, $email ) );
					if ( self::$current_as_action_id ) {
						\ActionScheduler_Logger::instance()->log(
							self::$current_as_action_id,
							sprintf( 'Flag-push succeeded for integration "%s" of %s.', $integration_id, $email )
						);
					}
				}
			} else {
				static::log( sprintf( 'Unknown handling mode "%s" for integration "%s"; skipping.', $mode, $integration_id ) );
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'newspack_esp_delete_failed', implode( '; ', $errors ) );
		}
		return true;
	}

	/**
	 * Classify an ESP sync error to decide retry behavior.
	 *
	 * Matches against every message carried by the error — the ESP layer
	 * aggregates messages (invalid-list errors, exception detail) ahead of the
	 * provider's own error, so reading only the first message would miss a
	 * real signature exactly when a site is misconfigured.
	 *
	 * @param string|\WP_Error $error     The error from a failed push.
	 * @param string           $direction The sync direction: 'push' (default) or 'deletion'.
	 *                                    Deletion uses its own signature map because some
	 *                                    push-oriented classes invert their meaning on the
	 *                                    removal path.
	 * @return string One of 'permanent_contact', 'permanent_config', 'benign', or 'transient'.
	 */
	private static function classify_error( $error, $direction = 'push' ) {
		$message  = $error instanceof \WP_Error ? implode( ' ', $error->get_error_messages() ) : (string) $error;
		$haystack = strtolower( $message );

		$signature_map = 'deletion' === $direction ? self::DELETION_ERROR_SIGNATURES : self::ERROR_SIGNATURES;
		foreach ( $signature_map as $class => $signatures ) {
			foreach ( $signatures as $signature ) {
				if ( str_contains( $haystack, $signature ) ) {
					return $class;
				}
			}
		}

		return 'transient';
	}

	/**
	 * Schedule a retry for a failed integration sync via ActionScheduler.
	 *
	 * @param string           $integration_id The integration ID.
	 * @param int              $user_id        The WordPress user ID (0 when the contact has no
	 *                                         resolvable WP user).
	 * @param string           $context        The sync context.
	 * @param int              $retry_count    Current retry count (0 = first failure).
	 * @param string|\WP_Error $error          The error from the failure.
	 * @param string           $previous_email Optional. Previous email for email-change retries.
	 *
	 * @return string The error classification that decided the retry handling — one of
	 *                'benign', 'permanent_contact', 'permanent_config' or 'transient'.
	 *                Callers use 'benign' to detect a deliberately-ended retry chain.
	 */
	private static function schedule_integration_retry( $integration_id, $user_id, $context, $retry_count, $error, $previous_email = '' ) {
		$error_message = $error instanceof \WP_Error ? $error->get_error_message() : (string) $error;
		$error_class   = self::classify_error( $error );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return $error_class;
		}

		// Classification handling runs before the user-existence bail below so
		// benign and permanent results — including the actionable permanent_config
		// alert — apply even to syncs without a resolvable WP user (guest
		// checkouts, users deleted mid-flight).
		$user       = ! empty( $user_id ) ? get_userdata( $user_id ) : false;
		$user_email = $user ? $user->user_email : 'unknown';

		if ( 'benign' === $error_class ) {
			static::log(
				sprintf(
					'Skipping retry for integration "%s" sync of user %d (%s); ESP reports contact already synced. Detail: %s',
					$integration_id,
					$user_id,
					$user_email,
					$error_message
				)
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					'Benign result (contact already synced); retry chain deliberately ended.'
				);
			}
			return $error_class;
		}
		if ( 'transient' !== $error_class ) {
			static::log(
				sprintf(
					'Permanent %s failure for integration "%s" sync of user %d (%s); not retrying. Error: %s',
					$error_class,
					$integration_id,
					$user_id,
					$user_email,
					$error_message
				)
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					sprintf( 'Permanent failure (%s); not retrying.', $error_class )
				);
			}
			if ( 'permanent_config' === $error_class ) {
				/**
				 * Fires when a contact sync fails with a permanent config-level
				 * error (disabled/unpaid ESP account) that can never succeed on
				 * retry. Permanent contact-data errors are skipped silently on
				 * this path — the contact re-syncs on the reader's next event;
				 * only actionable config failures are surfaced. (The deletion
				 * path also fires this hook for permanent contact-data errors —
				 * see schedule_deletion_retry().)
				 *
				 * @param array $alert_data {
				 *     Alert data.
				 *
				 *     @type string $integration_id The integration that failed.
				 *     @type int    $user_id        The WordPress user ID (0 when the contact
				 *                                  has no resolvable WP user).
				 *     @type string $email          The contact's email address; empty when no
				 *                                  WP user could be resolved.
				 *     @type string $context        The sync context.
				 *     @type string $reason         The final error message.
				 *     @type string $error_class    'permanent_config' on this path.
				 * }
				 */
				do_action(
					'newspack_sync_permanent_failure',
					[
						'integration_id' => $integration_id,
						'user_id'        => $user_id,
						'email'          => $user ? $user->user_email : '',
						'context'        => $context,
						'reason'         => $error_message,
						'error_class'    => $error_class,
					]
				);
			}
			return $error_class;
		}

		if ( ! $user ) {
			static::log( sprintf( 'Cannot schedule retry for integration "%s": user %d not found.', $integration_id, $user_id ) );
			return $error_class;
		}

		$next_retry = $retry_count + 1;
		if ( $next_retry > self::MAX_RETRIES ) {
			static::log(
				sprintf(
					'Max retries (%d) reached for integration "%s" sync of user %d (%s). Giving up. Last error: %s',
					self::MAX_RETRIES,
					$integration_id,
					$user_id,
					$user_email,
					$error_message
				)
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					'Max retries exhausted.'
				);
			}
			/**
			 * Fires when a contact sync integration has exhausted all retry attempts.
			 *
			 * @param array $alert_data {
			 *     Alert data.
			 *
			 *     @type string $integration_id The integration that failed.
			 *     @type int    $user_id        The WordPress user ID.
			 *     @type string $context        The sync context.
			 *     @type int    $retry_count    Total retries attempted.
			 *     @type string $reason         The final error message.
			 * }
			 */
			do_action(
				'newspack_sync_retry_exhausted',
				[
					'integration_id' => $integration_id,
					'user_id'        => $user_id,
					'context'        => $context,
					'retry_count'    => self::MAX_RETRIES,
					'reason'         => $error_message,
				]
			);
			return $error_class;
		}

		$backoff_index   = min( $retry_count, count( self::RETRY_BACKOFF ) - 1 );
		$backoff_seconds = self::RETRY_BACKOFF[ $backoff_index ];

		$retry_data = [
			'integration_id' => $integration_id,
			'user_id'        => $user_id,
			'context'        => $context,
			'retry_count'    => $next_retry,
			'max_retries'    => self::MAX_RETRIES,
			'reason'         => $error_message,
			'previous_email' => $previous_email,
		];

		\as_schedule_single_action(
			time() + $backoff_seconds,
			self::RETRY_HOOK,
			[ $retry_data ],
			Integrations::get_action_group( $integration_id )
		);

		static::log(
			sprintf(
				'Scheduled retry %d/%d for integration "%s" sync of user %d (%s) in %ds. Error: %s',
				$next_retry,
				self::MAX_RETRIES,
				$integration_id,
				$user_id,
				$user_email,
				$backoff_seconds,
				$error_message
			)
		);
		return $error_class;
	}

	/**
	 * Execute an integration sync retry from ActionScheduler.
	 *
	 * @param array $retry_data The retry data containing integration_id, user_id, context, and retry_count.
	 *
	 * @throws \Exception When the final retry fails, so ActionScheduler marks the action as "failed".
	 */
	public static function execute_integration_retry( $retry_data ) {
		if ( ! is_array( $retry_data ) || empty( $retry_data['integration_id'] ) || empty( $retry_data['user_id'] ) ) {
			Logger::log( 'Invalid integration retry data received from Action Scheduler.', 'NEWSPACK-SYNC', 'error' );
			return;
		}

		$integration_id = $retry_data['integration_id'];
		$user_id        = $retry_data['user_id'];
		$context        = $retry_data['context'] ?? static::$context;
		$retry_count    = $retry_data['retry_count'] ?? 1;
		$previous_email = $retry_data['previous_email'] ?? '';

		$user = \get_userdata( $user_id );
		if ( ! $user ) {
			Logger::log( sprintf( 'User %d not found on retry %d.', $user_id, $retry_count ), 'NEWSPACK-SYNC', 'error' );
			return;
		}

		$contact = self::get_contact_data( $user_id );
		if ( is_wp_error( $contact ) ) {
			Logger::log( sprintf( 'Error getting contact data for user %d on retry %d: %s', $user_id, $retry_count, $contact->get_error_message() ), 'NEWSPACK-SYNC', 'error' );
			return;
		}

		$integration = Integrations::get_integration( $integration_id );
		if ( ! $integration ) {
			Logger::log( sprintf( 'Integration "%s" not found on retry %d.', $integration_id, $retry_count ), 'NEWSPACK-SYNC', 'error' );
			return;
		}

		if ( ! $integration->is_set_up() ) {
			static::log( sprintf( 'Integration "%s" no longer set up on retry %d; aborting retry chain.', $integration_id, $retry_count ) );
			return;
		}

		static::log( sprintf( 'Executing retry %d/%d for integration "%s" sync of user %d (%s).', $retry_count, self::MAX_RETRIES, $integration_id, $user_id, $contact['email'] ?? 'unknown' ) );

		/** This filter is documented in includes/reader-activation/sync/class-contact-sync.php */
		$contact = \apply_filters( 'newspack_esp_sync_contact', $contact, $context );
		$contact = Sync\Metadata::normalize_contact_data( $contact );

		// Reconstruct existing_contact for email-change retries so integrations
		// can upsert against the previous email address.
		$existing_contact = null;
		if ( ! empty( $previous_email ) ) {
			$existing_contact = array_merge( $contact, [ 'email' => $previous_email ] );
		}

		$integration_contact = $integration->prepare_contact( $contact );
		$result              = $integration->push_contact_data( $integration_contact, $context, $existing_contact );
		if ( \is_wp_error( $result ) ) {
			$error_messages = implode( '; ', $result->get_error_messages() );
			static::log(
				sprintf(
					'Retry %d failed for integration "%s" sync of user %d (%s): %s',
					$retry_count,
					$integration_id,
					$user_id,
					$contact['email'] ?? 'unknown',
					$error_messages
				)
			);
			$error_class   = self::schedule_integration_retry(
				$integration_id,
				$user_id,
				$context,
				$retry_count,
				$result,
				$previous_email
			);
			$error_message = sprintf(
				'Retry %d/%d failed for integration "%s" sync of user %d (%s): %s',
				$retry_count,
				self::MAX_RETRIES,
				$integration_id,
				$user_id,
				$contact['email'] ?? 'unknown',
				$error_messages
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					$error_message
				);
			}
			// Only throw on the last retry so ActionScheduler marks it as "failed".
			// Intermediate retries schedule the next attempt and complete normally.
			// A benign result is an effectively-synced outcome, not a failure, so
			// its deliberately-ended chain must not mark the action as failed.
			if ( $retry_count >= self::MAX_RETRIES && 'benign' !== $error_class ) {
				throw new \Exception( esc_html( $error_message ) );
			}
		} else {
			$success_message = sprintf(
				'Retry %d/%d succeeded for integration "%s" sync of user %d (%s).',
				$retry_count,
				self::MAX_RETRIES,
				$integration_id,
				$user_id,
				$contact['email'] ?? 'unknown'
			);
			static::log( $success_message );
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log( self::$current_as_action_id, $success_message );
			}
		}
	}

	/**
	 * Schedule a retry for a failed deletion sync via ActionScheduler.
	 *
	 * Mirrors schedule_integration_retry() but keys on email + mode + payload,
	 * since the WP user is gone by the time we get here.
	 *
	 * @param string           $integration_id The integration ID.
	 * @param string           $mode           Deletion mode: 'delete' or 'flag'.
	 * @param string           $email          Email of the deleted reader.
	 * @param array            $contact        Prepared flag-mode contact payload (empty in delete mode).
	 * @param string           $context        The sync context.
	 * @param int              $retry_count    Current retry count (0 = first failure).
	 * @param string|\WP_Error $error          The error from the failure.
	 *
	 * @return string The error classification that decided the retry handling — one of
	 *                'benign', 'permanent_contact', 'permanent_config' or 'transient'.
	 *                Callers use 'benign' to detect a deliberately-ended retry chain.
	 */
	private static function schedule_deletion_retry( $integration_id, $mode, $email, $contact, $context, $retry_count, $error ) {
		$error_message = $error instanceof \WP_Error ? $error->get_error_message() : (string) $error;
		$error_class   = self::classify_error( $error, 'deletion' );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return $error_class;
		}

		if ( 'benign' === $error_class ) {
			static::log(
				sprintf(
					'Skipping retry for deletion (%s) sync of %s in integration "%s"; ESP reports the contact is already gone. Detail: %s',
					$mode,
					$email,
					$integration_id,
					$error_message
				)
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					'Benign result (contact already gone from the ESP); retry chain deliberately ended.'
				);
			}
			return $error_class;
		}
		if ( 'transient' !== $error_class ) {
			static::log(
				sprintf(
					'Permanent %s failure for deletion (%s) sync of %s in integration "%s"; not retrying. Error: %s',
					$error_class,
					$mode,
					$email,
					$integration_id,
					$error_message
				)
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					sprintf( 'Permanent failure (%s); not retrying.', $error_class )
				);
			}
			/**
			 * Fires when a deletion sync fails with a permanent (non-retryable) error.
			 *
			 * Mirrors `newspack_sync_permanent_failure` on the contact-sync path
			 * (documented in includes/reader-activation/sync/class-contact-sync.php)
			 * with two differences: the payload substitutes `email` + `mode` for
			 * `user_id`, since the WP user is already gone, and the hook also fires
			 * for permanent contact-data errors — a skipped deletion retry has no
			 * natural re-trigger, so the dropped deletion signal must stay
			 * observable.
			 *
			 * @param array $alert_data {
			 *     Alert data.
			 *
			 *     @type string $integration_id The integration that failed.
			 *     @type string $email          Email of the deleted reader.
			 *     @type string $mode           Deletion mode: 'delete' or 'flag'.
			 *     @type string $context        The sync context.
			 *     @type string $reason         The final error message.
			 *     @type string $error_class    'permanent_config' or 'permanent_contact'.
			 * }
			 */
			do_action(
				'newspack_sync_permanent_failure',
				[
					'integration_id' => $integration_id,
					'email'          => $email,
					'mode'           => $mode,
					'context'        => $context,
					'reason'         => $error_message,
					'error_class'    => $error_class,
				]
			);
			return $error_class;
		}

		$next_retry = $retry_count + 1;
		if ( $next_retry > self::MAX_RETRIES ) {
			static::log(
				sprintf(
					'Max retries (%d) reached for deletion (%s) sync of %s in integration "%s". Giving up. Last error: %s',
					self::MAX_RETRIES,
					$mode,
					$email,
					$integration_id,
					$error_message
				)
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					'Max retries exhausted.'
				);
			}
			/**
			 * Fires when a deletion sync has exhausted all retry attempts.
			 *
			 * Mirrors `newspack_sync_retry_exhausted` for the regular sync path; the
			 * `mode` field carries the deletion handling mode (`delete` or `flag`).
			 *
			 * @param array $alert_data {
			 *     Alert data.
			 *
			 *     @type string $integration_id The integration that failed.
			 *     @type string $email          Email of the deleted reader.
			 *     @type string $mode           Deletion mode: 'delete' or 'flag'.
			 *     @type string $context        The sync context.
			 *     @type int    $retry_count    Total retries attempted.
			 *     @type string $reason         The final error message.
			 * }
			 */
			do_action(
				'newspack_sync_retry_exhausted',
				[
					'integration_id' => $integration_id,
					'email'          => $email,
					'mode'           => $mode,
					'context'        => $context,
					'retry_count'    => self::MAX_RETRIES,
					'reason'         => $error_message,
				]
			);
			return $error_class;
		}

		$backoff_index   = min( $retry_count, count( self::RETRY_BACKOFF ) - 1 );
		$backoff_seconds = self::RETRY_BACKOFF[ $backoff_index ];

		$retry_data = [
			'integration_id' => $integration_id,
			'mode'           => $mode,
			'email'          => $email,
			'contact'        => $contact,
			'context'        => $context,
			'retry_count'    => $next_retry,
			'max_retries'    => self::MAX_RETRIES,
			'reason'         => $error_message,
		];

		\as_schedule_single_action(
			time() + $backoff_seconds,
			self::RETRY_DELETION_HOOK,
			[ $retry_data ],
			Integrations::get_action_group( $integration_id )
		);

		static::log(
			sprintf(
				'Scheduled retry %d/%d for deletion (%s) sync of %s in integration "%s" in %ds. Error: %s',
				$next_retry,
				self::MAX_RETRIES,
				$mode,
				$email,
				$integration_id,
				$backoff_seconds,
				$error_message
			)
		);
		return $error_class;
	}

	/**
	 * Execute a deletion-sync retry from ActionScheduler.
	 *
	 * @param array $retry_data The retry data containing integration_id, mode, email, contact, context, and retry_count.
	 *
	 * @throws \Exception When the final retry fails, so ActionScheduler marks the action as "failed".
	 */
	public static function execute_deletion_retry( $retry_data ) {
		if (
			! is_array( $retry_data )
			|| empty( $retry_data['integration_id'] )
			|| empty( $retry_data['email'] )
			|| empty( $retry_data['mode'] )
		) {
			Logger::log( 'Invalid deletion retry data received from Action Scheduler.', 'NEWSPACK-SYNC', 'error' );
			return;
		}

		$integration_id = $retry_data['integration_id'];
		$mode           = $retry_data['mode'];
		$email          = $retry_data['email'];
		$contact        = isset( $retry_data['contact'] ) && is_array( $retry_data['contact'] ) ? $retry_data['contact'] : [];
		$context        = $retry_data['context'] ?? static::$context;
		$retry_count    = $retry_data['retry_count'] ?? 1;

		$integration = Integrations::get_integration( $integration_id );
		if ( ! $integration ) {
			Logger::log( sprintf( 'Integration "%s" not found on deletion retry %d.', $integration_id, $retry_count ), 'NEWSPACK-SYNC', 'error' );
			return;
		}

		if ( ! $integration->is_set_up() ) {
			static::log( sprintf( 'Integration "%s" no longer set up on deletion retry %d; aborting retry chain.', $integration_id, $retry_count ) );
			return;
		}

		static::log( sprintf( 'Executing retry %d/%d for deletion (%s) sync of %s in integration "%s".', $retry_count, self::MAX_RETRIES, $mode, $email, $integration_id ) );

		if ( 'delete' === $mode ) {
			$result = $integration->delete_contact( $email );
		} elseif ( 'flag' === $mode ) {
			$result = $integration->push_contact_data( $contact, $context );
		} else {
			Logger::log( sprintf( 'Unknown deletion retry mode "%s" for integration "%s".', $mode, $integration_id ), 'NEWSPACK-SYNC', 'error' );
			return;
		}

		if ( \is_wp_error( $result ) ) {
			$error_messages = implode( '; ', $result->get_error_messages() );
			static::log(
				sprintf(
					'Retry %d failed for deletion (%s) sync of %s in integration "%s": %s',
					$retry_count,
					$mode,
					$email,
					$integration_id,
					$error_messages
				)
			);
			$error_class   = self::schedule_deletion_retry( $integration_id, $mode, $email, $contact, $context, $retry_count, $result );
			$error_message = sprintf(
				'Retry %d/%d failed for deletion (%s) sync of %s in integration "%s": %s',
				$retry_count,
				self::MAX_RETRIES,
				$mode,
				$email,
				$integration_id,
				$error_messages
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log( self::$current_as_action_id, $error_message );
			}
			// Only throw on the last retry so ActionScheduler marks it as "failed".
			// Intermediate retries schedule the next attempt and complete normally.
			// A benign result (contact already gone from the ESP) is the deletion
			// end-state, not a failure, so its deliberately-ended chain must not
			// mark the action as failed.
			if ( $retry_count >= self::MAX_RETRIES && 'benign' !== $error_class ) {
				throw new \Exception( esc_html( $error_message ) );
			}
		} else {
			$success_message = sprintf(
				'Retry %d/%d succeeded for deletion (%s) sync of %s in integration "%s".',
				$retry_count,
				self::MAX_RETRIES,
				$mode,
				$email,
				$integration_id
			);
			static::log( $success_message );
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log( self::$current_as_action_id, $success_message );
			}
		}
	}

	/**
	 * Get the set of user IDs with pending sync retries in ActionScheduler.
	 *
	 * Useful for batch processing: fetch once, then check membership with isset()
	 * instead of calling has_pending_retries() per user.
	 *
	 * @return array<int, bool> Map keyed by user ID for O(1) lookup.
	 */
	public static function get_pending_retry_user_ids() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [];
		}
		$actions = \as_get_scheduled_actions(
			[
				'hook'     => self::RETRY_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);
		$user_ids = [];
		foreach ( $actions as $action ) {
			$args = $action->get_args();
			if ( ! empty( $args[0]['user_id'] ) ) {
				$user_ids[ (int) $args[0]['user_id'] ] = true;
			}
		}
		return $user_ids;
	}

	/**
	 * Check if a user has any pending sync retries in ActionScheduler.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if there are pending retries.
	 */
	public static function has_pending_retries( $user_id ) {
		return isset( self::get_pending_retry_user_ids()[ (int) $user_id ] );
	}

	/**
	 * Schedule a future sync.
	 *
	 * @param int    $user_id The user ID for the contact to sync.
	 * @param string $context The context of the sync.
	 * @param int    $delay   The delay in seconds.
	 */
	public static function schedule_sync( $user_id, $context, $delay ) {
		// Schedule another sync in $delay number of seconds.
		if ( ! is_int( $delay ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		static::log(
			sprintf(
				// Translators: %s is the email address of the contact to synced.
				__( 'Scheduling secondary sync for contact %s.', 'newspack-plugin' ),
				$user->data->user_email
			),
			[
				'user_email' => $user->data->user_email,
				'user_id'    => $user_id,
				'context'    => $context,
			]
		);
		\wp_schedule_single_event( \time() + $delay, 'newspack_scheduled_esp_sync', [ $user_id, $context ] );
	}

	/**
	 * Handle a scheduled sync event.
	 *
	 * @param int    $user_id The user ID for the contact to sync.
	 * @param string $context The context of the sync.
	 */
	public static function scheduled_sync( $user_id, $context ) {
		$contact = Sync\Metadata::get_contact_with_metadata( $user_id );
		if ( empty( $contact['email'] ) ) {
			return;
		}
		self::sync( $contact, $context );
	}

	/**
	 * Get contact data for syncing.
	 *
	 * @param int           $user_id The user ID.
	 * @param string[]|null $fields  Optional. Canonical field labels to restrict the computed
	 *                               metadata to. `null` computes every available field.
	 *
	 * @return array|\WP_Error The contact data or WP_Error.
	 */
	public static function get_contact_data( $user_id, $fields = null ) {
		$user = \get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'newspack_esp_sync_contact', __( 'User not found.', 'newspack-plugin' ) );
		}

		$contact = [
			'email'    => $user->user_email,
			'name'     => $user->display_name,
			'metadata' => [],
		];

		if ( ! class_exists( '\WC_Customer' ) ) {
			return $contact;
		}
		$customer = new \WC_Customer( $user_id );
		if ( ! $customer || ! $customer->get_id() ) {
			return new \WP_Error(
				'newspack_esp_sync_contact',
				sprintf(
				// Translators: %d is the user ID.
					__( 'Customer with ID %d does not exist.', 'newspack-plugin' ),
					$user_id
				)
			);
		}

		// Ensure the customer has a billing address.
		if ( ! $customer->get_billing_email() && $customer->get_email() ) {
			$customer->set_billing_email( $customer->get_email() );
			$customer->save();
		}

		$contact = Sync\Metadata::get_contact_with_metadata( $customer, $fields );

		return $contact;
	}

	/**
	 * Given a user ID or WooCommerce Order, sync that reader's contact data to
	 * the connected ESP.
	 *
	 * @param int|\WC_order $user_id_or_order User ID or WC_Order object.
	 * @param string        $context          The context of the sync.
	 * @param bool          $is_dry_run       True if a dry run.
	 * @param array         $options          Optional. Sync options: `skip_lists` (bool) and
	 *                                        `fields` (string[]|null, canonical labels). `fields`
	 *                                        restricts both what metadata is computed and what is
	 *                                        pushed; `skip_lists` upserts without a master list.
	 *
	 * @return true|\WP_Error True if the contact was synced successfully, WP_Error otherwise.
	 */
	public static function sync_contact( $user_id_or_order, $context = '', $is_dry_run = false, $options = [] ) {
		$can_sync = static::can_sync( true );
		if ( ! $is_dry_run && $can_sync->has_errors() ) {
			return $can_sync;
		}

		$is_order = $user_id_or_order instanceof \WC_Order;
		$order    = $is_order ? $user_id_or_order : false;
		$user_id  = $is_order ? $order->get_customer_id() : $user_id_or_order;
		$fields   = $options['fields'] ?? null;

		$contact = $is_order ? Sync\Metadata::get_contact_with_metadata( $order, $fields ) : self::get_contact_data( $user_id, $fields );
		if ( \is_wp_error( $contact ) || empty( $contact['email'] ) ) {
			return \is_wp_error( $contact ) ? $contact : new \WP_Error( 'newspack_esp_sync_contact', __( 'Contact email is empty.', 'newspack-plugin' ) );
		}

		if ( $is_dry_run && ! self::options_are_default( $options ) ) {
			self::log_dry_run_with_options( $contact, $context, $options );
		}

		$result = $is_dry_run ? true : self::sync( $contact, $context, null, $options );

		if ( $result && ! \is_wp_error( $result ) ) {
			static::log(
				sprintf(
					// Translators: %1$s is the status and %2$s is the contact's email address.
					__( '%1$s contact data for %2$s.', 'newspack-plugin' ),
					$is_dry_run ? __( 'Would sync', 'newspack-plugin' ) : __( 'Synced', 'newspack-plugin' ),
					$contact['email']
				)
			);
		}

		return $result;
	}

	/**
	 * Log, per active integration, the field/list-scoped payload a `--dry-run`
	 * with custom options would push. Warns when scoping leaves no metadata to
	 * send (e.g. requested fields aren't enabled as outgoing for that integration).
	 *
	 * @param array  $contact The computed contact data.
	 * @param string $context The sync context.
	 * @param array  $options Sync options (`skip_lists`, `fields`).
	 *
	 * @return void
	 */
	private static function log_dry_run_with_options( $contact, $context, $options ) {
		// Mirror the real push path (push_to_integrations): run the contact filter
		// before per-integration scoping so the preview reflects any metadata a
		// publisher filter contributes.
		/** This filter is documented in includes/reader-activation/sync/class-contact-sync.php. */
		$contact    = \apply_filters( 'newspack_esp_sync_contact', $contact, $context );
		$skip_lists = ! empty( $options['skip_lists'] );
		foreach ( Integrations::get_active_configured_integrations() as $integration_id => $integration ) {
			$prepared = self::prepare_contact_for_integration( $integration, $contact, $options );
			$metadata = $prepared['metadata'] ?? [];
			static::log(
				sprintf(
					'[dry-run] %s → integration "%s": lists %s, %d field(s): %s',
					$prepared['email'] ?? 'unknown',
					$integration_id,
					$skip_lists ? 'skipped' : 'master list',
					count( $metadata ),
					implode( ', ', array_keys( $metadata ) )
				)
			);
			if ( ! empty( $options['fields'] ) && empty( $metadata ) ) {
				static::log(
					sprintf(
						'[dry-run] WARNING: no metadata to sync for integration "%s" — this reader likely has no values for the requested fields (the CLI pre-flight already confirmed they are enabled as outgoing fields).',
						$integration_id
					)
				);
			}
		}
	}

	/**
	 * Run queued syncs.
	 *
	 * @return void
	 */
	public static function run_queued_syncs() {
		if ( empty( self::$queued_syncs ) ) {
			return;
		}

		// Restore the AS action ID so push_to_integrations() can log against it.
		$saved_action_id = self::$current_as_action_id;

		foreach ( self::$queued_syncs as $email => $queued_sync ) {
			// A deletion for this email ran earlier in the request. Never re-push it,
			// or a later event in the same batch would resurrect a deleted contact.
			if ( isset( self::$deleted_emails[ $email ] ) ) {
				continue;
			}
			self::$current_as_action_id = $queued_sync['as_action_id'] ?? null;

			$user = get_user_by( 'email', $email );
			$contact = null;
			if ( $user ) {
				// For existing users, get fresh contact data.
				$contact = self::get_contact_data( $user->ID );
			} else {
				// For deleted users, try to use the queued contact data directly; $user will return nothing.
				$contact = $queued_sync['contact'];
			}
			if ( ! $contact ) {
				continue;
			}
			$contexts = $queued_sync['contexts'];
			self::sync( $contact, implode( '; ', $contexts ) );
		}

		self::$current_as_action_id = $saved_action_id;
		self::$queued_syncs   = [];
		self::$deleted_emails = [];
	}
}
Contact_Sync::init_hooks();
