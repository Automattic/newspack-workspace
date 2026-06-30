<?php
/**
 * Tests for Insights Prewarm.
 *
 * @package Newspack
 */

use Newspack\Insights\Prewarm;
use Newspack\Insights\Cache;
use Newspack\Insights\Preset_Windows;

/**
 * Tests for Prewarm.
 *
 * @group insights
 */
class Test_Insights_Prewarm extends WP_UnitTestCase {

	/**
	 * Enable the Insights feature flag before each test.
	 * Constants cannot be undefined, so we define once if not already set.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NEWSPACK_INSIGHTS_ENABLED' ) ) {
			define( 'NEWSPACK_INSIGHTS_ENABLED', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
	}

	/**
	 * Reset prewarm registry and marker option after each test.
	 */
	public function tearDown(): void {
		delete_option( Prewarm::MARKER_OPTION );
		Prewarm::reset_registry_for_tests();
		parent::tearDown();
	}

	/**
	 * The run_prewarm method warms all five presets and records today's marker.
	 */
	public function test_run_prewarm_writes_durable_for_each_preset_and_sets_marker() {
		$calls = [];
		Prewarm::register_tab(
			'gates',
			function ( $start, $end ) use ( &$calls ) {
				$calls[] = $start->format( 'Y-m-d' ) . '..' . $end->format( 'Y-m-d' );
				$key     = [
					$start->format( 'Y-m-d' ),
					$end->format( 'Y-m-d' ),
					null,
					null,
				];
				Cache::store_durable(
					'gates',
					Cache::SOURCE_BIGQUERY,
					$key,
					[ 'ok' => true ],
					[
						'start' => $start->format( 'Y-m-d' ),
						'end'   => $end->format( 'Y-m-d' ),
					]
				);
				return $key;
			}
		);

		Prewarm::run_prewarm();

		$this->assertCount( 5, $calls, 'All five presets warmed.' );
		$today = current_datetime()->format( 'Y-m-d' );
		$this->assertSame( $today, get_option( Prewarm::MARKER_OPTION ) );
	}

	/**
	 * The maybe_schedule method skips when today's marker is already set.
	 */
	public function test_maybe_schedule_skips_when_marker_is_today() {
		update_option( Prewarm::MARKER_OPTION, current_datetime()->format( 'Y-m-d' ) );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		Prewarm::maybe_schedule();
		$this->assertFalse( as_has_scheduled_action( Prewarm::WARM_ACTION, [], Prewarm::WARM_GROUP ) );
	}

	/**
	 * The maybe_schedule method skips when the current user lacks the required capability.
	 */
	public function test_maybe_schedule_skips_without_capability() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		Prewarm::maybe_schedule();
		$this->assertFalse( as_has_scheduled_action( Prewarm::WARM_ACTION, [], Prewarm::WARM_GROUP ) );
	}

	/**
	 * The maybe_schedule method enqueues an async action for a capable user with no marker.
	 */
	public function test_maybe_schedule_enqueues_for_capable_user_without_marker() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		Prewarm::maybe_schedule();
		$this->assertTrue( as_has_scheduled_action( Prewarm::WARM_ACTION, [], Prewarm::WARM_GROUP ) );
	}

	/**
	 * The on_durable_stale method schedules a WARM_REFRESH_ACTION for a registered tab.
	 */
	public function test_on_durable_stale_schedules_refresh() {
		Prewarm::register_tab( 'gates', function ( $start, $end ) {} );
		Prewarm::on_durable_stale( 'gates', '2026-06-01', '2026-06-30' );
		$args = [
			[
				'tab'   => 'gates',
				'start' => '2026-06-01',
				'end'   => '2026-06-30',
			],
		];
		$this->assertTrue( as_has_scheduled_action( Prewarm::WARM_REFRESH_ACTION, $args, Prewarm::WARM_GROUP ) );
	}

	/**
	 * Maybe_schedule() must not enqueue a warm when the cache is disabled.
	 *
	 * Runs in a separate process so the define() does not leak into the parent
	 * and poison other tests (PHP constants cannot be unset).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_schedule_skips_when_cache_disabled() {
		define( 'NEWSPACK_INSIGHTS_CACHE_DISABLED', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		Prewarm::maybe_schedule();
		$this->assertFalse( as_has_scheduled_action( Prewarm::WARM_ACTION, [], Prewarm::WARM_GROUP ) );
	}

	/**
	 * A run where every warmer throws must NOT stamp the daily marker, so the
	 * next admin_init can retry without waiting until tomorrow.
	 */
	public function test_run_prewarm_skips_marker_when_all_warmers_fail() {
		delete_option( Prewarm::MARKER_OPTION );
		Prewarm::register_tab(
			'gates',
			function ( $start, $end ) {
				throw new \RuntimeException( 'BQ down' );
			}
		);
		Prewarm::run_prewarm();
		$this->assertFalse( get_option( Prewarm::MARKER_OPTION, false ) );
	}

	/**
	 * The run_warm_refresh method calls the registered warmer with the correct DTI range.
	 */
	public function test_run_warm_refresh_calls_registered_warmer() {
		$got = null;
		Prewarm::register_tab(
			'gates',
			function ( $start, $end ) use ( &$got ) {
				$got = $start->format( 'Y-m-d' ) . '..' . $end->format( 'Y-m-d' );
				return [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), null, null ];
			}
		);
		Prewarm::run_warm_refresh(
			[
				'tab'   => 'gates',
				'start' => '2026-06-01',
				'end'   => '2026-06-30',
			]
		);
		$this->assertSame( '2026-06-01..2026-06-30', $got );
	}

	/**
	 * REGRESSION — versioned-key prune correctness (FIX 1 / blocker).
	 *
	 * Uses the real Gates_REST_Controller (which overrides cache_schema_version()
	 * with Gates_Metric::CACHE_PREFIX) to prove that after run_prewarm() the
	 * durable entry SURVIVES the prune call under the versioned key.
	 *
	 * The bug: run_prewarm() previously built its keep-list from unversioned key
	 * parts [ $start, $end, null, null ], but warm_window() stored entries under
	 * versioned parts [ CACHE_PREFIX, $start, $end, null, null ]. The keep hash
	 * never matched the stored hash, so prune_durable() deleted the entry the run
	 * had just warmed, giving zero durable benefit on any tab with a non-empty
	 * cache_schema_version(). This test FAILS against the pre-fix code and PASSES
	 * after the fix.
	 *
	 * @group insights
	 */
	public function test_versioned_tab_durable_entry_survives_prune() {
		// Ensure the Gates controller and metric are loaded (they live in the gates
		// section file which is only included when NEWSPACK_INSIGHTS_GATES_PREVIEW is
		// set; in the unit harness we load them directly).
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		if ( ! class_exists( 'Newspack\Insights\Gates_Metric' ) ) {
			include_once $base . 'metrics/class-gates-metric.php';
		}
		if ( ! class_exists( 'Newspack\Insights\Gates_REST_Controller' ) ) {
			include_once $base . 'api/class-gates-rest-controller.php';
		}

		$controller = new \Newspack\Insights\Gates_REST_Controller();

		Prewarm::register_tab( 'gates', [ $controller, 'warm_window' ] );

		// Run the full prewarm (warms + prunes) against today's preset windows.
		Prewarm::run_prewarm();

		// Find the last-30 preset to check a concrete window.
		$today   = current_datetime();
		$windows = Preset_Windows::all( $today );
		$last30  = null;
		foreach ( $windows as $w ) {
			if ( 'last-30' === $w['preset'] ) {
				$last30 = $w;
				break;
			}
		}
		$this->assertNotNull( $last30, 'last-30 preset must exist.' );

		$start_str = $last30['start']->format( 'Y-m-d' );
		$end_str   = $last30['end']->format( 'Y-m-d' );

		// The durable entry must exist under the VERSIONED key (CACHE_PREFIX + window).
		$versioned_key = array_merge(
			[ \Newspack\Insights\Gates_Metric::CACHE_PREFIX ],
			[ $start_str, $end_str, null, null ]
		);
		$durable = Cache::peek_durable( 'gates', Cache::SOURCE_BIGQUERY, $versioned_key );

		$this->assertNotNull(
			$durable,
			'Durable entry must survive prune under the versioned key. ' .
			'If null, prune deleted the entry because the keep-list used unversioned keys (pre-fix bug).'
		);
	}
}
