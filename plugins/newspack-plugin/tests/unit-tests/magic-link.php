<?php
/**
 * Tests the Magic Link functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Emails;
use Newspack\Magic_Link;
use Newspack\Reader_Activation;

/**
 * Tests the Magic Link functionality.
 */
class Newspack_Test_Magic_Link extends WP_UnitTestCase {
	/**
	 * Reader user id.
	 *
	 * @var int
	 */
	private static $user_id = null;

	/**
	 * Secondary reader user id.
	 *
	 * @var int
	 */
	private static $secondary_user_id = null;

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private static $admin_id = null;

	/**
	 * Setup for the tests.
	 */
	public function set_up() {
		// Enable reader activation.
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );

		// Create sample reader.
		if ( empty( self::$user_id ) ) {
			self::$user_id = Reader_Activation::register_reader( 'reader@test.com', 'Test Reader' );
			// Ensure we're logged out before continuing.
			wp_logout();
		}

		// Create a secondary sample reader.
		if ( empty( self::$secondary_user_id ) ) {
			self::$secondary_user_id = wp_insert_user(
				[
					'user_login' => 'secondary-user',
					'user_pass'  => wp_generate_password(),
					'user_email' => 'secondary@test.com',
				]
			);
		}
		// Remove tokens.
		delete_user_meta( self::$user_id, Magic_Link::TOKENS_META );

		// Create sample admin.
		if ( empty( self::$admin_id ) ) {
			self::$admin_id = wp_insert_user(
				[
					'user_login' => 'sample-admin',
					'user_pass'  => wp_generate_password(),
					'user_email' => 'admin@test.com',
					'role'       => 'administrator',
				]
			);
		}
		// Remove tokens.
		delete_user_meta( self::$admin_id, Magic_Link::TOKENS_META );
	}

	/**
	 * Assert valid token.
	 *
	 * @param array $token_data Token data. {
	 *   The token data.
	 *
	 *   @type string $token  The token.
	 *   @type string $client Client hash.
	 *   @type string $time   Token creation time.
	 *   @type array  $otp    The OTP.
	 * }
	 */
	public function assertTokenIsValid( $token_data ) {
		$this->assertFalse( is_wp_error( $token_data ) );
		$this->assertIsString( $token_data['token'] );
		$this->assertIsString( $token_data['client'] );
		$this->assertIsInt( $token_data['time'] );
		$this->assertIsArray( $token_data['otp'] );
	}

	/**
	 * Test simple token generation.
	 */
	public function test_generate_token() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertTokenIsValid( $token_data );
	}

	/**
	 * Test simple secret generation.
	 */
	public function test_generate_secret() {
		$secret = Magic_Link::generate_secret( get_user_by( 'id', self::$user_id ) );
		$this->assertEquals( $secret, wp_hash( 'reader@test.com' ) );
	}

	/**
	 * Test rate limiting of token generation.
	 */
	public function test_rate_limit() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$new_token  = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertTrue( is_wp_error( $new_token ) );
		$this->assertEquals( 'rate_limit_exceeded', $new_token->get_error_code() );
	}

	/**
	 * Test simple token validation.
	 */
	public function test_validate_token() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertTokenIsValid( Magic_Link::validate_token( self::$user_id, $token_data['client'], $token_data['token'] ) );
	}

	/**
	 * Test whether up to five tokens can be generated and validated.
	 */
	public function test_multiple_tokens() {
		/**
		 * Filter the rate interval to 0 seconds.
		 *
		 * @param int $rate_interval The rate interval in seconds.
		 */
		function modify_magic_link_rate_interval( $rate_interval ) {
			return 0;
		}
		add_filter( 'newspack_magic_link_rate_interval', 'modify_magic_link_rate_interval', 10 );
		$tokens = [];
		for ( $i = 0; $i <= 5; $i++ ) {
			$tokens[] = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		}
		remove_filter( 'newspack_magic_link_rate_interval', 'modify_magic_link_rate_interval', 10 );
		foreach ( $tokens as $index => $token ) {
			if ( $index < 1 ) {
				$this->assertTrue( is_wp_error( Magic_Link::validate_token( self::$user_id, $token['client'], $token['token'] ) ) );
			} else {
				$this->assertTokenIsValid( Magic_Link::validate_token( self::$user_id, $token['client'], $token['token'] ) );
			}
		}
	}

	/**
	 * Test single-use quality of a token.
	 */
	public function test_single_use_token() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );

		// First use should be valid.
		$first_validation = Magic_Link::validate_token( self::$user_id, $token_data['client'], $token_data['token'] );
		$this->assertTokenIsValid( $first_validation );

		// Second use should error with "invalid_token", since it was deleted by previous use.
		$second_validation = Magic_Link::validate_token( self::$user_id, $token_data['client'], $token_data['token'] );
		$this->assertTrue( is_wp_error( $second_validation ) );
		$this->assertEquals( 'invalid_token', $second_validation->get_error_code() );
	}

	/**
	 * Test that generating a token for an admin returns an error.
	 */
	public function test_generate_token_for_admin() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$admin_id ) );
		$this->assertTrue( is_wp_error( $token_data ) );
		$this->assertEquals( 'newspack_magic_link_invalid_user', $token_data->get_error_code() );
	}

	/**
	 * Test that generating a token for a user with disabled magic links returns
	 * an error.
	 */
	public function test_generate_token_for_disabled_user() {
		update_user_meta( self::$user_id, Magic_Link::DISABLED_META, true );
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertTrue( is_wp_error( $token_data ) );
		$this->assertEquals( 'newspack_magic_link_invalid_user', $token_data->get_error_code() );
		delete_user_meta( self::$user_id, Magic_Link::DISABLED_META ); // Clean up.
	}

	/**
	 * Test that a self-served (unauthenticated) generated token contains a client
	 * hash for validation.
	 */
	public function test_generate_self_served_token() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertNotEmpty( $token_data['client'] );
	}

	/**
	 * Test that an admin generated token does not contain a client hash for
	 * validation.
	 */
	public function test_generate_admin_token() {
		wp_set_current_user( self::$admin_id );
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertEmpty( $token_data['client'] );
	}

	/**
	 * Test that valid token with different user ID.
	 */
	public function test_validate_token_with_different_user_id() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$validation = Magic_Link::validate_token( self::$secondary_user_id, $token_data['client'], $token_data['token'] );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_token', $validation->get_error_code() );
	}

	/**
	 * Test token OTP.
	 */
	public function test_token_otp() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$otp        = $token_data['otp'];
		$validation = Magic_Link::validate_otp( self::$user_id, $otp['hash'], $otp['code'] );
		$this->assertTokenIsValid( $validation );
	}

	/**
	 * Test invalid OTP code.
	 */
	public function test_invalid_otp_code() {
		$token_data  = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$otp         = $token_data['otp'];
		$random_code = 123456;
		$validation  = Magic_Link::validate_otp( self::$user_id, $otp['hash'], $random_code );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_otp', $validation->get_error_code() );
	}

	/**
	 * Test invalid OTP hash.
	 */
	public function test_invalid_otp_hash() {
		$token_data  = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$otp         = $token_data['otp'];
		$random_hash = wp_generate_password( 32, false );
		$validation  = Magic_Link::validate_otp( self::$user_id, $random_hash, $otp['code'] );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_hash', $validation->get_error_code() );
	}

	/**
	 * Test OTP hash expiration.
	 */
	public function test_otp_hash_expiration() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$otp        = $token_data['otp'];

		for ( $i = 0; $i < Magic_Link::OTP_MAX_ATTEMPTS; $i++ ) {
			$validation = Magic_Link::validate_otp( self::$user_id, $otp['hash'], 12345 );
			$this->assertTrue( is_wp_error( $validation ) );
			$this->assertEquals( 'invalid_otp', $validation->get_error_code() );
		}

		// On the max attempt threshold, hash will expire.
		$validation = Magic_Link::validate_otp( self::$user_id, $otp['hash'], 123456 );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'max_otp_attempts', $validation->get_error_code() );

		// Next attempt on the same hash should fail with `invalid_hash` because the hash was deleted.
		$validation = Magic_Link::validate_otp( self::$user_id, $otp['hash'], 123456 );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_hash', $validation->get_error_code() );
	}

	/**
	 * Test invalid token.
	 */
	public function test_invalid_token() {
		$token_data   = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$random_token = wp_generate_password( 32 );
		$validation   = Magic_Link::validate_token( self::$user_id, $token_data['client'], $random_token );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_token', $validation->get_error_code() );
	}

	/**
	 * Test invalid client hash.
	 */
	public function test_invalid_client_hash() {
		$token_data         = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$random_client_hash = wp_generate_password( 32 );
		$validation         = Magic_Link::validate_token( self::$user_id, $random_client_hash, $token_data['token'] );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_client', $validation->get_error_code() );
	}

	/**
	 * Test that an expired token is invalid.
	 */
	public function test_expired_token() {
		// Filter the token expiration time to be 0 seconds.
		add_filter( 'newspack_magic_link_token_expiration', '__return_zero' );
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		// Sleep for 1 second to ensure the token is expired.
		sleep( 1 );
		$validation = Magic_Link::validate_token( self::$user_id, $token_data['client'], $token_data['token'] );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_token', $validation->get_error_code() );
	}

	/**
	 * Test that newspack_magic_link_email_config filter can override the email config.
	 */
	public function test_email_config_filter() {
		$filter_called   = false;
		$received_params = [];

		// Add a filter to capture the parameters and modify the config name.
		$filter_callback = function ( $email_config_name, $email_type, $user, $redirect_to, $token_data ) use ( &$filter_called, &$received_params ) {
			$filter_called   = true;
			$received_params = [
				'email_config_name' => $email_config_name,
				'email_type'        => $email_type,
				'user'              => $user,
				'redirect_to'       => $redirect_to,
				'token_data'        => $token_data,
			];
			// Return a custom config name.
			return 'custom-email-config';
		};

		add_filter( 'newspack_magic_link_email_config', $filter_callback, 10, 5 );

		// Trigger send_email (it will fail to send since we don't have email setup, but filter should still fire).
		$user = get_user_by( 'id', self::$user_id );
		Magic_Link::send_email( $user, 'https://example.com/redirect' );

		remove_filter( 'newspack_magic_link_email_config', $filter_callback, 10 );

		$this->assertTrue( $filter_called, 'The newspack_magic_link_email_config filter was called.' );
		$this->assertEquals( 'OTP_AUTH', $received_params['email_type'], 'Filter received the correct email type.' );
		$this->assertEquals( $user->ID, $received_params['user']->ID, 'Filter received the correct user.' );
		$this->assertEquals( 'https://example.com/redirect', $received_params['redirect_to'], 'Filter received the correct redirect_to.' );
		$this->assertIsArray( $received_params['token_data'], 'Filter received token data as array.' );
	}

	/**
	 * Assert a generated magic link cannot resolve to a foreign host or scheme.
	 *
	 * A base that resolves off-origin is the whole risk. The link's host must be
	 * either empty (a relative URL, resolved against the site) or the site host;
	 * likewise its scheme, since the helper pins the full origin (scheme, host,
	 * port), not host alone — a same-host base on the wrong scheme would still
	 * carry the token off the site's actual origin (e.g. downgrading it to
	 * plaintext http, or off to a non-http scheme like mailto:).
	 *
	 * @param string $link  The generated magic link.
	 * @param string $input The input that produced it, for the failure message.
	 */
	private function assertLinkStaysOnSite( $link, $input ) {
		$link_host   = wp_parse_url( $link, PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$link_scheme = wp_parse_url( $link, PHP_URL_SCHEME );
		$site_scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$this->assertTrue(
			empty( $link_host ) || $link_host === $site_host,
			sprintf( 'Base must stay on the site host. Input "%s" produced "%s".', $input, $link )
		);
		$this->assertTrue(
			empty( $link_scheme ) || $link_scheme === $site_scheme,
			sprintf( 'Base must stay on the site scheme. Input "%s" produced "%s".', $input, $link )
		);
		$this->assertStringContainsString( 'token=', $link, 'Token must still be present.' );
		$this->assertStringContainsString( 'secret=', $link, 'Secret must still be present.' );
	}

	/**
	 * A generated magic link keeps its base on the site's own origin.
	 *
	 * @dataProvider data_off_origin_bases
	 * @param string $input An off-origin or unsafe base.
	 */
	public function test_generate_url_restricts_off_origin_base( $input ) {
		$url = Magic_Link::generate_url( get_user_by( 'id', self::$user_id ), $input );
		$this->assertLinkStaysOnSite( $url, $input );
	}

	/**
	 * Off-origin and unsafe bases that must not resolve to a foreign host.
	 *
	 * @return array[]
	 */
	public function data_off_origin_bases() {
		return [
			'off-origin host'     => [ 'https://attacker.example/harvest' ],
			// Derived from home_url() by flipping only its scheme, so this row
			// tests scheme pinning regardless of which scheme the test site runs
			// under (WP_TESTS_DOMAIN defaults to http, but should that ever
			// change, the row keeps exercising a scheme mismatch on the same host
			// instead of silently degrading to a host mismatch or a same-origin
			// input).
			'scheme mismatch'     => [ self::scheme_mismatch_base() ],
			'protocol-relative'   => [ '//attacker.example/harvest' ],
			// This row and 'bare authority' below both parse to an empty host, so
			// core rejects them before the origin check ever runs and they would
			// pass without this fix. They are kept because they document the
			// shapes core normalises, not because they cover the origin logic.
			'backslash authority' => [ 'https:\\\\attacker.example' ],
			'schemeful host-less' => [ 'mailto:reader@example.com' ],
			'userinfo confusion'  => [ 'https://example.org@attacker.example/x' ],
			'suffix host'         => [ 'https://example.org.attacker.example/' ],
			'bare authority'      => [ '://attacker.example' ], // Core normalises to a relative path.
			'port mismatch'       => [ self::port_mismatch_base() ],
		];
	}

	/**
	 * Build an off-origin base that mismatches home_url() by scheme only.
	 *
	 * PHPUnit calls data providers before set_up(), but home_url() is safe to
	 * call here: the WP test bootstrap loads WP (and its options) once, before
	 * PHPUnit collects any tests, so the site URL is already resolvable.
	 *
	 * @return string Same host and port as home_url(), opposite scheme.
	 */
	private static function port_mismatch_base() {
		$home = wp_parse_url( home_url() );
		$port = isset( $home['port'] ) ? (int) $home['port'] + 1 : 8080;
		return $home['scheme'] . '://' . $home['host'] . ':' . $port . '/x';
	}

	/**
	 * Build an off-origin base that mismatches home_url() by port only.
	 *
	 * @return string Same scheme and host as home_url(), a port it does not use.
	 */
	private static function scheme_mismatch_base() {
		$home            = wp_parse_url( home_url() );
		$mismatched_home = 'https' === $home['scheme'] ? 'http' : 'https';
		return $mismatched_home . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . '/x';
	}

	/**
	 * A same-origin base keeps its path, and always comes back absolute.
	 */
	public function test_generate_url_preserves_same_origin_base() {
		$user     = get_user_by( 'id', self::$user_id );
		$home     = wp_parse_url( home_url() );
		$expected = home_url( '/premium/' );

		// Built from home_url() rather than spelled out, so the row keeps testing
		// a same-origin base if WP_TESTS_DOMAIN or its scheme ever changes.
		$same_origin = Magic_Link::generate_url( $user, $expected );
		$this->assertStringStartsWith( $expected, $same_origin, 'Same-origin base preserved.' );

		// Reset rate-limiting state between calls: generate_token() rejects a
		// second token for the same user within RATE_INTERVAL (60s), which two
		// calls in the same test would otherwise always collide with.
		delete_user_meta( self::$user_id, Magic_Link::TOKENS_META );

		// A relative base resolves against home_url() instead of passing through.
		// The link is emailed, and a relative URL has nothing to resolve against
		// in a mail client.
		$relative = Magic_Link::generate_url( $user, '/premium/' );
		$this->assertStringStartsWith( $expected, $relative, 'Relative base resolved to an absolute link.' );

		delete_user_meta( self::$user_id, Magic_Link::TOKENS_META );

		// Credentials sit outside scheme/host/port, so the origin comparison reads
		// straight past them; they have to be dropped deliberately.
		$authority = $home['scheme'] . '://reader:hunter2@' . $home['host'] .
			( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . '/premium/';
		$stripped  = Magic_Link::generate_url( $user, $authority );
		$this->assertStringStartsWith( $expected, $stripped, 'Same-origin base with credentials preserved.' );
		$this->assertStringNotContainsString( 'hunter2', $stripped, 'Credentials dropped from the link.' );

		// Spelling out the scheme's default port names the same origin as omitting
		// it. Only meaningful when the test site itself runs on the default port.
		if ( ! isset( $home['port'] ) ) {
			delete_user_meta( self::$user_id, Magic_Link::TOKENS_META );
			$default_port = $home['scheme'] . '://' . $home['host'] .
				( 'https' === $home['scheme'] ? ':443' : ':80' ) . '/premium/';
			$normalised   = Magic_Link::generate_url( $user, $default_port );
			$this->assertStringStartsWith( $expected, $normalised, 'Default port is the same origin.' );
		}
	}

	/**
	 * The platform allowed_redirect_hosts list is NOT inherited for the base.
	 *
	 * A host other flows allowlist for browser navigation must not become a
	 * magic-link base, because the base carries the reader's auth token.
	 */
	public function test_generate_url_does_not_inherit_allowed_redirect_hosts() {
		$callback = function ( $hosts ) {
			$hosts[] = 'checkout.fundjournalism.org';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $callback );

		$url = Magic_Link::generate_url( get_user_by( 'id', self::$user_id ), 'https://checkout.fundjournalism.org/x' );

		remove_filter( 'allowed_redirect_hosts', $callback );

		$this->assertLinkStaysOnSite( $url, 'https://checkout.fundjournalism.org/x' );
	}

	/**
	 * An empty base yields home_url(), unchanged from prior behaviour.
	 */
	public function test_generate_url_empty_base_is_home() {
		$url = Magic_Link::generate_url( get_user_by( 'id', self::$user_id ), '' );
		$this->assertStringStartsWith( home_url(), $url );
	}

	/**
	 * The magic-link email built by send_email() also keeps its base on-origin.
	 *
	 * Mirrors the generate_url() coverage above for the second builder:
	 * send_email() embeds the URL in an email rather than returning it, so
	 * this captures the sent body via MockPHPMailer (as
	 * tests/unit-tests/emails.php does for its own sends). The mock config
	 * mirrors the real 'reader-activation-magic-link' config registered by
	 * Reader_Activation_Emails::add_email_configs() — a `template` pointing
	 * at the real template file, not an inline `html_payload` string,
	 * because Emails::get_email_config_by_type() resolves the rendered body
	 * from a post created from that template, not from a config-level
	 * payload field.
	 */
	public function test_send_email_restricts_off_origin_base() {
		$config_callback = function ( $configs ) {
			$configs['reader-activation-magic-link'] = [
				'name'        => 'reader-activation-magic-link',
				'category'    => 'reader-activation',
				'label'       => 'Magic link',
				'description' => 'Magic link email.',
				'template'    => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-activation-emails/magic-link.php',
			];
			return $configs;
		};
		$name_callback = function () {
			return 'reader-activation-magic-link';
		};
		// The filter add/reset and its teardown are wrapped in try/finally so the
		// two filters and the email-config cache are cleaned up even if an
		// assertion below fails — this suite has had order-dependent failures
		// from leaked filter/cache state between tests.
		add_filter( 'newspack_email_configs', $config_callback );
		add_filter( 'newspack_magic_link_email_config', $name_callback );
		Emails::reset_email_configs_cache();
		reset_phpmailer_instance();

		try {
			$sent = Magic_Link::send_email( get_user_by( 'id', self::$user_id ), 'https://attacker.example/harvest' );

			// Assert dispatch succeeded before reading the sent body: if send_email()
			// ever regressed to return false/WP_Error, get_sent() would return null
			// and ->body would fatal here instead of failing this assertion cleanly.
			$this->assertTrue( $sent, 'The email must dispatch through MockPHPMailer.' );

			$body = tests_retrieve_phpmailer_instance()->get_sent()->body;
		} finally {
			remove_filter( 'newspack_magic_link_email_config', $name_callback );
			remove_filter( 'newspack_email_configs', $config_callback );
			Emails::reset_email_configs_cache();
		}

		$this->assertStringNotContainsString( 'attacker.example', $body, 'The base must not be the attacker host.' );

		// wp_mail() sends this template as quoted-printable, so the raw body
		// has '=' encoded as '=3D' and long lines soft-wrapped with a
		// trailing '='. Decode before pattern-matching, otherwise the long
		// token-bearing URL is split across lines and a naive match on the
		// raw body truncates it.
		$decoded_body = quoted_printable_decode( $body );

		// Assert on the magic-link URL specifically, not on home_url()
		// appearing anywhere in the body: the template also renders
		// home_url() via *SITE_URL* in its logo and footer links,
		// independent of the redirect base, so a bare "body contains
		// home_url()" assertion would pass even without the fix. The token
		// is the anchor — it identifies the one URL in the email that
		// carries the reader's auth token, i.e. the magic-link base itself.
		$this->assertMatchesRegularExpression( '#https?://[^\s"\'<>]*token=[^\s"\'<>]*#', $decoded_body, 'The email must contain a magic-link URL carrying the token.' );
		preg_match( '#https?://[^\s"\'<>]*token=[^\s"\'<>]*#', $decoded_body, $matches );
		$link_host = wp_parse_url( $matches[0], PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertSame( $site_host, $link_host, 'The magic-link base must be the site host.' );
	}
}
