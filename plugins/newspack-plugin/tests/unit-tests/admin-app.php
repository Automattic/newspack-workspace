<?php
/**
 * Tests the new admin frame's flag and Settings redirect.
 *
 * @package Newspack\Tests
 */

use Newspack\Admin_App;

/**
 * Test the new admin frame.
 */
class Newspack_Test_Admin_App extends WP_UnitTestCase {

	/**
	 * Restore the request after each test.
	 */
	public function tear_down() {
		unset( $_GET['page'] );
		parent::tear_down();
	}

	/**
	 * Without NEWSPACK_NEW_ADMIN the classic Dashboard and Settings stay in place.
	 */
	public function test_is_off_without_the_flag() {
		$this->assertFalse( defined( 'NEWSPACK_NEW_ADMIN' ) );
		$this->assertFalse( Admin_App::is_enabled() );

		$_GET['page'] = Admin_App::PAGE;
		$this->assertFalse( Admin_App::serves( Admin_App::SETTINGS_PAGE ) );
		$this->assertFalse( Admin_App::serves( Admin_App::PAGE ) );
	}

	/**
	 * Settings links land on the Settings route and keep their other query args.
	 */
	public function test_settings_redirect_keeps_query_args() {
		$url = Admin_App::settings_redirect_url(
			[
				'page'                 => 'newspack-settings',
				'oauth_success'        => '1',
				'nextdoor_oauth_error' => 'access denied',
				'scrollTo'             => 'newspack-settings-recaptcha',
				'p'                    => '/elsewhere',
			]
		);
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( Admin_App::PAGE, $query['page'] );
		$this->assertSame( '/settings', $query['p'] );
		$this->assertSame( '1', $query['oauth_success'] );
		$this->assertSame( 'access denied', $query['nextdoor_oauth_error'] );
		$this->assertSame( 'newspack-settings-recaptcha', $query['scrollTo'] );
	}
}
