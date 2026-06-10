<?php
/**
 * Provisions Newspack's standard GA4 custom dimensions on the publisher's
 * connected GA4 property.
 *
 * Prefers Newspack's own Google OAuth credentials (whose tokens carry the
 * `analytics.edit` scope) and falls back to Site Kit's authenticated client
 * when Newspack OAuth is not configured, its token lacks that scope, or a call
 * through it fails.
 *
 * @package Newspack
 */

namespace Newspack;

use Google\Site_Kit\Context;

defined( 'ABSPATH' ) || exit;

/**
 * GA4 custom dimensions provisioning.
 */
final class GA4_Custom_Dimensions {

	const PROVISIONED_OPTION = 'newspack_ga4_dimensions_provisioned';
	const LOGGER_HEADER      = 'NEWSPACK-GA4-DIMENSIONS';
	const PROVISION_ACTION   = 'newspack_ga4_provision_dimensions';
	const RECHECK_ACTION     = 'newspack_ga4_recheck_dimensions';
	const RECHECK_GROUP      = 'newspack';

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Re-run provisioning when Site Kit's GA4 property ID is first set or changes.
		add_action( 'add_option_googlesitekit_analytics-4_settings', [ __CLASS__, 'on_sitekit_settings_added' ], 10, 2 );
		add_action( 'update_option_googlesitekit_analytics-4_settings', [ __CLASS__, 'on_sitekit_settings_updated' ], 10, 2 );
		add_action( self::PROVISION_ACTION, [ __CLASS__, 'provision' ] );
		add_action( self::RECHECK_ACTION, [ __CLASS__, 'provision' ] );
		// Catch sites that were already connected before this code shipped.
		add_action( 'admin_init', [ __CLASS__, 'maybe_schedule_recheck' ] );
	}

	/**
	 * Fired when Site Kit's analytics-4 option is first added. Schedules
	 * provisioning if the new settings include a property ID.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New option value.
	 */
	public static function on_sitekit_settings_added( $option, $value ) {
		$property_id = is_array( $value ) && ! empty( $value['propertyID'] ) ? (string) $value['propertyID'] : '';
		if ( '' === $property_id ) {
			return;
		}
		self::schedule_provisioning( $property_id );
		self::maybe_schedule_recheck();
	}

	/**
	 * Fired when Site Kit's analytics-4 option is updated. Schedules
	 * provisioning if the property ID has just been set or has changed.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function on_sitekit_settings_updated( $old_value, $new_value ) {
		$new_property_id = is_array( $new_value ) && ! empty( $new_value['propertyID'] ) ? (string) $new_value['propertyID'] : '';
		$old_property_id = is_array( $old_value ) && ! empty( $old_value['propertyID'] ) ? (string) $old_value['propertyID'] : '';
		if ( $old_property_id === $new_property_id ) {
			return;
		}
		if ( '' === $new_property_id ) {
			// Property disconnected – drop the recurring recheck.
			self::maybe_schedule_recheck();
			return;
		}
		self::schedule_provisioning( $new_property_id );
		self::maybe_schedule_recheck();
	}

	/**
	 * Schedule an immediate single-shot WP-Cron event to run provisioning in
	 * the background. Skips if the property has already been provisioned.
	 *
	 * The event is keyed only on the action name, not the property. If the
	 * connected property changes again before a pending event fires, no second
	 * event is queued; the handler reads the current property at run time, so
	 * the latest value wins (the intended outcome). Any dimensions partially
	 * created against a superseded property are simply left in place – harmless,
	 * they just won't show up in the summary for the new property.
	 *
	 * @param string $property_id The GA4 property ID that will be provisioned.
	 */
	private static function schedule_provisioning( $property_id ) {
		$provisioned = get_option( self::PROVISIONED_OPTION, [] );
		if (
			is_array( $provisioned )
			&& isset( $provisioned['property_id'] )
			&& (string) $provisioned['property_id'] === $property_id
		) {
			return;
		}
		if ( wp_next_scheduled( self::PROVISION_ACTION ) ) {
			return;
		}
		wp_schedule_single_event( time() + 10, self::PROVISION_ACTION );
		Logger::log( "Scheduled GA4 dimension provisioning for property $property_id.", self::LOGGER_HEADER );
	}

	/**
	 * Keep a recurring monthly recheck scheduled while a GA4 property is
	 * connected, and drop it when none is. The recheck re-runs provisioning so
	 * additions to Newspack's dimension list, or dimensions deleted in GA4,
	 * self-heal without a manual CLI run. When everything is already in place it
	 * is a no-op: one list call, zero writes.
	 *
	 * Idempotent and safe to call repeatedly (e.g. on every admin page load).
	 */
	public static function maybe_schedule_recheck() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$is_scheduled = as_has_scheduled_action( self::RECHECK_ACTION, [], self::RECHECK_GROUP );
		if ( ! self::get_property_id() ) {
			if ( $is_scheduled && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::RECHECK_ACTION, [], self::RECHECK_GROUP );
			}
			return;
		}
		if ( $is_scheduled ) {
			return;
		}
		as_schedule_recurring_action( time() + MONTH_IN_SECONDS, MONTH_IN_SECONDS, self::RECHECK_ACTION, [], self::RECHECK_GROUP );
		Logger::log( 'Scheduled monthly GA4 dimension recheck.', self::LOGGER_HEADER );
	}

	/**
	 * Priority-ordered list of custom dimensions Newspack provisions.
	 * Each entry: parameter name => display name.
	 */
	public static function get_dimensions() {
		return [
			'gate_post_id'                => 'Gate Post ID',
			'is_reader'                   => 'Is Reader',
			'action_type'                 => 'Action Type',
			'action'                      => 'Action',
			'logged_in'                   => 'Logged In',
			'is_subscriber'               => 'Is Subscriber',
			'is_donor'                    => 'Is Donor',
			'is_newsletter_subscriber'    => 'Is Newsletter Subscriber',
			'newspack_popup_id'           => 'Newspack Popup ID',
			'prompt_placement'            => 'Prompt Placement',
			'prompt_frequency'            => 'Prompt Frequency',
			'prompt_title'                => 'Prompt Title',
			'gate_has_donation_block'     => 'Gate Has Donation Block',
			'gate_has_registration_block' => 'Gate Has Registration Block',
			'gate_has_checkout_button'    => 'Gate Has Checkout Button',
			'gate_has_registration_link'  => 'Gate Has Registration Link',
			'gate_has_signin_link'        => 'Gate Has Signin Link',
			'product_id'                  => 'Product ID',
			'product_type'                => 'Product Type',
			'recurrence'                  => 'Recurrence',
			'price'                       => 'Price',
			'donation_frequency'          => 'Donation Frequency',
			'donation_amount'             => 'Donation Amount',
			'registration_method'         => 'Registration Method',
			'lists'                       => 'Newsletter Lists',
			'categories'                  => 'Categories',
			'author'                      => 'Author',
		];
	}

	/**
	 * Read the connected GA4 property ID from Site Kit's stored settings.
	 *
	 * @return string|false
	 */
	private static function get_property_id() {
		$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
		if ( empty( $settings['propertyID'] ) ) {
			return false;
		}
		return (string) $settings['propertyID'];
	}

	/**
	 * Run a callable with an authenticated GA4 Admin API client.
	 *
	 * Tries Newspack's own Google OAuth first. Its tokens already carry
	 * `analytics.edit` and the call hits the proxy's GCP project, which has
	 * the Admin API enabled. If Newspack OAuth is not configured or the
	 * callback throws/returns a WP_Error, falls back to Site Kit's client
	 * (which stores tokens keyed on user ID and requires `analytics.edit`
	 * to have been granted — which many publishers have not done).
	 *
	 * Switches the current user to a capable one if none is set (e.g. in
	 * WP-Cron) so permission checks in `Google_OAuth::get_oauth2_credentials()`
	 * and Site Kit's `User_Options` can resolve credentials. Restores the
	 * previous user before returning so we don't leak an unexpected identity
	 * into subsequent operations in the same process.
	 *
	 * The callback is invoked as `$callback( $client, $source )` where
	 * `$source` is either 'newspack' or 'sitekit'. Both client types expose
	 * `list_custom_dimensions()` and `create_custom_dimension()` with
	 * matching signatures.
	 *
	 * If every route fails, the returned WP_Error names each route that was
	 * tried and why it failed, so a 403 on writes (the common "publisher never
	 * granted analytics.edit to Site Kit" case) is self-explanatory rather than
	 * buried in the log.
	 *
	 * @param callable $callback Called with `( $client, string $source )`.
	 * @return mixed|\WP_Error The callback's return value, or WP_Error.
	 */
	private static function with_admin_client( callable $callback ) {
		$previous_user_id = get_current_user_id();
		$switched_user    = false;

		if ( ! $previous_user_id ) {
			$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
			$owner_id = isset( $settings['ownerID'] ) ? (int) $settings['ownerID'] : 0;
			if ( $owner_id <= 0 ) {
				return new \WP_Error( 'newspack_ga4_dimensions', 'No user context available to authenticate GA4 Admin API calls.' );
			}
			wp_set_current_user( $owner_id );
			$switched_user = true;
		}

		// Route name => why it was skipped or failed, used to compose the error
		// if nothing works.
		$attempts = [];

		try {
			// Prefer Newspack's own OAuth. Returns null if not configured or no
			// credentials are saved; skip it outright if its token predates the
			// analytics.edit scope, since writes would just 403.
			$np_client = Google_OAuth_GA4_Client::build();
			if ( ! $np_client ) {
				$attempts['Newspack OAuth'] = 'not configured';
				Logger::log( 'Newspack OAuth not available; trying Site Kit.', self::LOGGER_HEADER );
			} elseif ( ! Google_OAuth_GA4_Client::has_edit_scope() ) {
				$attempts['Newspack OAuth'] = 'stored token lacks the analytics.edit scope (reconnect Google in the Newspack settings to grant it)';
				Logger::log( 'Newspack OAuth token lacks analytics.edit; trying Site Kit.', self::LOGGER_HEADER );
			} else {
				try {
					$result = $callback( $np_client, 'newspack' );
					if ( ! is_wp_error( $result ) ) {
						return $result;
					}
					$attempts['Newspack OAuth'] = $result->get_error_message();
				} catch ( \Throwable $e ) {
					$attempts['Newspack OAuth'] = $e->getMessage();
				}
				Logger::log( 'Newspack OAuth path failed (' . $attempts['Newspack OAuth'] . '); trying Site Kit.', self::LOGGER_HEADER );
			}

			// Fall back to Site Kit.
			if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) || ! class_exists( __NAMESPACE__ . '\\GoogleSiteKitAnalytics' ) ) {
				$attempts['Site Kit'] = 'not available';
				return new \WP_Error( 'newspack_ga4_dimensions', self::describe_auth_failure( $attempts ) );
			}
			try {
				$module = new GoogleSiteKitAnalytics( new Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE ) );
				$result = $callback( $module, 'sitekit' );
				if ( ! is_wp_error( $result ) ) {
					return $result;
				}
				$attempts['Site Kit'] = $result->get_error_message();
			} catch ( \Throwable $e ) {
				$attempts['Site Kit'] = $e->getMessage();
			}
			return new \WP_Error( 'newspack_ga4_dimensions', self::describe_auth_failure( $attempts ) );
		} finally {
			if ( $switched_user ) {
				wp_set_current_user( $previous_user_id );
			}
		}
	}

	/**
	 * Compose a human-readable failure message from a map of auth route =>
	 * failure reason.
	 *
	 * @param array<string,string> $attempts Route name => reason.
	 * @return string
	 */
	private static function describe_auth_failure( array $attempts ) {
		$parts = [];
		foreach ( $attempts as $route => $reason ) {
			$parts[] = "$route – $reason";
		}
		return 'Could not reach the GA4 Admin API. Tried: ' . implode( '; ', $parts ) . '.';
	}

	/**
	 * Report the current state without making any changes: which auth route
	 * is in use, whether the GA4 property is connected, and how many of our
	 * standard dimensions are already present.
	 *
	 * @return array|\WP_Error
	 */
	public static function status() {
		$property_id = self::get_property_id();
		if ( ! $property_id ) {
			return new \WP_Error( 'newspack_ga4_dimensions', 'No GA4 property ID configured in Site Kit.' );
		}

		$used_source = null;
		$existing    = self::with_admin_client(
			function ( $client, $source ) use ( $property_id, &$used_source ) {
				$used_source = $source;
				try {
					return $client->list_custom_dimensions( $property_id );
				} catch ( \Throwable $e ) {
					return new \WP_Error( 'newspack_ga4_dimensions', 'Failed listing custom dimensions: ' . $e->getMessage() );
				}
			}
		);
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$event_scoped = [];
		foreach ( $existing as $dimension ) {
			if ( isset( $dimension['scope'] ) && 'EVENT' === $dimension['scope'] ) {
				$event_scoped[] = $dimension['parameterName'];
			}
		}

		$desired          = array_keys( self::get_dimensions() );
		$existing_params  = array_column( $existing, 'parameterName' );
		$missing          = array_values( array_diff( $desired, $existing_params ) );
		$already_present  = array_values( array_intersect( $desired, $existing_params ) );

		return [
			'property_id'           => $property_id,
			'auth_source'           => $used_source,
			'site_kit_connected'    => true,
			'event_scoped_existing' => count( $event_scoped ),
			'newspack_total'        => count( $desired ),
			'newspack_present'      => $already_present,
			'newspack_missing'      => $missing,
			'provisioned_option'    => get_option( self::PROVISIONED_OPTION, null ),
		];
	}

	/**
	 * List the parameter names of every EVENT-scoped custom dimension currently
	 * registered on the connected GA4 property.
	 *
	 * Scoped to EVENT dimensions specifically because the Data API references
	 * those as `customEvent:<param>` (USER-scoped dimensions are `customUser:`),
	 * so callers checking a `customEvent:` reference must not be satisfied by a
	 * same-named USER-scoped dimension.
	 *
	 * Unlike status(), this returns the full event-scoped set actually present
	 * on the property — not just the intersection with Newspack's standard set —
	 * so callers can authoritatively check whether an arbitrary `customEvent:`
	 * dimension (e.g. `post_id`) is available before querying the Data API.
	 *
	 * Reuses the same Newspack-OAuth-then-Site-Kit auth fallback as the rest of
	 * this class.
	 *
	 * @param string|null $property_id GA4 property ID to list dimensions for. When
	 *                                 null (default), resolves Site Kit's configured
	 *                                 property. Pass an explicit ID so the Admin API
	 *                                 lookup matches the Data API property being
	 *                                 queried (the lists can differ per property).
	 *
	 * @return string[]|\WP_Error Registered event-scoped parameter names, or
	 *                            WP_Error if the property or Admin API can't be reached.
	 */
	public static function get_registered_parameter_names( ?string $property_id = null ) {
		$property_id = $property_id ?? self::get_property_id();
		if ( ! $property_id ) {
			return new \WP_Error( 'newspack_ga4_dimensions', 'No GA4 property ID configured in Site Kit.' );
		}

		$existing = self::with_admin_client(
			function ( $client, $source ) use ( $property_id ) {
				try {
					return $client->list_custom_dimensions( $property_id );
				} catch ( \Throwable $e ) {
					return new \WP_Error( 'newspack_ga4_dimensions', 'Failed listing custom dimensions: ' . $e->getMessage() );
				}
			}
		);
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$event_scoped = array_filter(
			$existing,
			function ( $dimension ) {
				return isset( $dimension['scope'] ) && 'EVENT' === $dimension['scope'];
			}
		);

		return array_values( array_filter( array_column( $event_scoped, 'parameterName' ) ) );
	}

	/**
	 * Provision Newspack's standard GA4 custom dimensions.
	 *
	 * Idempotent: existing dimensions on the property are detected by
	 * parameter name and skipped. Per-dimension create failures are logged
	 * and recorded in the summary but do not abort the run.
	 *
	 * Cron and Action Scheduler run handlers synchronously inside a request
	 * whose time limit is often 30–60s, while creating ~27 dimensions each
	 * behind a 15s HTTP timeout can run longer in a pathological case. We lift
	 * the limit where the host allows it (CLI already runs unlimited). On hosts
	 * that disable `set_time_limit`, a very slow run may still be cut short
	 * before the summary is written; that's safe – the next scheduled run lists
	 * what already exists and only creates the remainder.
	 *
	 * @return array|\WP_Error Summary of what was created and skipped, or error.
	 */
	public static function provision() {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$property_id = self::get_property_id();
		if ( ! $property_id ) {
			Logger::log( 'No GA4 property ID found; skipping custom dimension provisioning.', self::LOGGER_HEADER );
			return new \WP_Error( 'newspack_ga4_dimensions', 'No GA4 property ID configured.' );
		}

		$used_source = null;
		$result      = self::with_admin_client(
			function ( $client, $source ) use ( $property_id, &$used_source ) {
				$used_source = $source;
				try {
					$existing = $client->list_custom_dimensions( $property_id );
				} catch ( \Throwable $e ) {
					Logger::log( 'Failed listing GA4 custom dimensions: ' . $e->getMessage(), self::LOGGER_HEADER );
					return new \WP_Error( 'newspack_ga4_dimensions', 'Failed listing custom dimensions: ' . $e->getMessage() );
				}

				$existing_params = [];
				foreach ( $existing as $dimension ) {
					if ( isset( $dimension['parameterName'] ) ) {
						$existing_params[ $dimension['parameterName'] ] = true;
					}
				}

				$created        = [];
				$skipped_exists = [];
				$errors         = [];

				foreach ( self::get_dimensions() as $parameter_name => $display_name ) {
					if ( isset( $existing_params[ $parameter_name ] ) ) {
						$skipped_exists[] = $parameter_name;
						continue;
					}
					try {
						$client->create_custom_dimension( $property_id, $parameter_name, $display_name );
						$created[] = $parameter_name;
						Logger::log( "Created GA4 dimension '$parameter_name' on property $property_id.", self::LOGGER_HEADER );
					} catch ( \Throwable $e ) {
						$errors[ $parameter_name ] = $e->getMessage();
						Logger::log( "Failed to create GA4 dimension '$parameter_name': " . $e->getMessage(), self::LOGGER_HEADER );
					}
				}

				return [ $created, $skipped_exists, $errors ];
			}
		);

		if ( is_wp_error( $result ) ) {
			Logger::log( 'Skipping provisioning: ' . $result->get_error_message(), self::LOGGER_HEADER );
			return $result;
		}

		list( $created, $skipped_exists, $errors ) = $result;

		$summary = [
			'property_id'    => $property_id,
			'auth_source'    => $used_source,
			'timestamp'      => time(),
			'created'        => $created,
			'skipped_exists' => $skipped_exists,
			'errors'         => $errors,
		];

		// Merge created lists across runs only when the previous run targeted
		// the same property, so a property switch starts fresh.
		$previous = get_option( self::PROVISIONED_OPTION, [] );
		if (
			is_array( $previous )
			&& isset( $previous['property_id'], $previous['created'] )
			&& (string) $previous['property_id'] === $property_id
			&& is_array( $previous['created'] )
		) {
			$summary['created'] = array_values( array_unique( array_merge( $previous['created'], $created ) ) );
		}

		update_option( self::PROVISIONED_OPTION, $summary, false );

		Logger::log(
			sprintf(
				'GA4 dimension provisioning complete for property %s. Created: %d, existed: %d, errors: %d',
				$property_id,
				count( $created ),
				count( $skipped_exists ),
				count( $errors )
			),
			self::LOGGER_HEADER
		);

		return $summary;
	}
}
GA4_Custom_Dimensions::init();
