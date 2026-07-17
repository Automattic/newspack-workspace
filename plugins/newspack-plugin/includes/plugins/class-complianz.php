<?php
/**
 * Complianz integration class.
 * https://complianz.io/
 *
 * Provides more control for publishers over Complianz behavior with regards to blocking
 * Newspack-software-added trackers (GAM, Meta, etc.) before consent is given.
 * This is primarily intended for US-based publishers that want to block trackers until after
 * consent is given but do not set up Complianz specifically for GDPR. It allows them to have
 * a US-focused setup AND ability to block things until after consent is given.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Wizards\Newspack\Privacy_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Complianz {
	/**
	 * Default server variable carrying the visitor country code resolved at the
	 * hosting edge. `GEOIP_COUNTRY_CODE` is the server-level GeoIP value set on
	 * WordPress.com / Atomic (WPCloud); it is a non-HTTP server variable, so a
	 * client cannot spoof it by sending a request header. Other infrastructure
	 * (e.g. Cloudflare's `HTTP_CF_IPCOUNTRY`) can be added via the
	 * `newspack_complianz_edge_country_headers` filter, but only variables that
	 * are guaranteed to be injected by trusted infrastructure should be trusted,
	 * since the value selects which cookie-consent regime a visitor is shown.
	 *
	 * @var string[]
	 */
	const EDGE_COUNTRY_HEADERS = [ 'GEOIP_COUNTRY_CODE' ];

	/**
	 * Transient that rate-limits the "GeoIP filter swap failed" log to once per
	 * day, so a broken Complianz coupling is reported without flooding the log on
	 * every uncached request.
	 *
	 * @var string
	 */
	const SWAP_FAILED_TRANSIENT = 'newspack_complianz_geoip_swap_failed';

	/**
	 * Memoized edge country code for the current request. `null` before it is
	 * first resolved; afterwards the resolved value (a country code, or '' when
	 * the edge supplied nothing usable). The edge value is fixed per request, and
	 * this is read several times on the uncached banner/track endpoints, so it is
	 * resolved once.
	 *
	 * @var string|null
	 */
	private static $edge_country_code = null;

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'cmplz_cookie_blocker_output', [ __CLASS__, 'extra_third_party_script_blocking' ] );
		add_filter( 'cmplz_option_enable_cookie_blocker', [ __CLASS__, 'block_before_consent' ] );
		add_filter( 'cmplz_consenttype', [ __CLASS__, 'force_optin_consenttype' ], 10, 2 );
		add_filter( 'newspack_pixel_script_markup', [ __CLASS__, 'pixel_handling_for_complianz' ] );
		// Complianz Premium registers its GeoIP filters on plugins_loaded:9, so swap them afterwards.
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_use_edge_geolocation' ], 11 );
	}

	/**
	 * Get the visitor country code resolved by the hosting edge, if any.
	 *
	 * On Atomic (WPCloud) and behind Cloudflare the visitor's country is already
	 * resolved at the edge and passed to PHP in a request header, so Complianz's
	 * own per-visitor MaxMind lookup is redundant work.
	 *
	 * @return string Two-letter uppercase ISO country code, or '' if unavailable.
	 */
	public static function get_edge_country_code() {
		if ( null !== self::$edge_country_code ) {
			return self::$edge_country_code;
		}
		self::$edge_country_code = '';
		/**
		 * Filters the server variables consulted for the edge-resolved visitor
		 * country code, in priority order. Only add variables guaranteed to be
		 * injected by trusted infrastructure (not client-supplied) -- the value
		 * selects which cookie-consent regime a visitor is shown.
		 *
		 * @param string[] $headers Server variable names, highest priority first.
		 */
		$headers = apply_filters( 'newspack_complianz_edge_country_headers', self::EDGE_COUNTRY_HEADERS );
		foreach ( (array) $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$country_code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			// Reject the common unknown/anonymized edge sentinels (XX, T1). Any other
			// two-letter value that is not a real region maps to nothing downstream and
			// falls back to the site default, so an unrecognised code still fails safe.
			if ( preg_match( '/^[A-Z]{2}$/', $country_code ) && 'XX' !== $country_code && 'T1' !== $country_code ) {
				self::$edge_country_code = $country_code;
				break;
			}
		}
		return self::$edge_country_code;
	}

	/**
	 * Replace Complianz Premium's per-visitor MaxMind GeoIP lookup with the
	 * country code already resolved by the hosting edge.
	 *
	 * The Complianz banner and consent-tracking REST endpoints are deliberately
	 * uncached, so on high-traffic sites every visitor triggers a MaxMind GeoIP
	 * lookup on each request. When the edge already supplies the country, that
	 * lookup is wasted; this maps the edge country onto Complianz's
	 * region/consent-type using its own pure helpers, preserving behaviour while
	 * skipping the database work.
	 *
	 * @return void
	 */
	public static function maybe_use_edge_geolocation() {
		// Only relevant when Complianz Premium's GeoIP is active (i.e. doing MaxMind lookups).
		if ( ! function_exists( 'cmplz_geoip_enabled' ) || ! cmplz_geoip_enabled() ) {
			return;
		}
		// Only when this request actually carries an edge-resolved country.
		if ( '' === self::get_edge_country_code() ) {
			return;
		}
		/**
		 * Filters whether to use the hosting edge's country code in place of
		 * Complianz's own MaxMind GeoIP lookup. Allows opting out per site.
		 *
		 * @param bool $use_edge_geolocation Whether to use edge geolocation. Default true.
		 */
		if ( ! apply_filters( 'newspack_complianz_use_edge_geolocation', true ) ) {
			return;
		}

		// Only take over a filter we actually removed. If Complianz ever renames or
		// re-prioritizes its GeoIP callbacks, remove_filter() no-ops and we leave its
		// native behaviour in place rather than running both filters in parallel.
		$region_swapped = remove_filter( 'cmplz_user_region', 'cmplz_user_region', 20 );
		if ( $region_swapped ) {
			add_filter( 'cmplz_user_region', [ __CLASS__, 'edge_user_region' ], 20 );
		}
		$consenttype_swapped = remove_filter( 'cmplz_user_consenttype', 'cmplz_user_consenttype', 10 );
		if ( $consenttype_swapped ) {
			add_filter( 'cmplz_user_consenttype', [ __CLASS__, 'edge_user_consenttype' ], 10 );
		}

		// At this point GeoIP is active and a valid edge country is present, so the
		// swap should have taken. If either callback was not found at its expected
		// priority, Complianz changed how it registers them: the swap silently
		// declines and every visitor resumes the MaxMind lookup this integration
		// exists to avoid. Surface that (rate-limited to once per day) so a Complianz
		// update that breaks the coupling is observable rather than rediscovered as a
		// load regression on a high-traffic site.
		if ( ( ! $region_swapped || ! $consenttype_swapped ) && false === get_transient( self::SWAP_FAILED_TRANSIENT ) ) {
			set_transient( self::SWAP_FAILED_TRANSIENT, 1, DAY_IN_SECONDS );
			Logger::newspack_log(
				'newspack_complianz_geoip_swap_failed',
				'Complianz GeoIP filter swap failed: the cmplz_user_region/cmplz_user_consenttype callbacks were not found at their expected priorities, so per-visitor MaxMind lookups continue. Complianz likely changed how it registers these filters.',
				[
					'region_swapped'      => (bool) $region_swapped,
					'consenttype_swapped' => (bool) $consenttype_swapped,
				],
				'error'
			);
		}
	}

	/**
	 * Resolve the Complianz region from the edge-supplied country code.
	 *
	 * Mirrors Complianz Premium's own `cmplz_user_region` filter, but derives the
	 * country from the edge instead of a MaxMind lookup. The manual
	 * `?cmplz_user_region=` override (used for previews and region redirects) is
	 * preserved.
	 *
	 * @param string $region Region resolved so far (the site default).
	 * @return string
	 */
	public static function edge_user_region( $region ) {
		// Fail safe: if Complianz's helpers are unavailable, leave the region untouched.
		if ( ! function_exists( 'cmplz_get_region_for_country' ) || ! function_exists( 'cmplz_get_regions' ) ) {
			return $region;
		}
		$country_code = self::get_edge_country_code();
		if ( '' !== $country_code ) {
			// cmplz_get_region_for_country() is a pure config lookup (no database read).
			$user_region = cmplz_get_region_for_country( $country_code );
			if ( is_string( $user_region ) && in_array( $user_region, cmplz_get_regions(), true ) ) {
				$region = $user_region;
			} elseif ( function_exists( 'cmplz_select_region_outside_supported_regions' ) ) {
				$region = cmplz_select_region_outside_supported_regions( $user_region );
			}
		}

		// Preserve Complianz's manual region override. This mirrors Complianz's own
		// region-redirect/preview mechanism, which is public and nonceless by design;
		// the value only selects a consent regime and is sanitized below.
		if ( isset( $_GET['cmplz_user_region'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$region = sanitize_title( wp_unslash( $_GET['cmplz_user_region'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! cmplz_has_region( $region ) && function_exists( 'cmplz_select_region_outside_supported_regions' ) ) {
				$region = cmplz_select_region_outside_supported_regions( $region );
			}
		}

		return $region;
	}

	/**
	 * Resolve the Complianz consent type from the edge-supplied country code.
	 *
	 * Mirrors Complianz Premium's own `cmplz_user_consenttype` filter, deriving the
	 * country from the edge instead of a MaxMind lookup. `cmplz_get_consenttype_for_country()`
	 * itself re-applies the `cmplz_user_region` filter (so the region is re-resolved
	 * from the edge, intentionally and without recursion) and fires Complianz's own
	 * `cmplz_consenttype` filter, so `force_optin_consenttype()` still runs.
	 *
	 * @param string $consenttype Consent type resolved so far (the site default).
	 * @return string
	 */
	public static function edge_user_consenttype( $consenttype ) {
		// Fail safe: if Complianz's helpers are unavailable, leave the consent type untouched.
		if ( ! function_exists( 'cmplz_get_consenttype_for_country' ) || ! function_exists( 'cmplz_get_used_consenttypes' ) ) {
			return $consenttype;
		}
		$country_code = self::get_edge_country_code();
		if ( '' !== $country_code ) {
			// cmplz_get_consenttype_for_country() is a pure config lookup (no database read).
			$user_consenttype = cmplz_get_consenttype_for_country( $country_code );
			// Gate against the site's configured-region consent types and fall back to
			// 'other', exactly as Complianz's own cmplz_user_consenttype callback does.
			// This deliberately mirrors native behaviour rather than the wider
			// cmplz_sanitize_consenttype() type list: a type valid for a region the site
			// does not use resolves to 'other' both here and in stock Complianz, so the
			// swap stays behaviour-preserving. Do not widen this to the full type list --
			// that would diverge from what a non-optimized site would show.
			if ( in_array( $user_consenttype, cmplz_get_used_consenttypes(), true ) ) {
				$consenttype = $user_consenttype;
			} else {
				$consenttype = 'other';
			}
		}
		return $consenttype;
	}

	/**
	 * Force Complianz cookie blocker on if the setting is enabled.
	 *
	 * @param mixed $value Current option value.
	 * @return mixed 'yes' if force is enabled, original value otherwise.
	 */
	public static function block_before_consent( $value ) {
		if ( self::should_block_trackers_before_consent() ) {
			return 'yes';
		}
		return $value;
	}

	/**
	 * Force optin consent type when force cookie blocker is enabled.
	 *
	 * @param string $consenttype Current consent type.
	 * @param string $region      Region being evaluated.
	 * @return string
	 */
	public static function force_optin_consenttype( $consenttype, $region ) {
		if ( self::should_block_trackers_before_consent() ) {
			return 'optin';
		}
		return $consenttype;
	}

	/**
	 * In Cookie Blocker mode, make sure some third-party scripts are blocked too.
	 *
	 * @param string $output HTML output after Complianz has done an initial pass for cookie/script blocking.
	 * @return string Modified $output.
	 */
	public static function extra_third_party_script_blocking( $output ) {
		// Format is 'domain' => 'category'.
		// Category is one of 'statistics', 'marketing', or 'functional'.
		// 'functional' doesn't make sense here though because those shouldn't be blocked.
		$trackers = [
			'googletagmanager.com' => 'statistics',
			'parsely.com'          => 'statistics',
		];
		$ads = [
			'doubleclick.net' => 'marketing',
		];

		if ( ! self::should_block_trackers_before_consent() ) {
			return $output;
		}

		$scripts_to_block = $trackers;
		if ( self::should_block_ads_before_consent() ) {
			$scripts_to_block = array_merge( $scripts_to_block, $ads );
		}

		// The regex matches <script src=""> tags.
		$script_pattern = '/<script[^>]*?\s+src\s*=\s*([\'"])([^\'"]*?)\1[^>]*?>/is';
		if ( preg_match_all( $script_pattern, $output, $matches, PREG_PATTERN_ORDER ) ) {
			foreach ( $matches[0] as $index => $full_markup ) {

				// Skip any scripts that have already been set up to be deferred until consent.
				if ( false !== stripos( $full_markup, 'data-cmplz-src' ) ) {
					continue;
				}

				$src = $matches[2][ $index ];
				foreach ( $scripts_to_block as $domain => $category ) {
					if ( false === stripos( $src, $domain ) ) {
						continue;
					}

					$new_full_markup = preg_replace( '/\s+src\s*=/i', ' type="text/plain" data-category="' . $category . '" data-cmplz-src=', $full_markup );
					$output = str_replace( $full_markup, $new_full_markup, $output );
					break;
				}
			}
		}

		return $output;
	}

	/**
	 * In Cookie Blocker mode, also block pixels until consent is given.
	 *
	 * @param string $markup Pixel markup.
	 * @return string Modified $markup.
	 */
	public static function pixel_handling_for_complianz( $markup ) {
		if ( self::is_complianz_with_cookie_blocker_active() && self::should_block_trackers_before_consent() ) {
			$markup = str_ireplace( '<script', '<script type="text/plain" data-category="marketing"', $markup );
		}
		return $markup;
	}

	/**
	 * Determines whether the Complianz plugin is active.
	 *
	 * @return bool True if active. False if not.
	 */
	public static function is_complianz_active() {
		return function_exists( 'cmplz_can_run_cookie_blocker' );
	}

	/**
	 * Determine whether Complianz is active and can run cookie blocker.
	 *
	 * @return bool Whether Cookie Blocker is running.
	 */
	public static function is_complianz_with_cookie_blocker_active() {
		return function_exists( 'cmplz_can_run_cookie_blocker' ) && cmplz_can_run_cookie_blocker();
	}

	/**
	 * Determine whether to block trackers before consent is given.
	 *
	 * @return bool True if it should block. False otherwise.
	 */
	public static function should_block_trackers_before_consent() {
		$privacy_settings = Privacy_Section::get_settings();
		return (bool) $privacy_settings['block_before_consent'];
	}

	/**
	 * Determine whether to block ads before consent is given.
	 * Note: Blocking ads only makes sense if also blocking trackers.
	 *
	 * @return bool True if it should block. False otherwise.
	 */
	public static function should_block_ads_before_consent() {
		$privacy_settings = Privacy_Section::get_settings();
		return (bool) $privacy_settings['block_before_consent'] && (bool) $privacy_settings['block_ads_before_consent'];
	}
}
Complianz::init();
