<?php
/**
 * Reader Registration API for third-party integrations.
 *
 * Handles the REST endpoint, integration registry, key generation,
 * and rate limiting for frontend reader registration via integrations.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Recaptcha;
use Newspack\Logger;
use Newspack\Reader_Activation\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Reader Registration class.
 */
final class Reader_Registration {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		if ( Reader_Activation::is_enabled() ) {
			\add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		}
	}

	/**
	 * Register the REST route for frontend reader registration.
	 */
	public static function register_routes() {
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/reader-activation/check-email',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_check_email_exists' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'default'           => '',
					],
				],
			]
		);
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/reader-activation/register',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_frontend_register_reader' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'npe'                  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'default'           => '',
					],
					'email'                => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
					'integration_id'       => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
					'integration_key'      => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
					'first_name'           => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
					'last_name'            => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
					'metadata'             => [
						'type'              => 'object',
						'default'           => [],
						'sanitize_callback' => [ __CLASS__, 'sanitize_metadata' ],
					],
					'g-recaptcha-response' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
				],
			]
		);
	}

	/**
	 * Sanitize the metadata parameter.
	 *
	 * Ensures all keys and values are sanitized strings. If this ever accepts
	 * arrays, revisit {@see is_reserved_meta_key()}.
	 *
	 * @param array $metadata Raw metadata from the request.
	 * @return array Sanitized metadata.
	 */
	public static function sanitize_metadata( $metadata ) {
		if ( ! is_array( $metadata ) ) {
			return [];
		}
		$sanitized = [];
		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! empty( $key ) ) {
				$sanitized[ $key ] = \sanitize_text_field( $value );
			}
		}
		return $sanitized;
	}

	/**
	 * Whether a user meta key is reserved and therefore not caller-writable.
	 *
	 * Covers Newspack reader state and reader data, the identifiers other systems
	 * resolve records against, and WordPress account state. Other code trusts these
	 * values, so registration metadata must not set them. Add to the list when adding
	 * a key that gates anything.
	 *
	 * Normalizes the key itself, so it is safe to call with a raw request key.
	 *
	 * @param int|string $meta_key Meta key to check. Numeric array keys arrive as int.
	 * @return bool True if the key is reserved.
	 */
	public static function is_reserved_meta_key( $meta_key ): bool {
		$key = \sanitize_key( \wp_unslash( (string) $meta_key ) );
		if ( '' === $key ) {
			return true;
		}

		$reserved_prefixes = [ 'np_', '_np_', 'newspack_', '_newspack_' ];
		foreach ( $reserved_prefixes as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}

		$reserved_keys = [
			// Other systems' identifiers, read via get_user_option(), which falls back
			// to the unprefixed key. WooPayments needs all three.
			'wpcom_user_id',
			'_stripe_customer_id',
			'_wcpay_customer_id',
			'_wcpay_customer_id_live',
			'_wcpay_customer_id_test',

			'session_tokens',
			'_application_passwords',
			'default_password_nag',
		];

		/**
		 * Filters additional user meta keys that registration metadata may not write.
		 *
		 * Additive only: the keys above are always reserved and cannot be removed.
		 *
		 * @param string[] $keys Additional reserved meta keys.
		 */
		$reserved_keys = array_merge( $reserved_keys, (array) \apply_filters( 'newspack_reserved_registration_meta_keys', [] ) );

		if ( in_array( $key, $reserved_keys, true ) ) {
			return true;
		}

		return self::is_prefixed_account_key( $key );
	}

	/**
	 * Whether a key is one of the table-prefixed WordPress account keys.
	 *
	 * Prefixed, with a per-blog segment on multisite (wp_2_capabilities). Matches the
	 * site's prefix and the default, so this holds on any configuration.
	 *
	 * @param string $key Sanitized meta key.
	 * @return bool True if the key is a prefixed account key.
	 */
	private static function is_prefixed_account_key( string $key ): bool {
		global $wpdb;

		$prefixes = [ 'wp_' ];
		if ( isset( $wpdb->base_prefix ) ) {
			$prefixes[] = \sanitize_key( $wpdb->base_prefix );
		}
		foreach ( array_unique( $prefixes ) as $prefix ) {
			if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . '(\d+_)?(capabilities|user_level|user-settings|user-settings-time)$/', $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get registered frontend registration integrations.
	 *
	 * @return array<string, string> Map of integration ID => label.
	 */
	public static function get_frontend_registration_integrations(): array {
		/**
		 * Filters the list of integrations that can trigger frontend reader registration.
		 *
		 * @param array<string, string> $integrations Map of integration ID => display label.
		 */
		$integrations = \apply_filters( 'newspack_frontend_registration_integrations', [] );

		// Also include Integration subclasses that opt in.
		foreach ( Integrations::get_available_integrations() as $integration ) {
			if ( $integration->supports_frontend_registration() && ! isset( $integrations[ $integration->get_id() ] ) ) {
				$integrations[ $integration->get_id() ] = $integration->get_name();
			}
		}

		return $integrations;
	}

	/**
	 * Generate an HMAC key for a frontend registration integration.
	 *
	 * The key is deterministic (safe for page caching) and unique per
	 * integration ID and site. It is not a secret — it is output to the
	 * page source — but it binds registration requests to a PHP-registered
	 * integration, preventing arbitrary callers.
	 *
	 * @param string $integration_id Integration identifier.
	 * @return string HMAC-SHA256 hex string.
	 */
	public static function get_frontend_registration_key( string $integration_id ): string {
		$integration = Integrations::get_integration( $integration_id );
		if ( $integration && $integration->supports_frontend_registration() ) {
			return $integration->get_registration_key();
		}
		// Fallback for filter-only registrations.
		return hash_hmac( 'sha256', $integration_id, \wp_salt( 'auth' ) );
	}

	/**
	 * Get script data for frontend localization.
	 *
	 * Called by Reader_Activation::enqueue_scripts() to merge integration
	 * config into the newspack_ras_config object.
	 *
	 * @return array Script data to merge, or empty array if no integrations.
	 */
	public static function get_script_data(): array {
		if ( ! Reader_Activation::is_enabled() ) {
			return [];
		}

		$frontend_integrations = self::get_frontend_registration_integrations();
		if ( empty( $frontend_integrations ) ) {
			return [];
		}

		$integrations_config = [];
		foreach ( $frontend_integrations as $id => $label ) {
			$integrations_config[ $id ] = [
				'key'   => self::get_frontend_registration_key( $id ),
				'label' => $label,
			];
		}

		return [
			'frontend_registration_integrations' => $integrations_config,
			'frontend_registration_url'          => \rest_url( NEWSPACK_API_NAMESPACE . '/reader-activation/register' ),
		];
	}

	/**
	 * Get the rate-limit bucket name for an Integration-backed frontend registration.
	 *
	 * Each integration's registration traffic is counted in its own per-IP bucket,
	 * so a high-traffic capture integration can neither starve nor be starved by
	 * another integration's registrations. Integrations that size their own limit
	 * via the `newspack_frontend_registration_rate_limit` filter must compare
	 * against this same derivation.
	 *
	 * sanitize_key() preserves both dashes and underscores, so integrations whose
	 * IDs differ only by separator get distinct buckets rather than silently
	 * sharing a counter.
	 *
	 * @param string $integration_id Integration identifier.
	 *
	 * @return string Bucket name.
	 */
	public static function get_rate_limit_bucket_for( string $integration_id ): string {
		return 'registration_' . \sanitize_key( $integration_id );
	}

	/**
	 * Check and increment a per-IP rate-limit bucket for frontend registration traffic.
	 *
	 * Each bucket has its own per-IP counter at 10/hour by default. The /register
	 * endpoint and the /check-email preflight use separate buckets so that:
	 *   - A legitimate user submission (one preflight + one register) still buys 10
	 *     full registrations per hour — neither endpoint can double-charge the other.
	 *   - An attacker probing /check-email for email enumeration is rate-limited at
	 *     10 requests/hour regardless of registration traffic, and vice versa.
	 * Integration-backed registrations use a per-integration bucket (see
	 * get_rate_limit_bucket_for()) so each integration's traffic is independently
	 * bounded and can be sized via the filter below.
	 *
	 * @param string $bucket Bucket key. 'registration' for filter-only /register
	 *                       traffic (preserves the existing cache key), 'check_email'
	 *                       for the preflight, or a per-integration bucket.
	 *
	 * @return bool|\WP_Error True if under limit, WP_Error if exceeded.
	 */
	private static function check_registration_rate_limit( string $bucket = 'registration' ): bool|\WP_Error {
		// @todo REMOTE_ADDR may be a proxy/load-balancer IP in some environments.
		// On WordPress VIP/Atomic this is the real client IP. For other hosts,
		// consider parsing forwarded headers or providing a filter to override IP resolution.
		// See WooCommerce_Connection::get_client_ip() for a forwarded-header approach.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		// Bucket → cache-key prefix. Keep 'newspack_reg_ip_' for registration so any
		// in-flight counters from prior releases continue to apply.
		if ( 'registration' === $bucket ) {
			$prefix = 'newspack_reg_ip_';
		} elseif ( 'check_email' === $bucket ) {
			$prefix = 'newspack_check_email_ip_';
		} else {
			$prefix = 'newspack_' . $bucket . '_ip_';
		}
		$cache_key = $prefix . md5( $ip );

		/**
		 * Filters the maximum number of frontend registration attempts per IP per hour.
		 *
		 * Applies independently to each bucket, all defaulting to 10/hr:
		 *   - 'registration'      — /register traffic from filter-only integrations.
		 *   - 'check_email'       — the /check-email preflight.
		 *   - 'registration_<id>' — one per Integration-backed registration source,
		 *                           derived by get_rate_limit_bucket_for(). Scope a
		 *                           callback by comparing against that helper rather
		 *                           than rebuilding the string.
		 *
		 * Built-in integrations size their own bucket from this filter at priority 5,
		 * so a callback at the default priority sees the integration's limit, not 10 —
		 * a callback that transforms the incoming value (`return $limit * 2;`) rather
		 * than replacing it compounds off that. Form Capture, for instance, has
		 * already raised its bucket to 100 by the time a default-priority callback runs.
		 *
		 * @param int    $limit  Maximum attempts. Default 10.
		 * @param string $ip     The client IP address.
		 * @param string $bucket Bucket name (see above).
		 */
		$limit = \apply_filters( 'newspack_frontend_registration_rate_limit', 10, $ip, $bucket );

		if ( \wp_using_ext_object_cache() ) {
			$cache_group = 'newspack_rate_limit';
			\wp_cache_add( $cache_key, 0, $cache_group, HOUR_IN_SECONDS );
			$attempts = \wp_cache_incr( $cache_key, 1, $cache_group );
		} else {
			$attempts = (int) \get_transient( $cache_key );
			\set_transient( $cache_key, $attempts + 1, HOUR_IN_SECONDS );
			$attempts++;
		}

		if ( $attempts > $limit ) {
			Logger::log( sprintf( 'Frontend registration rate limit exceeded for IP %1$s (bucket: %2$s)', $ip, $bucket ) );
			// Remote-log once per crossing, not once per rejected request — an IP
			// hammering the endpoint must not amplify into remote log traffic. A
			// visitor-triggered condition, so 'debug' (logstash only), not 'error'.
			// The hashed IP matches the bucket key and lets a burst be correlated
			// without putting the raw client IP into the off-site log stream.
			if ( $attempts === $limit + 1 ) {
				Logger::newspack_log(
					'newspack_frontend_registration_rate_limited',
					'Frontend registration rate limit exceeded.',
					[
						'ip_hash'  => md5( $ip ),
						'bucket'   => $bucket,
						'attempts' => $attempts,
					],
					'debug'
				);
			}
			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Too many registration attempts. Please try again later.', 'newspack-plugin' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Get the registration method string stamped on registrations from a
	 * frontend integration. Integrations scope their behavior (metadata
	 * filters, magic link suppression, sync decisions) by comparing against
	 * this exact format, so both sides must derive it from this helper.
	 *
	 * @param string $integration_id The integration ID.
	 *
	 * @return string The registration method string.
	 */
	public static function get_registration_method_for( $integration_id ) {
		return 'integration-registration-' . $integration_id;
	}

	/**
	 * REST API handler for frontend integration reader registration.
	 *
	 * Validation sequence:
	 * 1. Already logged in — return current reader data
	 * 2. Reader Activation is enabled
	 * 3. Integration ID is registered
	 * 4. Integration key matches HMAC
	 * 5. Honeypot field is empty
	 * 6. Per-IP rate limit
	 * 7. reCAPTCHA (when configured)
	 * 8. Email is valid
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_frontend_register_reader( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

		// Step 1: Validate integration ID is registered.
		$integration_id       = $request->get_param( 'integration_id' );
		$integrations         = self::get_frontend_registration_integrations();
		$integration_instance = Integrations::get_integration( $integration_id );

		if ( empty( $integration_id ) || ! isset( $integrations[ $integration_id ] ) ) {
			Logger::log( 'Frontend registration rejected: invalid integration ID "' . $integration_id . '"' );
			return new \WP_Error(
				'invalid_integration',
				__( 'Invalid integration.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		// Step 2: If caller is already logged in, return current reader data.
		// This makes the API idempotent — integrations don't need to check
		// authentication state before calling register().
		if ( \is_user_logged_in() ) {
			$current_user = \wp_get_current_user();

			/**
			 * Action triggered when a logged-in user attempts to register via the frontend registration endpoint.
			 *
			 * Integrations can hook into this action to handle cases where an existing user attempts to register again via the frontend registration flow. For example, an integration might want to link the existing user account to the integration or log this event for analytics purposes.
			 *
			 * @param \WP_User         $current_user         The currently logged-in user.
			 * @param \WP_REST_Request $request              The original registration request.
			 * @param Integration|null $integration_instance The integration instance associated with the registration attempt, or null if the integration was registered via filter only.
			 */
			do_action( 'newspack_frontend_registration_existing_user', $current_user, $request, $integration_instance );

			return new \WP_REST_Response(
				[
					'success' => true,
					'status'  => 'existing',
					'email'   => $current_user->user_email,
				],
				200
			);
		}

		// Step 3: Check RAS is enabled.
		if ( ! Reader_Activation::is_enabled() ) {
			return new \WP_Error(
				'reader_activation_disabled',
				__( 'Reader Activation is not enabled.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}

		// Step 4: Validate integration key.
		$integration_key = $request->get_param( 'integration_key' );
		if ( $integration_instance && $integration_instance->supports_frontend_registration() ) {
			$key_valid = $integration_instance->validate_registration_request( $integration_key, $request );
		} else {
			// Fallback for filter-only registrations.
			$expected_key = self::get_frontend_registration_key( $integration_id );
			$key_valid    = hash_equals( $expected_key, $integration_key );
		}
		if ( ! $key_valid ) {
			Logger::log( 'Frontend registration rejected: invalid key for integration "' . $integration_id . '"' );
			return new \WP_Error(
				'invalid_integration_key',
				__( 'Invalid integration key.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}

		// Step 5: Honeypot — the `email` field must be empty. Real email is in `npe`.
		$honeypot = $request->get_param( 'email' );
		if ( ! empty( $honeypot ) ) {
			// Return fake success to avoid revealing the honeypot to bots.
			// @todo Consider returning the npe value instead of the honeypot value to make
			// the fake response indistinguishable from a real one.
			return new \WP_REST_Response(
				[
					'success' => true,
					'status'  => 'created',
					'email'   => $honeypot,
				],
				200
			);
		}

		// Step 6: Per-IP rate limit. Checked before reCAPTCHA to avoid
		// triggering external verification calls for rate-limited IPs.
		// Integration-backed registrations count in a per-integration bucket
		// (sized via the newspack_frontend_registration_rate_limit filter);
		// filter-only registrations keep the shared 'registration' bucket.
		$bucket     = $integration_instance && $integration_instance->supports_frontend_registration()
			? self::get_rate_limit_bucket_for( $integration_id )
			: 'registration';
		$rate_check = self::check_registration_rate_limit( $bucket );
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Step 7: reCAPTCHA (when configured).
		$recaptcha_token = $request->get_param( 'g-recaptcha-response' );
		$should_verify   = \apply_filters( 'newspack_recaptcha_verify_captcha', Recaptcha::can_use_captcha(), '', 'integration_registration' );
		if ( $should_verify ) {
			// Bridge: verify_captcha() reads from $_POST.
			// @todo Refactor Recaptcha::verify_captcha() to accept an optional $token parameter, eliminating this $_POST mutation.
			$_POST['g-recaptcha-response'] = $recaptcha_token; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$captcha_result                = Recaptcha::verify_captcha();
			unset( $_POST['g-recaptcha-response'] );
			if ( \is_wp_error( $captcha_result ) ) {
				// No log here: Recaptcha::verify_captcha() already remote-logs both
				// its transport-error and rejection paths, and this path is driven
				// by unauthenticated callers — a second entry would double volume.
				return new \WP_Error(
					'recaptcha_failed',
					$captcha_result->get_error_message(),
					[ 'status' => 403 ]
				);
			}
		}

		// Step 8: Validate email.
		$email = $request->get_param( 'npe' );
		if ( empty( $email ) ) {
			// Visitor-triggered client condition (bots, malformed submissions) —
			// routine once capture is live, so 'debug' (logstash only), not 'error'.
			Logger::newspack_log(
				'newspack_frontend_registration_invalid_email',
				'Frontend registration rejected: missing or invalid email.',
				[ 'integration_id' => $integration_id ],
				'debug'
			);
			return new \WP_Error(
				'invalid_email',
				__( 'A valid email address is required.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		// Build display name from profile fields.
		$first_name   = $request->get_param( 'first_name' );
		$last_name    = $request->get_param( 'last_name' );
		$display_name = trim( $first_name . ' ' . $last_name );

		// Build metadata. Normalize referer to a local path, matching process_auth_form().
		$referer          = \wp_parse_url( \wp_get_referer() );
		$referer          = is_array( $referer ) ? $referer : [];
		$current_page_url = ! empty( $referer['path'] ) ? \esc_url( \home_url( $referer['path'] ) ) : '';
		$metadata         = [
			'registration_method' => self::get_registration_method_for( $integration_id ),
			'current_page_url'    => $current_page_url,
		];

		$result = Reader_Activation::register_reader( $email, $display_name, true, $metadata );

		if ( \is_wp_error( $result ) ) {
			// Race condition: concurrent requests for the same email can cause
			// wp_insert_user() or wc_create_new_customer() to return an "existing
			// user" error instead of register_reader() returning false.
			$existing_user_codes = [ 'existing_user_email', 'existing_user_login', 'registration-error-email-exists' ];
			if ( array_intersect( $result->get_error_codes(), $existing_user_codes ) ) {
				return new \WP_Error(
					'reader_already_exists',
					__( 'A reader with this email address is already registered.', 'newspack-plugin' ),
					[ 'status' => 409 ]
				);
			}

			Logger::newspack_log(
				'newspack_frontend_registration_failed',
				'Frontend registration failed in register_reader().',
				[
					'integration_id' => $integration_id,
					'error'          => $result->get_error_message(),
				],
				'error'
			);
			return new \WP_Error(
				'registration_failed',
				$result->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		// @todo register_reader() returns false for both existing readers (sends magic link)
		// and existing non-reader accounts (sends login reminder). This 409 treats both
		// identically. Consider distinguishing these cases to avoid disclosing account type.
		if ( false === $result ) {
			return new \WP_Error(
				'reader_already_exists',
				__( 'A reader with this email address is already registered.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}

		// Apply profile fields after creation.
		if ( ! empty( $first_name ) || ! empty( $last_name ) ) {
			\wp_update_user(
				[
					'ID'         => $result,
					'first_name' => $first_name,
					'last_name'  => $last_name,
				]
			);
		}

		// Save arbitrary user metadata, skipping keys other code trusts.
		$user_metadata = $request->get_param( 'metadata' );
		$skipped_keys  = [];
		if ( ! empty( $user_metadata ) ) {
			foreach ( $user_metadata as $meta_key => $meta_value ) {
				if ( self::is_reserved_meta_key( $meta_key ) ) {
					$skipped_keys[] = $meta_key;
					continue;
				}
				\update_user_meta( $result, $meta_key, $meta_value );
			}
		}

		if ( ! empty( $skipped_keys ) ) {
			// Once per request, not per key: the caller controls how many it sends.
			// newspack_log() fires regardless of NEWSPACK_LOG_LEVEL.
			Logger::newspack_log(
				'newspack_frontend_registration_reserved_metadata',
				'Frontend registration skipped reserved metadata keys.',
				[
					'integration_id' => $integration_id,
					'count'          => count( $skipped_keys ),
					'keys'           => array_slice( $skipped_keys, 0, 20 ),
				],
				'warning'
			);
		}

		$response_data = [
			'success' => true,
			'status'  => 'created',
			'email'   => $email,
		];

		// Always present, so callers need no isset() guard. Prefixed account keys are
		// logged but not echoed: only the matching one comes back, which would
		// disclose the table prefix.
		$response_data['skipped_metadata_keys'] = array_values(
			array_filter(
				$skipped_keys,
				function ( $key ) {
					return ! self::is_prefixed_account_key( \sanitize_key( \wp_unslash( (string) $key ) ) );
				}
			)
		);

		// Surface verification state so integration callers (via window.newspackReaderActivation.register())
		// can opt into the post-registration verification modal when their UX warrants it. Callers that
		// don't need it simply ignore these fields.
		$response_data = array_merge( $response_data, Reader_Activation::get_verification_payload( $result ) );

		return new \WP_REST_Response( $response_data, 201 );
	}

	/**
	 * REST handler for checking whether an email maps to an existing reader.
	 *
	 * Used by registration entry points when the post-registration verification flow is
	 * disabled — those flows need to ask the reader to confirm "You're about to create an
	 * account for X" *before* the account is actually created, which requires knowing
	 * up front whether the email is new or already registered.
	 *
	 * Privacy notes:
	 *   - The /register and process_auth_form endpoints already disclose the same
	 *     "this email is a reader" signal via their response shapes, so this isn't a
	 *     net-new oracle.
	 *   - Responses are filtered to readers only — staff/admin/editor accounts that share
	 *     the email surface as "exists: false" so this endpoint can't be used to enumerate
	 *     non-reader logins.
	 *   - Rate-limited at 10 requests/hour per IP in its own bucket (separate from
	 *     /register, so neither endpoint can starve the other and an enumeration
	 *     attempt is independently bounded).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_check_email_exists( \WP_REST_Request $request ) {
		// Enforce a per-IP 10/hr budget in a bucket separate from /register so that
		// hammering this endpoint can't enumerate emails without tripping the limit.
		$rate_check = self::check_registration_rate_limit( 'check_email' );
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$email = $request->get_param( 'email' );
		if ( empty( $email ) || ! \is_email( $email ) ) {
			return new \WP_Error(
				'invalid_email',
				__( 'A valid email address is required.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		// Only disclose existence for reader accounts. Staff/admin/editor logins that
		// happen to share the email return false so this endpoint can't be turned into
		// a non-reader account enumerator.
		$user   = \get_user_by( 'email', $email );
		$exists = $user && Reader_Activation::is_user_reader( $user );

		return new \WP_REST_Response(
			[
				'exists' => $exists,
			],
			200
		);
	}
}
Reader_Registration::init();
