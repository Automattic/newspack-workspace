<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Insights prefix matches directory convention.
/**
 * Newspack Insights — daily pre-warm.
 *
 * Warms the five fixed timeframe presets for every registered windowed tab into
 * the durable cache, so opening Insights renders from cache. Scheduling is
 * triggered on admin_init (once/day, guarded) and fans out into one small async
 * Action Scheduler job per (tab, window) — so no single job times out.
 * Stale durable entries trigger a background refresh (stale-while-revalidate).
 * NEWS-2581 Phase 1.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights_Wizard;
use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Insights pre-warm scheduler + worker.
 */
final class Prewarm {

	const WARM_ACTION        = 'newspack_insights_prewarm';
	const WARM_WINDOW_ACTION = 'newspack_insights_warm_window';
	const WARM_GROUP         = 'newspack-insights';
	const MARKER_OPTION      = 'newspack_insights_last_prewarm_date';

	/**
	 * Registry: tab slug => [ 'warmer' => callable, 'key_for' => callable ].
	 *
	 * @var array<string, array{warmer: callable, key_for: callable}>
	 */
	private static $tabs = [];

	/**
	 * Whether hooks have been registered already.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Wire hooks. Idempotent; called from each section's register_hooks (on 'init').
	 */
	public static function init(): void {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( 'admin_init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( self::WARM_ACTION, [ __CLASS__, 'run_prewarm' ] );
		add_action( self::WARM_WINDOW_ACTION, [ __CLASS__, 'run_warm_window' ] );
		add_action( 'newspack_insights_durable_stale', [ __CLASS__, 'on_durable_stale' ], 10, 3 );
	}

	/**
	 * Register a tab's warmer and key resolver.
	 *
	 * @param string   $tab     Tab slug.
	 * @param callable $warmer  fn( DateTimeImmutable $start, DateTimeImmutable $end ): array — warms + stores a window and returns the versioned key parts.
	 * @param callable $key_for fn( DateTimeImmutable $start, DateTimeImmutable $end ): array — returns the versioned key parts WITHOUT warming.
	 */
	public static function register_tab( string $tab, callable $warmer, callable $key_for ): void {
		self::$tabs[ $tab ] = [
			'warmer'  => $warmer,
			'key_for' => $key_for,
		];
	}

	/**
	 * Test-only: clear the in-memory registry and hooks flag.
	 */
	public static function reset_registry_for_tests(): void {
		self::$tabs             = [];
		self::$hooks_registered = false;
	}

	/**
	 * Whether warming should run at all in this environment.
	 *
	 * @return bool
	 */
	private static function is_warmable(): bool {
		if ( ! Insights_Wizard::is_enabled() ) {
			return false;
		}
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return false;
		}
		if ( Cache::is_disabled() ) {
			return false;
		}
		return true;
	}

	/**
	 * Schedule an async warm at most once per day on admin_init. Never computes
	 * inline. Guards on environment, capability, and the daily marker.
	 *
	 * Marker semantics: the marker now means "fan-out was scheduled today", not
	 * "warming completed successfully." Individual window failures are retried
	 * automatically by Action Scheduler on each per-window job; a re-fan-out on
	 * the same day is never needed.
	 */
	public static function maybe_schedule(): void {
		if ( ! self::is_warmable() ) {
			return;
		}
		if ( ! current_user_can( Insights_Wizard::get_required_capability() ) ) {
			return;
		}
		if ( get_option( self::MARKER_OPTION ) === self::today() ) {
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::WARM_ACTION, [], self::WARM_GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::WARM_ACTION, [], self::WARM_GROUP );
		// Stamp the marker immediately: "fan-out scheduled today." Retry of
		// individual failed windows is handled by Action Scheduler per-job
		// retries, not by re-fanning-out the same day.
		update_option( self::MARKER_OPTION, self::today(), false );
	}

	/**
	 * WARM_ACTION handler. Fans out one WARM_WINDOW_ACTION per (tab, window).
	 *
	 * This dispatcher does NO warming, pruning, or BQ calls — it cannot time
	 * out. Each per-window job is retried independently by Action Scheduler if
	 * it fails.
	 */
	public static function run_prewarm(): void {
		if ( ! self::is_warmable() ) {
			return;
		}
		if ( empty( self::$tabs ) ) {
			if ( class_exists( '\Newspack\Logger' ) ) {
				\Newspack\Logger::newspack_log(
					'newspack_insights_prewarm_empty_registry',
					'Pre-warm ran with an empty tab registry — no warmers registered.',
					[ 'header' => Cache::LOGGER_HEADER ],
					'warning'
				);
			}
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$windows = Preset_Windows::all( current_datetime() );
		foreach ( self::$tabs as $tab => $entry ) {
			foreach ( $windows as $w ) {
				$args = [
					[
						'tab'   => $tab,
						'start' => $w['start']->format( 'Y-m-d' ),
						'end'   => $w['end']->format( 'Y-m-d' ),
					],
				];
				if ( as_has_scheduled_action( self::WARM_WINDOW_ACTION, $args, self::WARM_GROUP ) ) {
					continue;
				}
				as_enqueue_async_action( self::WARM_WINDOW_ACTION, $args, self::WARM_GROUP );
			}
		}
	}

	/**
	 * WARM_WINDOW_ACTION handler. Warms one (tab, window) and prunes orphaned
	 * durable entries for that tab.
	 *
	 * Pruning always runs (even when the warm throws) — it only removes
	 * orphaned/shifted-day options; the five current-preset keys are always in
	 * the keep-list, so a not-yet-warmed window is never wrongly deleted.
	 *
	 * @param array $args [ 'tab' => string, 'start' => 'Y-m-d', 'end' => 'Y-m-d' ].
	 */
	public static function run_warm_window( array $args ): void {
		if ( ! self::is_warmable() ) {
			return;
		}
		$tab = $args['tab'] ?? '';
		if ( ! isset( self::$tabs[ $tab ] ) ) {
			return;
		}
		$tz    = wp_timezone();
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) ( $args['start'] ?? '' ), $tz );
		$end   = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) ( $args['end'] ?? '' ), $tz );
		if ( ! $start || $start->format( 'Y-m-d' ) !== (string) ( $args['start'] ?? '' ) ) {
			return;
		}
		if ( ! $end || $end->format( 'Y-m-d' ) !== (string) ( $args['end'] ?? '' ) ) {
			return;
		}
		try {
			( self::$tabs[ $tab ]['warmer'] )( $start->setTime( 0, 0, 0 ), $end->setTime( 23, 59, 59 ) );
		} catch ( \Throwable $e ) {
			self::log_failure( $tab, $args['start'] . '..' . $args['end'], $e );
		}
		// Prune orphaned durable entries for this tab regardless of warm result.
		// The five current-preset keys are always included so no valid entry is removed.
		$keep = array_map(
			function ( $w ) use ( $tab ) {
				return ( self::$tabs[ $tab ]['key_for'] )( $w['start'], $w['end'] );
			},
			Preset_Windows::all( current_datetime() )
		);
		Cache::prune_durable( $tab, $keep );
	}

	/**
	 * Stale durable hit → schedule a one-off background refresh for that window.
	 *
	 * @param string $tab   Tab slug.
	 * @param string $start Window start (Y-m-d).
	 * @param string $end   Window end (Y-m-d).
	 */
	public static function on_durable_stale( string $tab, string $start, string $end ): void {
		if ( ! self::is_warmable() || '' === $start || '' === $end ) {
			return;
		}
		if ( ! isset( self::$tabs[ $tab ] ) ) {
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$args = [
			[
				'tab'   => $tab,
				'start' => $start,
				'end'   => $end,
			],
		];
		if ( as_has_scheduled_action( self::WARM_WINDOW_ACTION, $args, self::WARM_GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::WARM_WINDOW_ACTION, $args, self::WARM_GROUP );
	}

	/**
	 * Today's date in the site timezone (Y-m-d).
	 *
	 * @return string
	 */
	private static function today(): string {
		return current_datetime()->format( 'Y-m-d' );
	}

	/**
	 * Log a warm failure without aborting the run.
	 *
	 * @param string     $tab    Tab slug.
	 * @param string     $window Window label or date range.
	 * @param \Throwable $e      Error.
	 */
	private static function log_failure( string $tab, string $window, \Throwable $e ): void {
		if ( ! class_exists( '\Newspack\Logger' ) ) {
			return;
		}
		\Newspack\Logger::newspack_log(
			'newspack_insights_prewarm_failed',
			sprintf( '[%s/%s] pre-warm failed: %s', $tab, $window, $e->getMessage() ),
			[
				'tab'    => $tab,
				'window' => $window,
				'header' => Cache::LOGGER_HEADER,
			],
			'warning'
		);
	}
}
