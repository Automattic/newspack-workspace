<?php
/**
 * Tests for the donor-status segment param appended to newsletter links by
 * Newspack_Popups_Segmentation::append_donor_segment_param().
 *
 * @package Newspack_Popups
 */

// Stand-ins for the newspack-newsletters classes the handler guards on; the
// popups test suite loads only newspack-popups.
require_once __DIR__ . '/mocks/class-newspack-newsletters.php';
require_once __DIR__ . '/mocks/class-utils.php';
require_once __DIR__ . '/mocks/class-segmentation-redirect-exception.php';

/**
 * Test appending segment params to newsletter links.
 */
class SegmentationNewsletterLinkTest extends WP_UnitTestCase {
	const DONOR_FIELD = 'HUB-MEMBER';

	/**
	 * Set up: configure a donor merge field and a Mailchimp-syntax provider.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'newspack_popups_mc_donor_merge_field', self::DONOR_FIELD );
		\Newspack_Newsletters\Tracking\Utils::$syntax = '*|%s|*';
	}

	/**
	 * Make a newsletter post.
	 *
	 * @return WP_Post
	 */
	private function make_newsletter() {
		return self::factory()->post->create_and_get(
			[ 'post_type' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ]
		);
	}

	/**
	 * A first-party link in a newsletter gets the donor merge tag appended,
	 * which the ESP substitutes per recipient at send time.
	 */
	public function test_appends_merge_tag_to_first_party_newsletter_link() {
		$url    = home_url( '/some-article/' );
		$result = Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $this->make_newsletter() );

		$args = wp_parse_args( wp_parse_url( $result, PHP_URL_QUERY ) );
		$this->assertArrayHasKey( 'np_seg_donor', $args );
		$this->assertSame( '*|' . self::DONOR_FIELD . '|*', $args['np_seg_donor'] );

		// The merge tag must appear RAW (unencoded) in the URL: ESPs substitute only
		// the literal *|FIELD|* syntax and leave the percent-encoded form (%2A%7C…)
		// untouched (verified against a live Mailchimp send). add_query_arg() encodes
		// by default, so this guards the str_replace that restores the raw tag.
		$this->assertStringContainsString( 'np_seg_donor=*|' . self::DONOR_FIELD . '|*', $result );
		$this->assertStringNotContainsString( '%2A%7C', $result );
	}

	/**
	 * Third-party links are left untouched so the donor flag never leaks into
	 * external logs / Referer headers.
	 */
	public function test_skips_external_link() {
		$url = 'https://example.com/elsewhere/';
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * With no donor merge field configured there's nothing to segment on.
	 */
	public function test_skips_when_no_donor_field_configured() {
		update_option( 'newspack_popups_mc_donor_merge_field', '' );
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * The feature is opt-in: a site that never configured the setting must not
	 * fall back to DEFAULT_DONOR_MERGE_FIELD. That default is a name fragment for
	 * the Mailchimp substring matching in reader_logged_in(), not a merge tag, so
	 * using it here decorated links on every unconfigured site with a tag no ESP
	 * resolves (NPPM-3032).
	 */
	public function test_skips_when_donor_field_option_absent() {
		delete_option( 'newspack_popups_mc_donor_merge_field' );
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * ActiveCampaign's `%FIELD%` syntax is a malformed percent-escape once it sits
	 * raw in a URL, so anything that percent-decodes query params throws on it —
	 * blanking the page under Jetpack Instant Search (NPPM-3032). Skip the param
	 * rather than emit a URL that can break the page the reader landed on.
	 */
	public function test_skips_provider_whose_tag_breaks_url_decoding() {
		\Newspack_Newsletters\Tracking\Utils::$syntax = '%%%s%%';
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * A percent escape that is hex-shaped is still not necessarily decodable:
	 * `decodeURIComponent( '%FF' )` throws because 0xFF is never valid UTF-8, and
	 * `%C0%AF` is an overlong encoding. Checking escape *shape* alone would let
	 * these through, so the guard rejects any `%` at all.
	 *
	 * @param string $field Donor merge field containing a percent sign.
	 *
	 * @dataProvider hex_shaped_field_provider
	 */
	public function test_skips_hex_shaped_but_undecodable_tag( $field ) {
		update_option( 'newspack_popups_mc_donor_merge_field', $field );
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * Field names whose percent escapes are well-formed but not valid UTF-8.
	 *
	 * @return array[]
	 */
	public function hex_shaped_field_provider() {
		return [
			'invalid utf-8 byte' => [ '%FF' ],
			'overlong encoding'  => [ '%C0%AF' ],
			'valid escape'       => [ '%20' ],
		];
	}

	/**
	 * Bracket-delimited providers (Constant Contact, Campaign Monitor) carry no
	 * percent sign, so they stay supported by the guard above.
	 */
	public function test_appends_for_bracket_syntax_provider() {
		\Newspack_Newsletters\Tracking\Utils::$syntax = '[[%s]]';
		$url    = home_url( '/some-article/' );
		$result = Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $this->make_newsletter() );

		$this->assertStringContainsString( 'np_seg_donor=[[' . self::DONOR_FIELD . ']]', $result );
	}

	/**
	 * Run the inbound scrub against a simulated request, capturing any redirect
	 * instead of letting the handler exit.
	 *
	 * @param string $request_uri Request URI, including query string.
	 * @param string $method      HTTP method.
	 *
	 * @return string|null Redirect target, or null when no redirect was issued.
	 */
	private function scrub( $request_uri, $method = 'GET' ) {
		$captured = null;
		$filter   = function ( $location ) use ( &$captured ) {
			$captured = $location;
			throw new Segmentation_Redirect_Exception();
		};

		// Simulating an inbound request, so populating the superglobals the handler
		// reads is the point of this helper.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$_SERVER['REQUEST_URI']    = $request_uri;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = [];
		$query                     = wp_parse_url( $request_uri, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $_GET );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter( 'wp_redirect', $filter );
		try {
			Newspack_Popups_Segmentation::scrub_unsubstituted_donor_param();
		} catch ( Segmentation_Redirect_Exception $e ) {
			unset( $e ); // Expected: stands in for the exit() after the redirect.
		} finally {
			remove_filter( 'wp_redirect', $filter );
			unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
			$_GET = [];
		}

		return $captured;
	}

	/**
	 * The live incident: an ActiveCampaign tag that the ESP never substituted is
	 * a malformed percent-escape, so it must be gone before anything decodes the
	 * query string. Every other param — notably the `npnl` newsletter pass — has
	 * to survive.
	 */
	public function test_scrub_strips_activecampaign_tag_and_keeps_other_params() {
		$this->assertSame(
			'/2026/07/20/some-post/?utm_medium=email&npnl=ABC123&utm_source=ActiveCampaign',
			$this->scrub( '/2026/07/20/some-post/?utm_medium=email&npnl=ABC123&np_seg_donor=%DONAT%&utm_source=ActiveCampaign' )
		);
	}

	/**
	 * ActiveCampaign tags whose field name begins with a hex pair (`%CAFE%`,
	 * `%ABCD%`, …) must be scrubbed too. PHP percent-decodes `$_GET` before the
	 * handler runs, so the leading `%XX` is consumed and the value no longer looks
	 * like a `%…%` tag — yet the raw URL still throws in `decodeURIComponent`, so
	 * it blanks the page exactly like `%DONAT%`. The detection therefore has to run
	 * against the raw query string, not the decoded `$_GET` (NPPM-3032).
	 *
	 * @param string $tag Raw merge tag as it arrives in the query string.
	 *
	 * @dataProvider hex_shaped_tag_provider
	 */
	public function test_scrub_strips_hex_shaped_activecampaign_tag( $tag ) {
		$this->assertSame( '/p/?a=1', $this->scrub( '/p/?a=1&np_seg_donor=' . $tag ) );
	}

	/**
	 * ActiveCampaign tags whose field name starts with two hex digits — the class
	 * PHP's `$_GET` decode would mangle before the check sees it.
	 *
	 * @return array[]
	 */
	public function hex_shaped_tag_provider() {
		return [
			'CAFE' => [ '%CAFE%' ],
			'ABCD' => [ '%ABCD%' ],
			'DEAD' => [ '%DEAD%' ],
			'12AB' => [ '%12AB%' ],
		];
	}

	/**
	 * Unsubstituted tags from the other supported ESPs are dropped too: they
	 * carry no donor signal either, and leaving them would keep junk in the URL.
	 *
	 * @param string $tag Raw merge tag as it arrives in the query string.
	 *
	 * @dataProvider unsubstituted_tag_provider
	 */
	public function test_scrub_strips_unsubstituted_tag_from_any_esp( $tag ) {
		$this->assertSame( '/p/?a=1', $this->scrub( '/p/?a=1&np_seg_donor=' . $tag ) );
	}

	/**
	 * Unsubstituted merge tags, one per supported ESP.
	 *
	 * @return array[]
	 */
	public function unsubstituted_tag_provider() {
		return [
			'mailchimp'        => [ '*|DONAT|*' ],
			'constant contact' => [ '[[DONAT]]' ],
			'active campaign'  => [ '%DONAT%' ],
			'campaign monitor' => [ '[DONAT]' ],
		];
	}

	/**
	 * A substituted value is the whole point of the param — it must reach the
	 * criteria script untouched.
	 *
	 * @param string $value Substituted donor value.
	 *
	 * @dataProvider substituted_value_provider
	 */
	public function test_scrub_leaves_substituted_value_alone( $value ) {
		$this->assertNull( $this->scrub( '/p/?np_seg_donor=' . $value ) );
	}

	/**
	 * Values an ESP actually substitutes, positive and negative.
	 *
	 * @return array[]
	 */
	public function substituted_value_provider() {
		return [
			'true'    => [ 'true' ],
			'monthly' => [ 'monthly' ],
			'amount'  => [ '50.00' ],
			'falsy'   => [ 'false' ],
		];
	}

	/**
	 * No param, nothing to do — the overwhelmingly common request.
	 */
	public function test_scrub_ignores_request_without_the_param() {
		$this->assertNull( $this->scrub( '/p/?utm_medium=email' ) );
	}

	/**
	 * Redirecting a POST would discard its body.
	 */
	public function test_scrub_ignores_non_get_requests() {
		$this->assertNull( $this->scrub( '/p/?np_seg_donor=%DONAT%', 'POST' ) );
	}

	/**
	 * The scrubbed URL must not itself trigger another scrub, or the redirect
	 * would loop.
	 */
	public function test_scrub_result_does_not_redirect_again() {
		$once = $this->scrub( '/p/?a=1&np_seg_donor=%DONAT%' );
		$this->assertSame( '/p/?a=1', $once );
		$this->assertNull( $this->scrub( $once ) );
	}

	/**
	 * Non-newsletter posts (e.g. newsletter ads, which are proxied separately)
	 * are not touched.
	 */
	public function test_skips_non_newsletter_post() {
		$post = self::factory()->post->create_and_get( [ 'post_type' => 'post' ] );
		$url  = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $post )
		);
	}

	/**
	 * Relative (host-less) links are first-party by definition and get the param.
	 */
	public function test_appends_to_relative_link() {
		$result = Newspack_Popups_Segmentation::append_donor_segment_param( '/some-article/', '/some-article/', $this->make_newsletter() );

		$args = wp_parse_args( wp_parse_url( $result, PHP_URL_QUERY ) );
		$this->assertArrayHasKey( 'np_seg_donor', $args );
		$this->assertSame( '*|' . self::DONOR_FIELD . '|*', $args['np_seg_donor'] );
	}

	/**
	 * The donor-merge-field setting is a comma-delimited substring list (used for
	 * login matching); a query-param merge tag needs a single exact tag, so only
	 * the first entry is used and surrounding whitespace is trimmed.
	 */
	public function test_uses_first_entry_of_comma_delimited_field() {
		update_option( 'newspack_popups_mc_donor_merge_field', ' HUB-MEMBER , MEMBER ' );
		$url    = home_url( '/some-article/' );
		$result = Newspack_Popups_Segmentation::append_donor_segment_param( $url, $url, $this->make_newsletter() );

		$args = wp_parse_args( wp_parse_url( $result, PHP_URL_QUERY ) );
		$this->assertSame( '*|HUB-MEMBER|*', $args['np_seg_donor'] );
	}
}
