<?php
/**
 * Tests for Insights Prewarm (per-window fan-out model).
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
	 * Reset prewarm registry, marker option, and durable entries after each test.
	 */
	public function tearDown(): void {
		delete_option( Prewarm::MARKER_OPTION );
		Cache::prune_durable( 'gates', [] );
		Prewarm::reset_registry_for_tests();
		parent::tearDown();
	}

	/**
	 * The versioned key a warmer/key_for closure returns for a window, matching
	 * the shape Cached_Controller_Trait produces (global version prefix).
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	private function versioned_key( $start, $end ): array {
		return [
			Cache::ENVELOPE_SCHEMA_VERSION,
			$start->format( 'Y-m-d' ),
			$end->format( 'Y-m-d' ),
			null,
			null,
		];
	}

	/**
	 * Register a fake 'gates' tab whose warmer stores a durable entry and returns
	 * its key, paired with a matching key_for resolver.
	 *
	 * @param array $warmed Reference collecting warmed window labels.
	 * @return void
	 */
	private function register_fake_gates( array &$warmed ): void {
		$key_for = function ( $start, $end ) {
			return $this->versioned_key( $start, $end );
		};
		$warmer  = function ( $start, $end ) use ( &$warmed, $key_for ) {
			$warmed[] = $start->format( 'Y-m-d' ) . '..' . $end->format( 'Y-m-d' );
			$key      = $key_for( $start, $end );
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
		};
		Prewarm::register_tab( 'gates', $warmer, $key_for );
	}

	/**
	 * The dispatcher (run_prewarm) fans out one per-window action per preset and
	 * warms NOTHING inline — this is the fix for the 300s monolithic-action timeout.
	 */
	public function test_run_prewarm_fans_out_per_window_and_warms_nothing_inline() {
		$warmed = [];
		$this->register_fake_gates( $warmed );

		Prewarm::run_prewarm();

		$this->assertSame( [], $warmed, 'Dispatcher must not invoke warmers inline.' );

		$windows = Preset_Windows::all( current_datetime() );
		$this->assertCount( 5, $windows, 'Five preset windows.' );
		foreach ( $windows as $w ) {
			$args = [
				[
					'tab'   => 'gates',
					'start' => $w['start']->format( 'Y-m-d' ),
					'end'   => $w['end']->format( 'Y-m-d' ),
				],
			];
			$this->assertTrue(
				as_has_scheduled_action( Prewarm::WARM_WINDOW_ACTION, $args, Prewarm::WARM_GROUP ),
				'A per-window warm action is enqueued for each preset.'
			);
		}
	}

	/**
	 * The run_warm_window handler warms exactly the one requested window and prunes
	 * orphaned (non-current-preset) durable entries for the tab.
	 */
	public function test_run_warm_window_warms_one_and_prunes_orphan() {
		$warmed = [];
		$this->register_fake_gates( $warmed );

		// Seed an orphaned (old-date) durable entry that is not a current preset window.
		$orphan_key = [ Cache::ENVELOPE_SCHEMA_VERSION, '2000-01-01', '2000-01-30', null, null ];
		Cache::store_durable(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$orphan_key,
			[ 'old' => true ],
			[
				'start' => '2000-01-01',
				'end'   => '2000-01-30',
			]
		);

		$windows = Preset_Windows::all( current_datetime() );
		$w       = $windows[1]; // last-30.
		Prewarm::run_warm_window(
			[
				'tab'   => 'gates',
				'start' => $w['start']->format( 'Y-m-d' ),
				'end'   => $w['end']->format( 'Y-m-d' ),
			]
		);

		$this->assertSame( 1, count( $warmed ), 'Exactly one window warmed.' );
		$this->assertNotNull(
			Cache::peek_durable( 'gates', Cache::SOURCE_BIGQUERY, $this->versioned_key( $w['start'], $w['end'] ) ),
			'The warmed window is stored durably.'
		);
		$this->assertNull(
			Cache::peek_durable( 'gates', Cache::SOURCE_BIGQUERY, $orphan_key ),
			'The orphaned (non-current-preset) entry is pruned.'
		);
	}

	/**
	 * The run_warm_window handler rejects an Action Scheduler date arg that does
	 * not round-trip (e.g. a rolled-over 2026-02-30) before warming.
	 */
	public function test_run_warm_window_rejects_rolled_over_date() {
		$called  = false;
		$key_for = function ( $start, $end ) {
			return $this->versioned_key( $start, $end );
		};
		Prewarm::register_tab(
			'gates',
			function ( $start, $end ) use ( &$called ) {
				$called = true;
				return [];
			},
			$key_for
		);

		Prewarm::run_warm_window(
			[
				'tab'   => 'gates',
				'start' => '2026-02-30',
				'end'   => '2026-03-01',
			]
		);

		$this->assertFalse( $called, 'A rolled-over date must be rejected before warming.' );
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
	 * The maybe_schedule method enqueues the dispatcher AND stamps the daily marker
	 * for a capable user with no marker set.
	 */
	public function test_maybe_schedule_enqueues_dispatcher_and_sets_marker() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		Prewarm::maybe_schedule();
		$this->assertTrue( as_has_scheduled_action( Prewarm::WARM_ACTION, [], Prewarm::WARM_GROUP ) );
		$this->assertSame( current_datetime()->format( 'Y-m-d' ), get_option( Prewarm::MARKER_OPTION ) );
	}

	/**
	 * The on_durable_stale method schedules a WARM_WINDOW_ACTION (unified with the
	 * fan-out path) for a registered tab.
	 */
	public function test_on_durable_stale_schedules_warm_window() {
		$warmed = [];
		$this->register_fake_gates( $warmed );
		Prewarm::on_durable_stale( 'gates', '2026-06-01', '2026-06-30' );
		$args = [
			[
				'tab'   => 'gates',
				'start' => '2026-06-01',
				'end'   => '2026-06-30',
			],
		];
		$this->assertTrue( as_has_scheduled_action( Prewarm::WARM_WINDOW_ACTION, $args, Prewarm::WARM_GROUP ) );
	}

	/**
	 * The maybe_schedule method must not enqueue a warm when the cache is disabled.
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
	 * Regression — the durable entry for a versioned tab survives the prune.
	 *
	 * Drives the real Gates_REST_Controller (versioned via the global
	 * Cache::ENVELOPE_SCHEMA_VERSION) through run_warm_window() and asserts the
	 * durable entry survives the prune under the versioned key — proving the
	 * warm-write key and the prune keep-list agree for a versioned tab.
	 */
	public function test_versioned_tab_durable_entry_survives_prune() {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		if ( ! class_exists( 'Newspack\Insights\Gates_Metric' ) ) {
			include_once $base . 'metrics/class-gates-metric.php';
		}
		if ( ! class_exists( 'Newspack\Insights\Gates_REST_Controller' ) ) {
			include_once $base . 'api/class-gates-rest-controller.php';
		}

		$controller = new \Newspack\Insights\Gates_REST_Controller();
		Prewarm::register_tab( 'gates', [ $controller, 'warm_window' ], [ $controller, 'durable_key_for' ] );

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

		Prewarm::run_warm_window(
			[
				'tab'   => 'gates',
				'start' => $start_str,
				'end'   => $end_str,
			]
		);

		$versioned_key = [ Cache::ENVELOPE_SCHEMA_VERSION, $start_str, $end_str, null, null ];
		$durable       = Cache::peek_durable( 'gates', Cache::SOURCE_BIGQUERY, $versioned_key );

		$this->assertNotNull(
			$durable,
			'Durable entry must survive prune under the versioned key.'
		);
	}

	/**
	 * A failed warm re-enqueues the same window with an incremented attempt
	 * counter (Action Scheduler does not auto-retry a single async action).
	 */
	public function test_run_warm_window_reenqueues_on_failure() {
		$key_for = function ( $start, $end ) {
			return $this->versioned_key( $start, $end );
		};
		Prewarm::register_tab(
			'gates',
			function ( $start, $end ) {
				throw new \RuntimeException( 'BQ down' );
			},
			$key_for
		);

		Prewarm::run_warm_window(
			[
				'tab'   => 'gates',
				'start' => '2026-06-01',
				'end'   => '2026-06-30',
			]
		);

		$retry_args = [
			[
				'tab'     => 'gates',
				'start'   => '2026-06-01',
				'end'     => '2026-06-30',
				'attempt' => 2,
			],
		];
		$this->assertTrue(
			as_has_scheduled_action( Prewarm::WARM_WINDOW_ACTION, $retry_args, Prewarm::WARM_GROUP ),
			'A bounded retry is scheduled for the failed window.'
		);
	}

	/**
	 * When retry attempts are exhausted, the failed warm re-throws so Action
	 * Scheduler marks the action failed (visible) and schedules no further retry.
	 */
	public function test_run_warm_window_rethrows_when_attempts_exhausted() {
		$key_for = function ( $start, $end ) {
			return $this->versioned_key( $start, $end );
		};
		Prewarm::register_tab(
			'gates',
			function ( $start, $end ) {
				throw new \RuntimeException( 'BQ down' );
			},
			$key_for
		);

		$threw = false;
		try {
			Prewarm::run_warm_window(
				[
					'tab'     => 'gates',
					'start'   => '2026-06-01',
					'end'     => '2026-06-30',
					'attempt' => Prewarm::WARM_MAX_ATTEMPTS,
				]
			);
		} catch ( \RuntimeException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw, 'Exhausted retries re-throw for visibility.' );

		$retry_args = [
			[
				'tab'     => 'gates',
				'start'   => '2026-06-01',
				'end'     => '2026-06-30',
				'attempt' => Prewarm::WARM_MAX_ATTEMPTS + 1,
			],
		];
		$this->assertFalse(
			as_has_scheduled_action( Prewarm::WARM_WINDOW_ACTION, $retry_args, Prewarm::WARM_GROUP ),
			'No further retry is scheduled past the attempt cap.'
		);
	}

	/**
	 * A window the job just warmed is never pruned, even when it is not one of
	 * today's five presets (e.g. across a midnight roll or an SWR re-warm).
	 */
	public function test_run_warm_window_keeps_just_warmed_key_off_preset() {
		$key_for = function ( $start, $end ) {
			return $this->versioned_key( $start, $end );
		};
		$warmer  = function ( $start, $end ) use ( $key_for ) {
			$key = $key_for( $start, $end );
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
		};
		Prewarm::register_tab( 'gates', $warmer, $key_for );

		// An arbitrary window that is NOT one of today's five presets.
		Prewarm::run_warm_window(
			[
				'tab'   => 'gates',
				'start' => '2025-01-01',
				'end'   => '2025-01-15',
			]
		);

		$key = [ Cache::ENVELOPE_SCHEMA_VERSION, '2025-01-01', '2025-01-15', null, null ];
		$this->assertNotNull(
			Cache::peek_durable( 'gates', Cache::SOURCE_BIGQUERY, $key ),
			'The just-warmed off-preset window survives its own prune.'
		);
	}

	/**
	 * The run_warm_window handler logs a warning when the tab is not registered,
	 * so a fan-out/registry desync is diagnosable from logs.
	 */
	public function test_run_warm_window_logs_on_unregistered_tab() {
		$codes = [];
		add_action(
			'newspack_log',
			function ( $code ) use ( &$codes ) {
				$codes[] = $code;
			},
			10,
			1
		);
		Prewarm::run_warm_window(
			[
				'tab'   => 'not-registered',
				'start' => '2026-06-01',
				'end'   => '2026-06-30',
			]
		);
		$this->assertContains( 'newspack_insights_prewarm_skipped', $codes );
	}
}
