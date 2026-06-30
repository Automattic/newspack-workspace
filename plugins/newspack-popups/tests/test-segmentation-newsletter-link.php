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

/**
 * Test appending segment params to newsletter links.
 */
class SegmentationNewsletterLinkTest extends WP_UnitTestCase {
	const DONOR_FIELD = 'HUB-MEMBER';

	/**
	 * Set up: configure a donor merge field.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'newspack_popups_mc_donor_merge_field', self::DONOR_FIELD );
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
