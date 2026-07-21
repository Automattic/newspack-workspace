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
}
