<?php
/**
 * Newspack Newsletters ad stats — dated per-ad impression/click rows.
 *
 * Each ad post already keeps a lifetime cumulative counter in its
 * `tracking_impressions` / `tracking_clicks` meta. That's fine for the ad-list
 * admin columns but can't answer timeframe-scoped questions ("impressions in the
 * last 30 days"). This table records the same events bucketed by UTC day so
 * reporting (e.g. Newspack Insights) can sum over an arbitrary date range.
 *
 * Rows are aggregated per (ad_id, newsletter_id, day) via an upsert rather than
 * one row per event, to keep the table small under high impression volume.
 *
 * @package Newspack
 */

namespace Newspack_Newsletters\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Ad_Stats class.
 */
class Ad_Stats {
	const TABLE_NAME           = 'newspack_newsletters_ad_stats';
	const TABLE_VERSION        = '1.0';
	const TABLE_VERSION_OPTION = '_newspack_newsletters_ad_stats_version';
	const CRON_HOOK            = 'np_newsletters_ad_stats_cleanup';

	/**
	 * Fallback newsletter ID for click rows whose source newsletter is unknown
	 * (e.g. links proxied before newsletter attribution shipped). Clicks with a
	 * known newsletter share the real newsletter's row; impressions always carry
	 * a real newsletter ID.
	 */
	const UNKNOWN_NEWSLETTER_ID = 0;

	/**
	 * How long to retain rows. ~25 months keeps year-over-year comparisons
	 * intact while bounding table growth.
	 */
	const RETENTION_MONTHS = 25;

	/**
	 * Rows deleted per statement in cleanup(), and the max batches per run.
	 * The batch keeps any single DELETE lock small; the loop (bounded by
	 * MAX_BATCHES as a runaway guard) drains a backlog larger than one batch —
	 * a high-send site with a big inventory can exceed BATCH expired rows/week.
	 */
	const CLEANUP_BATCH      = 1000;
	const CLEANUP_MAX_BATCHES = 1000;

	/**
	 * Initialize hooks.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		register_activation_hook( self::plugin_main_file(), [ __CLASS__, 'create_custom_table' ] );
		add_action( 'init', [ __CLASS__, 'check_update_version' ] );
		add_action( 'init', [ __CLASS__, 'cron_init' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'cleanup' ] );
	}

	/**
	 * Absolute path to the plugin's main file, for the (de)activation hooks.
	 *
	 * `NEWSPACK_NEWSLETTERS_PLUGIN_FILE` is `plugin_dir_path()` — the plugin
	 * directory, not a file — so passing it to register_(de)activation_hook()
	 * computes the wrong `plugin_basename()` and the hooks never fire (which is
	 * why the weekly cleanup cron would otherwise be orphaned on deactivation).
	 *
	 * @codeCoverageIgnore
	 */
	private static function plugin_main_file(): string {
		return NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'newspack-newsletters.php';
	}

	/**
	 * Schedule the periodic cleanup.
	 *
	 * @codeCoverageIgnore
	 */
	public static function cron_init() {
		\register_deactivation_hook( self::plugin_main_file(), [ __CLASS__, 'cron_deactivate' ] );
		if ( ! \wp_next_scheduled( self::CRON_HOOK ) ) {
			\wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the cleanup cron.
	 *
	 * @codeCoverageIgnore
	 */
	public static function cron_deactivate() {
		\wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Get the custom table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the table if it is missing or the version has changed.
	 *
	 * See: https://codex.wordpress.org/Creating_Tables_with_Plugins
	 *
	 * @codeCoverageIgnore
	 */
	public static function check_update_version() {
		if ( self::TABLE_VERSION !== \get_option( self::TABLE_VERSION_OPTION, false ) ) {
			self::create_custom_table();
			\update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
		}
	}

	/**
	 * Create the custom DB table.
	 *
	 * @codeCoverageIgnore
	 */
	public static function create_custom_table() {
		global $wpdb;
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Columns: ad_id (newspack_nl_ads_cpt post), newsletter_id (source newsletter;
		// always real for impressions, real for clicks when the proxy carried `nid`,
		// 0 for clicks whose source newsletter is unknown — see UNKNOWN_NEWSLETTER_ID),
		// stat_date (UTC day), and the impressions/clicks day counters.
		// Note: no inline SQL comments below — dbDelta splits on ";", so a semicolon
		// inside a comment would corrupt the statement.
		$sql = "CREATE TABLE $table_name (
			ad_id bigint(20) unsigned NOT NULL,
			newsletter_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stat_date date NOT NULL,
			impressions int(11) unsigned NOT NULL DEFAULT 0,
			clicks int(11) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (ad_id, newsletter_id, stat_date),
			KEY stat_date (stat_date)
		) $charset_collate;";

		// Run dbDelta unconditionally (not guarded on table existence): it creates
		// the table when missing and applies ALTERs when the schema changes, so a
		// TABLE_VERSION bump actually migrates the schema.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record impressions for an ad within a newsletter, bucketed by UTC day.
	 *
	 * @param int $ad_id         Ad post ID.
	 * @param int $newsletter_id Newsletter post ID the impression came from.
	 * @param int $count         Number of impressions to add (default 1).
	 */
	public static function record_impressions( $ad_id, $newsletter_id, $count = 1 ) {
		self::increment( (int) $ad_id, (int) $newsletter_id, 'impressions', (int) $count );
	}

	/**
	 * Record clicks for an ad, bucketed by UTC day.
	 *
	 * When the source newsletter is known, the click lands on the same
	 * `(ad_id, newsletter_id, stat_date)` row as that newsletter's impressions.
	 * When it isn't (e.g. links proxied before newsletter attribution shipped),
	 * it falls back to the UNKNOWN_NEWSLETTER_ID sentinel.
	 *
	 * @param int $ad_id         Ad post ID.
	 * @param int $newsletter_id Source newsletter post ID, or 0 if unknown.
	 * @param int $count         Number of clicks to add (default 1).
	 */
	public static function record_clicks( $ad_id, $newsletter_id = self::UNKNOWN_NEWSLETTER_ID, $count = 1 ) {
		$newsletter_id = $newsletter_id > 0 ? (int) $newsletter_id : self::UNKNOWN_NEWSLETTER_ID;
		self::increment( (int) $ad_id, $newsletter_id, 'clicks', (int) $count );
	}

	/**
	 * Upsert a daily counter for an ad.
	 *
	 * @param int    $ad_id         Ad post ID.
	 * @param int    $newsletter_id Newsletter post ID (0 when not applicable).
	 * @param string $field         Column to increment: 'impressions' or 'clicks'.
	 * @param int    $count         Amount to add.
	 */
	private static function increment( $ad_id, $newsletter_id, $field, $count ) {
		global $wpdb;

		if ( $ad_id <= 0 || $count <= 0 ) {
			return;
		}

		$table = self::get_table_name();
		$date  = \current_time( 'Y-m-d', true ); // UTC day.

		// A fully literal query per field keeps the column name out of any
		// interpolation; only the two known fields are supported.
		if ( 'impressions' === $field ) {
			// Impressions must be attributed to a real newsletter. newsletter_id 0 is
			// reserved as the click sentinel, so drop any impression that lacks one
			// rather than merge it into (and corrupt) a click row.
			if ( $newsletter_id <= 0 ) {
				return;
			}
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'INSERT INTO %i ( ad_id, newsletter_id, stat_date, impressions ) VALUES ( %d, %d, %s, %d )
					ON DUPLICATE KEY UPDATE impressions = impressions + VALUES( impressions )',
					$table,
					$ad_id,
					$newsletter_id,
					$date,
					$count
				)
			);
		} elseif ( 'clicks' === $field ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'INSERT INTO %i ( ad_id, newsletter_id, stat_date, clicks ) VALUES ( %d, %d, %s, %d )
					ON DUPLICATE KEY UPDATE clicks = clicks + VALUES( clicks )',
					$table,
					$ad_id,
					$newsletter_id,
					$date,
					$count
				)
			);
		}
	}

	/**
	 * Delete rows older than the retention window, in bounded batches.
	 *
	 * Loops CLEANUP_BATCH rows at a time until a batch deletes fewer than the
	 * limit (backlog drained) — a single LIMITed DELETE could otherwise fall
	 * permanently behind a high-send site that expires more than one batch of
	 * rows per weekly run. CLEANUP_MAX_BATCHES caps the loop as a runaway guard;
	 * whatever remains is drained on the next run.
	 *
	 * @return int Total rows deleted this run.
	 */
	public static function cleanup(): int {
		global $wpdb;
		$table   = self::get_table_name();
		$deleted = 0;
		for ( $batch = 0; $batch < self::CLEANUP_MAX_BATCHES; $batch++ ) {
			$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					// UTC_DATE() (not CURDATE()) to match how stat_date is written (UTC day),
					// so retention isn't skewed by the DB server timezone.
					'DELETE FROM %i WHERE stat_date < ( UTC_DATE() - INTERVAL %d MONTH ) LIMIT %d',
					$table,
					self::RETENTION_MONTHS,
					self::CLEANUP_BATCH
				)
			);
			// query() returns false on error (false < CLEANUP_BATCH → loop ends).
			$deleted += (int) $rows;
			if ( $rows < self::CLEANUP_BATCH ) {
				break;
			}
		}
		return $deleted;
	}
}
Ad_Stats::init();
