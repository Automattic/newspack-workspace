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
	 * Option recording that the one-time adoption routine has completed.
	 *
	 * Deliberately outside OPTION_PREFIX. `update_settings()` writes every key of
	 * `get_default_settings()` under that stem, so a setting added later under a
	 * name this flag shared would clear it and re-run adoption over gates that
	 * have already adopted.
	 */
	const ADOPTED_OPTION = 'newspack_content_gate_meter_adopted';

	/**
	 * Option claimed by the request currently running the adoption routine.
	 */
	const CLAIM_OPTION = 'newspack_content_gate_meter_adoption_claim';

	/**
	 * Seconds after which an adoption claim is treated as abandoned.
	 */
	const CLAIM_TIMEOUT = 300;

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
	 * The counter key for this site's shared allowance.
	 *
	 * User meta is network-wide and a path-based network shares one localStorage
	 * origin, so on multisite the bare key would let a reader's views on one site
	 * spend the allowance every other site configured for itself.
	 *
	 * @return string Counter key.
	 */
	public static function get_shared_meter_key(): string {
		return is_multisite() ? self::METER_KEY . '-' . get_current_blog_id() : self::METER_KEY;
	}

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_adopt_gate_settings' ] );
	}

	/**
	 * Get all settings with their default values.
	 *
	 * @return array{anonymous_count: int, registered_count: int, period: string} Default site meter settings.
	 */
	public static function get_default_settings(): array {
		return [
			'anonymous_count'  => 1,
			'registered_count' => 1,
			'period'           => 'month',
		];
	}

	/**
	 * Get the site meter settings.
	 *
	 * @return array{anonymous_count: int, registered_count: int, period: string} Site meter settings.
	 */
	public static function get_settings(): array {
		$settings = self::get_default_settings();
		foreach ( $settings as $setting_key => $value ) {
			$settings[ $setting_key ] = self::sanitize_setting( $setting_key, get_option( self::OPTION_PREFIX . $setting_key, $value ) );
		}
		return $settings;
	}

	/**
	 * Get one site meter setting.
	 *
	 * Separate from `get_settings()` so that callers wanting the whole set are typed to
	 * receive an array and nothing else. Folding both into one method meant every such
	 * caller advertised an error return it could not handle, and iterating a `WP_Error`
	 * walks its properties rather than failing, so a mistake there would be written to
	 * the database rather than raised.
	 *
	 * @param string $key The setting key.
	 *
	 * @return int|string|\WP_Error The setting, or an error for an unknown key.
	 */
	public static function get_setting( string $key ): int|string|\WP_Error {
		$defaults = self::get_default_settings();
		if ( ! isset( $defaults[ $key ] ) ) {
			return self::unknown_setting_error( $key );
		}
		return self::sanitize_setting( $key, get_option( self::OPTION_PREFIX . $key, $defaults[ $key ] ) );
	}

	/**
	 * Sanitize a setting.
	 *
	 * @param string $key   The setting key.
	 * @param mixed  $value The setting value.
	 *
	 * @return int|string|\WP_Error The sanitized value, or an error for an unknown key.
	 */
	public static function sanitize_setting( string $key, mixed $value ): int|string|\WP_Error {
		$defaults = self::get_default_settings();
		if ( ! isset( $defaults[ $key ] ) ) {
			return self::unknown_setting_error( $key );
		}
		if ( 'period' === $key ) {
			return in_array( $value, [ 'week', 'month' ], true ) ? $value : $defaults[ $key ];
		}
		// Floor at 0: a negative count would read back through absint() as a positive allowance.
		return max( 0, intval( $value ) );
	}

	/**
	 * The error both settings entry points return for a key that does not exist.
	 *
	 * One channel for one condition: a caller that handles the error from either
	 * entry point handles it from both.
	 *
	 * @param string $key The unknown setting key.
	 *
	 * @return \WP_Error
	 */
	private static function unknown_setting_error( string $key ): \WP_Error {
		return new \WP_Error(
			'newspack_site_meter_invalid_setting',
			// translators: %s is the setting key.
			sprintf( __( 'Invalid setting key: %s.', 'newspack-plugin' ), $key )
		);
	}

	/**
	 * Update the site meter settings.
	 *
	 * @param array $settings New settings, keyed as in get_default_settings().
	 *
	 * @return array{anonymous_count: int, registered_count: int, period: string}|\WP_Error Updated settings, or an error if a write fails.
	 */
	public static function update_settings( array $settings ): array|\WP_Error {
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
			// A value reported but never written shows the publisher a save that did not happen.
			if ( ! update_option( self::OPTION_PREFIX . $key, $sanitized ) ) {
				return new \WP_Error(
					'newspack_site_meter_update_failed',
					__( 'Failed to update the site meter settings.', 'newspack-plugin' )
				);
			}
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
	public static function sanitize_scope( mixed $scope ): string {
		return self::SCOPE_GATE === $scope ? self::SCOPE_GATE : self::SCOPE_SITE;
	}

	/**
	 * Which site meter count governs a reader.
	 *
	 * The shared counters are already split by reader state - signed-out views live
	 * in localStorage, signed-in views in user meta - so the allowance is chosen the
	 * same way. Selecting it by audience path instead would let a signed-out reader
	 * spend the signed-in allowance on one gate and be locked out by the smaller
	 * signed-out allowance on the next.
	 *
	 * @param bool $is_logged_in Whether to evaluate for a logged-in reader.
	 *
	 * @return string A key of get_default_settings().
	 */
	public static function count_key_for_reader( bool $is_logged_in ): string {
		return $is_logged_in ? 'registered_count' : 'anonymous_count';
	}

	/**
	 * Whether the one-time adoption routine has completed.
	 *
	 * Until it has, the shared allowance has never been written and its defaults are
	 * not the publisher's configuration, so gates must keep reading their own.
	 *
	 * @return bool Whether adoption has completed.
	 */
	public static function has_adopted(): bool {
		return (bool) get_option( self::ADOPTED_OPTION );
	}

	/**
	 * Claim the adoption run for this request.
	 *
	 * `admin_init` fires on admin-ajax.php and admin-post.php, both reachable without
	 * a session, so a burst of anonymous requests after a deploy would otherwise each
	 * run the whole routine. The work is idempotent, so duplicates cannot corrupt the
	 * result; what this stops is a slow duplicate finishing after a publisher has
	 * already edited the allowance and writing its stale answer over theirs.
	 *
	 * This narrows the window; it does not close it. `add_option()` checks for the
	 * option in PHP and then writes with `INSERT ... ON DUPLICATE KEY UPDATE`, so two
	 * requests that both pass the check both write and both report success. It is not
	 * a mutex and must not be built on as one. A claim older than CLAIM_TIMEOUT is
	 * treated as abandoned, so a run that dies part-way does not block adoption forever.
	 *
	 * @return bool Whether this request owns the run.
	 */
	private static function claim_adoption(): bool {
		if ( add_option( self::CLAIM_OPTION, time(), '', false ) ) {
			return true;
		}
		$claimed_at = (int) get_option( self::CLAIM_OPTION );
		if ( $claimed_at && ( time() - $claimed_at ) < self::CLAIM_TIMEOUT ) {
			return false;
		}
		update_option( self::CLAIM_OPTION, time(), false );
		return true;
	}

	/**
	 * Seed the site meter from existing gates, once per site.
	 *
	 * Sites upgrading into a shared meter must not have their allowances change
	 * under them. Two cases:
	 *
	 * - Each audience of readers is served one distinct allowance: adopt it as the
	 *   site meter. Signed-out and signed-in counts are adopted independently, so a
	 *   site metering 3 free views before its registration wall and 5 before its
	 *   paywall keeps both.
	 * - Readers of one audience are served allowances that disagree: keep the site
	 *   defaults and stamp the metering paths as `gate`, preserving each one's own
	 *   counter. Publishers reconcile from the UI when they choose to.
	 *
	 * Only published gates get a vote. An unpublished gate enforces nothing, and letting
	 * one pin the whole site would ship the feature inert over a configuration no reader
	 * has ever met.
	 *
	 * @return void
	 */
	public static function maybe_adopt_gate_settings(): void {
		// Runs on every admin request, so a site without gating must not pay for the query below.
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return;
		}

		if ( self::has_adopted() ) {
			return;
		}

		if ( ! self::claim_adoption() ) {
			return;
		}

		// Access Control gates only; legacy Woo Memberships gates keep their per-gate
		// counters regardless. Clause filters stay suppressed, the get_posts() default:
		// this vote sets the site's allowance once and permanently, and a gate hidden
		// from it would serve an allowance it never granted. `pre_get_posts` fires
		// either way, so this narrows the exposure rather than removing it.
		$gates = get_posts(
			[
				'post_type'      => Content_Gate::GATE_CPT,
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Content-gate CPT; config-scale.
				'fields'         => 'ids',
			]
		);

		// Only a published gate enforces anything, so only a published gate votes; a
		// dormant one is still stamped below if publishing it would change what it grants.
		// Newsletter gates are excluded for a different reason: `Content_Gate::get_gates()`
		// hides them, so no publisher can see or reconcile one, and they keep their own
		// allowance rather than deciding the site's.
		$dormant    = [];
		$enforcing  = [];
		$newsletter = [];
		foreach ( $gates as $gate_id ) {
			if ( (bool) get_post_meta( $gate_id, 'is_newsletter', true ) ) {
				$newsletter[] = $gate_id;
				continue;
			}
			if ( 'publish' !== get_post_status( $gate_id ) ) {
				$dormant[] = $gate_id;
				continue;
			}
			$enforcing[] = $gate_id;
		}

		$anonymous  = [];
		$registered = [];
		$periods    = [];
		foreach ( $enforcing as $gate_id ) {
			$configs = self::get_reader_configs( $gate_id );
			foreach ( $configs['anonymous'] as $config ) {
				$anonymous[ $config['count'] ] = $config['count'];
				$periods[ $config['period'] ]  = $config['period'];
			}
			foreach ( $configs['registered'] as $config ) {
				$registered[ $config['count'] ] = $config['count'];
				$periods[ $config['period'] ]   = $config['period'];
			}
		}

		// A daily reset is still accepted on a gate but cannot be held here, and would be
		// rewritten to monthly on the way in.
		$periods_representable = empty( array_diff( array_keys( $periods ), [ 'week', 'month' ] ) );

		$agrees = $periods_representable && count( $anonymous ) <= 1 && count( $registered ) <= 1 && count( $periods ) <= 1;

		if ( $agrees ) {
			$settings = [];
			if ( ! empty( $anonymous ) ) {
				$settings['anonymous_count'] = reset( $anonymous );
			}
			if ( ! empty( $registered ) ) {
				$settings['registered_count'] = reset( $registered );
			}
			if ( ! empty( $periods ) ) {
				$settings['period'] = reset( $periods );
			}
			// Left unmarked so the next request retries. Adopting on settings that were
			// never written would serve every gate the defaults.
			if ( is_wp_error( self::update_settings( $settings ) ) ) {
				delete_option( self::CLAIM_OPTION );
				return;
			}
			foreach ( $dormant as $gate_id ) {
				self::pin_differing_paths( $gate_id );
			}
		} else {
			foreach ( array_merge( $enforcing, $dormant ) as $gate_id ) {
				self::pin_gate_to_own_meter( $gate_id );
			}
		}

		foreach ( $newsletter as $gate_id ) {
			self::pin_gate_to_own_meter( $gate_id );
		}

		self::persist_settings();
		self::mark_adopted();
		delete_option( self::CLAIM_OPTION );
	}

	/**
	 * Stamp only the paths of a gate that would grant something else on the shared meter.
	 *
	 * Judged and applied per path. Pinning a whole gate because one of its paths
	 * disagrees would give the agreeing path a private counter as well as the shared
	 * one, so a reader could spend an allowance on the shared pool and then a second
	 * allowance of the same size on that gate: the multiplication a shared meter
	 * exists to remove.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return void
	 */
	private static function pin_differing_paths( int $gate_id ): void {
		$site  = self::get_settings();
		$paths = self::get_gate_paths( $gate_id );

		foreach ( self::get_path_audiences( $gate_id ) as $path_key => $count_keys ) {
			$config = self::get_path_config( $paths[ $path_key ] );
			if ( null === $config ) {
				continue;
			}
			$differs = $config['period'] !== $site['period'];
			foreach ( $count_keys as $count_key ) {
				if ( $config['count'] !== $site[ $count_key ] ) {
					$differs = true;
				}
			}
			if ( $differs ) {
				self::pin_path( $gate_id, $path_key );
			}
		}
	}

	/**
	 * Write the resolved settings to their options.
	 *
	 * Adoption can settle on values that match the defaults, and `update_settings()`
	 * skips writes that change nothing, so without this the options can stay absent
	 * and every gated request pays for three option misses.
	 *
	 * @return void
	 */
	private static function persist_settings(): void {
		foreach ( self::get_settings() as $key => $value ) {
			update_option( self::OPTION_PREFIX . $key, $value );
		}
	}

	/**
	 * Record that adoption has completed.
	 *
	 * Written after the work rather than before it: a run that dies part-way would
	 * otherwise leave the gates it never reached on the shared allowance with no
	 * retry. Adoption is idempotent, so the cost of that ordering is a rare repeat
	 * run rather than a half-migrated site.
	 *
	 * @return void
	 */
	private static function mark_adopted(): void {
		update_option( self::ADOPTED_OPTION, 1 );
	}

	/**
	 * Get the metered allowance each audience of readers is served by a gate.
	 *
	 * Mirrors how the allowance is resolved at runtime. Signed-out readers are governed
	 * by the registration wall when it is active and fall through to the paywall when it
	 * is not. Signed-in readers are governed by the paywall, except on a wall requiring a
	 * verification they have not completed, which holds them at the registration wall
	 * while they still draw the signed-in allowance: that path therefore has to agree
	 * with the paywall before the two can share one count.
	 *
	 * Only paths that actually meter are returned: a disabled meter imposes no allowance,
	 * so it cannot conflict with another gate's.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array{anonymous: array[], registered: array[]} Allowances as `[ 'count' => int, 'period' => string ]`.
	 */
	private static function get_reader_configs( int $gate_id ): array {
		$paths   = self::get_gate_paths( $gate_id );
		$configs = [
			'anonymous'  => [],
			'registered' => [],
		];

		foreach ( self::get_path_audiences( $gate_id ) as $path_key => $count_keys ) {
			$config = self::get_path_config( $paths[ $path_key ] );
			if ( null === $config ) {
				continue;
			}
			foreach ( $count_keys as $count_key ) {
				$audience               = 'anonymous_count' === $count_key ? 'anonymous' : 'registered';
				$configs[ $audience ][] = $config;
			}
		}

		return $configs;
	}

	/**
	 * A gate's two audience paths, keyed as `get_path_audiences()` keys them.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array{registration: array, custom_access: array} The paths' settings.
	 */
	private static function get_gate_paths( int $gate_id ): array {
		return [
			'registration'  => Content_Gate::get_registration_settings( $gate_id ),
			'custom_access' => Content_Gate::get_custom_access_settings( $gate_id ),
		];
	}

	/**
	 * Which site meter counts each audience path governs.
	 *
	 * The single source of truth for the mapping the vote and the per-path pinning
	 * both need. A path can govern both audiences, so this returns a list of count
	 * keys rather than one: with no registration wall the paywall governs signed-out
	 * readers as well as signed-in ones.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array{registration: string[], custom_access: string[]} Count keys per path.
	 */
	private static function get_path_audiences( int $gate_id ): array {
		$paths     = self::get_gate_paths( $gate_id );
		$audiences = [
			'registration'  => [],
			'custom_access' => [],
		];

		if ( $paths['registration']['active'] ) {
			$audiences['registration'][] = 'anonymous_count';
		} elseif ( $paths['custom_access']['active'] ) {
			$audiences['custom_access'][] = 'anonymous_count';
		}

		if ( $paths['custom_access']['active'] ) {
			$audiences['custom_access'][] = 'registered_count';
		}
		if ( $paths['registration']['active'] && ! empty( $paths['registration']['require_verification'] ) ) {
			$audiences['registration'][] = 'registered_count';
		}

		return $audiences;
	}

	/**
	 * Get the allowance an audience path imposes, if it meters at all.
	 *
	 * @param array|null $path An audience path's settings, or null when none applies.
	 *
	 * @return array|null `[ 'count' => int, 'period' => string ]`, or null when the path does not meter.
	 */
	private static function get_path_config( ?array $path ): ?array {
		if ( null === $path || empty( $path['metering']['enabled'] ) ) {
			return null;
		}
		return [
			'count'  => absint( $path['metering']['count'] ),
			'period' => $path['metering']['period'],
		];
	}

	/**
	 * Stamp every metering path of a gate as using its own allowance.
	 *
	 * For gates the site meter cannot speak for at all: the conflict case, where the
	 * meter keeps its defaults, and premium newsletter gates, which no publisher-facing
	 * surface can show. Where the meter does hold the publisher's configuration, use
	 * {@see self::pin_differing_paths()} so an agreeing path stays on the shared pool.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return void
	 */
	private static function pin_gate_to_own_meter( int $gate_id ): void {
		self::pin_path( $gate_id, 'registration' );
		self::pin_path( $gate_id, 'custom_access' );
	}

	/**
	 * Stamp one audience path as using its own allowance.
	 *
	 * Only a path that meters is stamped. Pinning a path whose meter is off would
	 * hand the publisher a per-gate counter they never asked for the day they turn
	 * that meter on.
	 *
	 * @param int    $gate_id  Gate ID.
	 * @param string $path_key Either `registration` or `custom_access`.
	 *
	 * @return void
	 */
	private static function pin_path( int $gate_id, string $path_key ): void {
		$path = self::get_gate_paths( $gate_id )[ $path_key ];
		if ( ! $path['active'] || empty( $path['metering']['enabled'] ) ) {
			return;
		}
		$path['metering']['scope'] = self::SCOPE_GATE;
		if ( 'registration' === $path_key ) {
			Content_Gate::update_registration_settings( $gate_id, [ 'metering' => $path['metering'] ] );
			return;
		}
		Content_Gate::update_custom_access_settings( $gate_id, [ 'metering' => $path['metering'] ] );
	}
}
