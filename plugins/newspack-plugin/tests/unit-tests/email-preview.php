<?php
/**
 * Tests Email_Preview.
 *
 * @package Newspack\Tests
 */

use Newspack\Emails;
use Newspack\Wizards\Newspack\Email_Preview;

require_once __DIR__ . '/../mocks/newsletters-mocks.php';

/**
 * Tests Email_Preview.
 */
class Newspack_Test_Email_Preview extends WP_UnitTestCase {

	/**
	 * Test email config name.
	 *
	 * @var string
	 */
	private static $test_config_name = 'test-preview-config';

	/**
	 * Filter callback reference so it can be removed in tear_down().
	 *
	 * @var callable
	 */
	private $config_filter_callback;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();

		$this->config_filter_callback = function ( $types ) {
			$types[ self::$test_config_name ] = [
				'name'        => self::$test_config_name,
				'label'       => __( 'Test preview config', 'newspack' ),
				'description' => __( 'Email for testing preview.', 'newspack' ),
				'template'    => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/receipt.php',
				'category'    => 'test',
			];
			return $types;
		};
		add_filter( 'newspack_email_configs', $this->config_filter_callback );
		\Newspack\Emails::reset_email_configs_cache();
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		remove_filter( 'newspack_email_configs', $this->config_filter_callback );
		\Newspack\Emails::reset_email_configs_cache();
		parent::tear_down();
	}

	/**
	 * Helper: create a newspack_rr_email post with the test config type.
	 *
	 * @param string $html Optional stored email HTML.
	 * @return int Post ID.
	 */
	private function create_email_post( string $html = '' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, Emails::EMAIL_CONFIG_NAME_META, self::$test_config_name );
		if ( ! empty( $html ) ) {
			update_post_meta( $post_id, \Newspack_Newsletters::EMAIL_HTML_META, $html );
		}
		return $post_id;
	}

	/**
	 * The substitution map has the expected three-key structure.
	 */
	public function test_sample_substitutions_structure() {
		$subs = Email_Preview::get_sample_substitutions();

		self::assertIsArray( $subs );
		self::assertArrayHasKey( 'html', $subs, 'Missing "html" key.' );
		self::assertArrayHasKey( 'url', $subs, 'Missing "url" key.' );
		self::assertArrayHasKey( 'raw', $subs, 'Missing "raw" key.' );
		self::assertCount( 3, $subs, 'Substitution map should have exactly 3 top-level keys.' );
	}

	/**
	 * The sample-substitutions map contains all expected token keys.
	 */
	public function test_sample_substitutions_map() {
		$subs = Email_Preview::get_sample_substitutions();
		$all  = array_merge( $subs['html'], $subs['url'], $subs['raw'] );

		self::assertGreaterThanOrEqual( 32, count( $all ), 'Substitution map should have at least 32 entries.' );

		$expected_keys = [
			'*SITE_TITLE*',
			'*SITE_URL*',
			'*SITE_LOGO*',
			'*BILLING_NAME*',
			'*BILLING_FIRST_NAME*',
			'*AMOUNT*',
			'*PAYMENT_METHOD*',
			'*DATE*',
			'*ACCOUNT_URL*',
			'*MAGIC_LINK_OTP*',
		];
		foreach ( $expected_keys as $key ) {
			self::assertArrayHasKey( $key, $all, "Missing expected token: $key" );
		}
	}

	/**
	 * HTML metacharacters in html-context tokens are escaped.
	 */
	public function test_html_tokens_are_escaped() {
		$source_html = '<html><body>Hello *BILLING_FIRST_NAME*</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		// Inject a malicious value via the filter.
		$filter = function ( $subs ) {
			$subs['html']['*BILLING_FIRST_NAME*'] = '<script>alert(1)</script>';
			return $subs;
		};
		add_filter( 'newspack_email_preview_substitutions', $filter );

		$result = Email_Preview::get_preview_html( $post_id );

		self::assertStringContainsString( '&lt;script&gt;', $result, 'HTML metacharacters should be escaped.' );
		self::assertStringNotContainsString( '<script>alert(1)</script>', $result, 'Raw script tag should not appear.' );

		remove_filter( 'newspack_email_preview_substitutions', $filter );
	}

	/**
	 * URL tokens reject dangerous protocols.
	 */
	public function test_url_tokens_are_sanitized() {
		$source_html = '<html><body><a href="*ACCOUNT_URL*">Account</a></body></html>';
		$post_id     = $this->create_email_post( $source_html );

		$filter = function ( $subs ) {
			$subs['url']['*ACCOUNT_URL*'] = 'javascript:alert(1)';
			return $subs;
		};
		add_filter( 'newspack_email_preview_substitutions', $filter );

		$result = Email_Preview::get_preview_html( $post_id );

		self::assertStringNotContainsString( 'javascript:', $result, 'javascript: protocol should be rejected by esc_url().' );

		remove_filter( 'newspack_email_preview_substitutions', $filter );
	}

	/**
	 * Raw tokens preserve their pre-escaped HTML intact.
	 */
	public function test_raw_tokens_are_not_double_escaped() {
		$source_html = '<html><body>Contact: *CONTACT_EMAIL*</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		$result = Email_Preview::get_preview_html( $post_id );

		self::assertStringContainsString( '<a href=', $result, 'CONTACT_EMAIL <a> tag should be preserved.' );
		self::assertStringNotContainsString( '&lt;a href=', $result, 'CONTACT_EMAIL <a> tag should not be double-escaped.' );
	}

	/**
	 * Raw-bucket value-construction escapes admin-controlled inputs.
	 *
	 * *SITE_CONTACT* lives in the 'raw' bucket (not re-escaped at
	 * strtr-time), so the values built into it MUST be escaped at
	 * construction. The bucket includes get_bloginfo('name') and the WC
	 * store address — both admin-controlled. An admin (or a role-elevation
	 * supply-chain attack) writing `<img onerror=...>` to the site title
	 * must not flow through to the iframe's srcDoc raw.
	 */
	public function test_site_contact_escapes_admin_controlled_values() {
		$original_blogname = get_option( 'blogname' );
		update_option( 'blogname', 'Acme <img src=x onerror=alert(1)>' );

		$source_html = '<html><body>From: *SITE_CONTACT*</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		$result = Email_Preview::get_preview_html( $post_id );

		update_option( 'blogname', $original_blogname );

		// The dangerous form is the unescaped HTML element — angle brackets
		// must be escaped to `&lt;...&gt;`. The literal text `onerror=`
		// inside escaped content (`&lt;img ... onerror=...&gt;`) is safe
		// because the browser won't interpret it as an attribute.
		self::assertStringNotContainsString(
			'<img src=x',
			$result,
			'Raw-bucket *SITE_CONTACT* must escape the unescaped `<img>` tag.'
		);
		self::assertStringContainsString(
			'&lt;img',
			$result,
			'Raw-bucket *SITE_CONTACT* must HTML-encode angle brackets in the site title.'
		);
	}

	/**
	 * CONTACT_EMAIL resolves through Emails::get_reply_to_email() — not admin_email.
	 *
	 * Pins the fix for the function_exists() bug: function_exists() cannot
	 * test class methods, so the guard was always false and the token fell
	 * back to admin_email regardless of the Reader Activation contact setting.
	 */
	public function test_contact_email_uses_reply_to_email() {
		$custom_email = 'reply@example.org';
		add_filter( 'newspack_reply_to_email', fn() => $custom_email );

		$source_html = '<html><body>Contact us at *CONTACT_EMAIL*</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		$result = Email_Preview::get_preview_html( $post_id );

		self::assertStringContainsString( $custom_email, $result, 'CONTACT_EMAIL should resolve to the filtered reply-to address.' );
		self::assertStringNotContainsString( '*CONTACT_EMAIL*', $result, 'Raw CONTACT_EMAIL token should not remain.' );

		remove_all_filters( 'newspack_reply_to_email' );
	}

	/**
	 * Tests that get_preview_html() substitutes tokens in stored EMAIL_HTML_META.
	 */
	public function test_get_preview_html_with_stored_meta() {
		$source_html = '<html><body>Hello *BILLING_NAME*, your total is *AMOUNT*.</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		$result = Email_Preview::get_preview_html( $post_id );

		self::assertIsString( $result );
		self::assertStringContainsString( 'Sample Reader', $result, 'BILLING_NAME should be substituted.' );
		self::assertStringContainsString( '$25.00', $result, 'AMOUNT should be substituted.' );
		self::assertStringNotContainsString( '*BILLING_NAME*', $result, 'Raw token should not remain.' );
		self::assertStringNotContainsString( '*AMOUNT*', $result, 'Raw token should not remain.' );
	}

	/**
	 * Tests that get_preview_html() falls back to template HTML when no stored meta exists.
	 */
	public function test_get_preview_html_fallback_to_template() {
		$post_id = $this->create_email_post();

		$result = Email_Preview::get_preview_html( $post_id );

		self::assertIsString( $result );
		self::assertNotEmpty( $result, 'Fallback to template should produce non-empty HTML.' );
		// The receipt template contains "Thank you!" — verify substitution ran.
		self::assertStringContainsString( 'Thank you!', $result );
	}

	/**
	 * Tests that get_preview_html() returns false for a nonexistent post.
	 */
	public function test_get_preview_html_returns_false_for_nonexistent() {
		$result = Email_Preview::get_preview_html( 999999 );
		self::assertFalse( $result );
	}

	/**
	 * Permission check rejects non-admin users.
	 */
	public function test_api_permissions_check_rejects_non_admin() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$result = Email_Preview::api_permissions_check();
		self::assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * REST API returns 404 for a post that is not of the email post type.
	 */
	public function test_api_get_preview_returns_404_for_wrong_post_type() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$request = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-settings/emails/' . $post_id . '/preview' );
		$request->set_param( 'id', (string) $post_id );

		$response = Email_Preview::api_get_preview( $request );

		self::assertInstanceOf( 'WP_Error', $response );
		self::assertEquals( 'newspack_email_preview_not_found', $response->get_error_code() );
		self::assertEquals( 404, $response->get_error_data()['status'] );
	}

	/**
	 * REST API returns HTML and id on success.
	 */
	public function test_api_get_preview_returns_html() {
		$source_html = '<html><body>Preview for *BILLING_NAME*</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		$request = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-settings/emails/' . $post_id . '/preview' );
		$request->set_param( 'id', (string) $post_id );

		$response = Email_Preview::api_get_preview( $request );

		self::assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		self::assertArrayHasKey( 'html', $data );
		self::assertArrayHasKey( 'id', $data );
		self::assertEquals( $post_id, $data['id'] );
		self::assertStringContainsString( 'Sample Reader', $data['html'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * wc:{id} route (slice 2b.2 — folded in from PR #4758)
	 * ------------------------------------------------------------------
	 * The route accepts `wc:{email_id}` strings alongside numeric post
	 * IDs. Validation is against the unified email config schema
	 * filtered to source==='woocommerce'. Routing picks block-editor
	 * render when a template post exists, falls back to classic
	 * render otherwise.
	 */

	/**
	 * REST API returns 404 for a wc: identifier that doesn't resolve to a
	 * woocommerce-source config in the unified schema.
	 */
	public function test_api_get_preview_returns_404_for_unregistered_wc_email() {
		$request = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-settings/emails/wc:nonexistent_email/preview' );
		$request->set_param( 'id', 'wc:nonexistent_email' );

		$response = Email_Preview::api_get_preview( $request );

		self::assertInstanceOf( 'WP_Error', $response );
		self::assertEquals( 'newspack_email_preview_not_found', $response->get_error_code() );
		self::assertEquals( 404, $response->get_error_data()['status'] );
	}

	/**
	 * REST API also rejects wc: identifiers for configs whose source
	 * isn't 'woocommerce' — guards against spoofing a newspack-source
	 * config through the wc: branch.
	 */
	public function test_api_get_preview_rejects_wc_path_for_non_wc_source() {
		// Inject a newspack-source config under a wc-style id key.
		$callback = function ( $configs ) {
			$configs['fake_wc_id'] = [
				'name'     => 'fake_wc_id',
				'source'   => 'newspack', // Wrong source — should reject the wc: path.
				'category' => 'reader-revenue',
			];
			return $configs;
		};
		add_filter( 'newspack_email_configs', $callback );
		\Newspack\Emails::reset_email_configs_cache();

		$request = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-settings/emails/wc:fake_wc_id/preview' );
		$request->set_param( 'id', 'wc:fake_wc_id' );

		$response = Email_Preview::api_get_preview( $request );

		remove_filter( 'newspack_email_configs', $callback );
		\Newspack\Emails::reset_email_configs_cache();

		self::assertInstanceOf( 'WP_Error', $response );
		self::assertEquals( 'newspack_email_preview_not_found', $response->get_error_code() );
	}

	/**
	 * Get_wc_classic_preview_html returns false when the WC internal
	 * EmailPreview class isn't available (e.g. real WooCommerce not
	 * loaded in the test env, even when a `class WooCommerce` shim
	 * declared by a sibling test file flips class_exists('WooCommerce')
	 * to true).
	 */
	public function test_wc_classic_preview_returns_false_without_internal_preview_class() {
		$result = Email_Preview::get_wc_classic_preview_html( 'customer_payment_retry' );
		self::assertFalse( $result );
	}

	/**
	 * Valid wc: id with no template post → routes to classic render
	 * → classic render fails (no internal WC class in test env)
	 * → endpoint returns 500 with `newspack_email_preview_unavailable`.
	 *
	 * Confirms the route widening + validation + classic branch decision
	 * + failure-mode handling all wire correctly. Doesn't assert HTML
	 * because real WC rendering needs WC core loaded; the 500 path is
	 * the observable signal that everything before classic-render
	 * executed correctly.
	 */
	public function test_api_get_preview_wc_path_reaches_classic_render() {
		$wc_id    = 'customer_payment_retry';
		$callback = function ( $configs ) use ( $wc_id ) {
			$configs[ $wc_id ] = [
				'name'     => $wc_id,
				'source'   => 'woocommerce',
				'category' => 'woocommerce',
				'label'    => 'Test WC',
			];
			return $configs;
		};
		add_filter( 'newspack_email_configs', $callback );
		\Newspack\Emails::reset_email_configs_cache();

		$request = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-settings/emails/wc:' . $wc_id . '/preview' );
		$request->set_param( 'id', 'wc:' . $wc_id );

		$response = Email_Preview::api_get_preview( $request );

		remove_filter( 'newspack_email_configs', $callback );
		\Newspack\Emails::reset_email_configs_cache();

		self::assertInstanceOf( 'WP_Error', $response );
		self::assertEquals( 'newspack_email_preview_unavailable', $response->get_error_code() );
		self::assertEquals( 500, $response->get_error_data()['status'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * NPPD-1555 shape-validation fallback (extension XSS resistance)
	 * ------------------------------------------------------------------
	 * The `newspack_email_preview_substitutions` filter is wrapped in
	 * shape validation — if a third-party filter returns a non-array,
	 * drops a sub-array, or replaces one with a non-array, the call
	 * site falls back to the unfiltered defaults instead of skipping
	 * escaping or crashing.
	 */

	/**
	 * Malformed filter response (non-array) reverts to defaults — the
	 * preview still renders with proper escaping applied.
	 */
	public function test_substitutions_filter_shape_validation_fallback_non_array() {
		$source_html = '<html><body>Hello *BILLING_NAME*</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		// Broken filter — returns a non-array. The shape validation must
		// catch this and fall back to defaults, not skip escaping.
		$broken = fn () => 'oops not an array';
		add_filter( 'newspack_email_preview_substitutions', $broken );

		$result = Email_Preview::get_preview_html( $post_id );

		remove_filter( 'newspack_email_preview_substitutions', $broken );

		// Substitution should still happen (proves defaults kicked in).
		self::assertStringContainsString( 'Sample Reader', $result );
		self::assertStringNotContainsString( '*BILLING_NAME*', $result );
	}

	/**
	 * Filter response missing a required sub-array (drops `url`) reverts
	 * to defaults.
	 */
	public function test_substitutions_filter_shape_validation_fallback_missing_subarray() {
		$source_html = '<html><body>Visit <a href="*SITE_URL*">us</a> as *BILLING_NAME*</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		// Broken filter — drops the 'url' sub-array entirely.
		$broken = function ( $subs ) {
			unset( $subs['url'] );
			return $subs;
		};
		add_filter( 'newspack_email_preview_substitutions', $broken );

		$result = Email_Preview::get_preview_html( $post_id );

		remove_filter( 'newspack_email_preview_substitutions', $broken );

		// URL token must still resolve (proves defaults kicked in even
		// though the broken filter tried to drop the whole map).
		self::assertStringNotContainsString( '*SITE_URL*', $result );
		// And the html-side substitution must also still work.
		self::assertStringContainsString( 'Sample Reader', $result );
	}

	/**
	 * Filter response where a sub-array is replaced with a non-array
	 * reverts to defaults (and importantly: doesn't strip escaping by
	 * silently merging a malformed structure).
	 */
	public function test_substitutions_filter_shape_validation_fallback_non_array_subarray() {
		$source_html = '<html><body>Hello *BILLING_NAME*</body></html>';
		$post_id     = $this->create_email_post( $source_html );

		// Broken filter — replaces 'html' sub-array with a string.
		// Without shape validation this would crash array_map().
		$broken = function ( $subs ) {
			$subs['html'] = 'totally wrong type';
			return $subs;
		};
		add_filter( 'newspack_email_preview_substitutions', $broken );

		$result = Email_Preview::get_preview_html( $post_id );

		remove_filter( 'newspack_email_preview_substitutions', $broken );

		// No crash, defaults applied.
		self::assertIsString( $result );
		self::assertStringContainsString( 'Sample Reader', $result );
	}
}
