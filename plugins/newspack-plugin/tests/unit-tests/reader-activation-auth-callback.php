<?php
/**
 * Tests where the auth form sends a reader after signing in on My Account.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account;
use Newspack\Reader_Activation;

/**
 * A signed-out reader who opens a My Account endpoint sees the sign-in form in
 * its place. Signing in must return them to that endpoint, not the account root.
 */
class Newspack_Test_RA_Auth_Callback extends WP_UnitTestCase {

	/**
	 * Render the auth form on a My Account endpoint and return the "Continue" link target.
	 *
	 * @param string $endpoint       The current My Account endpoint, '' for the account root.
	 * @param string $endpoint_value The endpoint's value, such as a group's subscription ID.
	 * @return string The auth-callback href.
	 */
	private function get_auth_callback_url( $endpoint, $endpoint_value = '' ) {
		$GLOBALS['newspack_test_wc_endpoint'] = $endpoint;
		set_query_var( $endpoint, $endpoint_value );

		ob_start();
		Reader_Activation::render_auth_form( false );
		$auth_form_html = ob_get_clean();

		$this->assertSame( 1, preg_match( '/<a href="([^"]*)" class="auth-callback/', $auth_form_html, $matches ) );
		return html_entity_decode( $matches[1] );
	}

	/**
	 * Without WooCommerce, the native account page returns the reader to the requested endpoint too.
	 */
	public function test_signing_in_on_native_my_account_returns_to_the_requested_endpoint() {
		$this->set_permalink_structure( '/%postname%/' );
		$account_page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $account_page_id );
		$add_group_endpoint = fn( $endpoints ) => $endpoints + [ 'group' => 'Group' ];
		add_filter( 'newspack_my_account_endpoints', $add_group_endpoint );

		$this->go_to( get_permalink( $account_page_id ) );
		global $wp;
		$wp->query_vars['group'] = '63';
		set_query_var( 'group', '63' );

		ob_start();
		Reader_Activation::render_auth_form( false );
		$auth_form_html = ob_get_clean();

		remove_filter( 'newspack_my_account_endpoints', $add_group_endpoint );
		$this->assertSame( 1, preg_match( '/<a href="([^"]*)" class="auth-callback/', $auth_form_html, $matches ) );
		$this->assertSame( get_permalink( $account_page_id ) . 'group/63/', html_entity_decode( $matches[1] ) );
	}

	/**
	 * The callback is the requested endpoint, except the lost-password form.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_signing_in_on_my_account_returns_to_the_requested_endpoint() {
		// Stub the WooCommerce functions the auth form reads. The separate process
		// keeps these global definitions away from tests/mocks/wc-mocks.php.
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stand-in for WooCommerce's query object.
			'function WC() {
				return (object) [
					"query" => new class() {
						public function get_current_endpoint() {
							return $GLOBALS["newspack_test_wc_endpoint"];
						}
					},
				];
			}
			function is_account_page() {
				return true;
			}
			function wc_get_page_permalink( $page ) {
				return "https://example.test/my-account/";
			}
			function wc_get_endpoint_url( $endpoint, $value, $permalink ) {
				return $permalink . $endpoint . "/" . ( $value ? $value . "/" : "" );
			}'
		);

		$this->assertSame( 'https://example.test/my-account/group/63/', $this->get_auth_callback_url( 'group', '63' ) );
		$this->assertSame( 'https://example.test/my-account/', $this->get_auth_callback_url( '' ) );

		// A signed-in reader has no use for the lost-password form.
		$this->assertSame( 'https://example.test/my-account/', $this->get_auth_callback_url( 'lost-password' ) );
	}
}
