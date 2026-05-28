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
	 * Ensures all keys and values are sanitized strings.
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
	 * Check and increment the per-IP rate limit for frontend registration.
	 *
	 * @return bool|\WP_Error True if under limit, WP_Error if exceeded.
	 */
	private static function check_registration_rate_limit(): bool|\WP_Error {
		// @todo REMOTE_ADDR may be a proxy/load-balancer IP in some environments.
		// On WordPress VIP/Atomic this is the real client IP. For other hosts,
		// consider parsing forwarded headers or providing a filter to override IP resolution.
		// See WooCommerce_Connection::get_client_ip() for a forwarded-header approach.
		$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		$cache_key = 'newspack_reg_ip_' . md5( $ip );

		/**
		 * Filters the maximum number of frontend registration attempts per IP per hour.
		 *
		 * @param int    $limit Maximum attempts. Default 10.
		 * @param string $ip    The client IP address.
		 */
		$limit = \apply_filters( 'newspack_frontend_registration_rate_limit', 10, $ip );

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
			Logger::log( 'Frontend registration rate limit exceeded for IP ' . $ip );
			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Too many registration attempts. Please try again later.', 'newspack-plugin' ),
				[ 'status' => 429 ]
			);
		}

		return true;
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
		$rate_check = self::check_registration_rate_limit();
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
			'registration_method' => 'integration-registration-' . $integration_id,
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

		// Save arbitrary user metadata.
		$user_metadata = $request->get_param( 'metadata' );
		if ( ! empty( $user_metadata ) ) {
			foreach ( $user_metadata as $meta_key => $meta_value ) {
				\update_user_meta( $result, $meta_key, $meta_value );
			}
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'status'  => 'created',
				'email'   => $email,
			],
			201
		);
	}
}
Reader_Registration::init();
