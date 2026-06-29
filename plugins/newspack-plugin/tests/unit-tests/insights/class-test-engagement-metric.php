<?php
/**
 * Test Engagement_Metric BQ payload shapers (NPPD-1729 Task B4/B5).
 *
 * The Engagement tab BQ swap is "zero UI change": each *_via_bq payload must
 * match the canonical engagement fixture's shape key-for-key, since the React
 * components (and the dev fixture) are unchanged. These tests load the fixture
 * as the spec and assert each metric's row keys (and relabeled values) against
 * it, mirroring the Audience-side reconciliation tests.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Engagement_Metric;
use WP_UnitTestCase;

/**
 * Engagement_Metric BQ shaper test class.
 *
 * @group insights
 */
class Test_Engagement_Metric extends WP_UnitTestCase {

	/**
	 * Build a fake BigQuery_Proxy_Client that returns canned rows for a specific
	 * query name. Any other query name returns an empty array.
	 *
	 * @param string $expected_name Catalog query name to intercept.
	 * @param array  $rows          Rows to return for that query.
	 * @return BigQuery_Proxy_Client
	 */
	private function makeProxyReturning( string $expected_name, array $rows ): BigQuery_Proxy_Client {
		return new class( $expected_name, $rows ) extends BigQuery_Proxy_Client {
			public function __construct( private string $expected_name, private array $rows ) {}
			public function query( string $query_name, \DateTimeInterface $start, \DateTimeInterface $end ) {
				return $query_name === $this->expected_name ? $this->rows : [];
			}
		};
	}

	/**
	 * Load the current-window block of the canonical Engagement fixture — the
	 * authoritative payload contract the frontend renders.
	 *
	 * @return array
	 */
	private function fixture_current(): array {
		$fixture = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/engagement-fixture.php';
		return $fixture['current'];
	}

	/**
	 * The row-key set the fixture defines for a given metric.
	 *
	 * @param string $metric Fixture metric key.
	 * @return string[] Sorted row keys.
	 */
	private function fixture_row_keys( string $metric ): array {
		$rows = $this->fixture_current()[ $metric ]['rows'];
		$keys = array_keys( $rows[0] );
		sort( $keys );
		return $keys;
	}

	/**
	 * Assert a shaped payload's first-row keys equal the fixture metric's keys.
	 *
	 * @param string $metric Fixture metric key.
	 * @param array  $out    Shaped via_bq payload.
	 */
	private function assertRowKeysMatchFixture( string $metric, array $out ): void {
		$keys = array_keys( $out['rows'][0] );
		sort( $keys );
		$this->assertSame(
			$this->fixture_row_keys( $metric ),
			$keys,
			"row keys for $metric must match the frontend fixture contract"
		);
	}

	/**
	 * A pair of window dates reused across cases.
	 *
	 * @return \DateTimeImmutable[]
	 */
	private function window(): array {
		return [ new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) ];
	}

	/*
	===================================================================
	 * Scalars (NPPD-1729 Task B4)
	 * ===================================================================
	 */

	/**
	 * avg_pages_per_session_via_bq → { value, computable, type: 'decimal' }.
	 */
	public function test_avg_pages_per_session_via_bq_shapes_decimal() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning( 'engagement_avg_pages_per_session', [ [ 'avg_pages_per_session' => 2.34 ] ] );
		$out   = Engagement_Metric::avg_pages_per_session_via_bq( $proxy, $start, $end );
		$this->assertSame( 2.34, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'decimal', $out['type'] );
	}

	/**
	 * avg_engaged_session_duration_via_bq reads the proxy's *_sec alias and emits
	 * type 'duration'.
	 */
	public function test_avg_engaged_session_duration_via_bq_shapes_duration() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning( 'engagement_avg_engaged_session_duration', [ [ 'avg_engaged_session_duration_sec' => 142 ] ] );
		$out   = Engagement_Metric::avg_engaged_session_duration_via_bq( $proxy, $start, $end );
		$this->assertSame( 142.0, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'duration', $out['type'] );
	}

	/**
	 * bounce_rate_via_bq → { value, computable, type: 'rate' }.
	 */
	public function test_bounce_rate_via_bq_shapes_rate() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning( 'engagement_bounce_rate', [ [ 'bounce_rate' => 0.34 ] ] );
		$out   = Engagement_Metric::bounce_rate_via_bq( $proxy, $start, $end );
		$this->assertSame( 0.34, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'rate', $out['type'] );
	}

	/**
	 * article_completion_rate_via_bq reads the proxy's scroll_to_90_rate alias and
	 * emits type 'rate'.
	 */
	public function test_article_completion_rate_via_bq_shapes_rate() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning( 'engagement_article_completion_rate', [ [ 'scroll_to_90_rate' => 0.42 ] ] );
		$out   = Engagement_Metric::article_completion_rate_via_bq( $proxy, $start, $end );
		$this->assertSame( 0.42, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'rate', $out['type'] );
	}

	/*
	===================================================================
	 * Tables (NPPD-1729 Task B5) — fixture-parity reconciliation
	 * ===================================================================
	 */

	/**
	 * most_read_articles: proxy carries page_url + avg_scroll_depth; the fixture
	 * renders only { page_title, unique_readers, avg_engagement_seconds,
	 * engagement_score } — the extra columns must be dropped.
	 */
	public function test_most_read_articles_drops_url_and_scroll_depth() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning(
			'engagement_most_read_articles',
			[
				[
					'page_url'               => 'https://example.test/a',
					'page_title'             => 'City council passes contested budget',
					'unique_readers'         => 21900,
					'avg_scroll_depth'       => 0.62,
					'avg_engagement_seconds' => 188,
					'engagement_score'       => 41200,
				],
			]
		);
		$out = Engagement_Metric::most_read_articles_via_bq( $proxy, $start, $end );
		$this->assertSame( 'table', $out['type'] );
		$this->assertRowKeysMatchFixture( 'most_read_articles', $out );
		$this->assertArrayNotHasKey( 'page_url', $out['rows'][0] );
		$this->assertArrayNotHasKey( 'avg_scroll_depth', $out['rows'][0] );
		$this->assertSame( 'City council passes contested budget', $out['rows'][0]['page_title'] );
		$this->assertSame( 21900, $out['rows'][0]['unique_readers'] );
		$this->assertSame( 41200.0, $out['rows'][0]['engagement_score'] );
	}

	/**
	 * articles_by_completion_rate: proxy carries page_url; the fixture renders only
	 * { page_title, readers, completion_rate } — page_url must be dropped.
	 */
	public function test_articles_by_completion_rate_drops_url() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning(
			'engagement_articles_by_completion_rate',
			[
				[
					'page_url'        => 'https://example.test/b',
					'page_title'      => 'Your guide to surviving the heat wave',
					'readers'         => 11200,
					'completion_rate' => 0.62,
				],
			]
		);
		$out = Engagement_Metric::articles_by_completion_rate_via_bq( $proxy, $start, $end );
		$this->assertSame( 'table', $out['type'] );
		$this->assertRowKeysMatchFixture( 'articles_by_completion_rate', $out );
		$this->assertArrayNotHasKey( 'page_url', $out['rows'][0] );
		$this->assertSame( 11200, $out['rows'][0]['readers'] );
		$this->assertSame( 0.62, $out['rows'][0]['completion_rate'] );
	}

	/**
	 * top_authors_by_avg_engagement_time: proxy carries article_reads +
	 * avg_scroll_depth; the fixture renders only
	 * { author, unique_readers, avg_engagement_seconds } — drop the extras.
	 */
	public function test_top_authors_by_avg_engagement_time_projects_to_fixture() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning(
			'engagement_top_authors_by_avg_engagement_time',
			[
				[
					'author'                 => 'Priya Nair',
					'article_reads'          => 980,
					'unique_readers'         => 12400,
					'avg_engagement_seconds' => 246,
					'avg_scroll_depth'       => 0.58,
				],
			]
		);
		$out = Engagement_Metric::top_authors_by_avg_engagement_time_via_bq( $proxy, $start, $end );
		$this->assertSame( 'table', $out['type'] );
		$this->assertRowKeysMatchFixture( 'top_authors_by_avg_engagement_time', $out );
		$this->assertArrayNotHasKey( 'article_reads', $out['rows'][0] );
		$this->assertArrayNotHasKey( 'avg_scroll_depth', $out['rows'][0] );
		$this->assertSame( 'Priya Nair', $out['rows'][0]['author'] );
		$this->assertSame( 12400, $out['rows'][0]['unique_readers'] );
	}

	/**
	 * engagement_by_device_type: proxy carries avg_scroll_depth (dropped) and does
	 * NOT yet return avg_pages_per_session (a PR 1 SQL gap). The shaper keeps the
	 * available keys and drops avg_scroll_depth; it must not invent the missing key.
	 */
	public function test_engagement_by_device_type_drops_scroll_depth_and_omits_missing_pages() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning(
			'engagement_by_device_type',
			[
				[
					'device'                 => 'mobile',
					'sessions'               => 178000,
					'avg_engagement_seconds' => 118,
					'avg_scroll_depth'       => 0.51,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_device_type_via_bq( $proxy, $start, $end );
		$this->assertSame( 'table', $out['type'] );
		$this->assertArrayNotHasKey( 'avg_scroll_depth', $out['rows'][0] );
		// PR 1 SQL gap: the proxy does not return avg_pages_per_session, so the
		// shaper must not fabricate it.
		$this->assertArrayNotHasKey( 'avg_pages_per_session', $out['rows'][0] );
		$this->assertSame( 'mobile', $out['rows'][0]['device'] );
		$this->assertSame( 178000, $out['rows'][0]['sessions'] );
		$this->assertSame( 118.0, $out['rows'][0]['avg_engagement_seconds'] );
	}

	/**
	 * engagement_by_device_type: once PR 1 adds avg_pages_per_session to the proxy
	 * SELECT, the shaper forwards it and the row matches the fixture exactly — no
	 * further plugin change required.
	 */
	public function test_engagement_by_device_type_forwards_pages_when_present() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning(
			'engagement_by_device_type',
			[
				[
					'device'                 => 'mobile',
					'sessions'               => 178000,
					'avg_engagement_seconds' => 118,
					'avg_pages_per_session'  => 2.1,
					'avg_scroll_depth'       => 0.51,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_device_type_via_bq( $proxy, $start, $end );
		$this->assertRowKeysMatchFixture( 'engagement_by_device_type', $out );
		$this->assertSame( 2.1, $out['rows'][0]['avg_pages_per_session'] );
	}

	/**
	 * engagement_by_traffic_source: per-channel proxy rows are bucketed into the
	 * newsletter/other cohorts, weighting avg_engagement_seconds by sessions. The
	 * cohort row shape must match the fixture { segment, sessions,
	 * avg_engagement_seconds } and the NEWSLETTER_SESSION_FLOOR needs_data guard.
	 */
	public function test_engagement_by_traffic_source_buckets_to_fixture_shape() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning(
			'engagement_by_traffic_source',
			[
				[
					'channel'                => 'Email',
					'sessions'               => 38200,
					'avg_pages_per_session'  => 2.4,
					'avg_engagement_seconds' => 98,
				],
				[
					'channel'                => 'Organic Search',
					'sessions'               => 200000,
					'avg_pages_per_session'  => 1.8,
					'avg_engagement_seconds' => 40,
				],
				[
					'channel'                => 'Direct',
					'sessions'               => 43600,
					'avg_pages_per_session'  => 1.9,
					'avg_engagement_seconds' => 60,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_traffic_source_via_bq( $proxy, $start, $end );
		$this->assertSame( 'table', $out['type'] );
		$this->assertRowKeysMatchFixture( 'engagement_by_traffic_source', $out );

		$by_segment = array_column( $out['rows'], 'sessions', 'segment' );
		$this->assertSame( 38200, $by_segment['newsletter'] );
		$this->assertSame( 243600, $by_segment['other'], 'all non-newsletter channels sum into other' );

		// Newsletter sessions (38200) exceed the floor → needs_data is false.
		$this->assertFalse( $out['needs_data'] );

		// "other" weighted avg = (200000*40 + 43600*60) / 243600.
		$avg = array_column( $out['rows'], 'avg_engagement_seconds', 'segment' );
		$this->assertEqualsWithDelta( ( 200000 * 40 + 43600 * 60 ) / 243600, $avg['other'], 0.001 );
	}

	/**
	 * engagement_by_traffic_source: below NEWSLETTER_SESSION_FLOOR newsletter
	 * sessions, needs_data flips true so the card shows its low-data state.
	 */
	public function test_engagement_by_traffic_source_needs_data_below_floor() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning(
			'engagement_by_traffic_source',
			[
				[
					'channel'                => 'Email',
					'sessions'               => 10,
					'avg_pages_per_session'  => 2.0,
					'avg_engagement_seconds' => 90,
				],
				[
					'channel'                => 'Organic Search',
					'sessions'               => 5000,
					'avg_pages_per_session'  => 1.5,
					'avg_engagement_seconds' => 30,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_traffic_source_via_bq( $proxy, $start, $end );
		$this->assertTrue( $out['needs_data'] );
	}

	/**
	 * engagement_by_returning_vs_new: proxy aliases already match the fixture
	 * { reader_type, sessions, avg_pages_per_session, avg_engagement_seconds }.
	 */
	public function test_engagement_by_returning_vs_new_matches_fixture_keys() {
		[ $start, $end ] = $this->window();
		$proxy = $this->makeProxyReturning(
			'engagement_by_returning_vs_new',
			[
				[
					'reader_type'            => 'new',
					'sessions'               => 173000,
					'avg_pages_per_session'  => 1.9,
					'avg_engagement_seconds' => 96,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_returning_vs_new_via_bq( $proxy, $start, $end );
		$this->assertSame( 'table', $out['type'] );
		$this->assertRowKeysMatchFixture( 'engagement_by_returning_vs_new', $out );
		$this->assertSame( 'new', $out['rows'][0]['reader_type'] );
	}

	/*
	===================================================================
	 * hidden_in_v1 metrics
	 * ===================================================================
	 */

	/**
	 * The fixture marks the three newly-enabled BQ metrics, plus
	 * article_freshness_vs_engagement, as hidden_in_v1 (no rows). The shapers for
	 * the three are live (proxy_rows passthroughs) — this test pins the fixture's
	 * intent so a future change that makes them visible must update the fixture too.
	 */
	public function test_fixture_keeps_bq_only_metrics_hidden() {
		$current = $this->fixture_current();
		foreach (
			[
				'top_categories_by_engagement',
				'mobile_vs_desktop_content_preferences',
				'top_authors_by_repeat_reader_rate',
				'article_freshness_vs_engagement',
			] as $metric
		) {
			$this->assertTrue(
				isset( $current[ $metric ]['hidden_in_v1'] ) && $current[ $metric ]['hidden_in_v1'],
				"$metric must be hidden_in_v1 in the fixture"
			);
			$this->assertArrayNotHasKey( 'rows', $current[ $metric ], "$metric must have no rows while hidden" );
		}
	}

	/**
	 * article_freshness_vs_engagement stays a hidden_in_v1 payload (no proxy call).
	 */
	public function test_article_freshness_stays_hidden() {
		[ $start, $end ] = $this->window();
		$payload = Engagement_Metric::get_all( '2026-01-01', '2026-01-31' );
		$this->assertTrue(
			isset( $payload['article_freshness_vs_engagement']['hidden_in_v1'] ) && $payload['article_freshness_vs_engagement']['hidden_in_v1'],
			'article_freshness_vs_engagement must remain hidden_in_v1'
		);
	}

	/*
	===================================================================
	 * Graceful degradation
	 * ===================================================================
	 */

	/**
	 * A WP_Error from the proxy degrades a table shaper to an empty,
	 * not-computable payload rather than fataling.
	 */
	public function test_table_shaper_degrades_on_proxy_error() {
		$proxy = new class() extends BigQuery_Proxy_Client {
			public function query( string $query_name, \DateTimeInterface $start, \DateTimeInterface $end ) {
				return new \WP_Error( 'bq_failure', 'boom' );
			}
		};
		$out = Engagement_Metric::most_read_articles_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertFalse( $out['computable'] );
		$this->assertSame( [], $out['rows'] );
	}
}
