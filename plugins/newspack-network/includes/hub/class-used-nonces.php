<?php
/**
 * Newspack Hub record of already-processed message nonces.
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub;

/**
 * Durable, single-use record of the nonces the Hub has already processed.
 *
 * A Node signs each event under a fresh nonce ({@see \Newspack_Network\Crypto::generate_nonce()})
 * and the nonce travels with the delivery, so it identifies one delivery uniquely.
 * Recording a nonce the first time it is processed lets the Hub recognise, and skip,
 * any later delivery carrying the same nonce.
 *
 * Two properties matter and both are deliberate:
 *
 * - **Atomic.** The claim is a single INSERT against a UNIQUE column, so two
 *   deliveries of the same nonce arriving at once cannot both be treated as
 *   first-seen. A check-then-insert would leave that gap.
 * - **Durable.** The record lives in the database, not the object cache, so an
 *   eviction under memory pressure cannot drop a nonce and let its delivery be
 *   processed a second time.
 *
 * **Records are kept indefinitely; there is no purge.** Removing a nonce record
 * would let its delivery be processed again, so do not add one.
 *
 * The store is intentionally caller-agnostic — it records and recognises nonces and
 * nothing more — so other Hub code can reuse it.
 */
class Used_Nonces {

	/**
	 * Schema version. Bump when the table definition changes.
	 *
	 * @var string
	 */
	const DB_VERSION = '1';

	/**
	 * Option that stores the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'newspack_network_used_nonces_db_version';

	/**
	 * Creates the table on activation. It is also created lazily on first use
	 * ({@see self::get_table_name()}), which is what installs it on an existing
	 * Hub updated in place, where the activation hook does not run.
	 *
	 * @return void
	 */
	public static function install() {
		self::maybe_create_table();
	}

	/**
	 * Returns the table name, creating the table if it does not yet exist.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		self::maybe_create_table();
		global $wpdb;
		return $wpdb->prefix . 'newspack_network_used_nonces';
	}

	/**
	 * Records the first use of a nonce, atomically.
	 *
	 * The nonce is expected to be a 48-character hex string — the length and
	 * alphabet {@see \Newspack_Network\Crypto::generate_nonce()} produces, and the
	 * width of the storage column. On the webhook path libsodium already rejects any
	 * other shape before this is reached; the guard here makes that a precondition of
	 * the store itself, so a future caller cannot silently truncate a longer value
	 * into a colliding key. The nonce is recorded in a single casing, so recognising a
	 * repeat does not depend on the storage column's collation.
	 *
	 * @param string $nonce The nonce to record.
	 * @return bool|null True if this is the first time the nonce is seen; false if it
	 *                   was already recorded (a repeat delivery); null if it could not
	 *                   be recorded — a malformed nonce, or a database write error, so
	 *                   the caller can ask for a retry rather than guess.
	 */
	public static function claim( $nonce ): ?bool {
		if ( ! is_string( $nonce ) || 48 !== strlen( $nonce ) || ! ctype_xdigit( $nonce ) ) {
			return null;
		}
		$nonce = strtolower( $nonce );

		global $wpdb;
		$table = self::get_table_name();

		// A duplicate nonce trips the UNIQUE key and makes insert() return false; we
		// expect that, so silence the resulting DB error rather than log it.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			[
				'nonce'      => $nonce,
				'created_at' => time(),
			],
			[ '%s', '%d' ]
		);
		$wpdb->suppress_errors( $suppress );

		if ( false !== $inserted ) {
			return true;
		}

		// The insert failed. If the nonce is now on record, another delivery claimed
		// it first — a repeat. If it is absent, the write failed for another reason.
		$exists = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE nonce = %s", $nonce ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $exists > 0 ? false : null;
	}

	/**
	 * Removes a nonce's record so a redelivery can be processed again.
	 *
	 * Used to undo a claim when processing failed, so the delivery is not treated as
	 * already-handled on the Node's retry.
	 *
	 * @param string $nonce The nonce to release.
	 * @return void
	 */
	public static function release( $nonce ) {
		global $wpdb;
		$table = self::get_table_name();
		$wpdb->delete( $table, [ 'nonce' => strtolower( (string) $nonce ) ], [ '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Creates the table with dbDelta, guarded by a stored schema version so the
	 * check is cheap on the common path.
	 *
	 * The version option is written only once the table is confirmed to exist, so a
	 * dbDelta that silently fails (a host that restricts DDL, say) is retried on the
	 * next call rather than being marked done. The fast path trusts the option and
	 * does not re-verify existence, so a table dropped *after* the option was set
	 * (a partial DB restore that keeps `wp_options`) is a known limitation — the same
	 * one the sibling event-log table carries.
	 *
	 * @return void
	 */
	protected static function maybe_create_table() {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		$table_name      = $wpdb->prefix . 'newspack_network_used_nonces';
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// A generated nonce is a fixed 48-character hex string, and it is not secret,
		// so it is stored under a UNIQUE key (in one casing) — the key is the atomic
		// claim. `created_at` is kept for diagnostics only; nothing prunes by it (see
		// the class docblock on indefinite retention), so it carries no index.
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nonce varchar(48) NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY nonce (nonce)
		) $charset_collate;";

		dbDelta( $sql );

		if ( self::table_exists( $table_name ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
		);
		return $found === $table_name;
	}
}
