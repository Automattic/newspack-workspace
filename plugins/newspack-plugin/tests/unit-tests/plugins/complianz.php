<?php
/**
 * Tests for Complianz integration methods.
 *
 * @package Newspack\Tests
 */

use Newspack\Complianz;
use Newspack\Wizards\Newspack\Privacy_Section;

/**
 * Test Complianz script-blocking and pixel-handling methods.
 */
class Newspack_Test_Complianz extends WP_UnitTestCase {

	/**
	 * Mock controlling whether Complianz cookie blocker is active.
	 *
	 * @var bool
	 */
	public static $cookie_blocker_active = false;

	/**
	 * Mock controlling whether Complianz Premium GeoIP is enabled.
	 *
	 * @var bool
	 */
	public static $geoip_enabled = false;

	/**
	 * Mock map of country code => Complianz region, used by the cmplz_get_region_for_country mock.
	 *
	 * @var array<string,string>
	 */
	public static $region_map = [
		'US' => 'us',
		'NL' => 'eu',
		'DE' => 'eu',
	];

	/**
	 * Mock map of Complianz region => consent type, used by the
	 * cmplz_get_consenttype_for_country mock. Mirrors real Complianz, which derives
	 * the consent type from the (filterable) region's configured type, not directly
	 * from the country.
	 *
	 * @var array<string,string>
	 */
	public static $region_consenttype_map = [
		'us' => 'optout',
		'eu' => 'optin',
	];

	/**
	 * Reset privacy options before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', false );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', false );

		// Clear any geolocation request state between tests. HTTP_CF_IPCOUNTRY /
		// HTTP_X_COUNTRY_CODE are not trusted by default; they are cleared because
		// some tests set them to prove untrusted headers are ignored.
		foreach ( [ 'GEOIP_COUNTRY_CODE', 'HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE' ] as $header ) {
			unset( $_SERVER[ $header ] );
		}
		unset( $_GET['cmplz_user_region'] );

		// Reset the per-request edge-country memo so each test resolves fresh.
		$edge_country_cache = new ReflectionProperty( Complianz::class, 'edge_country_code' );
		$edge_country_cache->setAccessible( true );
		$edge_country_cache->setValue( null, null );

		// Clear the swap-failure log rate-limit so each test starts fresh.
		delete_transient( Complianz::SWAP_FAILED_TRANSIENT );

		// Simulate Complianz cookie blocker settings.
		if ( ! function_exists( 'cmplz_can_run_cookie_blocker' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_can_run_cookie_blocker() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return Newspack_Test_Complianz::$cookie_blocker_active; // phpcs:ignore Squiz.Classes.SelfMemberReference.NotUsed
			}
		}

		// Mock Complianz's pure region/consent-type helpers (no MaxMind lookup involved).
		if ( ! function_exists( 'cmplz_get_region_for_country' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_get_region_for_country( $country_code ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return Newspack_Test_Complianz::$region_map[ $country_code ] ?? false; // phpcs:ignore Squiz.Classes.SelfMemberReference.NotUsed
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_get_consenttype_for_country( $country_code ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				// Mirror real Complianz: re-derive the region while re-applying the
				// cmplz_user_region filter (so a manual ?cmplz_user_region= override or the
				// edge swap changes the result), then map that region to its consent type.
				// Real Complianz returns false (not 'other') when the region has no type.
				$region = apply_filters( 'cmplz_user_region', cmplz_get_region_for_country( $country_code ) );
				return Newspack_Test_Complianz::$region_consenttype_map[ $region ] ?? false; // phpcs:ignore Squiz.Classes.SelfMemberReference.NotUsed
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_get_regions() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return [ 'us', 'eu' ];
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_get_used_consenttypes() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return [ 'optin', 'optout' ];
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_has_region( $code ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return in_array( $code, [ 'us', 'eu' ], true );
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_geoip_enabled() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return Newspack_Test_Complianz::$geoip_enabled; // phpcs:ignore Squiz.Classes.SelfMemberReference.NotUsed
			}
			// Stand-ins for Complianz Premium's own GeoIP filter callbacks, so the
			// swap performed by maybe_use_edge_geolocation() can be asserted.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_user_region( $region ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return $region;
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			function cmplz_user_consenttype( $consenttype ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return $consenttype;
			}
		}
	}

	/**
	 * Reset GeoIP mock state after each test.
	 */
	public function tearDown(): void {
		self::$geoip_enabled = false;
		remove_filter( 'cmplz_user_region', 'cmplz_user_region', 20 );
		remove_filter( 'cmplz_user_consenttype', 'cmplz_user_consenttype', 10 );
		remove_filter( 'cmplz_user_region', [ Complianz::class, 'edge_user_region' ], 20 );
		remove_filter( 'cmplz_user_consenttype', [ Complianz::class, 'edge_user_consenttype' ], 10 );
		remove_all_filters( 'newspack_complianz_use_edge_geolocation' );
		remove_all_filters( 'newspack_complianz_edge_country_headers' );
		parent::tearDown();
	}

	/**
	 * Simulate Complianz Premium having registered its GeoIP-backed filters.
	 */
	private function register_premium_geoip_filters() {
		add_filter( 'cmplz_user_region', 'cmplz_user_region', 20 );
		add_filter( 'cmplz_user_consenttype', 'cmplz_user_consenttype', 10 );
	}

	// -------------------------------------------------------------------------
	// extra_third_party_script_blocking
	// -------------------------------------------------------------------------

	/**
	 * Output is returned unchanged when both settings are off.
	 */
	public function test_script_blocking_passthrough_when_both_settings_off() {
		$html = '<script src="https://www.googletagmanager.com/gtag/js?id=G-123"></script>';
		$this->assertSame( $html, Complianz::extra_third_party_script_blocking( $html ) );
	}

	/**
	 * Check googletagmanager.com is blocked when block_before_consent is on.
	 */
	public function test_blocks_gtm_when_block_before_consent_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$html   = '<script src="https://www.googletagmanager.com/gtag/js?id=G-123"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringContainsString( 'data-cmplz-src=', $result );
		$this->assertStringContainsString( 'data-category="statistics"', $result );
		$this->assertStringContainsString( 'type="text/plain"', $result );
		$this->assertStringNotContainsString( ' src=', $result );
	}

	/**
	 * Check parsely.com is blocked when block_before_consent is on.
	 */
	public function test_blocks_parsely_when_block_before_consent_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$html   = '<script src="https://cdn.parsely.com/keys/example.com/p.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringContainsString( 'data-cmplz-src=', $result );
		$this->assertStringContainsString( 'data-category="statistics"', $result );
		$this->assertStringNotContainsString( ' src=', $result );
	}

	/**
	 * Check doubleclick.net is NOT blocked when only block_before_consent is on
	 * (ads require block_ads_before_consent).
	 */
	public function test_does_not_block_ads_when_only_block_before_consent_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$html   = '<script src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringNotContainsString( 'data-cmplz-src=', $result );
		$this->assertSame( $html, $result );
	}

	/**
	 * Check doubleclick.net is blocked when block_ads_before_consent is on.
	 */
	public function test_blocks_doubleclick_when_block_ads_before_consent_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', true );

		$html   = '<script src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringContainsString( 'data-cmplz-src=', $result );
		$this->assertStringContainsString( 'data-category="marketing"', $result );
		$this->assertStringNotContainsString( ' src=', $result );
	}

	/**
	 * Check doubleclick.net is NOT blocked when only block_ads_before_consent is on
	 * but block_before_consent is off (both checks required, early return fires).
	 */
	public function test_does_not_block_ads_when_only_block_ads_setting_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', false );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', true );

		$html   = '<script src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringNotContainsString( 'data-cmplz-src=', $result );
	}

	/**
	 * Scripts already processed by Complianz (data-cmplz-src present) are skipped.
	 */
	public function test_already_blocked_scripts_are_skipped() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$html   = '<script type="text/plain" data-category="statistics" data-cmplz-src="https://www.googletagmanager.com/gtag/js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		// Should be unchanged — already has data-cmplz-src, no src= to replace.
		$this->assertSame( $html, $result );
	}

	/**
	 * Unrecognised domains are not touched.
	 */
	public function test_unrecognised_domain_is_not_blocked() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', true );

		$html   = '<script src="https://example.com/script.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Multiple scripts in the same output are each handled correctly.
	 */
	public function test_multiple_scripts_in_output() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', true );

		$html = implode(
			"\n",
			[
				'<script src="https://www.googletagmanager.com/gtag/js"></script>',
				'<script src="https://example.com/safe.js"></script>',
				'<script src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>',
			]
		);

		$result = Complianz::extra_third_party_script_blocking( $html );

		// GTM blocked.
		$this->assertStringContainsString( 'data-category="statistics"', $result );
		// Doubleclick blocked.
		$this->assertStringContainsString( 'data-category="marketing"', $result );
		// Safe script untouched.
		$this->assertStringContainsString( 'src="https://example.com/safe.js"', $result );
	}

	// -------------------------------------------------------------------------
	// pixel_handling_for_complianz
	// -------------------------------------------------------------------------

	/**
	 * Pixel markup is unchanged when block_before_consent is off.
	 */
	public function test_pixel_unchanged_when_block_before_consent_off() {
		$markup = '<script>fbq("track","PageView");</script>';
		$result = Complianz::pixel_handling_for_complianz( $markup );
		$this->assertSame( $markup, $result );
	}

	/**
	 * Pixel markup is unchanged when block_before_consent is on but
	 * Complianz cookie blocker is not active (cmplz_can_run_cookie_blocker absent).
	 */
	public function test_pixel_unchanged_when_complianz_cookie_blocker_not_active() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		// cmplz_can_run_cookie_blocker() is not defined in tests, so
		// is_complianz_with_cookie_blocker_active() returns false.
		$markup = '<script>fbq("track","PageView");</script>';
		$result = Complianz::pixel_handling_for_complianz( $markup );
		$this->assertSame( $markup, $result );
	}

	/**
	 * When block_before_consent is on AND Complianz cookie blocker is active,
	 * pixel <script> tags get type="text/plain" data-category="marketing".
	 */
	public function test_pixel_blocked_when_settings_and_complianz_active() {
		self::$cookie_blocker_active = true;
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$markup = '<script>fbq("track","PageView");</script>';
		$result = Complianz::pixel_handling_for_complianz( $markup );

		$this->assertStringContainsString( 'type="text/plain"', $result );
		$this->assertStringContainsString( 'data-category="marketing"', $result );
		self::$cookie_blocker_active = false;
	}

	// -------------------------------------------------------------------------
	// get_edge_country_code
	// -------------------------------------------------------------------------

	/**
	 * No country headers present returns an empty string.
	 */
	public function test_edge_country_empty_when_no_header() {
		$this->assertSame( '', Complianz::get_edge_country_code() );
	}

	/**
	 * The server-level GeoIP header is read and upper-cased.
	 */
	public function test_edge_country_reads_geoip_header() {
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'nl';
		$this->assertSame( 'NL', Complianz::get_edge_country_code() );
	}

	/**
	 * Client-suppliable headers (Cloudflare, generic) are ignored by default, so
	 * the country cannot be spoofed when only those are present.
	 */
	public function test_edge_country_ignores_untrusted_headers_by_default() {
		$_SERVER['HTTP_CF_IPCOUNTRY']   = 'DE';
		$_SERVER['HTTP_X_COUNTRY_CODE'] = 'NL';
		$this->assertSame( '', Complianz::get_edge_country_code() );
	}

	/**
	 * The trusted GEOIP_COUNTRY_CODE is used even when untrusted headers are also
	 * present, and the untrusted values do not influence the result.
	 */
	public function test_edge_country_uses_trusted_header_over_untrusted() {
		$_SERVER['GEOIP_COUNTRY_CODE']  = 'US';
		$_SERVER['HTTP_CF_IPCOUNTRY']   = 'DE';
		$_SERVER['HTTP_X_COUNTRY_CODE'] = 'NL';
		$this->assertSame( 'US', Complianz::get_edge_country_code() );
	}

	/**
	 * The newspack_complianz_edge_country_headers filter can add a trusted header,
	 * and the list is consulted in priority order, skipping invalid values.
	 */
	public function test_edge_country_filter_can_add_trusted_header() {
		add_filter(
			'newspack_complianz_edge_country_headers',
			function () {
				return [ 'GEOIP_COUNTRY_CODE', 'HTTP_CF_IPCOUNTRY' ];
			}
		);
		// First header invalid -> falls through to the next trusted header.
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'XX';
		$_SERVER['HTTP_CF_IPCOUNTRY']  = 'nl';
		$this->assertSame( 'NL', Complianz::get_edge_country_code() );
	}

	/**
	 * Unknown/anonymized edge values (XX, T1) and malformed values are rejected.
	 *
	 * @dataProvider data_invalid_edge_country_values
	 * @param string $value Raw header value.
	 */
	public function test_edge_country_rejects_invalid_values( $value ) {
		$_SERVER['GEOIP_COUNTRY_CODE'] = $value;
		$this->assertSame( '', Complianz::get_edge_country_code() );
	}

	/**
	 * Invalid edge country header values.
	 *
	 * @return array<string,array{string}>
	 */
	public function data_invalid_edge_country_values() {
		return [
			'unknown XX'    => [ 'XX' ],
			'cloudflare T1' => [ 'T1' ],
			'three letters' => [ 'USA' ],
			'numeric'       => [ '12' ],
			'empty'         => [ '' ],
		];
	}

	// -------------------------------------------------------------------------
	// edge_user_region / edge_user_consenttype
	// -------------------------------------------------------------------------

	/**
	 * The edge country is mapped to its supported region.
	 */
	public function test_edge_region_uses_country_when_supported() {
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$this->assertSame( 'eu', Complianz::edge_user_region( 'us' ) );
	}

	/**
	 * A country mapping to an unsupported region leaves the default region intact
	 * (Complianz's outside-region fallback is unavailable in the test environment).
	 */
	public function test_edge_region_keeps_default_for_unsupported_country() {
		// 'FR' is not in the mock region map, so cmplz_get_region_for_country returns false.
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'FR';
		$this->assertSame( 'us', Complianz::edge_user_region( 'us' ) );
	}

	/**
	 * The manual ?cmplz_user_region= override wins over the edge country.
	 */
	public function test_edge_region_manual_override_wins() {
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$_GET['cmplz_user_region']     = 'us';
		$this->assertSame( 'us', Complianz::edge_user_region( 'eu' ) );
	}

	/**
	 * The edge country is mapped to its consent type when that type is in use.
	 */
	public function test_edge_consenttype_uses_country_when_in_use() {
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$this->assertSame( 'optin', Complianz::edge_user_consenttype( 'optout' ) );
	}

	/**
	 * A country whose consent type is not configured falls back to 'other'.
	 */
	public function test_edge_consenttype_falls_back_to_other() {
		// 'FR' maps to no region, so cmplz_get_consenttype_for_country returns false;
		// the 'other' comes from edge_user_consenttype()'s own else branch, not the helper.
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'FR';
		$this->assertSame( 'other', Complianz::edge_user_consenttype( 'optin' ) );
	}

	/**
	 * A manual ?cmplz_user_region= override drives the consent type through the
	 * re-entrant path: after the swap, cmplz_get_consenttype_for_country() re-applies
	 * cmplz_user_region (now edge_user_region), where the override wins, so the consent
	 * type follows the override region (US -> optout) rather than the edge country
	 * (NL -> optin). This locks in the trickiest behaviour called out in the docblock.
	 */
	public function test_edge_consenttype_follows_override_region_through_reentrant_path() {
		self::$geoip_enabled           = true;
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$_GET['cmplz_user_region']     = 'us';
		$this->register_premium_geoip_filters();

		Complianz::maybe_use_edge_geolocation();

		$this->assertSame( 'optout', apply_filters( 'cmplz_user_consenttype', 'optin' ) );
	}

	// -------------------------------------------------------------------------
	// maybe_use_edge_geolocation (filter swap wiring)
	// -------------------------------------------------------------------------

	/**
	 * When GeoIP is on and the edge supplies a country, Complianz's GeoIP filters
	 * are replaced with the Newspack edge-based ones.
	 */
	public function test_swaps_filters_when_geoip_on_and_edge_country_present() {
		self::$geoip_enabled           = true;
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$this->register_premium_geoip_filters();

		Complianz::maybe_use_edge_geolocation();

		// Complianz's own GeoIP callbacks are removed.
		$this->assertFalse( has_filter( 'cmplz_user_region', 'cmplz_user_region' ) );
		$this->assertFalse( has_filter( 'cmplz_user_consenttype', 'cmplz_user_consenttype' ) );
		// Newspack's edge-based callbacks take their place.
		$this->assertSame( 20, has_filter( 'cmplz_user_region', [ Complianz::class, 'edge_user_region' ] ) );
		$this->assertSame( 10, has_filter( 'cmplz_user_consenttype', [ Complianz::class, 'edge_user_consenttype' ] ) );
	}

	/**
	 * The swap is skipped when GeoIP is not enabled (no MaxMind lookups happening).
	 */
	public function test_no_swap_when_geoip_disabled() {
		self::$geoip_enabled           = false;
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$this->register_premium_geoip_filters();

		Complianz::maybe_use_edge_geolocation();

		$this->assertSame( 20, has_filter( 'cmplz_user_region', 'cmplz_user_region' ) );
		$this->assertFalse( has_filter( 'cmplz_user_region', [ Complianz::class, 'edge_user_region' ] ) );
	}

	/**
	 * The swap is skipped when no edge country is available (nothing to substitute).
	 */
	public function test_no_swap_when_no_edge_country() {
		self::$geoip_enabled = true;
		$this->register_premium_geoip_filters();

		Complianz::maybe_use_edge_geolocation();

		$this->assertSame( 20, has_filter( 'cmplz_user_region', 'cmplz_user_region' ) );
		$this->assertFalse( has_filter( 'cmplz_user_region', [ Complianz::class, 'edge_user_region' ] ) );
	}

	/**
	 * The newspack_complianz_use_edge_geolocation filter can opt a site out.
	 */
	public function test_opt_out_filter_prevents_swap() {
		self::$geoip_enabled           = true;
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$this->register_premium_geoip_filters();
		add_filter( 'newspack_complianz_use_edge_geolocation', '__return_false' );

		Complianz::maybe_use_edge_geolocation();

		$this->assertSame( 20, has_filter( 'cmplz_user_region', 'cmplz_user_region' ) );
		$this->assertFalse( has_filter( 'cmplz_user_region', [ Complianz::class, 'edge_user_region' ] ) );
	}

	/**
	 * If Complianz's own filters are not present at the expected priority (e.g. it
	 * renamed or re-prioritized them), the swap leaves its native behavior alone
	 * rather than adding a second, parallel filter.
	 */
	public function test_no_swap_when_premium_filters_absent() {
		self::$geoip_enabled           = true;
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		// Deliberately do NOT register Complianz's filters: remove_filter() will no-op.

		Complianz::maybe_use_edge_geolocation();

		$this->assertFalse( has_filter( 'cmplz_user_region', [ Complianz::class, 'edge_user_region' ] ) );
		$this->assertFalse( has_filter( 'cmplz_user_consenttype', [ Complianz::class, 'edge_user_consenttype' ] ) );
	}

	/**
	 * When the swap cannot take (Complianz's callbacks are absent from their
	 * expected priorities), the broken coupling is reported via newspack_log -- but
	 * only once, since the transient rate-limits repeat reports on uncached requests.
	 */
	public function test_logs_once_when_swap_fails() {
		self::$geoip_enabled           = true;
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		// Deliberately do NOT register Complianz's filters: remove_filter() no-ops.
		$logged = [];
		add_action(
			'newspack_log',
			function ( $code ) use ( &$logged ) {
				$logged[] = $code;
			}
		);

		Complianz::maybe_use_edge_geolocation();
		Complianz::maybe_use_edge_geolocation();

		$this->assertSame( [ 'newspack_complianz_geoip_swap_failed' ], $logged );
	}

	/**
	 * A successful swap emits no failure log.
	 */
	public function test_no_log_when_swap_succeeds() {
		self::$geoip_enabled           = true;
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$this->register_premium_geoip_filters();
		$logged = [];
		add_action(
			'newspack_log',
			function ( $code ) use ( &$logged ) {
				$logged[] = $code;
			}
		);

		Complianz::maybe_use_edge_geolocation();

		$this->assertNotContains( 'newspack_complianz_geoip_swap_failed', $logged );
	}

	/**
	 * End to end: after the swap, applying Complianz's filters (as the banner
	 * endpoint does) yields the edge-derived region and consent type.
	 */
	public function test_applying_filters_after_swap_yields_edge_values() {
		self::$geoip_enabled           = true;
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'NL';
		$this->register_premium_geoip_filters();

		Complianz::maybe_use_edge_geolocation();

		$this->assertSame( 'eu', apply_filters( 'cmplz_user_region', 'us' ) );
		$this->assertSame( 'optin', apply_filters( 'cmplz_user_consenttype', 'optout' ) );
	}

	// Note: the function_exists() fail-safe branches in edge_user_region() /
	// edge_user_consenttype() / maybe_use_edge_geolocation() are not unit-tested:
	// the Complianz helper mocks are defined globally for the whole test run and
	// PHP cannot undefine a function, so those branches are exercised only when
	// Complianz is genuinely absent. They are simple early returns of the incoming
	// value.
}
