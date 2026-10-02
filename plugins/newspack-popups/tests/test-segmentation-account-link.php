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
require_once __DIR__ . '/mocks/class-integrations.php';
require_once __DIR__ . '/mocks/class-integration.php';

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
	 * The stand-in integration syncing reader data to the provider.
	 *
	 * @var Newspack_Popups_Test_Integration
	 */
	private $integration;

	/**
	 * Set up: a Mailchimp-syntax provider that knows the Account field's tag,
	 * and an integration syncing that field to it.
	 */
	public function set_up() {
		parent::set_up();
		$this->provider                     = new Newspack_Popups_Test_Service_Provider();
		$this->provider->tags               = [ 'NP_Account' => 'NP_ACCOUNT' ];
		Newspack_Newsletters::$provider     = $this->provider;
		\Newspack_Newsletters\Tracking\Utils::$syntax = '*|%s|*';
		\Newspack\Reader_Activation\Sync\Metadata::$keys = [ 'Account' => 'Account' ];
		$this->integration = new Newspack_Popups_Test_Integration();
		\Newspack\Reader_Activation\Integrations::$integrations = [ 'esp' => $this->integration ];

		// The handler keeps what it resolved for each newsletter for the request.
		$resolved_merge_tags = new ReflectionProperty( Newspack_Popups_Segmentation::class, 'account_merge_tags' );
		$resolved_merge_tags->setAccessible( true );
		$resolved_merge_tags->setValue( null, [] );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		Newspack_Newsletters::$provider = null;
		\Newspack\Reader_Activation\Integrations::$integrations = [];
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
	 * Runs the real newspack_newsletters_process_link chain: add_query_arg() in
	 * the account handler would re-encode the donor handler's already-raw tag
	 * into the unresolvable %2A%7C... form, which single-handler tests cannot
	 * catch.
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
		$this->provider->service          = 'active_campaign';
		$this->integration->provider_slug = 'active_campaign';
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
	 * Each integration owns the prefix of the fields it syncs, so the field to
	 * look up is named by the integration syncing to the newsletter provider.
	 */
	public function test_names_the_field_with_the_syncing_integrations_prefix() {
		$this->integration->prefix = 'CUSTOM_';
		$this->provider->tags      = [ 'CUSTOM_Account' => 'CUSTOM_ACCOUNT' ];
		$url    = home_url( '/some-article/' );
		$result = Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() );
		$this->assertStringContainsString( 'np_account=*|CUSTOM_ACCOUNT|*', $result );
	}

	/**
	 * A field left in the ESP by an earlier selection still has a tag there,
	 * but its values stopped updating when the field was turned off.
	 */
	public function test_skips_when_the_integration_does_not_sync_the_account_field() {
		$this->integration->enabled_fields = [ 'Registration Date' ];
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * An integration syncing to another platform says nothing about the fields
	 * of the ESP sending the newsletter.
	 */
	public function test_skips_when_no_integration_syncs_to_the_newsletter_provider() {
		$this->integration->provider_slug = 'active_campaign';
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * A site can run several integrations. Only the one syncing to the ESP that
	 * sends the newsletter names the field.
	 */
	public function test_picks_the_integration_syncing_to_the_newsletters_esp() {
		$activecampaign                = new Newspack_Popups_Test_Integration();
		$activecampaign->provider_slug = 'active_campaign';
		$activecampaign->prefix        = 'AC_';
		\Newspack\Reader_Activation\Integrations::$integrations = [
			'esp'            => $this->integration,
			'activecampaign' => $activecampaign,
		];
		$this->provider->service = 'active_campaign';
		$this->provider->tags    = [ 'AC_Account' => 'AC_ACCOUNT' ];
		\Newspack_Newsletters\Tracking\Utils::$syntax = '%%%s%%';

		$url    = home_url( '/some-article/' );
		$result = Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() );
		$this->assertStringContainsString( 'np_account=%AC_ACCOUNT%', $result );
	}

	/**
	 * Pausing an integration's outbound sync keeps its field selection, but
	 * the field's values stop updating.
	 */
	public function test_skips_when_the_integrations_outbound_sync_is_paused() {
		$this->integration->push_enabled = false;
		$url                             = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * Integrations are third-party code running inside newsletter rendering; one
	 * that throws must cost the parameter, not the newsletter.
	 */
	public function test_leaves_the_link_alone_when_an_integration_throws() {
		$throwing_integration = new class() extends Newspack_Popups_Test_Integration {
			/**
			 * Fail the way a misbehaving integration would.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function get_enabled_outgoing_fields() {
				throw new \RuntimeException( 'integration failure' );
			}
		};
		\Newspack\Reader_Activation\Integrations::$integrations = [ 'esp' => $throwing_integration ];
		$url = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * Resolving the tag can take a request to the ESP, and an unresponsive ESP
	 * holds each request until it times out. A newsletter pays that once, not
	 * once per link, whether or not a tag was found.
	 *
	 * @param array $tags Tag names the provider knows, keyed by field name.
	 *
	 * @dataProvider provider_tags_provider
	 */
	public function test_looks_the_tag_up_once_per_newsletter( $tags ) {
		$this->provider->tags = $tags;
		$newsletter           = $this->make_newsletter();
		foreach ( [ '/first-article/', '/second-article/' ] as $path ) {
			$url = home_url( $path );
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $newsletter );
		}
		$this->assertSame( 1, $this->provider->lookups );
	}

	/**
	 * A provider that knows the Account field's tag, and one that doesn't.
	 *
	 * @return array[]
	 */
	public function provider_tags_provider() {
		return [
			'tag found'    => [ [ 'NP_Account' => 'NP_ACCOUNT' ] ],
			'no tag found' => [ [] ],
		];
	}

	/**
	 * The tag name comes from the ESP and lands unescaped in the link, so one
	 * that could break out of the parameter or the attribute is not used.
	 *
	 * @param string $tag_name Tag name as the ESP returned it.
	 *
	 * @dataProvider unsafe_tag_name_provider
	 */
	public function test_skips_a_tag_name_that_is_unsafe_in_a_link( $tag_name ) {
		$this->provider->tags = [ 'NP_Account' => $tag_name ];
		$url                  = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * Tag names that would change the URL or the markup around it.
	 *
	 * @return array[]
	 */
	public function unsafe_tag_name_provider() {
		return [
			'adds a parameter'     => [ 'ACCOUNT&next=1' ],
			'starts a fragment'    => [ 'ACCOUNT#top' ],
			'closes the attribute' => [ 'ACCOUNT" onclick="x' ],
			'contains a space'     => [ 'NP ACCOUNT' ],
		];
	}

	/**
	 * The legacy metadata schema keys the Account field as 'account'. Both
	 * schemas must resolve.
	 */
	public function test_resolves_legacy_metadata_raw_key() {
		\Newspack\Reader_Activation\Sync\Metadata::$keys = [ 'account' => 'Account' ];
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
	 * A provider from a Newsletters version that can't resolve tag names must
	 * not fatal mid-render — that would break every newsletter on the site.
	 */
	public function test_skips_when_the_provider_cannot_resolve_tags() {
		Newspack_Newsletters::$provider = (object) [ 'service' => 'mailchimp' ];
		$url                            = home_url( '/some-article/' );
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
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

	/**
	 * A whole-URL merge-tag placeholder (e.g. Mailchimp's *|UNSUB|*) is host-less
	 * and reads as first-party, but decorating it breaks the expanded link — and
	 * unsubscribe links are required by law. They must pass through untouched.
	 *
	 * @param string $url Placeholder URL as the ESP filter hands it over.
	 *
	 * @dataProvider placeholder_url_provider
	 */
	public function test_skips_esp_placeholder_url( $url ) {
		$this->assertSame(
			$url,
			Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() )
		);
	}

	/**
	 * Whole-URL merge-tag placeholders: the Mailchimp links that carry real
	 * consequences, plus one shape per other supported ESP.
	 *
	 * @return array[]
	 */
	public function placeholder_url_provider() {
		return [
			'mailchimp unsubscribe'    => [ '*|UNSUB|*' ],
			'mailchimp update profile' => [ '*|UPDATE_PROFILE|*' ],
			'mailchimp forward'        => [ '*|FORWARD|*' ],
			'constant contact'         => [ '[[UNSUBSCRIBE]]' ],
			'active campaign'          => [ '%UNSUBSCRIBE%' ],
			'campaign monitor'         => [ '[unsubscribe]' ],
		];
	}

	/**
	 * A real URL that merely carries a merge tag in a query value is still a real
	 * URL. Only a whole-URL placeholder is skipped.
	 */
	public function test_still_decorates_url_carrying_a_merge_tag_value() {
		$url    = home_url( '/some-article/?utm_content=*|CAMPAIGN_UID|*' );
		$result = Newspack_Popups_Segmentation::append_account_param( $url, $url, $this->make_newsletter() );
		$this->assertStringContainsString( 'np_account=*|NP_ACCOUNT|*', $result );
	}
}
