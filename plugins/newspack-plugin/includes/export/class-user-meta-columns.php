<?php
/**
 * Arbitrary user meta as CSV export columns.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the users export carry any user meta the site actually stores, chosen
 * per export.
 *
 * The offered keys come from the database rather than from a hand-maintained
 * list, which is both how the picker stays useful as plugins come and go and
 * how an exported key is bounded: a key the site has never written cannot be
 * exported, so a mistyped or probing value selects nothing instead of reaching
 * a meta read.
 *
 * Column ids are namespaced so a meta key named like a core export column
 * (`first_name`, say) cannot overwrite it, while the CSV header stays the bare
 * key — what a publisher matching an export back to their data looks for.
 */
final class User_Meta_Columns {

	/**
	 * Prefix namespacing the export column ids.
	 */
	const COLUMN_PREFIX = 'meta_';

	/**
	 * Transient holding the site's user meta keys.
	 */
	const KEYS_TRANSIENT = 'newspack_export_user_meta_keys';

	/**
	 * How long the key list is cached. The query behind it scans the whole
	 * usermeta table, and the set of keys a site uses changes on the timescale
	 * of a plugin being activated, not of an export being run.
	 */
	const KEYS_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Most keys offered. A site with more than this has something writing
	 * per-user keys programmatically, and a picker that long is unusable
	 * anyway.
	 */
	const MAX_KEYS = 500;

	/**
	 * The user meta keys this site actually stores, sorted.
	 *
	 * @return string[]
	 */
	public static function get_available_keys(): array {
		$cached = \get_transient( self::KEYS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// Deliberately uncached and unprepared-free: a full DISTINCT scan of
		// usermeta, run at most twice a day and stored in the transient below.
		$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} ORDER BY meta_key ASC LIMIT %d", self::MAX_KEYS )
		);
		$keys = is_array( $keys ) ? array_map( 'strval', $keys ) : [];

		/**
		 * Filters the user meta keys offered as export columns.
		 *
		 * @param string[] $keys Meta keys.
		 */
		$keys = \apply_filters( 'newspack_users_export_meta_keys', $keys );

		\set_transient( self::KEYS_TRANSIENT, $keys, self::KEYS_TTL );
		return $keys;
	}

	/**
	 * Drop the cached key list.
	 */
	public static function flush_available_keys() {
		\delete_transient( self::KEYS_TRANSIENT );
	}

	/**
	 * Keep only the requested keys the site actually stores.
	 *
	 * @param mixed $keys Requested meta keys.
	 * @return string[]
	 */
	public static function sanitize_keys( $keys ): array {
		if ( ! is_array( $keys ) ) {
			return [];
		}
		$requested = array_map( 'strval', array_filter( $keys, 'is_scalar' ) );
		return array_values( array_unique( array_intersect( $requested, self::get_available_keys() ) ) );
	}

	/**
	 * Export columns for the chosen keys, as column id => CSV header.
	 *
	 * @param string[] $keys Meta keys.
	 * @return array
	 */
	public static function get_column_names( array $keys ): array {
		$columns = [];
		foreach ( $keys as $key ) {
			$columns[ self::COLUMN_PREFIX . $key ] = $key;
		}
		return $columns;
	}

	/**
	 * One user's meta values, keyed by column id.
	 *
	 * Every chosen key gets a cell whether or not the user has the meta, or
	 * the row would be short and every column after it would shift.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $keys    Meta keys.
	 * @return array
	 */
	public static function get_row_values( int $user_id, array $keys ): array {
		$row = [];
		foreach ( $keys as $key ) {
			$row[ self::COLUMN_PREFIX . $key ] = self::format_value( \get_user_meta( $user_id, $key, true ) );
		}
		return $row;
	}

	/**
	 * Flatten a stored value into a CSV cell.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function format_value( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			// A nested array (a serialized structure rather than a list) has no
			// single-cell reading, so only flat lists are joined.
			$scalars = array_filter( $value, 'is_scalar' );
			return count( $scalars ) === count( $value )
				? implode( ', ', array_map( 'strval', $scalars ) )
				: \wp_json_encode( $value );
		}
		return '';
	}
}
