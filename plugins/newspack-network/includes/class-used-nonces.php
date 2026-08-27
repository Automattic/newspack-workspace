<?php
/**
 * Newspack Network record of processed message nonces.
 *
 * @package Newspack
 */

namespace Newspack_Network;

/**
 * Durable record of the delivery nonces the receiving site has processed or is
 * processing.
 *
 * A Node signs each event under a fresh nonce ({@see \Newspack_Network\Crypto::generate_nonce()})
 * and the nonce travels with the delivery, so it identifies one delivery uniquely.
 * Each delivery moves through a two-phase lifecycle: {@see self::claim()} records
 * its nonce as `pending` before processing starts, and {@see self::complete()}
 * marks it `completed` once processing has finished. A claim of a nonce already on
 * record reports which of those states the delivery is in, so the caller can
 * acknowledge a completed delivery without reprocessing it and answer an
 * unfinished one with a retryable error.
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
 * **Completed records are kept indefinitely; there is no purge.** Removing one
 * would let its delivery be processed again, so do not add one. A pending record
 * normally ends as completed, or is removed by {@see self::release()} when
 * processing fails with nothing persisted; one that stays pending — processing
 * failed after the event was persisted, the marking write itself failed, or the
 * request died first — answers every later arrival of that delivery with a
 * retryable error until the Node stops resending it. If the event was persisted before the marking failed,
 * nothing is lost; if it was not, the delivery surfaces as a failed request
 * rather than a silent success. Such a row is resolved by hand: confirm whether
 * its event reached the Event Log, then delete the row so a redelivery can be
 * processed. Do not add time-based cleanup for pending records: they record a
 * delivery whose outcome is unknown, and removing one on a timer would let that
 * delivery be processed twice.
 *
 * The store is intentionally caller-agnostic — it records and recognises nonces and
 * nothing more — so other code can reuse it.
 */
class Used_Nonces {

	/**
	 * Schema version. Bump whenever the table definition changes, and teach
	 * {@see self::table_has_current_shape()} the new shape: certification
	 * requires both this recorded version and that probe.
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
	 * Return value of {@see self::claim()}: the caller now holds the claim, and
	 * should process the delivery and then record the outcome with
	 * {@see self::complete()} or {@see self::release()}.
	 *
	 * @var string
	 */
	const CLAIMED = 'claimed';

	/**
	 * Record status: the delivery is being processed and its outcome is not yet
	 * recorded.
	 *
	 * @var string
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Record status: the delivery was processed to completion. Permanent — this is
	 * what lets a repeat of the delivery be recognised and skipped.
	 *
	 * @var string
	 */
	const STATUS_COMPLETED = 'completed';

	/**
	 * Creates the table on activation. It is also created lazily on first use
	 * ({@see self::get_table_name()}), which is what installs it on an existing
	 * Hub updated in place, where the activation hook does not run.
	 *
	 * @return void
	 */
	public static function install(): void {
		self::maybe_create_table();
	}

	/**
	 * Returns the table name, creating the table if it does not yet exist.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		self::maybe_create_table();
		global $wpdb;
		return $wpdb->prefix . 'newspack_network_used_nonces';
	}

	/**
	 * Claims a nonce for processing, atomically, and reports the delivery's state.
	 *
	 * The nonce is expected to be a 48-character hex string — the length and
	 * alphabet {@see \Newspack_Network\Crypto::generate_nonce()} produces, and the
	 * width of the storage column. On the webhook path libsodium already rejects any
	 * other shape before this is reached; the guard here makes that a precondition of
	 * the store itself, so a future caller cannot silently truncate a longer value
	 * into a colliding key. The nonce is recorded in a single casing, so recognising a
	 * repeat does not depend on the storage column's collation.
	 *
	 * @param string $nonce The nonce to claim.
	 * @return string|null Where the delivery stands:
	 *                     - `claimed`: first time seen; the caller now holds the
	 *                       claim and should process the delivery.
	 *                     - `completed`: the delivery was already processed to
	 *                       completion, and can be acknowledged without reprocessing.
	 *                     - `pending`: an attempt to process the delivery is on
	 *                       record with no outcome yet; retryable.
	 *                     - null: no state could be determined — a malformed nonce,
	 *                       or a write that failed outright — so the caller can ask
	 *                       for a retry rather than guess.
	 */
	public static function claim( $nonce ): ?string {
		$nonce = self::normalize( $nonce );
		if ( null === $nonce ) {
			return null;
		}

		global $wpdb;
		$table = self::get_table_name();

		// A nonce already on record trips the UNIQUE key and makes insert() return
		// false; we expect that, so silence the resulting DB error rather than log
		// it. `status` is written explicitly rather than left to the column
		// default, so a table missing the column fails on the first claim instead
		// of misbehaving only on repeats.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			[
				'nonce'      => $nonce,
				'status'     => self::STATUS_PENDING,
				'created_at' => time(),
			],
			[ '%s', '%s', '%d' ]
		);
		$wpdb->suppress_errors( $suppress );

		if ( false !== $inserted ) {
			return self::CLAIMED;
		}

		// The insert failed. An existing record says where the delivery stands:
		// completed, or pending. No record at all means the write failed for some
		// other reason — report no state, so the caller retries rather than treats
		// the delivery as either fresh or already handled.
		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT status FROM $table WHERE nonce = %s", $nonce ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( self::STATUS_COMPLETED === $status || self::STATUS_PENDING === $status ) {
			return $status;
		}

		return null;
	}

	/**
	 * Marks a claimed nonce's delivery as fully processed.
	 *
	 * A completed record is permanent: it is what lets a later arrival of the same
	 * delivery be recognised and skipped.
	 *
	 * Holding the claim is the caller's precondition: true means the write did
	 * not error, not that a record was matched — completing a nonce that was
	 * never claimed reports true while writing nothing.
	 *
	 * @param string $nonce The nonce to mark completed.
	 * @return bool False if the nonce is malformed or the write failed.
	 */
	public static function complete( $nonce ): bool {
		$nonce = self::normalize( $nonce );
		if ( null === $nonce ) {
			return false;
		}

		global $wpdb;
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_table_name(),
			[ 'status' => self::STATUS_COMPLETED ],
			[ 'nonce' => $nonce ],
			[ '%s' ],
			[ '%s' ]
		);

		return false !== $updated;
	}

	/**
	 * Removes a pending nonce record so a redelivery can be processed again.
	 *
	 * Used to undo a claim when processing failed, so the delivery is not treated
	 * as already-handled on the Node's retry. Only a pending record is removed: a
	 * completed one records a delivery that was fully processed, and removing it
	 * would let that delivery be processed again.
	 *
	 * As with {@see self::complete()}, true means the delete did not error, not
	 * that a pending record was found to remove.
	 *
	 * @param string $nonce The nonce to release.
	 * @return bool False if the nonce is malformed or the delete failed.
	 */
	public static function release( $nonce ): bool {
		$nonce = self::normalize( $nonce );
		if ( null === $nonce ) {
			return false;
		}

		global $wpdb;
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_table_name(),
			[
				'nonce'  => $nonce,
				'status' => self::STATUS_PENDING,
			],
			[ '%s', '%s' ]
		);

		return false !== $deleted;
	}

	/**
	 * Validates a nonce and returns it in the single casing the store records.
	 *
	 * Every public method keys records on the value this returns, so a claim made
	 * under one casing can always be completed or released under another.
	 *
	 * @param string $nonce The nonce as the caller received it.
	 * @return string|null The normalized nonce, or null if it is not a
	 *                     48-character hex string.
	 */
	private static function normalize( $nonce ): ?string {
		if ( ! is_string( $nonce ) || 48 !== strlen( $nonce ) || ! ctype_xdigit( $nonce ) ) {
			return null;
		}
		return strtolower( $nonce );
	}

	/**
	 * Creates the table with dbDelta when it is missing or out of shape, guarded
	 * by the stored schema version plus a shape probe.
	 *
	 * The early return requires both the recorded version and the shape that
	 * version promises, and the version is recorded only once that shape is
	 * confirmed — so an install that silently fails (a host that restricts DDL,
	 * say) and a table lost while its marker survived (a partial restore that
	 * keeps `wp_options`) are both healed on the next use rather than wedged.
	 *
	 * @return void
	 */
	protected static function maybe_create_table(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'newspack_network_used_nonces';

		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) && self::table_has_current_shape( $table_name ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// A generated nonce is a fixed 48-character hex string, and it is not secret,
		// so it is stored under a UNIQUE key (in one casing) — the key is the atomic
		// claim. `status` is where the delivery stands in its lifecycle: pending from
		// claim until it is completed or released. `created_at` is kept for
		// diagnostics only; nothing prunes by it (see the class docblock on
		// retention), so it carries no index.
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nonce varchar(48) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			created_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY nonce (nonce)
		) $charset_collate;";

		dbDelta( $sql );

		if ( self::table_has_current_shape( $table_name ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Whether the table carries the shape this schema version requires: the
	 * `status` column, and the single-column unique key on `nonce` that the
	 * claim's at-most-once guarantee rests on — a table that kept the column
	 * but lost the key, or whose key covers more than the nonce, would report a
	 * repeated delivery as first-seen.
	 *
	 * Both probes address the table directly rather than listing tables, so they
	 * answer for it however it exists — including the session-local tables the
	 * WordPress test suite substitutes, which table listings do not show on
	 * MySQL — and against a missing table they raise only a suppressed error and
	 * report no rows.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @return bool
	 */
	private static function table_has_current_shape( $table_name ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$column   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SHOW COLUMNS FROM $table_name LIKE %s", 'status' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$key_rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SHOW INDEX FROM $table_name WHERE Key_name = %s AND Non_unique = 0", 'nonce' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$wpdb->suppress_errors( $suppress );

		// Exactly one indexed column, and it is the nonce.
		return null !== $column && 1 === count( $key_rows ) && 'nonce' === ( $key_rows[0]->Column_name ?? null );
	}
}
