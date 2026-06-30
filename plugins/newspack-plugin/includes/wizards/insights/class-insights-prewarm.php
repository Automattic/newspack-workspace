<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Insights prefix matches directory convention.
/**
 * Newspack Insights — daily pre-warm.
 *
 * Warms the five fixed timeframe presets for every registered windowed tab into
 * the durable cache, so opening Insights renders from cache. Scheduling is
 * triggered on admin_init (once/day, guarded) and the actual work runs in an
 * async Action Scheduler job — never inline on a request. Stale durable entries
 * trigger a background refresh (stale-while-revalidate). NEWS-2581 Phase 1.
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

	const WARM_ACTION         = 'newspack_insights_prewarm';
	const WARM_REFRESH_ACTION = 'newspack_insights_warm_refresh';
	const WARM_GROUP          = 'newspack-insights';
	const MARKER_OPTION       = 'newspack_insights_last_prewarm_date';

	/**
	 * Registry: tab slug => warmer callable( DateTimeImmutable, DateTimeImmutable ): void.
	 *
	 * @var array<string, callable>
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
		add_action( self::WARM_REFRESH_ACTION, [ __CLASS__, 'run_warm_refresh' ] );
		add_action( 'newspack_insights_durable_stale', [ __CLASS__, 'on_durable_stale' ], 10, 3 );
	}

	/**
	 * Register a tab's warmer. The warmer builds + durably stores one base
	 * window. Typically [ $controller, 'warm_window' ].
	 *
	 * @param string   $tab    Tab slug.
	 * @param callable $warmer fn( DateTimeImmutable $start, DateTimeImmutable $end ): void.
	 */
	public static function register_tab( string $tab, callable $warmer ): void {
		self::$tabs[ $tab ] = $warmer;
	}

	/**
	 * Test-only: clear the in-memory registry.
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
	}

	/**
	 * WARM_ACTION handler. Warms every registered tab × preset, prunes orphaned
	 * windows, and records the daily marker.
	 *
	 * The daily marker is only stamped when at least one window warmed
	 * successfully across all tabs. A total-failure run (e.g. a BQ-proxy outage
	 * that throws on every window) leaves the marker unchanged so the next
	 * admin_init can re-enqueue a retry, still de-duped by as_has_scheduled_action.
	 * A partial success (some tabs/windows succeeded) does stamp the marker —
	 * real work was done.
	 */
	public static function run_prewarm(): void {
		if ( ! self::is_warmable() ) {
			return;
		}
		$windows     = Preset_Windows::all( current_datetime() );
		$any_success = false;
		foreach ( self::$tabs as $tab => $warmer ) {
			$keep = [];
			foreach ( $windows as $w ) {
				try {
					$warmer( $w['start'], $w['end'] );
				} catch ( \Throwable $e ) {
					self::log_failure( $tab, $w['preset'], $e );
					continue;
				}
				$keep[] = [ $w['start']->format( 'Y-m-d' ), $w['end']->format( 'Y-m-d' ), null, null ];
			}
			// Only prune when at least one window warmed successfully. An empty
			// $keep (total-failure run, e.g. BQ outage) would wipe all durable
			// entries, removing yesterday's still-serveable cache on a transient error.
			if ( ! empty( $keep ) ) {
				Cache::prune_durable( $tab, $keep );
				$any_success = true;
			}
		}
		// Only stamp the marker when real work was done. A total-failure run
		// must leave the marker unchanged so the next admin_init re-enqueues a
		// retry rather than skipping until tomorrow.
		if ( $any_success ) {
			update_option( self::MARKER_OPTION, self::today(), false );
		}
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
		if ( as_has_scheduled_action( self::WARM_REFRESH_ACTION, $args, self::WARM_GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::WARM_REFRESH_ACTION, $args, self::WARM_GROUP );
	}

	/**
	 * WARM_REFRESH_ACTION handler. Re-warms a single (tab, window).
	 *
	 * @param array $args [ 'tab' => string, 'start' => 'Y-m-d', 'end' => 'Y-m-d' ].
	 */
	public static function run_warm_refresh( array $args ): void {
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
		if ( ! $start || ! $end ) {
			return;
		}
		try {
			( self::$tabs[ $tab ] )( $start->setTime( 0, 0, 0 ), $end->setTime( 23, 59, 59 ) );
		} catch ( \Throwable $e ) {
			self::log_failure( $tab, 'swr-refresh', $e );
		}
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
	 * @param string     $window Preset/window label.
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
