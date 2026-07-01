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
	 * Max attempts for a single per-window warm before giving up. Action Scheduler
	 * does not auto-retry a single (non-recurring) async action, so run_warm_window()
	 * re-enqueues a failed window itself up to this many times.
	 */
	const WARM_MAX_ATTEMPTS = 3;

	/**
	 * Base backoff in seconds between per-window warm retries; multiplied by the
	 * attempt number for a simple linear backoff.
	 */
	const WARM_RETRY_BACKOFF = 15 * MINUTE_IN_SECONDS;

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
	 * Marker semantics: the marker means "fan-out was scheduled today", not
	 * "warming completed successfully." A re-fan-out on the same day is not needed
	 * because each window is its own job: a window that fails is re-enqueued with a
	 * bounded backoff by run_warm_window() (Action Scheduler does NOT auto-retry a
	 * single async action), and once retries are exhausted the action is marked
	 * failed so the outage is visible.
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
		// Stamp the marker immediately: "fan-out scheduled today." A failed window
		// is recovered by run_warm_window()'s bounded self-re-enqueue, not by
		// re-fanning-out the whole set the same day.
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
	 * WARM_WINDOW_ACTION handler. Warms one (tab, window) then prunes orphaned
	 * durable entries for that tab.
	 *
	 * On a warm failure the window is re-enqueued with a bounded backoff (up to
	 * WARM_MAX_ATTEMPTS), since Action Scheduler does not auto-retry a single
	 * async action; once attempts are exhausted the exception is re-thrown so the
	 * action is marked failed and visible in the AS admin. Pruning runs only after
	 * a successful warm, and the just-warmed key is always kept, so a job never
	 * deletes the entry it just wrote (e.g. across a midnight roll, or an SWR
	 * re-warm of a now-shifted preset).
	 *
	 * @param array $args [ 'tab' => string, 'start' => 'Y-m-d', 'end' => 'Y-m-d', 'attempt' => int ].
	 * @throws \Throwable Re-thrown when retry attempts are exhausted.
	 */
	public static function run_warm_window( array $args ): void {
		if ( ! self::is_warmable() ) {
			return;
		}
		$tab = $args['tab'] ?? '';
		if ( ! isset( self::$tabs[ $tab ] ) ) {
			self::log_skip( $tab, $args, 'unregistered tab' );
			return;
		}
		$tz    = wp_timezone();
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) ( $args['start'] ?? '' ), $tz );
		$end   = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) ( $args['end'] ?? '' ), $tz );
		if ( ! $start || $start->format( 'Y-m-d' ) !== (string) ( $args['start'] ?? '' )
			|| ! $end || $end->format( 'Y-m-d' ) !== (string) ( $args['end'] ?? '' ) ) {
			self::log_skip( $tab, $args, 'invalid date argument' );
			return;
		}

		try {
			$warmed_key = ( self::$tabs[ $tab ]['warmer'] )( $start->setTime( 0, 0, 0 ), $end->setTime( 23, 59, 59 ) );
		} catch ( \Throwable $e ) {
			self::log_failure( $tab, $args['start'] . '..' . $args['end'], $e );
			$attempt = (int) ( $args['attempt'] ?? 1 );
			if ( $attempt < self::WARM_MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + ( $attempt * self::WARM_RETRY_BACKOFF ),
					self::WARM_WINDOW_ACTION,
					[
						[
							'tab'     => $tab,
							'start'   => $args['start'],
							'end'     => $args['end'],
							'attempt' => $attempt + 1,
						],
					],
					self::WARM_GROUP
				);
				return;
			}
			// Retries exhausted: re-throw so Action Scheduler records this action as
			// failed (visible/monitorable) rather than silently complete.
			throw $e;
		}

		// Prune orphaned durable entries for this tab. The five current-preset keys
		// PLUS the key just warmed are kept, so a job never prunes what it just
		// wrote even when its window is no longer one of today's presets.
		$keep = array_map(
			function ( $w ) use ( $tab ) {
				return ( self::$tabs[ $tab ]['key_for'] )( $w['start'], $w['end'] );
			},
			Preset_Windows::all( current_datetime() )
		);
		$keep[] = $warmed_key;
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
	 * Log a skipped per-window warm (unregistered tab or invalid date args), so a
	 * fan-out/registry desync is diagnosable from logs rather than vanishing.
	 *
	 * @param string $tab    Tab slug (may be empty/unknown).
	 * @param array  $args   The raw action args.
	 * @param string $reason Why the window was skipped.
	 */
	private static function log_skip( string $tab, array $args, string $reason ): void {
		if ( ! class_exists( '\Newspack\Logger' ) ) {
			return;
		}
		\Newspack\Logger::newspack_log(
			'newspack_insights_prewarm_skipped',
			sprintf( '[%s] per-window warm skipped: %s', $tab, $reason ),
			[
				'tab'    => $tab,
				'args'   => $args,
				'reason' => $reason,
				'header' => Cache::LOGGER_HEADER,
			],
			'warning'
		);
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
