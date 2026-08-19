<?php
/**
 * Newspack site-wide metering allowance.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * One free-view allowance shared by every content gate.
 *
 * Counters used to be keyed by gate, so a site whose tiers unlock via different
 * product lists gave readers a fresh allowance per section. This class owns the
 * counts and reset period centrally; gates keep their own enablement, so a hard
 * wall and a metered gate can coexist against the same pool.
 *
 * A gate can still opt out per audience path by setting its metering `scope` to
 * `gate`, which restores the pre-2191 per-gate counter.
 */
class Site_Meter {
	/**
	 * Option prefix for the site meter settings.
	 */
	const OPTION_PREFIX = 'newspack_site_meter_';

	/**
	 * Option recording that the one-time adoption routine has run.
	 */
	const MIGRATED_OPTION = 'newspack_site_meter_migrated';

	/**
	 * Scope value: the gate draws on the shared site allowance.
	 */
	const SCOPE_SITE = 'site';

	/**
	 * Scope value: the gate keeps its own allowance and counter.
	 */
	const SCOPE_GATE = 'gate';

	/**
	 * Counter key for the shared allowance.
	 *
	 * Substituted for the gate ID in `metering-<key>` (localStorage) and
	 * `np_content_metering_<key>` (user meta). It cannot collide with a gate ID
	 * because post IDs are numeric.
	 */
	const METER_KEY = 'site';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_adopt_gate_settings' ] );
	}

	/**
	 * Get all settings with their default values.
	 *
	 * @return array Default site meter settings.
	 */
	public static function get_default_settings() {
		return [
			'anonymous_count'  => 1,
			'registered_count' => 1,
			'period'           => 'month',
		];
	}

	/**
	 * Get the site meter settings.
	 *
	 * @param string|null $key Optional key to get a specific setting. If not provided, all settings are returned.
	 *
	 * @return array|mixed Site meter settings, or a specific setting if a key is provided.
	 */
	public static function get_settings( $key = null ) {
		$settings = self::get_default_settings();
		if ( $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				return null;
			}
			return self::sanitize_setting( $key, get_option( self::OPTION_PREFIX . $key, $settings[ $key ] ) );
		}
		foreach ( $settings as $setting_key => $value ) {
			$settings[ $setting_key ] = self::sanitize_setting( $setting_key, get_option( self::OPTION_PREFIX . $setting_key, $value ) );
		}
		return $settings;
	}

	/**
	 * Sanitize a setting.
	 *
	 * @param string $key   The setting key.
	 * @param mixed  $value The setting value.
	 *
	 * @return mixed|\WP_Error The sanitized value, or WP_Error if the key is unknown.
	 */
	public static function sanitize_setting( $key, $value ) {
		$defaults = self::get_default_settings();
		if ( ! isset( $defaults[ $key ] ) ) {
			// translators: %s is the setting key.
			return new \WP_Error( 'newspack_site_meter_invalid_setting', sprintf( __( 'Invalid setting key: %s.', 'newspack-plugin' ), $key ) );
		}
		if ( 'period' === $key ) {
			return in_array( $value, [ 'week', 'month' ], true ) ? $value : $defaults[ $key ];
		}
		// Floor at 0: a negative count would read back through absint() as a positive allowance.
		return max( 0, intval( $value ) );
	}

	/**
	 * Update the site meter settings.
	 *
	 * @param array $settings New settings, keyed as in get_default_settings().
	 *
	 * @return array|\WP_Error Updated settings, or WP_Error if a value is invalid.
	 */
	public static function update_settings( $settings ) {
		$current = self::get_settings();
		foreach ( $settings as $key => $value ) {
			if ( ! isset( $current[ $key ] ) ) {
				continue;
			}
			$sanitized = self::sanitize_setting( $key, $value );
			if ( is_wp_error( $sanitized ) ) {
				return $sanitized;
			}
			if ( $sanitized === $current[ $key ] ) {
				continue;
			}
			update_option( self::OPTION_PREFIX . $key, $sanitized );
			$current[ $key ] = $sanitized;
		}
		return $current;
	}

	/**
	 * Sanitize a metering scope value.
	 *
	 * Anything other than an explicit opt-out resolves to the shared allowance, so
	 * gates saved before this setting existed adopt it. The one-time adoption
	 * routine stamps `gate` on the gates that must keep their own counter.
	 *
	 * @param mixed $scope The scope value.
	 *
	 * @return string Either SCOPE_SITE or SCOPE_GATE.
	 */
	public static function sanitize_scope( $scope ) {
		return self::SCOPE_GATE === $scope ? self::SCOPE_GATE : self::SCOPE_SITE;
	}

	/**
	 * Seed the site meter from existing gates, once per site.
	 *
	 * Sites upgrading into a shared meter must not have their allowances change
	 * under them. Two cases:
	 *
	 * - At most one distinct metered configuration exists: adopt it as the site
	 *   meter. Every gate keeps the shared default and behavior is identical.
	 * - Several metered gates disagree: keep the site defaults and stamp every
	 *   metered gate as `gate`, preserving each one's own counter. Publishers
	 *   reconcile from the UI when they choose to.
	 *
	 * @return void
	 */
	public static function maybe_adopt_gate_settings() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}
		// Claim the run before doing any work, so two concurrent admin requests
		// cannot both stamp scopes.
		if ( ! add_option( self::MIGRATED_OPTION, 1, '', false ) ) {
			return;
		}

		// Only the Access Control CPT: legacy Woo Memberships gates read both meters
		// from the `metering` meta and keep their per-gate counters regardless.
		$gates = get_posts(
			[
				'post_type'        => Content_Gate::GATE_CPT,
				'post_status'      => 'any',
				'posts_per_page'   => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Content-gate CPT; config-scale.
				'fields'           => 'ids',
				'suppress_filters' => false,
			]
		);
		if ( empty( $gates ) ) {
			return;
		}

		$configs = [];
		foreach ( $gates as $gate_id ) {
			foreach ( self::get_metered_configs( $gate_id ) as $config ) {
				$configs[ wp_json_encode( $config ) ] = $config;
			}
		}
		if ( empty( $configs ) ) {
			return;
		}

		if ( 1 === count( $configs ) ) {
			$config = reset( $configs );
			self::update_settings(
				[
					'anonymous_count'  => $config['count'],
					'registered_count' => $config['count'],
					'period'           => $config['period'],
				]
			);
			return;
		}

		foreach ( $gates as $gate_id ) {
			self::pin_gate_to_own_meter( $gate_id );
		}
	}

	/**
	 * Get the metered configurations a gate contributes to the adoption decision.
	 *
	 * Only paths that actually meter are returned: a disabled meter imposes no
	 * allowance, so it cannot conflict with another gate's.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array[] List of `[ 'count' => int, 'period' => string ]`.
	 */
	private static function get_metered_configs( $gate_id ) {
		$configs      = [];
		$registration = Content_Gate::get_registration_settings( $gate_id );
		if ( $registration['active'] && $registration['metering']['enabled'] ) {
			$configs[] = [
				'count'  => absint( $registration['metering']['count'] ),
				'period' => $registration['metering']['period'],
			];
		}
		$custom_access = Content_Gate::get_custom_access_settings( $gate_id );
		if ( $custom_access['active'] && $custom_access['metering']['enabled'] ) {
			$configs[] = [
				'count'  => absint( $custom_access['metering']['count'] ),
				'period' => $custom_access['metering']['period'],
			];
		}
		return $configs;
	}

	/**
	 * Stamp both of a gate's metering paths as using their own allowance.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return void
	 */
	private static function pin_gate_to_own_meter( $gate_id ) {
		$registration                       = Content_Gate::get_registration_settings( $gate_id );
		$registration['metering']['scope']  = self::SCOPE_GATE;
		Content_Gate::update_registration_settings( $gate_id, [ 'metering' => $registration['metering'] ] );

		$custom_access                      = Content_Gate::get_custom_access_settings( $gate_id );
		$custom_access['metering']['scope'] = self::SCOPE_GATE;
		Content_Gate::update_custom_access_settings( $gate_id, [ 'metering' => $custom_access['metering'] ] );
	}
}
