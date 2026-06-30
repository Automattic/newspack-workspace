<?php
/**
 * Tests for Insights Prewarm.
 *
 * @package Newspack
 */

use Newspack\Insights\Prewarm;
use Newspack\Insights\Cache;

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
				Cache::store_durable(
					'gates',
					Cache::SOURCE_BIGQUERY,
					[
						$start->format( 'Y-m-d' ),
						$end->format( 'Y-m-d' ),
						null,
						null,
					],
					[ 'ok' => true ],
					[
						'start' => $start->format( 'Y-m-d' ),
						'end'   => $end->format( 'Y-m-d' ),
					]
				);
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
}
