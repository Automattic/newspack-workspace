<?php
/**
 * Tests the User_Meta_Columns helper.
 *
 * @package Newspack\Tests
 */

use Newspack\User_Meta_Columns;

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
require_once dirname( __DIR__, 3 ) . '/includes/export/class-user-meta-columns.php';

/**
 * The users export can carry any user meta the site stores. What the site
 * stores is read from the database, and that set is also the boundary on what
 * an export may ask for.
 *
 * @group csv-export
 */
class Newspack_Test_User_Meta_Columns extends WP_UnitTestCase {

	/**
	 * The key list is cached in a transient that outlives the per-test
	 * transaction rollback.
	 */
	public function set_up() {
		parent::set_up();
		User_Meta_Columns::flush_available_keys();
	}

	/**
	 * A key the site has never written cannot be exported, so a mistyped or
	 * probing key selects nothing instead of reaching a meta read.
	 */
	public function test_only_keys_the_site_stores_survive_sanitization() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'reader_zip_code', '07079' );

		$this->assertSame(
			[ 'reader_zip_code' ],
			User_Meta_Columns::sanitize_keys( [ 'reader_zip_code', 'never_written', 'reader_zip_code' ] )
		);
		$this->assertSame( [], User_Meta_Columns::sanitize_keys( 'not-an-array' ) );
	}

	/**
	 * Existing is not enough to be offered. The usermeta table holds whatever
	 * every plugin ever stashed on a user, and the users export is reachable by
	 * a shop manager, so the picker excludes protected keys, WordPress's own
	 * bookkeeping and screen preferences, and anything named like a credential —
	 * while still offering the Memberships registration fields, which are
	 * protected and are the reason the picker exists.
	 */
	public function test_protected_core_and_credential_keys_are_not_offered() {
		global $wpdb;
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'reader_zip_code', '07079' );
		update_user_meta( $user_id, '_wc_memberships_profile_field_birth_year', '1979' );
		update_user_meta( $user_id, '_woocommerce_persistent_cart_1', 'cart' );
		update_user_meta( $user_id, 'session_tokens', [ 'abc' => [ 'expiration' => 0 ] ] );
		update_user_meta( $user_id, 'acme_2fa_totp_secret', 'JBSWY3DPEHPK3PXP' );

		$keys = User_Meta_Columns::get_available_keys();

		$this->assertContains( 'reader_zip_code', $keys );
		$this->assertContains( '_wc_memberships_profile_field_birth_year', $keys );
		$this->assertNotContains( '_woocommerce_persistent_cart_1', $keys );
		$this->assertNotContains( 'session_tokens', $keys );
		$this->assertNotContains( 'acme_2fa_totp_secret', $keys );
		$this->assertNotContains( $wpdb->prefix . 'capabilities', $keys );
		$this->assertNotContains( 'admin_color', $keys );
		// The picker is also the boundary, so an excluded key cannot be asked
		// for by name either.
		$this->assertSame( [], User_Meta_Columns::sanitize_keys( [ 'session_tokens' ] ) );
	}

	/**
	 * A profile field's key is `sanitize_title()` of the label the publisher
	 * typed, so the credential heuristic is reading publisher wording rather
	 * than a plugin's naming: "Secretary" and "Salt Lake City resident" give
	 * keys holding `secret` and `salt`. Those fields are the reason the picker
	 * exists, so the Memberships prefix settles a key before the heuristic
	 * sees it.
	 */
	public function test_memberships_fields_survive_credential_shaped_labels() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_wc_memberships_profile_field_secretary', 'yes' );
		update_user_meta( $user_id, '_wc_memberships_profile_field_salt_lake_city_resident', 'no' );
		update_user_meta( $user_id, 'acme_2fa_totp_secret', 'JBSWY3DPEHPK3PXP' );

		$keys = User_Meta_Columns::get_available_keys();

		$this->assertContains( '_wc_memberships_profile_field_secretary', $keys );
		$this->assertContains( '_wc_memberships_profile_field_salt_lake_city_resident', $keys );
		// The prefix is the allowance, not the heuristic going away.
		$this->assertNotContains( 'acme_2fa_totp_secret', $keys );
	}

	/**
	 * The cap counts keys that can actually be offered. Memberships for Teams
	 * writes two protected keys per team, and every protected key sorts before
	 * the keys a publisher is looking for, so spending the cap on them would
	 * push the profile fields out of the picker — and out of `--meta`, which
	 * is bounded by the same list.
	 */
	public function test_unofferable_keys_do_not_spend_the_cap() {
		$user_id = self::factory()->user->create();
		self::store_meta_keys( $user_id, '_wc_memberships_for_teams_team_%d_role', User_Meta_Columns::MAX_KEYS + 20 );
		update_user_meta( $user_id, '_wc_memberships_profile_field_birth_year', '1979' );
		update_user_meta( $user_id, 'reader_zip_code', '07079' );

		$keys = User_Meta_Columns::get_available_keys();

		$this->assertContains( '_wc_memberships_profile_field_birth_year', $keys );
		$this->assertContains( 'reader_zip_code', $keys );
		$this->assertFalse( User_Meta_Columns::keys_were_capped() );
	}

	/**
	 * A capped list and a complete one look identical on screen, which is what
	 * would turn a missing column into a silent one, so the cap is something
	 * the picker and the CLI can report. The cap bounds the list, though, not
	 * the export: a key sorting past the last one listed is still a key the
	 * site stores, so naming it — from the dialog's typed field or from
	 * `--meta` — exports it.
	 */
	public function test_the_list_stops_at_the_cap_but_the_export_does_not() {
		$user_id = self::factory()->user->create();
		self::store_meta_keys( $user_id, 'reader_field_%03d', User_Meta_Columns::MAX_KEYS + 20 );
		$unlisted = sprintf( 'reader_field_%03d', User_Meta_Columns::MAX_KEYS + 20 );

		$this->assertCount( User_Meta_Columns::MAX_KEYS, User_Meta_Columns::get_available_keys() );
		$this->assertTrue( User_Meta_Columns::keys_were_capped() );
		$this->assertNotContains( $unlisted, User_Meta_Columns::get_available_keys() );
		$this->assertSame(
			[ 'reader_field_001', $unlisted ],
			User_Meta_Columns::sanitize_keys( [ 'reader_field_001', $unlisted ] )
		);
	}

	/**
	 * Naming a key past the end of a capped list is not a way around the
	 * boundary the list draws: the key still has to exist and still has to be
	 * offerable, and the filter is still the site's veto.
	 */
	public function test_naming_a_key_past_the_cap_still_obeys_the_boundary() {
		$user_id = self::factory()->user->create();
		self::store_meta_keys( $user_id, 'reader_field_%03d', User_Meta_Columns::MAX_KEYS + 20 );
		$unlisted = sprintf( 'reader_field_%03d', User_Meta_Columns::MAX_KEYS + 20 );
		update_user_meta( $user_id, 'zz_acme_api_key', 'sk-live' );

		$this->assertSame( [], User_Meta_Columns::sanitize_keys( [ 'zz_never_written' ] ) );
		$this->assertSame( [], User_Meta_Columns::sanitize_keys( [ 'zz_acme_api_key' ] ) );

		$drop_it = function ( $keys ) use ( $unlisted ) {
			return array_values( array_diff( $keys, [ $unlisted ] ) );
		};
		add_filter( 'newspack_users_export_meta_keys', $drop_it );
		$sanitized = User_Meta_Columns::sanitize_keys( [ $unlisted ] );
		remove_filter( 'newspack_users_export_meta_keys', $drop_it );

		$this->assertSame( [], $sanitized );
	}

	/**
	 * Writes $count meta keys in one statement. The cap only shows up above
	 * 500 keys, which is more rows than update_user_meta() should be asked to
	 * write one at a time.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $key_format sprintf format taking the key's index.
	 * @param int    $count      How many keys to write.
	 */
	private static function store_meta_keys( int $user_id, string $key_format, int $count ) {
		global $wpdb;
		$rows = [];
		foreach ( range( 1, $count ) as $index ) {
			$rows[] = $wpdb->prepare( '( %d, %s, %s )', $user_id, sprintf( $key_format, $index ), 'x' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "INSERT INTO {$wpdb->usermeta} ( user_id, meta_key, meta_value ) VALUES " . implode( ',', $rows ) );
	}

	/**
	 * Column ids are namespaced so a meta key named like a core export column
	 * cannot overwrite it, while the CSV header stays the bare key.
	 */
	public function test_columns_are_namespaced_but_headed_by_the_key() {
		$this->assertSame(
			[
				'meta_first_name'      => 'first_name',
				'meta_reader_zip_code' => 'reader_zip_code',
			],
			User_Meta_Columns::get_column_names( [ 'first_name', 'reader_zip_code' ] )
		);
	}

	/**
	 * Two shapes reach the same key in practice — a list written as a
	 * serialized array, and a plain string written later by whatever replaced
	 * the original form. Both have to render as a cell, and every chosen key
	 * needs one whether the user has the meta or not, or the row goes short and
	 * every column after it shifts.
	 */
	public function test_row_values_flatten_both_shapes_and_cover_every_column() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'reader_subjects', [ 'History', 'Civics' ] );
		update_user_meta( $user_id, 'reader_zip_code', '07079' );

		$this->assertSame(
			[
				'meta_reader_subjects' => 'History, Civics',
				'meta_reader_zip_code' => '07079',
				'meta_reader_unfilled' => '',
			],
			User_Meta_Columns::get_row_values( $user_id, [ 'reader_subjects', 'reader_zip_code', 'reader_unfilled' ] )
		);
	}

	/**
	 * A nested structure has no single-cell reading, so it is not silently
	 * flattened into something that looks like a list.
	 */
	public function test_row_values_encode_a_nested_structure() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'reader_prefs', [ 'topics' => [ 'History' ] ] );

		$this->assertSame(
			'{"topics":["History"]}',
			User_Meta_Columns::get_row_values( $user_id, [ 'reader_prefs' ] )['meta_reader_prefs']
		);
	}

	/**
	 * The offered key list is filterable, which is also how a site keeps a key
	 * out of the picker.
	 */
	public function test_available_keys_are_filterable() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'reader_zip_code', '07079' );

		$drop_it = function ( $keys ) {
			return array_values( array_diff( $keys, [ 'reader_zip_code' ] ) );
		};
		add_filter( 'newspack_users_export_meta_keys', $drop_it );
		$keys = User_Meta_Columns::get_available_keys();
		remove_filter( 'newspack_users_export_meta_keys', $drop_it );

		$this->assertNotContains( 'reader_zip_code', $keys );
		$this->assertSame( [], User_Meta_Columns::sanitize_keys( [ 'reader_zip_code' ] ) );
	}
}
