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
	 * Most keys the picker lists. A site with more offerable keys than this
	 * has something writing per-user keys programmatically, and a select that
	 * long is unusable anyway.
	 *
	 * It bounds the list, not the export. A key sorting after the last one
	 * listed is still a key the site stores, so the dialog takes typed keys
	 * and `--meta` takes named ones, both checked against the table.
	 */
	const MAX_KEYS = 500;

	/**
	 * Transient marking a rebuild as recent enough to reuse.
	 */
	const REFRESH_THROTTLE_TRANSIENT = 'newspack_export_user_meta_keys_refreshed';

	/**
	 * How long one rebuild on demand stands in for the next.
	 *
	 * The scan behind the list is the expensive part, and the refresh control
	 * is a button anyone past the export gate can hold down, so a second
	 * refresh inside this window is served the list the first one built. It is
	 * short enough that a publisher who adds a registration field and goes back
	 * to the dialog still gets a scan.
	 */
	const REFRESH_THROTTLE = 10;

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
	 * The offered prefixes are settled first, and settle the key: what sits
	 * behind one is a registration answer a reader typed into a field the
	 * publisher named, so the credential heuristic below has nothing to catch
	 * there — only the publisher's own wording to trip over. A field labelled
	 * "Secretary", "Salt Lake City resident" or "Tokens purchased"
	 * `sanitize_title()`s into a key holding `secret`, `salt` or `token`, and
	 * would vanish from the picker with nothing on screen to say why. The
	 * scope limit is a field a publisher literally labels "Password", which
	 * is offered; `newspack_users_export_meta_keys` can drop it.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_offerable_key( string $key ): bool {
		foreach ( self::OFFERED_PROTECTED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
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
		return ! \is_protected_meta( $key, 'user' );
	}

	/**
	 * The user meta keys this site actually stores, sorted.
	 *
	 * @return string[]
	 */
	public static function get_available_keys(): array {
		return self::get_key_list()['keys'];
	}

	/**
	 * Whether MAX_KEYS cut the offered list short.
	 *
	 * A capped list and a complete one look the same on screen, which is what
	 * would turn a missing column into a silent one, so the dialog says so and
	 * offers a field for the keys it cannot list.
	 *
	 * @return bool
	 */
	public static function keys_were_capped(): bool {
		return self::get_key_list()['capped'];
	}

	/**
	 * The offered keys and whether the cap cut the list short, from the cache
	 * when there is one.
	 *
	 * @return array{keys:string[],capped:bool}
	 */
	private static function get_key_list(): array {
		$cached = self::get_cached_key_list();
		if ( null !== $cached ) {
			return $cached;
		}
		return self::store_key_list( self::build_key_list() );
	}

	/**
	 * The cached key list, or null when there is nothing usable cached.
	 *
	 * @return array{keys:string[],capped:bool}|null
	 */
	private static function get_cached_key_list(): ?array {
		$cached = \get_transient( self::KEYS_TRANSIENT );
		if ( ! is_array( $cached ) || ! isset( $cached['keys'], $cached['capped'] ) ) {
			return null;
		}
		return $cached;
	}

	/**
	 * Cache a freshly built list.
	 *
	 * @param array{keys:string[],capped:bool} $list Built list.
	 * @return array{keys:string[],capped:bool}
	 */
	private static function store_key_list( array $list ): array {
		\set_transient( self::KEYS_TRANSIENT, $list, self::KEYS_TTL );
		return $list;
	}

	/**
	 * Read the offered keys out of the database.
	 *
	 * The cap is applied to the keys that survive filtering rather than to the
	 * rows the query returns, or keys that can never be offered would spend
	 * the budget: `_`-prefixed keys sort first, and a site running Memberships
	 * for Teams writes two of them per team. The query drops those in SQL
	 * instead, which is exact for `is_protected_meta()` — a leading-underscore
	 * test — up to a site filtering `is_protected_meta` to unprotect an
	 * underscored key, which this would then not offer.
	 *
	 * @return array{keys:string[],capped:bool}
	 */
	private static function build_key_list(): array {
		global $wpdb;
		$conditions = [ 'meta_key NOT LIKE %s' ];
		$values     = [ $wpdb->esc_like( '_' ) . '%' ];
		foreach ( self::OFFERED_PROTECTED_PREFIXES as $prefix ) {
			$conditions[] = 'meta_key LIKE %s';
			$values[]     = $wpdb->esc_like( $prefix ) . '%';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $conditions is a literal list carrying only %s placeholders; the values are bound below.
		$sql = "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE " . implode( ' OR ', $conditions ) . ' ORDER BY meta_key ASC';
		// Deliberately uncached here: a DISTINCT scan of usermeta, held in the
		// transient the callers above read and write.
		$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$keys   = is_array( $keys ) ? array_map( 'strval', $keys ) : [];
		$keys   = array_values( array_filter( $keys, [ __CLASS__, 'is_offerable_key' ] ) );
		$capped = count( $keys ) > self::MAX_KEYS;
		if ( $capped ) {
			$keys = array_slice( $keys, 0, self::MAX_KEYS );
		}

		/**
		 * Filters the user meta keys offered as export columns.
		 *
		 * Protected keys, core bookkeeping and credential-named keys are
		 * already gone; a site wanting one of those exported adds it back
		 * here. Dropping a key here keeps it out of an export however it is
		 * asked for, including by name past the end of a capped list.
		 *
		 * Called on two lists, so a callback that drops keys by name works on
		 * both while one keying off position does not: the offered list, or
		 * just the keys a single export asked for by name.
		 *
		 * @param string[] $keys Meta keys: the offered list, or the keys one
		 *                       export named.
		 */
		$keys = \apply_filters( 'newspack_users_export_meta_keys', $keys );

		return [
			'keys'   => $keys,
			'capped' => $capped,
		];
	}

	/**
	 * Rebuild the cached key list now, and return it.
	 *
	 * The list is overwritten in place rather than dropped first, so a reader
	 * arriving mid-rebuild is served the previous list instead of missing the
	 * cache and starting a scan of its own, and repeat calls inside
	 * REFRESH_THROTTLE collapse onto one scan.
	 *
	 * @return array{keys:string[],capped:bool}
	 */
	public static function refresh_available_keys(): array {
		$cached = self::get_cached_key_list();
		if ( null !== $cached && \get_transient( self::REFRESH_THROTTLE_TRANSIENT ) ) {
			return $cached;
		}
		// Claimed before the scan, so refreshes arriving while it runs are
		// held off rather than piling a scan each onto the same table.
		\set_transient( self::REFRESH_THROTTLE_TRANSIENT, 1, self::REFRESH_THROTTLE );
		return self::store_key_list( self::build_key_list() );
	}

	/**
	 * Drop the cached key list.
	 */
	public static function flush_available_keys() {
		\delete_transient( self::KEYS_TRANSIENT );
		\delete_transient( self::REFRESH_THROTTLE_TRANSIENT );
	}

	/**
	 * Keep only the requested keys the site actually stores and may offer.
	 *
	 * The list is both capped and cached, so a key missing from it is not a
	 * key the site does not store: above MAX_KEYS there are real keys sorting
	 * after the last one listed, and within KEYS_TTL there are keys first
	 * written since the list was built. Either way a publisher who names one
	 * has named a key their readers filled in, so any requested key the list
	 * does not carry is checked against the table on its own — an indexed
	 * lookup of the few keys one export asks for, not the DISTINCT scan behind
	 * the list.
	 *
	 * @param mixed $keys Requested meta keys.
	 * @return string[]
	 */
	public static function sanitize_keys( $keys ): array {
		if ( ! is_array( $keys ) ) {
			return [];
		}
		$requested = array_values( array_unique( array_map( 'strval', array_filter( $keys, 'is_scalar' ) ) ) );
		// One column per key, so a request is bounded by the same ceiling the
		// list is.
		$requested = array_slice( $requested, 0, self::MAX_KEYS );
		$offered   = self::get_available_keys();
		$valid     = array_intersect( $requested, $offered );
		$unlisted  = array_diff( $requested, $offered );
		if ( ! empty( $unlisted ) ) {
			$valid = array_merge( $valid, self::filter_stored_keys( $unlisted ) );
		}
		// Back through $requested so the columns come out in the order they
		// were asked for.
		return array_values( array_intersect( $requested, $valid ) );
	}

	/**
	 * Which of these keys the site stores and may offer.
	 *
	 * @param string[] $keys Candidate meta keys.
	 * @return string[]
	 */
	private static function filter_stored_keys( array $keys ): array {
		// Offerability is settled without a query, so a probing or
		// credential-named key never reaches one.
		$keys = array_values( array_filter( $keys, [ __CLASS__, 'is_offerable_key' ] ) );
		if ( empty( $keys ) ) {
			return [];
		}

		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		// Deliberately uncached: an indexed lookup of the handful of keys one
		// export named, run once per export.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders carries only %s, one per key; the keys are bound below.
		$sql    = "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key IN ( {$placeholders} )";
		$stored = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $keys ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$stored = is_array( $stored ) ? array_map( 'strval', $stored ) : [];

		/** This filter is documented in includes/export/class-user-meta-columns.php */
		$filtered = (array) \apply_filters( 'newspack_users_export_meta_keys', $stored );
		// Only a removal can matter here: a key the filter adds is in the
		// offered list already, and never reaches this path.
		return array_values( array_intersect( $stored, $filtered ) );
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
