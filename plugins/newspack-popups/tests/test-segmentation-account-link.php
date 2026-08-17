<?php
/**
 * Tests for the account-ID param appended to newsletter links by
 * Newspack_Popups_Segmentation::append_account_param().
 *
 * @package Newspack_Popups
 */

// Stand-ins for the cross-plugin classes the handler guards on, plus a fake
// ESP provider; the popups test suite loads only newspack-popups.
require_once __DIR__ . '/mocks/class-newspack-newsletters.php';
require_once __DIR__ . '/mocks/class-utils.php';
require_once __DIR__ . '/mocks/class-metadata.php';
require_once __DIR__ . '/mocks/class-service-provider.php';

/**
 * Test appending the account param to newsletter links.
 */
class SegmentationAccountLinkTest extends WP_UnitTestCase {

	/**
	 * The stand-in provider.
	 *
	 * @var Newspack_Popups_Test_Service_Provider
	 */
	private $provider;

	/**
	 * Set up: a Mailchimp-syntax provider that knows the Account field's tag.
	 */
	public function set_up() {
		parent::set_up();
		$this->provider                     = new Newspack_Popups_Test_Service_Provider();
		$this->provider->tags               = [ 'NP_Account' => 'NP_ACCOUNT' ];
		Newspack_Newsletters::$provider     = $this->provider;
		\Newspack_Newsletters\Tracking\Utils::$syntax = '*|%s|*';
		\Newspack\Reader_Activation\Sync\Metadata::$keys = [ 'Account' => 'NP_Account' ];
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		Newspack_Newsletters::$provider = null;
		parent::tear_down();
	}

	/**
	 * Make a newsletter post.
	 *
	 * @param string $send_list_id Optional audience ID to store as post meta.
	 *
	 * @return WP_Post
	 */
	private function make_newsletter( $send_list_id = '' ) {
		$post = self::factory()->post->create_and_get(
			[ 'post_type' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ]
		);
		if ( '' !== $send_list_id ) {
			update_post_meta( $post->ID, 'send_list_id', $send_list_id );
		}
		return $post;
	}

	/**
	 * A first-party newsletter link gets the account merge tag, raw, so the ESP
	 * substitutes the recipient's account ID at send time.
	 */
	public function test_appends_raw_merge_tag_to_first_party_link() {
		$url    = home_url( '/some-article/' );
		$result = Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() );

		$args = wp_parse_args( wp_parse_url( $result, PHP_URL_QUERY ) );
		$this->assertArrayHasKey( 'np_account', $args );
		$this->assertSame( '*|NP_ACCOUNT|*', $args['np_account'] );

		// ESPs substitute only the literal syntax, never a percent-encoded form,
		// so the tag must appear raw in the URL for the ESP to resolve it.
		$this->assertStringContainsString( 'np_account=*|NP_ACCOUNT|*', $result );
		$this->assertStringNotContainsString( '%2A%7C', $result );
	}

	/**
	 * A query param appended after a URL fragment would become part of the
	 * fragment instead of a real query param — e.g. the unparseable
	 * `/post/#section?np_account=TAG` — so the tag must land before it.
	 */
	public function test_appends_before_url_fragment() {
		$base   = home_url( '/some-article/' );
		$url    = $base . '#section';
		$result = Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() );

		$this->assertSame( $base . '?np_account=*|NP_ACCOUNT|*#section', $result );
	}

	/**
	 * Regression test for the REAL newspack_newsletters_process_link chain, not
	 * a handler called standalone. append_donor_segment_param() is registered
	 * first (see the constructor) and restores its own tag raw; if
	 * append_account_param() ran that URL through add_query_arg(), WordPress's
	 * urlencode_deep() would re-encode the donor handler's already-raw tag right
	 * back into the unresolvable %2A%7C... form. Every other test in this file
	 * calls a single handler directly, which cannot catch this — the corruption
	 * only appears when both handlers actually run back-to-back through the
	 * filter, which is exactly what this test does.
	 */
	public function test_real_filter_chain_keeps_both_tags_raw() {
		update_option( 'newspack_popups_mc_donor_merge_field', 'HUB-MEMBER' );

		$url    = home_url( '/some-article/' );
		$result = apply_filters( 'newspack_newsletters_process_link', $url, $url, $this->make_newsletter() );

		$this->assertStringContainsString( 'np_seg_donor=*|HUB-MEMBER|*', $result );
		$this->assertStringContainsString( 'np_account=*|NP_ACCOUNT|*', $result );
	}

	/**
	 * ActiveCampaign's `%TAG%` syntax is emitted here, unlike np_seg_donor: the
	 * account param is always redirected away before any output, so an
	 * unsubstituted tag can never reach a consumer that decodes query params
	 * (NPPM-3032).
	 */
	public function test_appends_activecampaign_percent_syntax() {
		\Newspack_Newsletters\Tracking\Utils::$syntax = '%%%s%%';
		$url    = home_url( '/some-article/' );
		$result = Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() );
		$this->assertStringContainsString( 'np_account=%NP_ACCOUNT%', $result );
	}

	/**
	 * Relative links are first-party by definition.
	 */
	public function test_appends_to_relative_link() {
		$result = Newspack_Popups_Segmentation::append_account_param( '/some-article/', '/some-article/', $this->make_newsletter() );
		$this->assertStringContainsString( 'np_account=*|NP_ACCOUNT|*', $result );
	}

	/**
	 * Third-party links are untouched: the account ID must not leak into
	 * external logs or Referer headers.
	 */
	public function test_skips_external_link() {
		$url = 'https://example.com/elsewhere/';
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * Newsletter ads and other non-newsletter posts are proxied separately and
	 * would not forward the param.
	 */
	public function test_skips_non_newsletter_post() {
		$post = self::factory()->post->create_and_get( [ 'post_type' => 'post' ] );
		$url  = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $post )
		);
	}

	/**
	 * No tag for the field means the field isn't synced to this ESP, so there is
	 * nothing for the ESP to substitute. Emit nothing rather than a dead tag.
	 */
	public function test_skips_when_field_has_no_tag() {
		$this->provider->tags = [];
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * The legacy metadata schema keys the Account field as 'account'. Both
	 * schemas must resolve.
	 */
	public function test_resolves_legacy_metadata_raw_key() {
		\Newspack\Reader_Activation\Sync\Metadata::$keys = [ 'account' => 'NP_Account' ];
		$url    = home_url( '/some-article/' );
		$result = Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() );
		$this->assertStringContainsString( 'np_account=*|NP_ACCOUNT|*', $result );
	}

	/**
	 * Mailchimp merge-field tags are per-audience, so the newsletter's audience
	 * has to reach the resolver.
	 */
	public function test_passes_newsletter_send_list_to_the_resolver() {
		$url = home_url( '/some-article/' );
		Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter( 'abc123' ) );
		$this->assertSame( 'abc123', $this->provider->received_list_id );
	}

	/**
	 * A newsletter with no audience set passes null, letting providers whose
	 * fields are account-wide (ActiveCampaign) still resolve.
	 */
	public function test_passes_null_list_id_when_no_send_list() {
		$url = home_url( '/some-article/' );
		Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() );
		$this->assertNull( $this->provider->received_list_id );
	}

	/**
	 * A missing or version-skewed provider must not fatal mid-render — that
	 * would break every newsletter on the site.
	 */
	public function test_skips_when_provider_is_absent() {
		Newspack_Newsletters::$provider = null;
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}
}
