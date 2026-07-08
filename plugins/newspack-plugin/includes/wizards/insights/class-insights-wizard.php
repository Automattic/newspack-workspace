<?php
/**
 * Newspack Insights Wizard (NPPD-1602).
 *
 * Top-level wizard chrome for the Insights page. Tab routing happens
 * entirely on the React side via URL query persistence; this PHP wizard
 * registers the admin page and localizes the boot config (tab visibility,
 * default date range, timezone, settings URL).
 *
 * Section classes (Insights_Section_*) live alongside this file and exist
 * for future per-tab REST endpoint registration when each tab's data layer
 * lands in subsequent issues.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Insights Wizard.
 */
class Insights_Wizard extends Wizard {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-insights';

	/**
	 * Capability required to access this wizard.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Parent menu item slug. Nests under the top-level Newspack admin menu,
	 * matching the Setup wizard's precedent.
	 *
	 * @var string
	 */
	public $parent_menu = 'newspack-dashboard';

	/**
	 * Checks if the feature is enabled.
	 *
	 * True when:
	 * - NEWSPACK_INSIGHTS_ENABLED is defined and true.
	 *
	 * Feature-flagged for gradual rollout.
	 * Remove this gate once fully released.
	 *
	 * @return bool True if the feature is enabled, false otherwise.
	 */
	public static function is_enabled() {
		/**
		 * Enables the Newspack Insights feature.
		 *
		 * @constant NEWSPACK_INSIGHTS_ENABLED
		 * @type     bool
		 * @default  Insights feature disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_INSIGHTS_ENABLED', true );
		 */
		return defined( 'NEWSPACK_INSIGHTS_ENABLED' ) && NEWSPACK_INSIGHTS_ENABLED;
	}

	/**
	 * The capability required to view the Insights wizard pages. Reused by the
	 * pre-warm scheduler so warming is only triggered by users who can see the
	 * dashboard.
	 *
	 * @return string
	 */
	public static function get_required_capability(): string {
		return 'manage_options';
	}

	/**
	 * Globally disable the Insights cache for development / debugging.
	 *
	 * @constant NEWSPACK_INSIGHTS_CACHE_DISABLED
	 * @type     bool
	 * @default  Caching enabled
	 * @status   stable
	 *
	 * @example define( 'NEWSPACK_INSIGHTS_CACHE_DISABLED', true );
	 */

	/**
	 * Constructor.
	 *
	 * Bails before parent registration when the feature flag is disabled,
	 * so no menu item, asset enqueue, or admin hooks are registered.
	 */
	public function __construct() {
		if ( ! self::is_enabled() ) {
			return;
		}
		parent::__construct();
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string
	 */
	public function get_name() {
		return esc_html__( 'Insights', 'newspack-plugin' );
	}

	/**
	 * Enqueue the shared modern-wizard bundle and localize boot config.
	 *
	 * The React view is registered in src/wizards/index.tsx under the
	 * 'newspack-insights' key.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();

		if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
			return;
		}

		wp_enqueue_script( 'newspack-wizards' );

		wp_localize_script( 'newspack-wizards', 'newspackInsights', $this->get_boot_config() );
	}

	/**
	 * Cache key for the donation-activity detection result.
	 *
	 * @var string
	 */
	const DONATION_ACTIVITY_TRANSIENT = 'newspack_insights_has_donation_activity';

	/**
	 * Trailing window, in days, over which a completed donation order keeps the
	 * Donors tab visible. Matches the active-donor recency leg the donor
	 * storage adapters use ({@see \Newspack\Insights\HPOS_Donors_Storage}),
	 * so the visibility gate and the tab's own metrics agree on what counts
	 * as "has donations". (Active subscriptions keep the tab visible
	 * regardless of this window — see {@see self::build_donation_activity_sql()}.)
	 *
	 * @var int
	 */
	const DONATION_ACTIVITY_WINDOW_DAYS = 365;

	/**
	 * Donors tab visibility. True when the publisher has active donation
	 * activity — an active donation subscription, or a completed donation
	 * order in the trailing {@see self::DONATION_ACTIVITY_WINDOW_DAYS} days
	 * (the same two-leg definition the Donors metrics use for an active
	 * donor). A publisher who collects donations through a third-party
	 * platform — or who has no active donation subscription and no
	 * Newspack-native WooCommerce donation order in over a year — does not
	 * get the tab, because its metrics would have nothing to report.
	 *
	 * Product existence is NOT a useful signal: every Newspack publisher
	 * receives the canonical donation product family on install regardless
	 * of whether they ever collect donations, so a product-existence
	 * check showed Tab 7 on every site, including the many publishers
	 * who have never taken a donation. Recent activity is the right
	 * heuristic — an active donation subscription, or a single qualifying
	 * donation order in the trailing window, gates the tab visible.
	 *
	 * Result is cached for 24h via {@see self::DONATION_ACTIVITY_TRANSIENT}.
	 * State transitions ("publisher started taking donations") are rare
	 * and one-way, so aggressive caching is correct. Tests / manual
	 * invalidation can call {@see self::force_refresh_donation_activity()}.
	 *
	 * Returns false immediately when the donation product ID set is
	 * empty (nothing the activity query could match) without running
	 * the EXISTS query. Falls back to true if the classifier class
	 * isn't loaded (defensive — preserves visibility so the missing
	 * dependency can be diagnosed rather than silently hiding the tab).
	 *
	 * @return bool
	 */
	private static function has_donation_activity(): bool {
		$cached = get_transient( self::DONATION_ACTIVITY_TRANSIENT );
		if ( 'yes' === $cached ) {
			return true;
		}
		if ( 'no' === $cached ) {
			return false;
		}

		$has_activity = self::compute_donation_activity();
		set_transient( self::DONATION_ACTIVITY_TRANSIENT, $has_activity ? 'yes' : 'no', DAY_IN_SECONDS );
		return $has_activity;
	}

	/**
	 * Force-recompute the donation activity flag, bypassing and
	 * refreshing the cache. Useful for tests and for the case where a
	 * publisher just received their first donation.
	 *
	 * @return bool The freshly computed activity flag.
	 */
	public static function force_refresh_donation_activity(): bool {
		delete_transient( self::DONATION_ACTIVITY_TRANSIENT );
		// Recompute from live state: also clear the donation-product set and
		// backend caches the activity query depends on, so a just-configured
		// donation (or a test) isn't evaluated against a stale product set or
		// backend.
		if ( class_exists( '\Newspack\Insights\Donation_Product_Classifier' ) ) {
			\Newspack\Insights\Donation_Product_Classifier::flush_cache();
		}
		if ( class_exists( '\Newspack\Insights\Storage_Detector' ) ) {
			\Newspack\Insights\Storage_Detector::force_refresh();
		}
		$has_activity = self::compute_donation_activity();
		set_transient( self::DONATION_ACTIVITY_TRANSIENT, $has_activity ? 'yes' : 'no', DAY_IN_SECONDS );
		return $has_activity;
	}

	/**
	 * Run the activity query without consulting the cache.
	 *
	 * @return bool
	 */
	private static function compute_donation_activity(): bool {
		if ( ! class_exists( '\Newspack\Insights\Donation_Product_Classifier' ) ) {
			// Defensive: keep tab visible so the missing dep can be diagnosed.
			return true;
		}
		$donation_ids = \Newspack\Insights\Donation_Product_Classifier::get_donation_product_ids();
		if ( empty( $donation_ids ) ) {
			return false;
		}

		// Dispatch by backend so we read from the authoritative orders
		// source rather than scanning a potentially stale legacy CPT
		// table on HPOS sites (or vice versa).
		$backend = class_exists( '\Newspack\Insights\Storage_Detector' )
			? \Newspack\Insights\Storage_Detector::detect()
			: 'legacy';

		$donations_list = implode( ',', array_map( 'intval', $donation_ids ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) (int) $wpdb->get_var( self::build_donation_activity_sql( $backend, $donations_list ) );
		// phpcs:enable
	}

	/**
	 * Build the query that tests for active donation activity.
	 *
	 * Mirrors the two-leg "active donor" definition the Donors metrics use
	 * ({@see \Newspack\Insights\HPOS_Donors_Storage::get_active_donors()}) so
	 * the gate and the tab's own scorecards agree on whether there's anything
	 * to show. A publisher is active if EITHER:
	 *
	 *  - they have an **active donation subscription** (`shop_subscription` in
	 *    `wc-active`), regardless of date — this keeps recurring donors
	 *    (annual subscribers, and subscribers whose latest renewal order is
	 *    pending/on-hold after a retry) visible, which a shop_order-only
	 *    recency check would wrongly hide; OR
	 *  - they have a **completed donation order** (`shop_order` in
	 *    `wc-completed` / `wc-processing`) in the trailing
	 *    {@see self::DONATION_ACTIVITY_WINDOW_DAYS}-day window — this also
	 *    covers one-time gifts and subscription renewals, which post their own
	 *    dated `shop_order` rows.
	 *
	 * The order leg resolves products via `wc_order_product_lookup`, the same
	 * table the metrics use, so the two can't disagree at the margins: if that
	 * analytics table is unpopulated the tab's metrics are empty too, so
	 * hiding stays consistent. Lapsed subscription statuses (`wc-cancelled`,
	 * `wc-expired`, …) and refunded/failed/pending orders are intentionally
	 * excluded — they aren't active activity. `UTC_TIMESTAMP()` matches the
	 * UTC `*_gmt` columns.
	 *
	 * Returned as a string (rather than executed in place) so the query shape
	 * is unit-testable without WooCommerce order tables installed.
	 *
	 * @param string $backend        Storage backend: 'hpos' or 'legacy'.
	 * @param string $donations_list Comma-separated, integer-sanitized product IDs.
	 * @return string SQL string.
	 */
	private static function build_donation_activity_sql( string $backend, string $donations_list ): string {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$days   = (int) self::DONATION_ACTIVITY_WINDOW_DAYS;

		if ( 'hpos' === $backend ) {
			return "SELECT (
				EXISTS (
					SELECT 1 FROM {$prefix}wc_orders o
					JOIN {$prefix}woocommerce_order_items oi
						ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
					JOIN {$prefix}woocommerce_order_itemmeta oim
						ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
					WHERE o.type = 'shop_subscription'
					  AND o.status = 'wc-active'
					  AND oim.meta_value IN ($donations_list)
				)
				OR EXISTS (
					SELECT 1 FROM {$prefix}wc_orders o
					JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
					WHERE o.type = 'shop_order'
					  AND o.status IN ('wc-completed', 'wc-processing')
					  AND o.date_created_gmt >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL {$days} DAY )
					  AND opl.product_id IN ($donations_list)
				)
			) AS has_activity";
		}

		return "SELECT (
			EXISTS (
				SELECT 1 FROM {$prefix}posts p
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND p.post_status = 'wc-active'
				  AND oim.meta_value IN ($donations_list)
			)
			OR EXISTS (
				SELECT 1 FROM {$prefix}posts p
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
				WHERE p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed', 'wc-processing')
				  AND p.post_date_gmt >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL {$days} DAY )
				  AND opl.product_id IN ($donations_list)
			)
		) AS has_activity";
	}

	/**
	 * Whether the Advertising (Tab 8) nav entry should render. Shown when Google
	 * Ad Manager is the active ad provider (runtime check), or when fixture mode
	 * is on (so the tab is testable without a GAM connection).
	 *
	 * @return bool
	 */
	private static function is_advertising_tab_visible(): bool {
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return true;
		}
		return \Newspack\Insights\Advertising_Metric::is_tab_visible();
	}

	/**
	 * Whether the App (Tab 10) nav entry should render: Pugpig ("Bolt") app
	 * publishers only — or fixture mode for dev testing. Public because
	 * {@see Insights_Section_App::init()} gates its own initialization on this.
	 *
	 * @return bool
	 */
	public static function is_app_tab_visible(): bool {
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return true;
		}
		return class_exists( '\Newspack_Manager\Pugpig\Pugpig' )
			&& \Newspack_Manager\Pugpig\Pugpig::is_enabled();
	}

	/**
	 * Whether the Newsletter Ads (NPPD-1861) nav entry should render: the
	 * newsletter ads CPT exists and the site has at least one published ad
	 * (transient-cached in the orchestrator), or fixture mode is on so the tab
	 * is testable without the newsletters plugin. Class-guarded so a boot-order
	 * hiccup (section not loaded) degrades to a hidden tab, never a fatal.
	 *
	 * @return bool
	 */
	private static function is_newsletter_ads_tab_visible(): bool {
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return true;
		}
		return class_exists( '\Newspack\Insights\Newsletter_Ads_Metric' )
			&& \Newspack\Insights\Newsletter_Ads_Metric::is_tab_visible();
	}

	/**
	 * Build the boot config consumed by the React entry.
	 *
	 * @return array
	 */
	protected function get_boot_config() {
		// current_datetime() returns DateTimeImmutable; modify() returns a new
		// instance and does not mutate $today. -29 days yields an inclusive
		// 30-day window ending today (today + 29 prior days = 30 days).
		$today      = current_datetime();
		$thirty_ago = $today->modify( '-29 days' );

		return [
			// Tab visibility. Audience, Engagement, Conversion, and Prompts tabs
			// are live BQ-backed tabs (data layers complete per NPPD-1729). They
			// are always shown (true) — feature detection is handled at the metric
			// level via the BQ proxy, not at tab-registration time. Advertising
			// (Tab 8, NPPD-1618) has its own data layer: it shows when Google Ad
			// Manager is the active ad provider (Advertising_Metric::is_tab_visible()
			// === Client::is_gam_active()) or fixture mode is on for dev testing.
			// See is_advertising_tab_visible().
			// Subscribers stays all-on for now; Tab 6 visibility detection
			// (non-donation subscription product presence) is a separate follow-up.
			// Donors hides when there's no recent donation activity —
			// has_donation_activity() uses the Donation_Product_Classifier to find
			// donation products, then checks for an active donation subscription or
			// a qualifying donation order in the trailing
			// DONATION_ACTIVITY_WINDOW_DAYS window (cached for a day). Gates is
			// always shown (true) alongside the other BQ-backed tabs; it is
			// governed solely by the Insights feature flag.
			'tabs'                => [
				'audience'       => true,
				'engagement'     => true,
				'conversion'     => true,
				'gates'          => true,
				'prompts'        => true,
				'subscribers'    => true,
				'donors'         => self::has_donation_activity(),
				'advertising'    => self::is_advertising_tab_visible(),
				// Newsletter Ads (NPPD-1861): shown when the site has published
				// newsletter ads (or fixture mode). See is_newsletter_ads_tab_visible().
				'newsletter_ads' => self::is_newsletter_ads_tab_visible(),
				// App (Tab 10, NPPD-1882): shown for Pugpig app publishers (or
				// fixture mode). See is_app_tab_visible().
				'app'            => self::is_app_tab_visible(),
			],
			'defaultDateRange'    => [
				'preset' => 'last-30',
				'start'  => $thirty_ago->format( 'Y-m-d' ),
				'end'    => $today->format( 'Y-m-d' ),
			],
			'defaultComparison'   => false,
			'timezone'            => wp_timezone_string(),
			'adminUrl'            => admin_url(),
			'settingsUrl'         => admin_url( 'admin.php?page=newspack-settings' ),
			'siteKitUrl'          => self::get_site_kit_url(),
			// Publisher (site) name, shown in the PDF export document header
			// (NPPD-1661). Resolved at render time from the site's own title —
			// never a hardcoded name. Decode entities: `blogname` is stored
			// HTML-escaped (e.g. "Ben &amp; Jerry's"), and React escapes again
			// on render, so a raw get_bloginfo() would print the literal entity.
			// Hand React the decoded string and let it do the single escaping.
			'publisherName'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			// Feedback abandon-beacon (NPPD-1728). navigator.sendBeacon can't set
			// request headers, so the client needs the absolute REST URL plus a
			// `wp_rest` nonce as a query param. apiFetch carries its own nonce for
			// the normal submit/dismiss path, but the beacon can't reuse it and
			// `window.wpApiSettings` isn't enqueued on this page — so hand both to
			// the client explicitly here.
			'feedbackBeaconUrl'   => rest_url( 'newspack-insights/v1/feedback' ),
			'feedbackBeaconNonce' => wp_create_nonce( 'wp_rest' ),
			// Per-tab "next steps" links (NPPD-1842) — outcome-worded entry points
			// to the matching help-site Playbooks flow, rendered as a strip in the
			// tab footer. Product-owned mapping; see get_next_steps_links().
			'nextStepsLinks'      => self::get_next_steps_links(),
		];
	}

	/**
	 * Per-tab "next steps" links (NPPD-1842).
	 *
	 * The in-product half of NPPD-1723: a static, outcome-framed strip pinned
	 * below the metrics on each relevant tab, linking to the matching help-site
	 * "Playbooks" goal flow. The whole bet is the copy — a link is worded as the
	 * outcome ("Grow reader revenue"), never a generic "Help" or "Learn more".
	 *
	 * Product-owned mapping, held to 1–2 links per tab. A tab only gets a link
	 * when the outcome squarely matches it, so tabs with no strong match (Gates,
	 * Campaigns, Advertising in v1) are intentionally absent and render no strip.
	 * The whole map is filterable so the mapping and copy can be tuned without a
	 * code change.
	 *
	 * @return array<string, array<int, array{label: string, url: string}>> Map of tab key => ordered list of { label, url }.
	 */
	protected static function get_next_steps_links() {
		// TEMPORARY: the Playbooks pages currently live only on the STAGING help
		// site. Flip this base to https://help.newspack.com/playbooks/ once they
		// are published to production help (NPPD-1843/1844/1845).
		$base = 'https://help.newspackstaging.com/playbooks/';

		$grow_newsletter_signups = [
			'label' => __( 'Grow newsletter signups', 'newspack-plugin' ),
			'url'   => $base . 'grow-newsletter-signups/',
		];
		$grow_reader_revenue = [
			'label' => __( 'Grow reader revenue', 'newspack-plugin' ),
			'url'   => $base . 'grow-reader-revenue/',
		];
		$recover_lapsed_donors = [
			'label' => __( 'Recover lapsed donors', 'newspack-plugin' ),
			'url'   => $base . 'recover-lapsed-donors/',
		];

		$links = [
			'audience'    => [ $grow_newsletter_signups ],
			'engagement'  => [ $grow_newsletter_signups ],
			'conversion'  => [ $grow_newsletter_signups, $grow_reader_revenue ],
			'subscribers' => [ $grow_reader_revenue ],
			'donors'      => [ $grow_reader_revenue, $recover_lapsed_donors ],
		];

		/**
		 * Filters the per-tab Insights "next steps" links (NPPD-1842).
		 *
		 * Keys are Insights tab slugs (audience, engagement, conversion, gates,
		 * prompts, subscribers, donors, advertising); each value is an ordered
		 * list of `[ 'label' => string, 'url' => string ]`. Return an empty list
		 * (or omit the key) to hide the strip on that tab.
		 *
		 * @param array<string, array<int, array{label: string, url: string}>> $links Map of tab key => list of { label, url }.
		 */
		$filtered = apply_filters( 'newspack_insights_next_steps_links', $links );

		// Harden: the filtered value is fed to the client and rendered into an
		// href, so a malformed or malicious filter must not reach React. Keep only
		// well-formed entries with a safe http(s) URL — esc_url_raw() strips
		// disallowed schemes (e.g. javascript:), returning '' for them.
		$sanitized = [];
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $tab => $tab_links ) {
				if ( ! is_array( $tab_links ) ) {
					continue;
				}
				foreach ( $tab_links as $link ) {
					if ( ! is_array( $link ) || empty( $link['label'] ) || empty( $link['url'] ) ) {
						continue;
					}
					$url = esc_url_raw( (string) $link['url'], [ 'http', 'https' ] );
					if ( '' === $url ) {
						continue;
					}
					$sanitized[ $tab ][] = [
						'label' => (string) $link['label'],
						'url'   => $url,
					];
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Admin URL for connecting Google Analytics through Site Kit (NPPD-1731).
	 * GA4 is owned upstream by Site Kit, so the banner points there rather than
	 * at Newspack → Connections.
	 *
	 * Precedence mirrors Newspack Settings and the Dashboard:
	 *  - Site Kit set up with the Analytics module → deep link to the GA4 service.
	 *  - Site Kit active but Analytics not yet connected → Site Kit's setup splash.
	 *  - Site Kit not installed at all → Newspack → Connections, where it gets
	 *    installed (the splash URL would 404 without the plugin present).
	 *
	 * @return string
	 */
	private static function get_site_kit_url(): string {
		if ( google_site_kit_available() ) {
			return admin_url( 'admin.php?page=googlesitekit-settings#/connected-services/analytics-4' );
		}
		if ( GoogleSiteKit::is_active() ) {
			return admin_url( 'admin.php?page=googlesitekit-splash' );
		}
		return admin_url( 'admin.php?page=newspack-settings' );
	}
}
