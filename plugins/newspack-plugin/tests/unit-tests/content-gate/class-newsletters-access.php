<?php
/**
 * Tests for Newsletters_Access.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Newsletters_Access;

/**
 * Tests for Newsletters_Access HMAC signing and verification.
 */
class Test_Newsletters_Access extends \WP_UnitTestCase {

	/**
	 * Create a newsletter post and optionally mark it sent at the given time.
	 *
	 * @param int|null $sent_at Unix timestamp to record as send time. Null = not sent.
	 *
	 * @return int Newsletter post ID.
	 */
	private function create_newsletter( $sent_at = null ) {
		$post_id = $this->factory->post->create( [ 'post_type' => 'newspack_nl_cpt' ] );
		if ( null !== $sent_at ) {
			update_post_meta( $post_id, 'newsletter_sent', $sent_at );
		}
		return $post_id;
	}

	/**
	 * Test that sign() returns a non-empty string token.
	 */
	public function test_sign_produces_nonempty_token() {
		$token = Newsletters_Access::sign( 123 );
		$this->assertIsString( $token );
		$this->assertNotEmpty( $token );
	}

	/**
	 * Test that sign() is deterministic — same ID always yields the same token.
	 */
	public function test_sign_is_deterministic_across_calls() {
		// Same input must produce the same token, since signing happens on
		// every render and verification can't depend on render timing.
		$this->assertSame( Newsletters_Access::sign( 123 ), Newsletters_Access::sign( 123 ) );
	}

	/**
	 * Test that verify() returns the payload array for a valid sent newsletter token.
	 */
	public function test_verify_accepts_token_for_sent_newsletter() {
		$post_id = $this->create_newsletter( time() );
		$token   = Newsletters_Access::sign( $post_id );
		$result  = Newsletters_Access::verify( $token );
		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['newsletter_id'] );
	}

	/**
	 * Test that verify() returns false for a newsletter without a send timestamp.
	 */
	public function test_verify_rejects_token_for_unsent_newsletter() {
		// Post exists but `newsletter_sent` meta is absent — e.g., a draft
		// or a forwarded preview from a test send.
		$post_id = $this->create_newsletter( null );
		$token   = Newsletters_Access::sign( $post_id );
		$this->assertFalse( Newsletters_Access::verify( $token ) );
	}

	/**
	 * Test that verify() returns false when the newsletter post no longer exists.
	 */
	public function test_verify_rejects_token_for_deleted_newsletter() {
		// Sign a token for a post ID that doesn't exist (e.g., the campaign
		// was deleted after the URL was distributed).
		$token = Newsletters_Access::sign( 999999 );
		$this->assertFalse( Newsletters_Access::verify( $token ) );
	}

	/**
	 * Test that verify() returns false when the payload ID has been tampered with.
	 */
	public function test_verify_rejects_tampered_payload() {
		$post_id  = $this->create_newsletter( time() );
		$token    = Newsletters_Access::sign( $post_id );
		// Decode, mutate the id, re-encode without re-signing.
		$decoded  = base64_decode( strtr( $token, '-_', '+/' ) );
		$parts    = explode( '|', $decoded );
		$parts[0] = '999';
		$tampered = rtrim( strtr( base64_encode( implode( '|', $parts ) ), '+/', '-_' ), '=' );
		$this->assertFalse( Newsletters_Access::verify( $tampered ) );
	}

	/**
	 * Test that verify() returns false for garbage/malformed token inputs.
	 */
	public function test_verify_rejects_garbage_token() {
		$this->assertFalse( Newsletters_Access::verify( 'not-a-real-token' ) );
		$this->assertFalse( Newsletters_Access::verify( '' ) );
		$this->assertFalse( Newsletters_Access::verify( 'aaaa' ) );
	}

	/**
	 * Test that verify() returns false when the newsletter was sent beyond SIGNATURE_TTL.
	 */
	public function test_verify_rejects_signature_past_send_window() {
		$old_send_time = time() - ( Newsletters_Access::SIGNATURE_TTL + 60 );
		$post_id       = $this->create_newsletter( $old_send_time );
		$token         = Newsletters_Access::sign( $post_id );
		$this->assertFalse( Newsletters_Access::verify( $token ) );
	}

	/**
	 * Test that verify() returns the payload array for a newsletter just within SIGNATURE_TTL.
	 */
	public function test_verify_accepts_signature_at_edge_of_window() {
		$edge_send_time = time() - ( Newsletters_Access::SIGNATURE_TTL - 60 );
		$post_id        = $this->create_newsletter( $edge_send_time );
		$token          = Newsletters_Access::sign( $post_id );
		$this->assertIsArray( Newsletters_Access::verify( $token ) );
	}

	/**
	 * Test that append_signature_to_link() appends a valid npnl param for a sent newsletter.
	 */
	public function test_append_signature_to_link_adds_npnl_param() {
		$post = $this->factory->post->create_and_get(
			[
				'post_type'  => 'newspack_nl_cpt',
				'post_title' => 'Test Newsletter',
				'post_date'  => gmdate( 'Y-m-d H:i:s' ),
			]
		);
		// Mark sent so the signature passes the send-time check during verify.
		// Signing itself does not depend on send state — see the next test.
		update_post_meta( $post->ID, 'newsletter_sent', time() );

		$url    = 'https://example.test/some-article/';
		$result = Newsletters_Access::append_signature_to_link( $url, $url, $post );
		$this->assertStringContainsString( 'npnl=', $result );
		$query = wp_parse_url( $result, PHP_URL_QUERY );
		parse_str( $query, $parsed );
		$this->assertArrayHasKey( 'npnl', $parsed );
		$decoded = Newsletters_Access::verify( $parsed['npnl'] );
		$this->assertIsArray( $decoded );
		$this->assertSame( $post->ID, $decoded['newsletter_id'] );
	}

	/**
	 * Test that append_signature_to_link() signs links even when the newsletter is unsent.
	 */
	public function test_append_signature_signs_even_when_newsletter_is_unsent() {
		// Signing must succeed during draft renders. Verification will reject
		// the resulting token (no send time meta), but signing itself is a
		// pure function of the post ID.
		$post = $this->factory->post->create_and_get( [ 'post_type' => 'newspack_nl_cpt' ] );
		$url    = 'https://example.test/foo/';
		$result = Newsletters_Access::append_signature_to_link( $url, $url, $post );
		$this->assertStringContainsString( 'npnl=', $result );
	}

	/**
	 * Test that append_signature_to_link() returns the URL unchanged when post is null.
	 */
	public function test_append_signature_returns_url_unchanged_when_post_is_null() {
		$url    = 'https://example.test/foo/';
		$result = Newsletters_Access::append_signature_to_link( $url, $url, null );
		$this->assertSame( $url, $result );
	}

	/**
	 * Test that append_signature_to_link() returns the URL unchanged for non-newsletter posts.
	 */
	public function test_append_signature_returns_url_unchanged_for_non_newsletter_post() {
		$post = $this->factory->post->create_and_get( [ 'post_type' => 'post' ] );
		$url  = 'https://example.test/foo/';
		$this->assertSame( $url, Newsletters_Access::append_signature_to_link( $url, $url, $post ) );
	}

	/**
	 * Test that handle_inbound_request() returns 'verified' with the newsletter_id
	 * when a valid npnl token is present in the query string.
	 */
	public function test_handle_inbound_returns_verified_when_token_valid() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$post_id = $this->create_newsletter( time() );
		$token   = Newsletters_Access::sign( $post_id );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = $token;
		$result = Newsletters_Access::handle_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'verified', $result['action'] );
		$this->assertSame( $post_id, $result['newsletter_id'] );
	}

	/**
	 * Test that handle_inbound_request() returns 'skipped' when no npnl param is present.
	 */
	public function test_handle_inbound_returns_skipped_when_no_param_present() {
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$result = Newsletters_Access::handle_inbound_request( false );
		$this->assertSame( 'skipped', $result['action'] );
	}

	/**
	 * Test that handle_inbound_request() returns 'invalid' for a garbage token.
	 */
	public function test_handle_inbound_returns_invalid_for_bad_token() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_GET[ Newsletters_Access::QUERY_PARAM ] = 'garbage';
		$result = Newsletters_Access::handle_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'invalid', $result['action'] );
	}

	/**
	 * Test that handle_inbound_request() returns 'invalid' for a cryptographically
	 * valid token whose newsletter has never been sent (no newsletter_sent meta).
	 */
	public function test_handle_inbound_returns_invalid_for_unsent_newsletter() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		// Cryptographically valid token, but the newsletter was never sent.
		$post_id = $this->create_newsletter( null );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = Newsletters_Access::sign( $post_id );
		$result = Newsletters_Access::handle_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'invalid', $result['action'] );
	}

	/**
	 * Test that handle_inbound_request() returns 'skipped' when the current user
	 * is a logged-in editor (who bypasses the gate via capability checks).
	 */
	public function test_handle_inbound_skips_for_logged_in_editor() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$editor = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		$post_id = $this->create_newsletter( time() );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = Newsletters_Access::sign( $post_id );
		$result = Newsletters_Access::handle_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		wp_set_current_user( 0 );
		$this->assertSame( 'skipped', $result['action'] );
	}

	/**
	 * Create a sent newsletter for the given list ID containing the given URL in its email HTML.
	 *
	 * @param string $list_id Send list ID to store in `send_list_id` meta.
	 * @param string $url     URL to embed in `newspack_email_html` meta.
	 * @param int    $sent_at Send time (unix timestamp).
	 *
	 * @return int Newsletter post ID.
	 */
	private function create_sent_newsletter_with_link( $list_id, $url, $sent_at = null ) {
		if ( null === $sent_at ) {
			$sent_at = time();
		}
		$post_id = $this->factory->post->create( [ 'post_type' => 'newspack_nl_cpt' ] );
		update_post_meta( $post_id, 'send_list_id', $list_id );
		update_post_meta( $post_id, 'newsletter_sent', $sent_at );
		update_post_meta(
			$post_id,
			'newspack_email_html',
			'<html><body><a href="' . esc_url( $url ) . '">link</a></body></html>'
		);
		return $post_id;
	}

	/**
	 * Test that handle_utm_fallback_request() grants a single-post bypass when
	 * utm_medium=email, utm_source matches a valid list ID, and the current URL
	 * appears in a recently-sent newsletter for that list.
	 */
	public function test_utm_fallback_grants_single_post_bypass_on_match() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );

		$post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$url     = get_permalink( $post_id );
		$this->create_sent_newsletter_with_link( 'list_abc', $url );

		// Use add_query_arg so the UTM params are correctly appended even when
		// the permalink already contains a query string (e.g., ?p=N in test env).
		$request_url = add_query_arg(
			[
				'utm_medium' => 'email',
				'utm_source' => 'list_abc',
			],
			$url
		);
		$this->go_to( $request_url );

		// Stub is_valid_send_list_id to accept our test list ID; in production this
		// queries Subscription_Lists.
		add_filter(
			'newspack_newsletters_access_test_valid_list_ids',
			function() {
				return [ 'list_abc' ];
			}
		);

		$result = Newsletters_Access::handle_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'verified', $result['action'] );
		$this->assertSame( $post_id, $result['post_id'] );
	}

	/**
	 * Test that handle_utm_fallback_request() returns 'invalid' when utm_source
	 * does not match any known send list ID.
	 */
	public function test_utm_fallback_rejects_unknown_list_id() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );

		$post_id     = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$request_url = add_query_arg(
			[
				'utm_medium' => 'email',
				'utm_source' => 'fake_list_xyz',
			],
			get_permalink( $post_id )
		);
		$this->go_to( $request_url );

		$result = Newsletters_Access::handle_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'invalid', $result['action'] );
	}

	/**
	 * Test that handle_utm_fallback_request() returns 'invalid' when the current
	 * URL does not appear in any newsletter sent to the given list.
	 */
	public function test_utm_fallback_rejects_when_url_not_in_any_newsletter() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );

		$post_id           = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$unrelated_post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );
		// The newsletter linked to a different post; readers can't extrapolate.
		$this->create_sent_newsletter_with_link( 'list_abc', get_permalink( $unrelated_post_id ) );

		$request_url = add_query_arg(
			[
				'utm_medium' => 'email',
				'utm_source' => 'list_abc',
			],
			get_permalink( $post_id )
		);
		$this->go_to( $request_url );

		add_filter(
			'newspack_newsletters_access_test_valid_list_ids',
			function() {
				return [ 'list_abc' ];
			}
		);

		$result = Newsletters_Access::handle_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'invalid', $result['action'] );
	}

	/**
	 * Test that handle_utm_fallback_request() returns 'disabled' when the
	 * newsletter link bypass setting is turned off.
	 */
	public function test_utm_fallback_skips_when_setting_disabled() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 0, false );

		$post_id     = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$request_url = add_query_arg(
			[
				'utm_medium' => 'email',
				'utm_source' => 'list_abc',
			],
			get_permalink( $post_id )
		);
		$this->create_sent_newsletter_with_link( 'list_abc', get_permalink( $post_id ) );
		$this->go_to( $request_url );

		$result = Newsletters_Access::handle_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'disabled', $result['action'] );
	}

	/**
	 * Test that handle_utm_fallback_request() returns 'skipped' for a logged-in
	 * editor, who bypasses the gate via capability checks.
	 */
	public function test_utm_fallback_skips_for_logged_in_editor() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		$editor = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$post_id     = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$request_url = add_query_arg(
			[
				'utm_medium' => 'email',
				'utm_source' => 'list_abc',
			],
			get_permalink( $post_id )
		);
		$this->create_sent_newsletter_with_link( 'list_abc', get_permalink( $post_id ) );
		$this->go_to( $request_url );

		$result = Newsletters_Access::handle_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		wp_set_current_user( 0 );
		$this->assertSame( 'skipped', $result['action'] );
	}

	/**
	 * Test that handle_utm_fallback_request() returns 'skipped' when
	 * utm_medium is absent or not 'email'.
	 */
	public function test_utm_fallback_skips_when_no_email_utm() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );

		$post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$this->go_to( get_permalink( $post_id ) );

		$result = Newsletters_Access::handle_utm_fallback_request( false );

		$this->assertSame( 'skipped', $result['action'] );
	}

	/**
	 * Regression: the URL matcher must use boundary characters so that one URL
	 * being a substring of another (e.g., `?p=5` inside `?p=599`, or
	 * `my-article/` inside `my-article-extended/`) doesn't cause a false-positive
	 * bypass. This test exercises a representative case via a crafted collision
	 * URL; the boundary check protects both numeric-prefix and slug-prefix forms
	 * through the same code path.
	 */
	public function test_utm_fallback_rejects_slug_prefix_collision() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );

		// Create two posts. In a plain-permalink environment their canonical
		// URLs are ?p=<id>, so two posts with IDs where one is a numeric prefix
		// of the other (e.g., 99 and 999) reproduce the false-positive.
		// We rely on the DB auto-increment to give us two distinct IDs; the
		// short-ID post is created first so its ID is numerically shorter.
		$short_post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );
		// Insert enough posts so the next ID is at least one digit longer,
		// guaranteeing a numeric prefix relationship without assuming IDs.
		// Instead, build the newsletter HTML by hand so we control the collision.
		$long_post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );

		$short_url = get_permalink( $short_post_id );
		$long_url  = get_permalink( $long_post_id );

		// Manually craft newsletter HTML that contains ONLY the long-post URL
		// followed by a quote boundary (as esc_url produces). The short URL
		// must not appear in the HTML at all — only its numeric prefix does,
		// embedded inside the longer URL string.
		//
		// We build the HTML so that $short_url stripped of its trailing slash
		// appears as a substring of $long_url (the false-positive condition).
		// Concretely: replace the long-post ?p=N with a value that starts with
		// the short-post ID, e.g., short_id=5 → long_id becomes "50" or we
		// just embed a crafted URL directly.
		$short_needle = untrailingslashit( $short_url );
		// Build a fake linked URL whose un-slashed form starts with $short_needle
		// but is longer — simulating ?p=5 vs ?p=50 or /slug vs /slug-extended/.
		$colliding_linked_url = $short_needle . '99/'; // e.g., ?p=599 if short_url ended in ?p=5.

		$newsletter_id = $this->factory->post->create( [ 'post_type' => 'newspack_nl_cpt' ] );
		update_post_meta( $newsletter_id, 'send_list_id', 'list_abc' );
		update_post_meta( $newsletter_id, 'newsletter_sent', time() );
		update_post_meta(
			$newsletter_id,
			'newspack_email_html',
			'<html><body><a href="' . esc_url( $colliding_linked_url ) . '">link</a></body></html>'
		);

		// Reader visits the short-post URL with email UTMs.
		$request_url = add_query_arg(
			[
				'utm_medium' => 'email',
				'utm_source' => 'list_abc',
			],
			$short_url
		);
		$this->go_to( $request_url );

		add_filter(
			'newspack_newsletters_access_test_valid_list_ids',
			function() {
				return [ 'list_abc' ];
			}
		);

		$result = Newsletters_Access::handle_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'invalid', $result['action'], 'slug-prefix collision must not grant bypass' );
	}

	/**
	 * Test that filter_post_restricted() returns false when the site-wide bypass cookie is set.
	 */
	public function test_bypass_filter_returns_false_when_cookie_present() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_post_restricted( true, 123, 0 );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( $result );
	}

	/**
	 * Test that filter_post_restricted() preserves the input value when no bypass cookie is present.
	 */
	public function test_bypass_filter_preserves_value_when_cookie_absent() {
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( Newsletters_Access::filter_post_restricted( true, 123, 0 ) );
		$this->assertFalse( Newsletters_Access::filter_post_restricted( false, 123, 0 ) );
	}

	/**
	 * Test that is_cookie_set() correctly reads from the $_COOKIE superglobal.
	 */
	public function test_is_cookie_set_reads_cookie_superglobal() {
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( Newsletters_Access::is_cookie_set() );
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( Newsletters_Access::is_cookie_set() );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Test that filter_post_restricted() returns false when the single-post cookie matches the current post ID.
	 */
	public function test_bypass_filter_returns_false_for_matching_single_post_cookie() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = '42'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_post_restricted( true, 42, 0 );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( $result );
	}

	/**
	 * Test that filter_post_restricted() preserves the value when the single-post cookie is for a different post.
	 */
	public function test_bypass_filter_preserves_value_for_nonmatching_single_post_cookie() {
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = '42'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		// Reader has bypass for post 42, but is now viewing post 99 — stay gated.
		$result = Newsletters_Access::filter_post_restricted( true, 99, 0 );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( $result );
	}

	/**
	 * Test that filter_post_restricted() ignores a single-post cookie containing a non-numeric value.
	 */
	public function test_bypass_filter_ignores_garbage_single_post_cookie() {
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = 'not-an-id'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_post_restricted( true, 42, 0 );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( $result );
	}

	/**
	 * Test that filter_wc_memberships_is_post_public() returns true when the
	 * site-wide bypass cookie is present.
	 */
	public function test_wc_memberships_filter_returns_true_when_cookie_present() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_wc_memberships_is_post_public( false );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( $result );
	}

	/**
	 * Test that filter_wc_memberships_is_post_public() preserves the incoming
	 * value when no bypass cookie is present.
	 */
	public function test_wc_memberships_filter_preserves_value_when_cookie_absent() {
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		// Should leave whatever WC (or another filter) already decided.
		$this->assertFalse( Newsletters_Access::filter_wc_memberships_is_post_public( false ) );
		$this->assertTrue( Newsletters_Access::filter_wc_memberships_is_post_public( true ) );
	}

	/**
	 * Test that filter_wc_memberships_is_post_public() short-circuits when the
	 * setting is disabled, returning the unchanged input value.
	 */
	public function test_wc_memberships_filter_short_circuits_when_setting_disabled() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 0, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_wc_memberships_is_post_public( false );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( $result );
	}

	/**
	 * Test that filter_wc_memberships_is_post_public() returns true when the
	 * single-post bypass cookie matches the currently queried post.
	 */
	public function test_wc_memberships_filter_returns_true_for_matching_single_post_cookie() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = (string) $post_id; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_wc_memberships_is_post_public( false );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( $result );
	}

	/**
	 * Test that filter_wc_memberships_is_post_public() preserves the incoming
	 * value when the single-post bypass cookie is for a different post.
	 */
	public function test_wc_memberships_filter_preserves_value_for_nonmatching_single_post_cookie() {
		$cookie_post  = $this->factory->post->create();
		$viewing_post = $this->factory->post->create();
		$this->go_to( get_permalink( $viewing_post ) );
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = (string) $cookie_post; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_wc_memberships_is_post_public( false );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( $result );
	}

	/**
	 * When the site-wide bypass cookie is already set (from the signed path),
	 * the UTM fallback handler must short-circuit and not redundantly set the
	 * per-post cookie. The cache-defeat side effects still happen (verified by
	 * the existing batcache_cancel/nocache_headers tests via the always-runs
	 * branch), but validation + cookie set are skipped.
	 */
	public function test_utm_fallback_skips_when_site_wide_cookie_already_set() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );

		$post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$this->create_sent_newsletter_with_link( 'list_abc', get_permalink( $post_id ) );

		// Reader already has the site-wide cookie from the signed path.
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$_GET['utm_medium'] = 'email';
		$_GET['utm_source'] = 'list_abc';
		$this->go_to( get_permalink( $post_id ) . '?utm_medium=email&utm_source=list_abc' );

		add_filter(
			'newspack_newsletters_access_test_valid_list_ids',
			function() {
				return [ 'list_abc' ];
			}
		);

		// Clear the single-post cookie so we can detect whether the handler
		// would have set it.
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$result = Newsletters_Access::handle_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$this->assertSame( 'skipped', $result['action'], 'site-wide cookie should short-circuit UTM handler' );
	}

	/**
	 * Clean up the bypass-enabled option and the settings cache after every test
	 * so option state doesn't bleed between tests.
	 */
	public function tear_down() {
		delete_option( 'newspack_content_gate_newsletter_link_bypass_enabled' );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		parent::tear_down();
	}

	/**
	 * Test that handle_inbound_request() returns 'disabled' when the bypass
	 * setting is turned off, even with a valid token present.
	 */
	public function test_handle_inbound_short_circuits_when_setting_disabled() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 0, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$post_id = $this->create_newsletter( time() );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = Newsletters_Access::sign( $post_id );
		$result = Newsletters_Access::handle_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'disabled', $result['action'] );
	}

	/**
	 * Test that filter_post_restricted() short-circuits when the bypass setting
	 * is disabled, returning the unchanged input value even when a cookie is set.
	 */
	public function test_bypass_filter_short_circuits_when_setting_disabled() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 0, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_post_restricted( true, 123, 0 );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( $result );
	}

	/**
	 * Test that append_signature_to_link() signs newsletter links regardless of
	 * the bypass-enabled setting, so toggling the setting on later activates
	 * bypass for recently distributed campaigns.
	 */
	public function test_signing_happens_even_when_setting_disabled() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 0, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$post = $this->factory->post->create_and_get( [ 'post_type' => 'newspack_nl_cpt' ] );
		$url  = 'https://example.test/article/';
		$result = Newsletters_Access::append_signature_to_link( $url, $url, $post );
		$this->assertStringContainsString( 'npnl=', $result );
	}

	/**
	 * Test that handle_inbound_request() proceeds to verification when the
	 * bypass setting is enabled and a valid token is present.
	 */
	public function test_handle_inbound_proceeds_when_setting_enabled() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$post_id = $this->create_newsletter( time() );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = Newsletters_Access::sign( $post_id );
		$result = Newsletters_Access::handle_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'verified', $result['action'] );
	}

	/**
	 * Bug regression: the signed inbound handler must be registered with
	 * accepted_args=0 so WordPress's empty-string padding on do_action('init')
	 * doesn't pass a falsy value as $with_side_effects.
	 */
	public function test_init_action_registered_with_zero_accepted_args() {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['init']->callbacks[2] ?? [] as $cb ) {
			if ( is_array( $cb['function'] )
				&& $cb['function'][0] === Newsletters_Access::class
				&& $cb['function'][1] === 'handle_inbound_request'
			) {
				$found = true;
				$this->assertSame( 0, $cb['accepted_args'], 'handle_inbound_request must use accepted_args=0' );
			}
		}
		$this->assertTrue( $found, 'handle_inbound_request must be registered on init priority 2' );
	}

	/**
	 * Bug regression: set_bypass_cookie() must update $_COOKIE so filters that
	 * run later in the same request can see the bypass.
	 */
	public function test_set_bypass_cookie_updates_cookie_superglobal() {
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$method = new \ReflectionMethod( Newsletters_Access::class, 'set_bypass_cookie' );
		$method->setAccessible( true );
		$method->invoke( null );
		$this->assertSame( '1', $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Bug regression: set_single_post_bypass_cookie() must update $_COOKIE.
	 */
	public function test_set_single_post_bypass_cookie_updates_cookie_superglobal() {
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$method = new \ReflectionMethod( Newsletters_Access::class, 'set_single_post_bypass_cookie' );
		$method->setAccessible( true );
		$method->invoke( null, 42 );
		$this->assertSame( '42', $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}
}
