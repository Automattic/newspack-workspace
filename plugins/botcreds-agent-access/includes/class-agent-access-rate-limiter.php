<?php
/**
 * Rate Limiting — cap REST requests per agent credential.
 *
 * Uses fixed-window counters stored as transients (non-atomic; minor
 * over-counting is acceptable for a governance layer).
 *
 * Hook priority 6 — after scope enforcement (5), before activity log (10).
 * Applies to ALL HTTP methods so read-heavy polling is also governed.
 *
 * Predefined tiers:
 *   strict    — 20/min, 200/hr
 *   standard  — 60/min, 1 000/hr  (default)
 *   relaxed   — 300/min, 5 000/hr
 *   unlimited — no cap
 *
 * @package BotCreds Agent Access
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Rate_Limiter {

	const META_PREFIX   = '_agent_access_rl_';
	const DEFAULT_TIER  = 'standard';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce' ), 6, 3 );
	}

	// ──────────────────────────────────────────────
	// Enforcement
	// ──────────────────────────────────────────────

	/**
	 * Check the current request against the credential's rate-limit tier.
	 * Returns a 429 WP_Error on violation; null otherwise.
	 *
	 * @param mixed            $result
	 * @param WP_REST_Server   $server
	 * @param WP_REST_Request  $request
	 * @return mixed
	 */
	public static function enforce( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result; // Already short-circuited upstream.
		}

		$tier_key = self::get_tier_for_current_request();
		if ( null === $tier_key || 'unlimited' === $tier_key ) {
			return $result;
		}

		$tier = self::get_tier( $tier_key );
		if ( ! $tier ) {
			return $result; // Unknown tier — fail open.
		}

		$uuid = self::get_current_uuid();
		if ( ! $uuid ) {
			return $result;
		}

		$hash = substr( md5( $uuid ), 0, 16 ); // Short hash — transient key length limit.
		$now  = time();

		// ── Per-minute window ──────────────────────
		if ( $tier['per_minute'] > 0 ) {
			$min_window = (int) floor( $now / 60 );
			$min_key    = 'aa_rl_' . $hash . '_m_' . $min_window;
			$min_count  = (int) get_transient( $min_key );

			if ( $min_count >= $tier['per_minute'] ) {
				$retry_after = 60 - ( $now % 60 );
				return self::rate_limited_error(
					$tier['per_minute'],
					'minute',
					$retry_after
				);
			}

			// Increment. Transient TTL = remainder of the current minute + 5 s buffer.
			set_transient( $min_key, $min_count + 1, 65 );
		}

		// ── Per-hour window ────────────────────────
		if ( $tier['per_hour'] > 0 ) {
			$hr_window = (int) floor( $now / 3600 );
			$hr_key    = 'aa_rl_' . $hash . '_h_' . $hr_window;
			$hr_count  = (int) get_transient( $hr_key );

			if ( $hr_count >= $tier['per_hour'] ) {
				$retry_after = 3600 - ( $now % 3600 );
				return self::rate_limited_error(
					$tier['per_hour'],
					'hour',
					$retry_after
				);
			}

			set_transient( $hr_key, $hr_count + 1, 3605 );
		}

		return $result;
	}

	/**
	 * Build the 429 WP_Error.
	 *
	 * @param int    $limit       Window limit.
	 * @param string $window_name 'minute' or 'hour'.
	 * @param int    $retry_after Seconds until window resets.
	 * @return WP_Error
	 */
	private static function rate_limited_error( $limit, $window_name, $retry_after ) {
		return new WP_Error(
			'agent_rate_limit_exceeded',
			sprintf(
				/* translators: 1: limit count, 2: window (minute or hour), 3: seconds until reset */
				__( 'Rate limit exceeded: %1$d requests per %2$s. Retry in %3$d seconds.', 'botcreds-agent-access' ),
				$limit,
				$window_name,
				$retry_after
			),
			array(
				'status'      => 429,
				'retry_after' => $retry_after,
			)
		);
	}

	// ──────────────────────────────────────────────
	// Tier lookup for the current request
	// ──────────────────────────────────────────────

	/**
	 * Return the rate-limit tier key for the current request, or null if
	 * the request is not from an Agent Access credential.
	 *
	 * @return string|null
	 */
	public static function get_tier_for_current_request() {
		// Pro Auth token path.
		if ( ! empty( $GLOBALS['_agent_access_pro_token_row'] ) ) {
			$tier = $GLOBALS['_agent_access_pro_token_row']->rate_limit ?? null;
			return $tier ?: self::DEFAULT_TIER;
		}

		// App password path.
		$uuid = self::get_current_uuid();
		if ( ! $uuid ) {
			return null;
		}

		// Only govern Agent Access credentials.
		$source = Agent_Access_Activity_Log::detect_source();
		if ( null === $source || Agent_Access_Activity_Log::SOURCE_AA !== $source['source'] ) {
			return null;
		}

		$user_id  = get_current_user_id();
		$tier_key = get_user_meta( $user_id, self::META_PREFIX . $uuid, true );

		return $tier_key ?: self::DEFAULT_TIER;
	}

	/**
	 * Get the app-password UUID for the current request, or null.
	 *
	 * @return string|null
	 */
	private static function get_current_uuid() {
		if ( function_exists( 'rest_get_authenticated_app_password' ) ) {
			return rest_get_authenticated_app_password() ?: null;
		}
		return null;
	}

	// ──────────────────────────────────────────────
	// Tier storage
	// ──────────────────────────────────────────────

	/**
	 * Save a rate-limit tier for a credential.
	 *
	 * @param int    $user_id  WP user id.
	 * @param string $uuid     App password UUID.
	 * @param string $tier_key Tier key (strict|standard|relaxed|unlimited).
	 */
	public static function save( $user_id, $uuid, $tier_key ) {
		$allowed = array_keys( self::get_tiers() );
		if ( ! in_array( $tier_key, $allowed, true ) ) {
			$tier_key = self::DEFAULT_TIER;
		}
		update_user_meta( (int) $user_id, self::META_PREFIX . $uuid, $tier_key );
	}

	/**
	 * Get the saved tier key for a user+UUID pair.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @return string Tier key, or DEFAULT_TIER if none set.
	 */
	public static function get( $user_id, $uuid ) {
		$key = get_user_meta( (int) $user_id, self::META_PREFIX . $uuid, true );
		return $key ?: self::DEFAULT_TIER;
	}

	/**
	 * Delete rate-limit meta when a credential is revoked.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 */
	public static function delete( $user_id, $uuid ) {
		delete_user_meta( (int) $user_id, self::META_PREFIX . $uuid );
	}

	// ──────────────────────────────────────────────
	// Tier definitions
	// ──────────────────────────────────────────────

	/**
	 * Return all rate-limit tiers.
	 *
	 * per_minute / per_hour of 0 means no cap for that window.
	 *
	 * @return array<string, array{label: string, description: string, per_minute: int, per_hour: int}>
	 */
	public static function get_tiers() {
		return array(
			'strict'    => array(
				'label'       => __( 'Strict (20/min, 200/hr)', 'botcreds-agent-access' ),
				'description' => __( 'Recommended for low-trust or experimental agents.', 'botcreds-agent-access' ),
				'per_minute'  => 20,
				'per_hour'    => 200,
			),
			'standard'  => array(
				'label'       => __( 'Standard (60/min, 1 000/hr)', 'botcreds-agent-access' ),
				'description' => __( 'Suitable for most agent workflows.', 'botcreds-agent-access' ),
				'per_minute'  => 60,
				'per_hour'    => 1000,
			),
			'relaxed'   => array(
				'label'       => __( 'Relaxed (300/min, 5 000/hr)', 'botcreds-agent-access' ),
				'description' => __( 'For high-throughput agents under active supervision.', 'botcreds-agent-access' ),
				'per_minute'  => 300,
				'per_hour'    => 5000,
			),
			'unlimited' => array(
				'label'       => __( 'Unlimited', 'botcreds-agent-access' ),
				'description' => __( 'No rate cap. Use only for fully trusted, internal agents.', 'botcreds-agent-access' ),
				'per_minute'  => 0,
				'per_hour'    => 0,
			),
		);
	}

	/**
	 * Return a single tier by key, or null.
	 *
	 * @param string $key
	 * @return array|null
	 */
	public static function get_tier( $key ) {
		$tiers = self::get_tiers();
		return $tiers[ $key ] ?? null;
	}

	/**
	 * Return the display label for a tier key.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function get_label( $key ) {
		$tier = self::get_tier( $key );
		return $tier ? $tier['label'] : ucfirst( $key );
	}
}
