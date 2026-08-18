<?php
/**
 * Class Test Admin Shell Preferences
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Admin_Shell_Preferences;

/**
 * Tests for the per-user admin-shell view preferences REST surface.
 */
class Admin_Shell_Preferences_Test extends WP_UnitTestCase {
	/**
	 * Editor user — has `edit_posts`, matching the list screens' gate.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up REST server and an authenticated editor.
	 */
	public function set_up() {
		parent::set_up();

		$this->editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $this->editor_id );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Reset REST server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Build a preferences update request.
	 *
	 * @param string $screen Screen key.
	 * @param mixed  $prefs  Prefs payload.
	 * @return WP_REST_Request
	 */
	private function make_request( $screen, $prefs ) {
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/admin-shell/preferences' );
		$request->set_body_params(
			[
				'screen' => $screen,
				'prefs'  => $prefs,
			]
		);
		return $request;
	}

	/**
	 * A valid per-page save round-trips into user meta and the
	 * bootstrap getter.
	 */
	public function test_saves_per_page_for_allowlisted_screen() {
		$response = rest_do_request( $this->make_request( 'newsletters-list', [ 'perPage' => 50 ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[ 'newsletters-list' => [ 'perPage' => 50 ] ],
			Admin_Shell_Preferences::get_preferences()
		);
	}

	/**
	 * The All sentinel (-1) is storable.
	 */
	public function test_saves_all_sentinel() {
		$response = rest_do_request( $this->make_request( 'ads-list', [ 'perPage' => Admin_Shell_Preferences::PER_PAGE_ALL ] ) );

		$this->assertSame( 200, $response->get_status() );
		$prefs = Admin_Shell_Preferences::get_preferences();
		$this->assertSame( Admin_Shell_Preferences::PER_PAGE_ALL, $prefs['ads-list']['perPage'] );
	}

	/**
	 * Saves are per-screen — a second screen's save doesn't clobber the
	 * first.
	 */
	public function test_saves_are_scoped_per_screen() {
		rest_do_request( $this->make_request( 'newsletters-list', [ 'perPage' => 50 ] ) );
		rest_do_request( $this->make_request( 'layouts-list', [ 'perPage' => 96 ] ) );

		$prefs = Admin_Shell_Preferences::get_preferences();
		$this->assertSame( 50, $prefs['newsletters-list']['perPage'] );
		$this->assertSame( 96, $prefs['layouts-list']['perPage'] );
	}

	/**
	 * Screens outside the allowlist are rejected by the enum arg.
	 */
	public function test_rejects_unknown_screen() {
		$response = rest_do_request( $this->make_request( 'evil-screen', [ 'perPage' => 50 ] ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Out-of-range and non-numeric per-page values are rejected.
	 */
	public function test_rejects_invalid_per_page_values() {
		foreach ( [ 0, -2, 101, 'lots' ] as $invalid ) {
			$response = rest_do_request( $this->make_request( 'newsletters-list', [ 'perPage' => $invalid ] ) );
			$this->assertSame( 400, $response->get_status(), 'Expected rejection for: ' . var_export( $invalid, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
		$this->assertSame( [], Admin_Shell_Preferences::get_preferences() );
	}

	/**
	 * Users without `edit_posts` cannot write preferences.
	 */
	public function test_requires_edit_posts() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = rest_do_request( $this->make_request( 'newsletters-list', [ 'perPage' => 50 ] ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Corrupted stored meta is filtered out on read rather than
	 * shipped to the client.
	 */
	public function test_get_preferences_sanitizes_stored_meta() {
		update_user_meta( $this->editor_id, Admin_Shell_Preferences::get_user_meta_key( 'newsletters-list' ), [ 'perPage' => 25 ] );
		update_user_meta( $this->editor_id, Admin_Shell_Preferences::get_user_meta_key( 'ads-list' ), [ 'perPage' => 9999 ] );
		update_user_meta( $this->editor_id, Admin_Shell_Preferences::get_user_meta_key( 'layouts-list' ), 'not-an-array' );

		$this->assertSame(
			[ 'newsletters-list' => [ 'perPage' => 25 ] ],
			Admin_Shell_Preferences::get_preferences()
		);
	}

	/**
	 * Each screen is stored under its own user-meta key, so a save for
	 * one screen never reads or rewrites another's — the fix for the
	 * shared-array race where concurrent saves from different screens
	 * could clobber one another.
	 */
	public function test_save_does_not_touch_other_screens_meta_key() {
		update_user_meta( $this->editor_id, Admin_Shell_Preferences::get_user_meta_key( 'ads-list' ), [ 'perPage' => 20 ] );

		rest_do_request( $this->make_request( 'newsletters-list', [ 'perPage' => 50 ] ) );

		$this->assertSame(
			[ 'perPage' => 20 ],
			get_user_meta( $this->editor_id, Admin_Shell_Preferences::get_user_meta_key( 'ads-list' ), true )
		);
		$prefs = Admin_Shell_Preferences::get_preferences();
		$this->assertSame( 50, $prefs['newsletters-list']['perPage'] );
		$this->assertSame( 20, $prefs['ads-list']['perPage'] );
	}

	/**
	 * The whole presentation half of a view round-trips: layout type,
	 * sort, visible fields in their display order, density and column
	 * widths.
	 */
	public function test_saves_the_full_appearance_state() {
		$prefs = [
			'perPage'    => 50,
			'type'       => 'table',
			'titleField' => 'title',
			'fields'     => [ 'author', 'status', 'date' ],
			'sort'       => [
				'field'     => 'author',
				'direction' => 'asc',
			],
			'layout'     => [
				'density' => 'compact',
				'styles'  => [ 'status' => [ 'width' => '120px' ] ],
			],
		];

		$response = rest_do_request( $this->make_request( 'newsletters-list', $prefs ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $prefs, Admin_Shell_Preferences::get_preferences()['newsletters-list'] );
	}

	/**
	 * The grid layout's preview-size slider is an appearance setting too.
	 */
	public function test_saves_the_grid_preview_size() {
		// A pixel width, not a slider position.
		$response = rest_do_request( $this->make_request( 'layouts-list', [ 'layout' => [ 'previewSize' => 430 ] ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 430, Admin_Shell_Preferences::get_preferences()['layouts-list']['layout']['previewSize'] );

		foreach ( [ -1, 99999 ] as $invalid ) {
			$rejected = rest_do_request( $this->make_request( 'layouts-list', [ 'layout' => [ 'previewSize' => $invalid ] ] ) );
			$this->assertSame( 400, $rejected->get_status() );
		}
	}

	/**
	 * Column order is the order of the `fields` array, so it has to
	 * survive the round trip intact.
	 */
	public function test_field_order_is_preserved() {
		rest_do_request( $this->make_request( 'newsletters-list', [ 'fields' => [ 'date', 'status', 'author' ] ] ) );

		$this->assertSame(
			[ 'date', 'status', 'author' ],
			Admin_Shell_Preferences::get_preferences()['newsletters-list']['fields']
		);
	}

	/**
	 * Hiding every column is a real choice, not an empty payload.
	 */
	public function test_saves_an_empty_field_list() {
		$response = rest_do_request( $this->make_request( 'newsletters-list', [ 'fields' => [] ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], Admin_Shell_Preferences::get_preferences()['newsletters-list']['fields'] );
	}

	/**
	 * Query state is not a preference — the schema rejects it outright
	 * rather than storing a stale search or filter set.
	 */
	public function test_rejects_query_state() {
		foreach ( [ [ 'search' => 'digest' ], [ 'page' => 3 ], [ 'filters' => [] ] ] as $prefs ) {
			$response = rest_do_request( $this->make_request( 'newsletters-list', $prefs ) );
			$this->assertSame( 400, $response->get_status() );
		}
	}

	/**
	 * Values outside the enums are rejected rather than stored for the
	 * client to trip over.
	 */
	public function test_rejects_out_of_enum_appearance_values() {
		$invalid = [
			[ 'type' => 'carousel' ],
			[ 'layout' => [ 'density' => 'roomy' ] ],
			[ 'sort' => [ 'direction' => 'sideways' ] ],
		];
		foreach ( $invalid as $prefs ) {
			$response = rest_do_request( $this->make_request( 'newsletters-list', $prefs ) );
			$this->assertSame( 400, $response->get_status(), 'Expected rejection for: ' . wp_json_encode( $prefs ) );
		}
		$this->assertSame( [], Admin_Shell_Preferences::get_preferences() );
	}

	/**
	 * A payload with nothing storable in it is a client bug, not an
	 * instruction to blank the screen's preferences.
	 */
	public function test_rejects_an_empty_payload() {
		$response = rest_do_request( $this->make_request( 'newsletters-list', [] ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Field IDs and the column-style map are keyed by whatever the
	 * screen defines, so they are bounded rather than trusted.
	 */
	public function test_caps_the_free_form_halves_of_the_payload() {
		$long_id = str_repeat( 'a', 200 );
		$fields  = array_map(
			static function ( $i ) {
				return 'field_' . $i;
			},
			range( 1, 80 )
		);

		$sanitized = Admin_Shell_Preferences::sanitize_prefs(
			[
				'fields' => array_merge( [ $long_id, '', '  ' ], $fields ),
				'layout' => [ 'styles' => [ $long_id => [ 'width' => str_repeat( 'x', 100 ) ] ] ],
			]
		);

		$this->assertCount( 50, $sanitized['fields'] );
		$this->assertNotContains( $long_id, $sanitized['fields'] );
		$this->assertArrayNotHasKey( 'layout', $sanitized );
	}

	/**
	 * Duplicate field IDs would render a column twice.
	 */
	public function test_deduplicates_field_ids() {
		$sanitized = Admin_Shell_Preferences::sanitize_prefs( [ 'fields' => [ 'status', 'author', 'status' ] ] );

		$this->assertSame( [ 'status', 'author' ], $sanitized['fields'] );
	}

	/**
	 * Preferences written by an older version stay readable: the extra
	 * keys simply arrive absent.
	 */
	public function test_reads_back_a_per_page_only_value_saved_by_an_earlier_version() {
		update_user_meta( $this->editor_id, Admin_Shell_Preferences::get_user_meta_key( 'newsletters-list' ), [ 'perPage' => 50 ] );

		$this->assertSame(
			[ 'newsletters-list' => [ 'perPage' => 50 ] ],
			Admin_Shell_Preferences::get_preferences()
		);
	}

	/**
	 * Hand-edited or half-corrupt meta is filtered key by key rather
	 * than discarded wholesale.
	 */
	public function test_get_preferences_drops_only_the_corrupt_keys() {
		update_user_meta(
			$this->editor_id,
			Admin_Shell_Preferences::get_user_meta_key( 'newsletters-list' ),
			[
				'perPage' => 50,
				'type'    => 'carousel',
				'fields'  => [ 'status', 42, null ],
				'layout'  => [ 'density' => 'roomy' ],
			]
		);

		$this->assertSame(
			[
				'perPage' => 50,
				'fields'  => [ 'status' ],
			],
			Admin_Shell_Preferences::get_preferences()['newsletters-list']
		);
	}

	/**
	 * DataViews owns the shape of a column style, so an unfamiliar key or
	 * alignment is sanitised away rather than failing the request. Schema
	 * validation rejects the whole payload on the first unknown key, which
	 * would take every other appearance setting down with it and leave no
	 * trace anywhere.
	 */
	public function test_an_unfamiliar_column_style_does_not_reject_the_payload() {
		$response = rest_do_request(
			$this->make_request(
				'newsletters-list',
				[
					'layout' => [
						'density' => 'compact',
						'styles'  => [
							'status' => [
								'width'     => '120px',
								'align'     => 'middle',
								'resizable' => true,
							],
						],
					],
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'density' => 'compact',
				'styles'  => [ 'status' => [ 'width' => '120px' ] ],
			],
			Admin_Shell_Preferences::get_preferences()['newsletters-list']['layout']
		);
	}

	/**
	 * `usermeta` is network-global. Single-site installs keep the
	 * unprefixed key so preferences saved before this survive.
	 */
	public function test_the_meta_key_is_scoped_per_site_only_on_multisite() {
		global $wpdb;

		$key = Admin_Shell_Preferences::get_user_meta_key( 'newsletters-list' );

		if ( is_multisite() ) {
			$this->assertStringStartsWith( $wpdb->get_blog_prefix() . Admin_Shell_Preferences::USER_META_KEY_PREFIX, $key );
			return;
		}

		$this->assertSame( Admin_Shell_Preferences::USER_META_KEY_PREFIX . 'newsletters-list', $key );
	}
}
