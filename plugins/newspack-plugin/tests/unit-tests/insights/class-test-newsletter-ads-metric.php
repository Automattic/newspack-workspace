<?php
/**
 * Test Newsletter_Ads_Metric (NPPD-1861).
 *
 * Covers tab visibility (ads-presence transient), report readiness (Ad_Stats
 * class + stats table), the not-ready degradation of get_all() (timeframe
 * metrics non-computable while lifetime metrics stay real), the lifetime CTR
 * zero-impressions guard, the flat-over-flight revenue proration (including
 * partial window overlap and exclusion counting), and the by-newsletter
 * breakdown's unknown-source sentinel exclusion.
 *
 * The newsletters plugin is NOT loaded in this suite's bootstrap, so the
 * tests pull in a minimal test double for
 * `Newspack_Newsletters\Tracking\Ad_Stats` (doubles/class-ad-stats.php) and
 * create the stats table directly, mimicking the documented schema. The
 * "Ad_Stats class absent" leg is exercised through the orchestrator's
 * protected `ad_stats_class()` seam
 * (doubles/class-newsletter-ads-metric-no-stats.php), since a defined class
 * can't be undefined.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Newsletter_Ads_Metric;
use WP_UnitTestCase;

require_once __DIR__ . '/doubles/class-ad-stats.php';
require_once __DIR__ . '/doubles/class-newsletter-ads-metric-no-stats.php';

/**
 * Newsletter_Ads_Metric test class.
 *
 * @group insights
 */
class Test_Newsletter_Ads_Metric extends WP_UnitTestCase {

	/**
	 * Set up: register the ads CPT + advertiser taxonomy (the newsletters
	 * plugin isn't loaded in this suite, and WP_UnitTestCase resets custom
	 * post types between tests) and clear the orchestrator's caches.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type( Newsletter_Ads_Metric::ADS_CPT, [ 'public' => false ] );
		register_taxonomy( Newsletter_Ads_Metric::ADVERTISER_TAX, [ Newsletter_Ads_Metric::ADS_CPT ] );
		Newsletter_Ads_Metric::reset_readiness_cache();
		delete_transient( Newsletter_Ads_Metric::ADS_PRESENCE_TRANSIENT );
	}

	/**
	 * Tear down: drop the stats table AFTER the parent's transaction
	 * rollback (DDL would implicitly commit any pending test data) and
	 * clear the orchestrator caches.
	 */
	public function tear_down() {
		parent::tear_down();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}newspack_newsletters_ad_stats" );
		// phpcs:enable
		Newsletter_Ads_Metric::reset_readiness_cache();
		delete_transient( Newsletter_Ads_Metric::ADS_PRESENCE_TRANSIENT );
	}

	/*
	 * Helpers
	 */

	/**
	 * Create the dated stats table, mimicking the newsletters plugin's
	 * documented schema, and reset the existence memo. Created FIRST in a
	 * test (before factory data) so the DDL's implicit commit happens
	 * before any rollback-able DML.
	 */
	private function create_stats_table(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}newspack_newsletters_ad_stats (
				ad_id BIGINT UNSIGNED NOT NULL,
				newsletter_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				stat_date DATE NOT NULL,
				impressions INT UNSIGNED NOT NULL DEFAULT 0,
				clicks INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (ad_id, newsletter_id, stat_date),
				KEY stat_date (stat_date)
			)"
		);
		// phpcs:enable
		Newsletter_Ads_Metric::reset_readiness_cache();
	}

	/**
	 * Insert a stats row directly.
	 *
	 * @param int    $ad_id         Ad post ID.
	 * @param int    $newsletter_id Newsletter post ID (0 = unknown-source sentinel).
	 * @param string $stat_date     Y-m-d (UTC day).
	 * @param int    $impressions   Impressions.
	 * @param int    $clicks        Clicks.
	 */
	private function insert_stat( int $ad_id, int $newsletter_id, string $stat_date, int $impressions, int $clicks ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'newspack_newsletters_ad_stats',
			[
				'ad_id'         => $ad_id,
				'newsletter_id' => $newsletter_id,
				'stat_date'     => $stat_date,
				'impressions'   => $impressions,
				'clicks'        => $clicks,
			],
			[ '%d', '%d', '%s', '%d', '%d' ]
		);
		// phpcs:enable
	}

	/**
	 * Create a published newsletter ad with meta.
	 *
	 * @param string $title Ad title.
	 * @param array  $meta  Meta key => value map (price, start_date, expiry_date, tracking_*).
	 * @return int Ad post ID.
	 */
	private function create_ad( string $title, array $meta = [] ): int {
		$ad_id = static::factory()->post->create(
			[
				'post_type'   => Newsletter_Ads_Metric::ADS_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		foreach ( $meta as $key => $value ) {
			add_post_meta( $ad_id, $key, $value );
		}
		return $ad_id;
	}

	/**
	 * Call get_all() with the window's envelope transient cleared first, so a
	 * previous call in the same test (or the No_Stats subclass, which shares
	 * the cache key) can't serve a stale envelope.
	 *
	 * @param string $orchestrator Orchestrator class to call.
	 * @param string $start        Window start (Y-m-d).
	 * @param string $end          Window end (Y-m-d).
	 * @return array
	 */
	private function fresh_get_all( string $orchestrator, string $start, string $end ): array {
		delete_transient( Newsletter_Ads_Metric::CACHE_KEY_PREFIX . $start . ':' . $end );
		return call_user_func( [ $orchestrator, 'get_all' ], $start, $end );
	}

	/*
	 * Visibility
	 */

	/**
	 * No published ads → tab hidden. A draft ad doesn't count.
	 */
	public function test_tab_hidden_without_published_ads() {
		$this->assertFalse( Newsletter_Ads_Metric::force_refresh_tab_visibility() );
		$this->assertFalse( Newsletter_Ads_Metric::is_tab_visible() );

		static::factory()->post->create(
			[
				'post_type'   => Newsletter_Ads_Metric::ADS_CPT,
				'post_status' => 'draft',
			]
		);
		$this->assertFalse( Newsletter_Ads_Metric::force_refresh_tab_visibility(), 'A draft ad must not make the tab visible.' );
	}

	/**
	 * A published ad makes the tab visible — but only after the 24h
	 * presence transient is refreshed (force_refresh_tab_visibility()),
	 * since state transitions are cached aggressively.
	 */
	public function test_tab_visible_with_published_ad_after_transient_reset() {
		// Prime the cache in the no-ads state.
		$this->assertFalse( Newsletter_Ads_Metric::is_tab_visible() );

		$this->create_ad( 'Hometown Hardware — Spring Sale' );

		// Still hidden: the 'no' result is transient-cached.
		$this->assertFalse( Newsletter_Ads_Metric::is_tab_visible(), 'The cached no-ads result must be served until refreshed.' );

		// Force refresh recomputes and re-primes the cache.
		$this->assertTrue( Newsletter_Ads_Metric::force_refresh_tab_visibility() );
		$this->assertTrue( Newsletter_Ads_Metric::is_tab_visible() );
	}

	/*
	 * Readiness
	 */

	/**
	 * Without the Ad_Stats class (old newsletters build — simulated via the
	 * protected seam), reporting is not ready and the stats-missing issue
	 * is surfaced.
	 */
	public function test_report_not_ready_when_ad_stats_class_absent() {
		$this->create_stats_table();
		$this->assertFalse( Newsletter_Ads_Metric_No_Stats::is_report_ready(), 'A present table must not make reporting ready without the Ad_Stats class.' );

		$codes = array_column( Newsletter_Ads_Metric_No_Stats::readiness_issues(), 'code' );
		$this->assertContains( 'newsletter_ads_stats_missing', $codes );
	}

	/**
	 * With the Ad_Stats class (test double) present, readiness follows the
	 * table's actual existence — memoized, so a reset is required between
	 * checks.
	 */
	public function test_report_ready_follows_table_existence() {
		// No table yet.
		$this->assertFalse( Newsletter_Ads_Metric::is_report_ready() );

		$this->create_stats_table();
		$this->assertTrue( Newsletter_Ads_Metric::is_report_ready() );
		$this->assertSame( [], array_column( Newsletter_Ads_Metric::readiness_issues(), 'code' ), 'A ready report must carry no stats-missing issue.' );
	}

	/*
	 * get_all(): not-ready degradation + lifetime metrics
	 */

	/**
	 * When reporting isn't ready, get_all() still returns the full
	 * envelope: lifetime metrics computed from the meta counters, every
	 * timeframe metric present but computable=false, no fatals, and no
	 * has_window_activity key (so the UI's empty-state check can't fire).
	 */
	public function test_get_all_not_ready_degrades_timeframe_but_computes_lifetime() {
		$this->create_ad(
			'Riverside Cafe — Weekend Brunch',
			[
				'tracking_impressions' => 5000,
				'tracking_clicks'      => 75,
			]
		);
		$this->create_ad(
			'Maple Street Books — Author Night',
			[
				'tracking_impressions' => 3000,
				'tracking_clicks'      => 25,
			]
		);

		$envelope = $this->fresh_get_all( Newsletter_Ads_Metric_No_Stats::class, '2026-06-01', '2026-06-30' );

		$this->assertFalse( $envelope['is_report_ready'] );
		$this->assertFalse( $envelope['is_loading'] );
		$this->assertSame( '2026-06-01', $envelope['window']['start'] );
		$this->assertSame( '2026-06-30', $envelope['window']['end'] );
		$this->assertContains( 'newsletter_ads_stats_missing', array_column( $envelope['readiness_issues'], 'code' ) );
		$this->assertArrayNotHasKey( 'has_window_activity', $envelope, 'The activity signal must be absent when window metrics are not computable.' );

		$metrics = $envelope['metrics'];

		// Lifetime metrics are real (meta counters need no stats table).
		$this->assertSame( 8000, $metrics['lifetime_impressions']['value'] );
		$this->assertTrue( $metrics['lifetime_impressions']['computable'] );
		$this->assertSame( 100, $metrics['lifetime_clicks']['value'] );
		$this->assertTrue( $metrics['lifetime_ctr']['computable'] );
		$this->assertEqualsWithDelta( 100 / 8000, $metrics['lifetime_ctr']['value'], 0.0001 );

		// Every timeframe metric is present but non-computable.
		$timeframe_keys = [ 'total_impressions', 'total_clicks', 'ctr', 'total_revenue', 'revenue_excluded_ads', 'ecpm', 'active_ads', 'performance_by_day', 'top_ads', 'top_advertisers', 'by_newsletter' ];
		foreach ( $timeframe_keys as $key ) {
			$this->assertArrayHasKey( $key, $metrics );
			$this->assertFalse( $metrics[ $key ]['computable'], "Timeframe metric '$key' must be non-computable when the stats table is unavailable." );
		}
	}

	/**
	 * Lifetime CTR at zero impressions (the real click-tracking-on /
	 * pixel-off case) must be non-computable — n/a, never a fake 0%.
	 */
	public function test_lifetime_ctr_not_computable_at_zero_impressions() {
		$this->create_ad(
			'Sunrise Bakery — Fresh Daily',
			[
				'tracking_impressions' => 0,
				'tracking_clicks'      => 40,
			]
		);

		$envelope = $this->fresh_get_all( Newsletter_Ads_Metric_No_Stats::class, '2026-05-01', '2026-05-31' );
		$metrics  = $envelope['metrics'];

		$this->assertSame( 40, $metrics['lifetime_clicks']['value'] );
		$this->assertSame( 0, $metrics['lifetime_impressions']['value'] );
		$this->assertFalse( $metrics['lifetime_ctr']['computable'], 'CTR over zero impressions must be non-computable.' );
	}

	/*
	 * Flat-over-flight revenue
	 */

	/**
	 * Revenue prorates each ad's flat price over its flight, counting only
	 * the days overlapping the window; ads missing price/dates are excluded
	 * from revenue but counted in revenue_excluded_ads and active_ads.
	 */
	public function test_flat_over_flight_revenue_prorates_and_counts_exclusions() {
		$this->create_stats_table();

		// Fully inside the window: 30-day flight, $300 → all $300.
		$this->create_ad(
			'Hometown Hardware — Spring Sale',
			[
				'price'       => '300',
				'start_date'  => '2026-06-01',
				'expiry_date' => '2026-06-30',
			]
		);
		// Partial overlap: 10-day flight at $10/day, 5 days in-window → $50.
		$this->create_ad(
			'Valley Bike Shop — Tune-Up Special',
			[
				'price'       => '100',
				'start_date'  => '2026-05-27',
				'expiry_date' => '2026-06-05',
			]
		);
		// Overlapping flight but NO price → excluded from revenue, still active.
		$this->create_ad(
			'House Promo — Membership Drive',
			[
				'start_date'  => '2026-06-10',
				'expiry_date' => '2026-06-20',
			]
		);
		// Valid but entirely outside the window → contributes nothing, not active.
		$this->create_ad(
			'Bluebird Florist — Mothers Day',
			[
				'price'       => '500',
				'start_date'  => '2026-07-10',
				'expiry_date' => '2026-07-20',
			]
		);

		$envelope = $this->fresh_get_all( Newsletter_Ads_Metric::class, '2026-06-01', '2026-06-30' );
		$metrics  = $envelope['metrics'];

		$this->assertTrue( $envelope['is_report_ready'] );
		$this->assertTrue( $metrics['total_revenue']['computable'] );
		$this->assertEqualsWithDelta( 350.0, $metrics['total_revenue']['value'], 0.001, 'Expected $300 (full flight) + $50 (5 of 10 flight days in-window).' );
		$this->assertSame( 1, $metrics['revenue_excluded_ads']['value'], 'The price-less overlapping ad must be counted as excluded.' );
		$this->assertSame( 3, $metrics['active_ads']['value'], 'Three flights overlap the window (the July flight does not).' );

		// No stats rows: volume is a computable zero, eCPM is not computable,
		// and the window is explicitly inactive.
		$this->assertTrue( $metrics['total_impressions']['computable'] );
		$this->assertSame( 0, $metrics['total_impressions']['value'] );
		$this->assertFalse( $metrics['ecpm']['computable'] );
		$this->assertFalse( $envelope['has_window_activity'] );
	}

	/*
	 * Stats-table breakdowns
	 */

	/**
	 * The by_newsletter table groups stats by source newsletter, EXCLUDING
	 * the newsletter_id=0 unknown-source sentinel (whose clicks still count
	 * toward window totals), counting DISTINCT ads per newsletter and
	 * labeling deleted newsletters.
	 */
	public function test_by_newsletter_excludes_sentinel_rows() {
		$this->create_stats_table();

		$ad_a = $this->create_ad( 'Riverside Cafe — Weekend Brunch' );
		$ad_b = $this->create_ad( 'Maple Street Books — Author Night' );
		wp_insert_term( 'Riverside Cafe', Newsletter_Ads_Metric::ADVERTISER_TAX );
		wp_set_object_terms( $ad_a, 'Riverside Cafe', Newsletter_Ads_Metric::ADVERTISER_TAX );

		$newsletter_id = static::factory()->post->create(
			[
				'post_title' => 'Weekly Roundup',
				'post_date'  => '2026-06-10 09:00:00',
			]
		);
		$deleted_newsletter_id = 999999;

		// Two ads in the real newsletter, one ad in a deleted one, plus
		// unknown-source sentinel clicks.
		$this->insert_stat( $ad_a, $newsletter_id, '2026-06-10', 1000, 20 );
		$this->insert_stat( $ad_b, $newsletter_id, '2026-06-10', 800, 10 );
		$this->insert_stat( $ad_a, $deleted_newsletter_id, '2026-06-12', 300, 5 );
		$this->insert_stat( $ad_a, 0, '2026-06-13', 0, 7 );

		$envelope = $this->fresh_get_all( Newsletter_Ads_Metric::class, '2026-06-01', '2026-06-30' );
		$metrics  = $envelope['metrics'];

		// Sentinel rows count toward totals.
		$this->assertSame( 2100, $metrics['total_impressions']['value'] );
		$this->assertSame( 42, $metrics['total_clicks']['value'] );
		$this->assertTrue( $envelope['has_window_activity'] );

		// But they never appear in the by-newsletter breakdown.
		$rows = $metrics['by_newsletter']['rows'];
		$this->assertCount( 2, $rows );
		$this->assertNotContains( 0, array_column( $rows, 'newsletter_id' ), 'The unknown-source sentinel must be excluded.' );

		$by_id = array_column( $rows, null, 'newsletter_id' );
		$this->assertSame( 'Weekly Roundup', $by_id[ $newsletter_id ]['title'] );
		$this->assertSame( '2026-06-10', $by_id[ $newsletter_id ]['sent_date'] );
		$this->assertSame( 2, $by_id[ $newsletter_id ]['ads'], 'Distinct ads per newsletter.' );
		$this->assertSame( 1800, $by_id[ $newsletter_id ]['impressions'] );
		$this->assertSame( '(deleted)', $by_id[ $deleted_newsletter_id ]['title'], 'A missing newsletter post is labeled (deleted).' );

		// top_ads reflects per-ad sums (sentinel included) with titles and
		// the advertiser term.
		$top_ads = $metrics['top_ads']['rows'];
		$by_ad   = array_column( $top_ads, null, 'ad_id' );
		$this->assertSame( 1300, $by_ad[ $ad_a ]['impressions'] );
		$this->assertSame( 32, $by_ad[ $ad_a ]['clicks'] );
		$this->assertSame( 'Riverside Cafe', $by_ad[ $ad_a ]['advertiser'] );
		$this->assertSame( '', $by_ad[ $ad_b ]['advertiser'] );

		// Daily series is chronological and skips zero-recorded days.
		$days = array_column( $metrics['performance_by_day']['rows'], 'date' );
		$this->assertSame( [ '2026-06-10', '2026-06-12', '2026-06-13' ], $days );
	}
}
