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
 * Existing is not on its own enough to be offered, though. Everything a plugin
 * ever stashed on a user is in that table, credentials included, and the users
 * export is reachable by a shop manager rather than only an administrator. So
 * protected keys, WordPress's own bookkeeping, and anything named like a
 * credential are dropped before the filter below sees the list, leaving a site
 * free to add one back deliberately.
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
	 * Protected key prefixes offered anyway.
	 *
	 * WooCommerce Memberships writes its registration fields to a protected
	 * key, and those fields are what a publisher leaving Memberships comes to
	 * this export for.
	 */
	const OFFERED_PROTECTED_PREFIXES = [ '_wc_memberships_profile_field_' ];

	/**
	 * Substrings that mark a key as credential-adjacent, matched case
	 * insensitively anywhere in the key. The plugin that wrote the key chose
	 * its name, so there is no prefix to key off — a 2FA secret or a
	 * third-party API token can sit in an unprotected key.
	 */
	const SENSITIVE_KEY_SUBSTRINGS = [ 'password', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'nonce', 'salt', 'totp', '2fa' ];

	/**
	 * WordPress's own per-user bookkeeping: role storage, and the admin's
	 * screen preferences. No publisher matches an export against these, and
	 * there are enough of them to bury the keys a publisher is looking for.
	 *
	 * The role patterns allow for the table prefix core puts in front of them
	 * (`wp_capabilities`, and `wp_2_capabilities` on a multisite).
	 */
	const CORE_INTERNAL_KEY_PATTERNS = [
		'/(^|_)(capabilities|user_level)$/',
		'/(^|_)user-settings(-time)?$/',
		'/^(closedpostboxes|metaboxhidden|meta-box-order|screen_layout|manage[a-z-]*columnshidden)_/',
		'/^(admin_color|comment_shortcuts|rich_editing|syntax_highlighting|show_admin_bar_front|show_welcome_panel|use_ssl|dismissed_wp_pointers|community-events-location|wp_dashboard_quick_press_last_post_id)$/',
	];

	/**
	 * Whether a key may be offered as an export column.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_offerable_key( string $key ): bool {
		foreach ( self::CORE_INTERNAL_KEY_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $key ) ) {
				return false;
			}
		}
		foreach ( self::SENSITIVE_KEY_SUBSTRINGS as $substring ) {
			if ( false !== stripos( $key, $substring ) ) {
				return false;
			}
		}
		if ( ! \is_protected_meta( $key, 'user' ) ) {
			return true;
		}
		foreach ( self::OFFERED_PROTECTED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

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
		$keys = array_values( array_filter( $keys, [ __CLASS__, 'is_offerable_key' ] ) );

		/**
		 * Filters the user meta keys offered as export columns.
		 *
		 * Protected keys, core bookkeeping and credential-named keys are
		 * already gone; a site wanting one of those exported adds it back here.
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
