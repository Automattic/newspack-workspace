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
	 * Regression: verify() must handle base64url-encoded tokens whose
	 * length is not a multiple of 4 (i.e., would require padding "=" in
	 * strict-mode base64_decode). base64url_encode strips padding, so
	 * decoding has to re-pad before strict decode.
	 */
	public function test_verify_handles_unpadded_base64url_token() {
		$post_id = $this->create_newsletter( time() );
		// Iterate post IDs until we find one whose token's unpadded length
		// requires re-padding (length % 4 !== 0). Most do — the format
		// "<id>|<hex hmac>" plus base64 padding makes this common.
		$found_unpadded = false;
		for ( $i = 0; $i < 20; $i++ ) {
			$test_id = $post_id + $i;
			// Mark as sent.
			update_post_meta( $test_id, 'newsletter_sent', time() );
			$token = Newsletters_Access::sign( $test_id );
			if ( 0 !== strlen( $token ) % 4 ) {
				$found_unpadded = true;
				$result         = Newsletters_Access::verify( $token );
				$this->assertIsArray( $result, 'unpadded base64url token must decode and verify' );
				$this->assertSame( $test_id, $result['newsletter_id'] );
				break;
			}
		}
		$this->assertTrue( $found_unpadded, 'failed to find an unpadded token to test (unlikely)' );
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

		$url    = home_url( '/some-article/' );
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
		$url    = home_url( '/foo/' );
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
	 * Test that process_inbound_request() returns 'verified' with the newsletter_id
	 * when a valid npnl token is present in the query string.
	 */
	public function test_handle_inbound_returns_verified_when_token_valid() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$post_id = $this->create_newsletter( time() );
		$token   = Newsletters_Access::sign( $post_id );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = $token;
		$result = Newsletters_Access::process_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'verified', $result['action'] );
		$this->assertSame( $post_id, $result['newsletter_id'] );
	}

	/**
	 * Test that process_inbound_request() returns 'skipped' when no npnl param is present.
	 */
	public function test_handle_inbound_returns_skipped_when_no_param_present() {
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$result = Newsletters_Access::process_inbound_request( false );
		$this->assertSame( 'skipped', $result['action'] );
	}

	/**
	 * Test that process_inbound_request() returns 'invalid' for a garbage token.
	 */
	public function test_handle_inbound_returns_invalid_for_bad_token() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_GET[ Newsletters_Access::QUERY_PARAM ] = 'garbage';
		$result = Newsletters_Access::process_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'invalid', $result['action'] );
	}

	/**
	 * Test that process_inbound_request() returns 'invalid' for a cryptographically
	 * valid token whose newsletter has never been sent (no newsletter_sent meta).
	 */
	public function test_handle_inbound_returns_invalid_for_unsent_newsletter() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		// Cryptographically valid token, but the newsletter was never sent.
		$post_id = $this->create_newsletter( null );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = Newsletters_Access::sign( $post_id );
		$result = Newsletters_Access::process_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'invalid', $result['action'] );
	}

	/**
	 * Test that process_inbound_request() returns 'skipped' when the current user
	 * is a logged-in editor (who bypasses the gate via capability checks).
	 */
	public function test_handle_inbound_skips_for_logged_in_editor() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$editor = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		$post_id = $this->create_newsletter( time() );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = Newsletters_Access::sign( $post_id );
		$result = Newsletters_Access::process_inbound_request( false );
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
	 * Test that process_utm_fallback_request() grants a single-post bypass when
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
			'newspack_newsletters_access_is_valid_send_list_id',
			function( $valid, $list_id ) {
				return 'list_abc' === $list_id ? true : null;
			},
			10,
			2
		);

		$result = Newsletters_Access::process_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'verified', $result['action'] );
		$this->assertSame( $post_id, $result['post_id'] );
	}

	/**
	 * Test that process_utm_fallback_request() returns 'invalid' when utm_source
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

		$result = Newsletters_Access::process_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'invalid', $result['action'] );
	}

	/**
	 * Test that process_utm_fallback_request() returns 'invalid' when the current
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
			'newspack_newsletters_access_is_valid_send_list_id',
			function( $valid, $list_id ) {
				return 'list_abc' === $list_id ? true : null;
			},
			10,
			2
		);

		$result = Newsletters_Access::process_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'invalid', $result['action'] );
	}

	/**
	 * Test that process_utm_fallback_request() returns 'disabled' when the
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

		$result = Newsletters_Access::process_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'disabled', $result['action'] );
	}

	/**
	 * Test that process_utm_fallback_request() returns 'skipped' for a logged-in
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

		$result = Newsletters_Access::process_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		wp_set_current_user( 0 );
		$this->assertSame( 'skipped', $result['action'] );
	}

	/**
	 * Test that process_utm_fallback_request() returns 'skipped' when
	 * utm_medium is absent or not 'email'.
	 */
	public function test_utm_fallback_skips_when_no_email_utm() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );

		$post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$this->go_to( get_permalink( $post_id ) );

		$result = Newsletters_Access::process_utm_fallback_request( false );

		$this->assertSame( 'skipped', $result['action'] );
	}

	/**
	 * Regression: the URL matcher must use DOMDocument-parsed hrefs so that one
	 * URL being a substring of another (e.g., `?p=5` inside `?p=599`, or
	 * `my-article/` inside `my-article-extended/`) doesn't cause a false-positive
	 * bypass. This test exercises a representative case via a crafted collision
	 * URL; the href-extraction approach protects both numeric-prefix and
	 * slug-prefix forms through the same code path.
	 */
	public function test_utm_fallback_rejects_slug_prefix_collision() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );

		// Create the short-slug post the reader will visit. We don't need a
		// second real post — instead we craft a collision URL directly by
		// appending characters to the short URL, simulating real-world cases
		// like `?p=5` appearing as a substring of `?p=599`, or `/slug/`
		// appearing as a substring of `/slug-extended/`.
		$short_post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$short_url     = get_permalink( $short_post_id );
		$short_needle  = untrailingslashit( $short_url );

		// Build a fake linked URL whose un-slashed form starts with $short_needle
		// but is longer. The newsletter HTML below contains ONLY this colliding
		// URL — never $short_url itself — so a naive substring search would
		// false-positive and the boundary check must reject it.
		$colliding_linked_url = $short_needle . '99/';

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
			'newspack_newsletters_access_is_valid_send_list_id',
			function( $valid, $list_id ) {
				return 'list_abc' === $list_id ? true : null;
			},
			10,
			2
		);

		$result = Newsletters_Access::process_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		$this->assertSame( 'invalid', $result['action'], 'slug-prefix collision must not grant bypass' );
	}

	/**
	 * Build a signed cookie value the way Newsletters_Access does in production.
	 * Used by tests that need to seed $_COOKIE with a valid signed value.
	 *
	 * @param string   $payload Cookie payload ("1" or post ID as string).
	 * @param int|null $expiry  Unix timestamp at which the cookie expires.
	 *                          Defaults to 1 hour in the future.
	 *
	 * @return string
	 */
	private function build_signed_cookie_value( $payload, $expiry = null ) {
		if ( null === $expiry ) {
			$expiry = time() + HOUR_IN_SECONDS;
		}
		$body = $payload . '.' . $expiry;
		$hmac = hash_hmac( 'sha256', $body, wp_salt( Newsletters_Access::COOKIE_SALT_KEY ) );
		return $body . '|' . $hmac;
	}

	/**
	 * Test that filter_post_restricted() returns false when the site-wide bypass cookie is set.
	 */
	public function test_bypass_filter_returns_false_when_cookie_present() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = $this->build_signed_cookie_value( '1' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
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
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = $this->build_signed_cookie_value( '1' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( Newsletters_Access::is_cookie_set() );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Test that filter_post_restricted() returns false when the single-post cookie matches the current post ID.
	 */
	public function test_bypass_filter_returns_false_for_matching_single_post_cookie() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = $this->build_signed_cookie_value( '42' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$result = Newsletters_Access::filter_post_restricted( true, 42, 0 );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( $result );
	}

	/**
	 * Test that filter_post_restricted() preserves the value when the single-post cookie is for a different post.
	 */
	public function test_bypass_filter_preserves_value_for_nonmatching_single_post_cookie() {
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = $this->build_signed_cookie_value( '42' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
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
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = $this->build_signed_cookie_value( '1' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
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
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = $this->build_signed_cookie_value( '1' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
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
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = $this->build_signed_cookie_value( (string) $post_id ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
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
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = $this->build_signed_cookie_value( (string) $cookie_post ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
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
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = $this->build_signed_cookie_value( '1' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$_GET['utm_medium'] = 'email';
		$_GET['utm_source'] = 'list_abc';
		$this->go_to( get_permalink( $post_id ) . '?utm_medium=email&utm_source=list_abc' );

		add_filter(
			'newspack_newsletters_access_is_valid_send_list_id',
			function( $valid, $list_id ) {
				return 'list_abc' === $list_id ? true : null;
			},
			10,
			2
		);

		// Clear the single-post cookie so we can detect whether the handler
		// would have set it.
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$result = Newsletters_Access::process_utm_fallback_request( false );

		unset( $_GET['utm_medium'], $_GET['utm_source'] );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$this->assertSame( 'skipped', $result['action'], 'site-wide cookie should short-circuit UTM handler' );
	}

	/**
	 * Bug regression: filter_wc_memberships_is_post_public must use the
	 * $post_id arg dispatched by WC Memberships (not get_queried_object_id)
	 * so that single-post bypass is correctly scoped during cap checks,
	 * loop restrict_post, REST output, and widget queries that ask about
	 * a post unrelated to the main queried object.
	 */
	public function test_wc_memberships_filter_honors_post_id_arg_over_queried_object() {
		$cookie_post  = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$queried_post = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$other_post   = $this->factory->post->create( [ 'post_type' => 'post' ] );

		// Simulate being on the queried_post page in the main query.
		$this->go_to( get_permalink( $queried_post ) );

		// Reader has single-post bypass for $cookie_post.
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = $this->build_signed_cookie_value( (string) $cookie_post ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();

		// WC asks: is $cookie_post public? With the $post_id arg honored, YES.
		$this->assertTrue(
			Newsletters_Access::filter_wc_memberships_is_post_public( false, $cookie_post ),
			'WC dispatching with the cookie-scoped post must grant bypass regardless of the main query.'
		);

		// WC asks: is $other_post public? With the $post_id arg honored, NO.
		$this->assertFalse(
			Newsletters_Access::filter_wc_memberships_is_post_public( false, $other_post ),
			'WC dispatching with an unrelated post must NOT grant bypass even on a single-post-cookie page.'
		);

		// WC asks: is $queried_post public (the page being viewed)? NO, the cookie isn't for it.
		$this->assertFalse(
			Newsletters_Access::filter_wc_memberships_is_post_public( false, $queried_post ),
			'WC dispatching with the queried post must NOT grant bypass when the cookie is for a different post.'
		);

		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Bug regression: when WC dispatches the filter without a $post_id
	 * (some edge call sites), fall back to get_queried_object_id() so
	 * legacy "1-arg" behavior still works on a singular page.
	 */
	public function test_wc_memberships_filter_falls_back_to_queried_object_when_post_id_null() {
		$cookie_post = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$this->go_to( get_permalink( $cookie_post ) );

		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = $this->build_signed_cookie_value( (string) $cookie_post ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();

		$this->assertTrue( Newsletters_Access::filter_wc_memberships_is_post_public( false ) );
		$this->assertTrue( Newsletters_Access::filter_wc_memberships_is_post_public( false, null ) );

		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Bug regression: the wc_memberships_is_post_public hook must be
	 * registered with accepted_args = 2.
	 */
	public function test_wc_memberships_filter_registered_with_two_accepted_args() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		// Re-init with the option flipped on so the conditional hook registration takes effect.
		Newsletters_Access::init();

		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['wc_memberships_is_post_public']->callbacks[20] ?? [] as $cb ) {
			if ( is_array( $cb['function'] )
				&& $cb['function'][0] === Newsletters_Access::class
				&& $cb['function'][1] === 'filter_wc_memberships_is_post_public'
			) {
				$found = true;
				$this->assertSame( 2, $cb['accepted_args'], 'filter_wc_memberships_is_post_public must register accepted_args=2' );
			}
		}
		$this->assertTrue( $found, 'filter_wc_memberships_is_post_public must be registered on wc_memberships_is_post_public priority 20' );
	}

	/**
	 * Regression: filter_wc_memberships_is_post_public should not turn a
	 * `false` return from another callback into `true` simply by being
	 * registered. Without a bypass cookie or single-post match, the
	 * incoming value should pass through unchanged.
	 */
	public function test_wc_memberships_filter_passes_through_when_no_bypass() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		// Re-init to register the verification hooks under the conditional registration.
		Newsletters_Access::init();

		// No cookies set, no single-post cookie. Filter should be a pass-through.
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ], $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( Newsletters_Access::filter_wc_memberships_is_post_public( false, 123 ) );
		$this->assertTrue( Newsletters_Access::filter_wc_memberships_is_post_public( true, 123 ) );
	}

	/**
	 * Bug regression: append_signature_to_link() must NOT sign URLs that
	 * point to external domains. Appending the npnl token to a third-party
	 * URL would leak a replayable bypass credential into their server logs.
	 */
	public function test_append_signature_skips_external_urls() {
		$post = $this->factory->post->create_and_get(
			[
				'post_type' => 'newspack_nl_cpt',
			]
		);
		update_post_meta( $post->ID, 'newsletter_sent', time() );

		$external_url = 'https://nytimes.com/article/';
		$result       = Newsletters_Access::append_signature_to_link( $external_url, $external_url, $post );

		$this->assertSame( $external_url, $result, 'external URLs must not be signed' );
		$this->assertStringNotContainsString( 'npnl=', $result );
	}

	/**
	 * Bug regression: append_signature_to_link() must sign URLs whose
	 * host matches the site's home_url() host (first-party).
	 */
	public function test_append_signature_signs_first_party_urls() {
		$post = $this->factory->post->create_and_get(
			[
				'post_type' => 'newspack_nl_cpt',
			]
		);
		update_post_meta( $post->ID, 'newsletter_sent', time() );

		$home_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal_url = 'https://' . $home_host . '/some-article/';
		$result       = Newsletters_Access::append_signature_to_link( $internal_url, $internal_url, $post );

		$this->assertStringContainsString( 'npnl=', $result, 'first-party URLs must be signed' );
	}

	/**
	 * Bug regression: append_signature_to_link() must sign relative URLs
	 * (no host) since those resolve to the current site.
	 */
	public function test_append_signature_signs_relative_urls() {
		$post = $this->factory->post->create_and_get(
			[
				'post_type' => 'newspack_nl_cpt',
			]
		);
		update_post_meta( $post->ID, 'newsletter_sent', time() );

		$relative_url = '/some-article/';
		$result       = Newsletters_Access::append_signature_to_link( $relative_url, $relative_url, $post );

		$this->assertStringContainsString( 'npnl=', $result, 'relative URLs must be signed' );
	}

	/**
	 * Clean up the bypass-enabled option and the settings cache after every test
	 * so option state doesn't bleed between tests.
	 */
	public function tear_down() {
		delete_option( 'newspack_content_gate_newsletter_link_bypass_enabled' );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		// Remove any test-only list-validation filters so anonymous callbacks
		// added inside individual tests don't leak into subsequent tests.
		remove_all_filters( 'newspack_newsletters_access_is_valid_send_list_id' );

		// Flush the per-newsletter href object cache so entries from one test
		// don't bleed into the next.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( Newsletters_Access::HREFS_CACHE_GROUP );
		}

		// Reset the in-request UTM-verification memo.
		$memo_property = new \ReflectionProperty( Newsletters_Access::class, 'utm_verification_memo' );
		$memo_property->setAccessible( true );
		$memo_property->setValue( null, [] );

		// Belt-and-suspenders: clean superglobals so a failed assertion
		// in one test doesn't bleed into the next.
		unset(
			$_GET[ Newsletters_Access::QUERY_PARAM ],
			$_GET['utm_medium'],
			$_GET['utm_source']
		);
		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset(
			$_COOKIE[ Newsletters_Access::COOKIE_NAME ],
			$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ]
		);
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		// B3 transient cache cleanup — the 1-hour transient persists across
		// tests in object cache, so leftover cached newsletter IDs from one
		// test would silently grant 'verified' to another using the same
		// list_id.
		foreach ( [ 'list_abc', 'list_cache_test', 'list_populate_test', 'list_memo_test', 'fake_list_xyz' ] as $list_id ) {
			delete_transient( 'newspack_nl_access_list_' . md5( $list_id ) );
		}

		parent::tear_down();
	}

	/**
	 * Cache regression: find_recent_sent_newsletters_for_list must consult
	 * the 1-hour transient cache before running the meta_query. Once the
	 * transient is populated, subsequent calls return the cached IDs even
	 * if new newsletters were added in the meantime — which is the
	 * intended behavior (1-hour staleness for the UTM fallback path).
	 *
	 * Uses reflection to invoke the private method directly.
	 */
	public function test_find_recent_sent_newsletters_uses_transient_cache() {
		$reflection = new \ReflectionMethod( Newsletters_Access::class, 'find_recent_sent_newsletters_for_list' );
		$reflection->setAccessible( true );

		// Pre-populate the transient with a known sentinel value.
		$list_id   = 'list_cache_test';
		$cache_key = 'newspack_nl_access_list_' . md5( $list_id );
		set_transient( $cache_key, [ 99999 ], HOUR_IN_SECONDS );

		$result = $reflection->invoke( null, $list_id );

		// Should return the cached value, not query the DB (no posts exist for this list).
		$this->assertSame( [ 99999 ], $result, 'Transient cache must be consulted before the DB.' );

		delete_transient( $cache_key );
	}

	/**
	 * Cache regression: when no transient exists, the query runs and the
	 * result is stored in the transient for subsequent calls.
	 */
	public function test_find_recent_sent_newsletters_populates_transient_on_miss() {
		$list_id   = 'list_populate_test';
		$cache_key = 'newspack_nl_access_list_' . md5( $list_id );
		delete_transient( $cache_key );

		// Create a sent newsletter for this list.
		$newsletter_id = $this->factory->post->create( [ 'post_type' => 'newspack_nl_cpt' ] );
		update_post_meta( $newsletter_id, 'send_list_id', $list_id );
		update_post_meta( $newsletter_id, 'newsletter_sent', time() );

		$reflection = new \ReflectionMethod( Newsletters_Access::class, 'find_recent_sent_newsletters_for_list' );
		$reflection->setAccessible( true );
		$result = $reflection->invoke( null, $list_id );

		$this->assertContains( $newsletter_id, $result );

		// Transient should now be populated.
		$cached = get_transient( $cache_key );
		$this->assertIsArray( $cached );
		$this->assertContains( $newsletter_id, $cached );

		delete_transient( $cache_key );
	}

	/**
	 * Memo regression: find_matching_newsletter_for_url must memoize the
	 * (list_id, url) → matched_post decision so multiple restriction
	 * filter dispatches in the same request don't repeat the HTML scan.
	 */
	public function test_find_matching_newsletter_for_url_memoizes_result() {
		$list_id = 'list_memo_test';
		$post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );
		$url     = get_permalink( $post_id );
		$this->create_sent_newsletter_with_link( $list_id, $url );

		// First call: populates memo.
		$reflection = new \ReflectionMethod( Newsletters_Access::class, 'find_matching_newsletter_for_url' );
		$reflection->setAccessible( true );
		$first = $reflection->invoke( null, $list_id, $url );
		$this->assertNotNull( $first );

		// Inspect the memo property to confirm it was populated.
		$memo_property = new \ReflectionProperty( Newsletters_Access::class, 'utm_verification_memo' );
		$memo_property->setAccessible( true );
		$memo = $memo_property->getValue();
		$this->assertArrayHasKey( $list_id . '|' . $url, $memo );

		// Reset the memo and re-populate the transient with a sentinel value to prove
		// the memo (when populated) short-circuits before the candidate scan.
		$memo_property->setValue( null, [ $list_id . '|' . $url => 12345 ] );
		$second = $reflection->invoke( null, $list_id, $url );
		$this->assertSame( 12345, $second, 'Memo must short-circuit the candidate scan.' );

		// Cleanup.
		$memo_property->setValue( null, [] );
		delete_transient( 'newspack_nl_access_list_' . md5( $list_id ) );
	}

	/**
	 * Test that process_inbound_request() returns 'disabled' when the bypass
	 * setting is turned off, even with a valid token present.
	 */
	public function test_handle_inbound_short_circuits_when_setting_disabled() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 0, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$post_id = $this->create_newsletter( time() );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = Newsletters_Access::sign( $post_id );
		$result = Newsletters_Access::process_inbound_request( false );
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
	 * Cache-invalidation regression: get_linked_post_ids_for_newsletter must
	 * return a fresh result after newspack_email_html post meta is updated.
	 * If the cache isn't invalidated, a stale entry would grant bypass for
	 * links that are no longer in the newsletter's email HTML.
	 *
	 * Exercises the meta-update path (added/updated_post_meta hooks), which
	 * is the realistic mutation surface — sender-side code persists
	 * newsletter HTML via bare update_post_meta(), and update_post_meta
	 * does NOT fire clean_post_cache in WP core.
	 */
	public function test_email_html_cache_invalidates_on_newsletter_update() {
		// The cache-invalidation hooks only register when bypass is enabled,
		// so flip the toggle on and re-init like other tests in this class.
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		Newsletters_Access::init();

		$linked_post_id = $this->factory->post->create();
		$newsletter_id  = $this->factory->post->create( [ 'post_type' => 'newspack_nl_cpt' ] );
		update_post_meta( $newsletter_id, 'newspack_email_html', '<a href="' . esc_url( get_permalink( $linked_post_id ) ) . '">x</a>' );

		// Prime the cache via reflection on the private method.
		$method = new \ReflectionMethod( Newsletters_Access::class, 'get_linked_post_ids_for_newsletter' );
		$method->setAccessible( true );
		$primed = $method->invoke( null, $newsletter_id );
		$this->assertContains( $linked_post_id, $primed, 'Initial cache must contain the linked post ID.' );

		// Update the meta — the updated_post_meta hook on
		// 'newspack_email_html' should flush the cache automatically.
		$other_post_id = $this->factory->post->create();
		update_post_meta( $newsletter_id, 'newspack_email_html', '<a href="' . esc_url( get_permalink( $other_post_id ) ) . '">y</a>' );

		$refreshed = $method->invoke( null, $newsletter_id );
		$this->assertNotContains( $linked_post_id, $refreshed, 'Stale post ID must be absent after meta update.' );
		$this->assertContains( $other_post_id, $refreshed, 'Updated post ID must appear after meta update.' );
	}

	/**
	 * The meta-update invalidation hook must IGNORE post-meta changes on
	 * keys other than newspack_email_html — invalidating on every meta
	 * mutation would defeat the cache.
	 */
	public function test_email_html_cache_only_invalidates_on_relevant_meta_key() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		Newsletters_Access::init();

		$linked_post_id = $this->factory->post->create();
		$newsletter_id  = $this->factory->post->create( [ 'post_type' => 'newspack_nl_cpt' ] );
		update_post_meta( $newsletter_id, 'newspack_email_html', '<a href="' . esc_url( get_permalink( $linked_post_id ) ) . '">x</a>' );

		$method = new \ReflectionMethod( Newsletters_Access::class, 'get_linked_post_ids_for_newsletter' );
		$method->setAccessible( true );
		$method->invoke( null, $newsletter_id );

		// Mutate an unrelated meta key — should NOT invalidate the href cache.
		update_post_meta( $newsletter_id, 'unrelated_meta_key', 'something-else' );

		$still_cached = wp_cache_get( $newsletter_id, Newsletters_Access::HREFS_CACHE_GROUP );
		$this->assertIsArray( $still_cached, 'Cache entry must survive an unrelated meta update.' );
		$this->assertContains( $linked_post_id, $still_cached, 'Cached IDs must still include the original linked post.' );
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
		$url  = home_url( '/article/' );
		$result = Newsletters_Access::append_signature_to_link( $url, $url, $post );
		$this->assertStringContainsString( 'npnl=', $result );
	}

	/**
	 * Test that process_inbound_request() proceeds to verification when the
	 * bypass setting is enabled and a valid token is present.
	 */
	public function test_handle_inbound_proceeds_when_setting_enabled() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		$post_id = $this->create_newsletter( time() );
		$_GET[ Newsletters_Access::QUERY_PARAM ] = Newsletters_Access::sign( $post_id );
		$result = Newsletters_Access::process_inbound_request( false );
		unset( $_GET[ Newsletters_Access::QUERY_PARAM ] );
		$this->assertSame( 'verified', $result['action'] );
	}

	/**
	 * Verify the signed-path inbound action is registered with the thin
	 * wrapper that doesn't take args, so WP's empty-string padding on
	 * do_action('init') cannot break it.
	 */
	public function test_init_action_registered_with_thin_wrapper() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		// Re-init with the option flipped on so the conditional hook registration takes effect.
		Newsletters_Access::init();

		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['init']->callbacks[2] ?? [] as $cb ) {
			if ( is_array( $cb['function'] )
				&& $cb['function'][0] === Newsletters_Access::class
				&& $cb['function'][1] === 'handle_inbound_request_action'
			) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'handle_inbound_request_action must be registered on init priority 2' );
	}

	/**
	 * Verify the UTM-fallback action is registered with the thin wrapper.
	 */
	public function test_wp_action_registered_with_thin_wrapper() {
		update_option( 'newspack_content_gate_newsletter_link_bypass_enabled', 1, false );
		\Newspack\Content_Gate_Advanced_Settings::reset_cache();
		// Re-init with the option flipped on so the conditional hook registration takes effect.
		Newsletters_Access::init();

		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['wp']->callbacks[10] ?? [] as $cb ) {
			if ( is_array( $cb['function'] )
				&& $cb['function'][0] === Newsletters_Access::class
				&& $cb['function'][1] === 'handle_utm_fallback_request_action'
			) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'handle_utm_fallback_request_action must be registered on wp priority 10' );
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
		// Cookie must be a signed value that is_cookie_set() accepts.
		$this->assertArrayHasKey( Newsletters_Access::COOKIE_NAME, $_COOKIE ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( Newsletters_Access::is_cookie_set(), 'set_bypass_cookie must set a value that is_cookie_set() accepts' );
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
		// Cookie must be a signed value that get_single_post_bypass_id() can decode to 42.
		$this->assertSame( 42, Newsletters_Access::get_single_post_bypass_id(), 'set_single_post_bypass_cookie must set a value that get_single_post_bypass_id() returns as the post ID' );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Bug regression: a presence-only cookie value (no signature) must
	 * not be honored as authorization. Anyone who knows the cookie name
	 * could otherwise set "1" in DevTools and bypass the gate.
	 */
	public function test_is_cookie_set_rejects_unsigned_value() {
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( Newsletters_Access::is_cookie_set() );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Bug regression: a presence-only single-post cookie value (no
	 * signature) must not be honored as authorization.
	 */
	public function test_get_single_post_bypass_id_rejects_unsigned_value() {
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = '42'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertNull( Newsletters_Access::get_single_post_bypass_id() );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Bug regression: tampering the payload portion of a signed cookie
	 * value (without re-signing) must invalidate the cookie.
	 */
	public function test_is_cookie_set_rejects_tampered_value() {
		$valid                                      = $this->build_signed_cookie_value( '1' );
		list( $body, $hmac )                        = explode( '|', $valid );
		// Mutate the body but keep the original HMAC.
		$tampered                                   = '2.' . explode( '.', $body )[1] . '|' . $hmac;
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = $tampered; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( Newsletters_Access::is_cookie_set() );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Bug regression: an expired signed cookie (expiry in the past) must
	 * not be honored, even with a correct HMAC.
	 */
	public function test_is_cookie_set_rejects_expired_value() {
		$expired_value                              = $this->build_signed_cookie_value( '1', time() - 60 );
		$_COOKIE[ Newsletters_Access::COOKIE_NAME ] = $expired_value; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( Newsletters_Access::is_cookie_set() );
		unset( $_COOKIE[ Newsletters_Access::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Bug regression: a single-post cookie value signed with a different
	 * secret (or class's salt key) must be rejected.
	 */
	public function test_get_single_post_bypass_id_rejects_foreign_signature() {
		// Sign with the wrong salt key (mimicking a cookie minted by a
		// different feature or an attacker without our secret).
		$body                                                   = '42.' . ( time() + HOUR_IN_SECONDS );
		$wrong_hmac                                             = hash_hmac( 'sha256', $body, wp_salt( 'some_other_key' ) );
		$_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] = $body . '|' . $wrong_hmac; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertNull( Newsletters_Access::get_single_post_bypass_id() );
		unset( $_COOKIE[ Newsletters_Access::SINGLE_POST_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}
}
