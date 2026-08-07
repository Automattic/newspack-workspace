<?php
/**
 * Provisions Newspack's standard GA4 custom dimensions on the publisher's
 * connected GA4 property.
 *
 * Auth routing lives in GA4_Client: Newspack's own Google OAuth credentials
 * first (whose tokens carry the `analytics.edit` scope these writes need),
 * falling back to Site Kit's authenticated client.
 *
 * @package Newspack
 */

namespace Newspack;

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
		return GA4_Client::get_property_id();
	}

	/**
	 * Run a callable with an authenticated GA4 Admin API client.
	 *
	 * Provisioning writes dimensions, so the `analytics.edit` scope is
	 * required — see GA4_Client for the routing and the scope rationale.
	 *
	 * @param callable $callback Called with `( $client, string $source )`.
	 * @return mixed|\WP_Error The callback's return value, or WP_Error.
	 */
	private static function with_admin_client( callable $callback ) {
		return GA4_Client::with_admin_client( $callback, [ 'require_edit_scope' => true ] );
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

		// A run that adds a dimension is what makes a report querying it
		// possible, so let the segment-reach fetch start over rather than
		// stay given-up until the property changes.
		if ( ! empty( $created ) ) {
			GA4_Segment_Reach::reset_failures();
		}

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
