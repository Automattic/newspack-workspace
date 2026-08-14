<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class AfterSuccessUrlTest
 *
 * @package Newspack_Blocks
 */

/**
 * Tests where a reader can be sent after completing checkout.
 *
 * The destination arrives with the request, so a crafted checkout link can carry one just
 * as a block's own settings can. Publishers do legitimately point readers off-site after a
 * purchase, so the rule is not "same site only" — it is whatever this site has said it is
 * willing to redirect to.
 */
class AfterSuccessUrlTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Host a publisher has chosen to allow.
	 */
	const ALLOWED_HOST = 'thanks.example.test';

	/**
	 * Drop any allowlist a test installed.
	 */
	public function tear_down() {
		remove_all_filters( 'allowed_redirect_hosts' );
		parent::tear_down();
	}

	/**
	 * Destinations on this site are kept.
	 */
	public function test_keeps_destinations_on_this_site() {
		$home = home_url( '/thank-you/' );

		$this->assertSame( $home, \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $home ) );
	}

	/**
	 * A destination given as a path on this site is kept as-is.
	 */
	public function test_keeps_a_relative_destination() {
		$this->assertSame(
			'/thank-you/',
			\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '/thank-you/' )
		);
	}

	/**
	 * Destinations elsewhere are dropped unless the site has allowed them.
	 *
	 * @dataProvider off_site_destinations
	 *
	 * @param string $url The destination to test.
	 */
	public function test_drops_off_site_destinations( $url ) {
		$this->assertSame(
			'',
			\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url ),
			'A destination this site has not allowed was kept.'
		);
	}

	/**
	 * Destinations that must not survive.
	 *
	 * @return array[]
	 */
	public function off_site_destinations() {
		return [
			'another site'           => [ 'https://elsewhere.example.test/collect' ],
			'protocol relative'      => [ '//elsewhere.example.test/collect' ],
			'credentials in the url' => [ 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '@elsewhere.example.test/' ],
			'a script url'           => [ 'javascript:alert(1)' ], // phpcs:ignore
			'a data url'             => [ 'data:text/html,<b>hi</b>' ],
		];
	}

	/**
	 * A publisher can still send readers off-site by allowing that host.
	 *
	 * This is the escape hatch that keeps the block's "go to a custom URL" setting usable
	 * for destinations a publisher genuinely owns.
	 */
	public function test_keeps_a_destination_the_site_has_allowed() {
		add_filter(
			'allowed_redirect_hosts',
			function ( $hosts ) {
				$hosts[] = self::ALLOWED_HOST;
				return $hosts;
			}
		);

		$url = 'https://' . self::ALLOWED_HOST . '/thanks';

		$this->assertSame(
			$url,
			\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url ),
			'A destination the publisher allowed was dropped.'
		);
	}

	/**
	 * An empty destination stays empty rather than becoming this site's home page.
	 */
	public function test_keeps_an_empty_destination_empty() {
		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '' ) );
	}

	/**
	 * A refused destination is announced, so the silent case can be watched for.
	 */
	public function test_announces_a_refused_destination() {
		$seen = [];
		add_action(
			'newspack_blocks_modal_checkout_after_success_url_rejected',
			function ( $url ) use ( &$seen ) {
				$seen[] = $url;
			}
		);

		\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( 'https://elsewhere.example.test/collect' );
		\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( home_url( '/thanks/' ) );

		remove_all_actions( 'newspack_blocks_modal_checkout_after_success_url_rejected' );

		$this->assertSame(
			[ 'https://elsewhere.example.test/collect' ],
			$seen,
			'The refused destination was not announced, or an accepted one was.'
		);
	}

	/**
	 * Create a published post to render a destination against.
	 *
	 * @return int Post ID.
	 */
	private function published_post() {
		return self::factory()->post->create( [ 'post_status' => 'publish' ] );
	}

	/**
	 * A destination this site vouched for is honoured wherever it points.
	 *
	 * This is what lets a publisher send readers to a host they own without anyone adding
	 * that host to this site's allowlist in code.
	 */
	public function test_keeps_a_vouched_destination_off_site() {
		$url   = 'https://elsewhere.example.test/thanks';
		$token = \Newspack_Blocks\Modal_Checkout::get_after_success_token( $url, $this->published_post() );

		$this->assertNotEmpty( $token, 'No token was minted for a published post.' );
		$this->assertSame(
			$url,
			\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '', $token ),
			'A destination this site vouched for was refused.'
		);
	}

	/**
	 * The same destination without a token is still refused.
	 *
	 * A link can carry the destination; it cannot carry a token.
	 */
	public function test_drops_the_same_destination_without_a_token() {
		$url = 'https://elsewhere.example.test/thanks';

		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url ) );
		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url, 'not.atoken' ) );
	}

	/**
	 * Nothing is vouched for on a post that is not published.
	 *
	 * The block renderer is reachable over REST by anyone who can edit posts, and draft
	 * preview reaches it too, so minting has to depend on something a contributor cannot do.
	 *
	 * @dataProvider unpublished_statuses
	 *
	 * @param string $status Post status.
	 */
	public function test_does_not_vouch_from_an_unpublished_post( $status ) {
		$post_id = self::factory()->post->create( [ 'post_status' => $status ] );

		$this->assertSame(
			'',
			\Newspack_Blocks\Modal_Checkout::get_after_success_token( 'https://elsewhere.example.test/thanks', $post_id ),
			'A destination was vouched for from a post the reader cannot see.'
		);
	}

	/**
	 * Statuses a contributor can reach without publishing.
	 *
	 * @return array[]
	 */
	public function unpublished_statuses() {
		return [
			'a draft'          => [ 'draft' ],
			'a pending review' => [ 'pending' ],
			'a private post'   => [ 'private' ],
		];
	}

	/**
	 * Nothing is vouched for with no post at all, which is the REST render case.
	 */
	public function test_does_not_vouch_without_a_post() {
		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::get_after_success_token( 'https://elsewhere.example.test/thanks', 0 ) );
	}

	/**
	 * A token stops working once its post is no longer published.
	 */
	public function test_refuses_a_token_whose_post_was_unpublished() {
		$post_id = $this->published_post();
		$token   = \Newspack_Blocks\Modal_Checkout::get_after_success_token( 'https://elsewhere.example.test/thanks', $post_id );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);

		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '', $token ) );
	}

	/**
	 * A token is not this site's if any of it has been altered.
	 */
	public function test_refuses_a_tampered_token() {
		$post_id = $this->published_post();
		$token   = \Newspack_Blocks\Modal_Checkout::get_after_success_token( 'https://elsewhere.example.test/thanks', $post_id );

		list( $payload, $signature ) = explode( '.', $token, 2 );

		$other = \Newspack_Blocks\Modal_Checkout::get_after_success_token( 'https://attacker.example.test/collect', $post_id );
		list( $other_payload ) = explode( '.', $other, 2 );

		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '', $other_payload . '.' . $signature ), 'A payload was swapped under a valid signature.' );
		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '', $payload . '.' . strrev( $signature ) ), 'A tampered signature was accepted.' );
		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '', $payload ), 'A payload with no signature was accepted.' );
	}

	/**
	 * The destination survives the sanitising it actually meets in transit.
	 *
	 * This is the failure this design exists to prevent, so the test reproduces the real
	 * transforms rather than a stand-in. Modelling transit as `sanitize_url()` — the one
	 * transform the normaliser itself applies — cannot fail, and would report the broken
	 * shapes below as working.
	 *
	 * @dataProvider awkward_destinations
	 *
	 * @param string $url A destination that transit is known to alter.
	 */
	public function test_destination_survives_real_transit( $url ) {
		$token = \Newspack_Blocks\Modal_Checkout::get_after_success_token( $url, $this->published_post() );
		$this->assertNotEmpty( $token );

		$transits = [
			'FILTER_SANITIZE_URL (checkout entry)'  => filter_var( $token, FILTER_SANITIZE_URL ),
			'sanitize_text_field (params reader)'   => sanitize_text_field( $token ),
			'FULL_SPECIAL_CHARS (donations path)'   => filter_var( $token, FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
			'esc_attr round trip (hidden input)'    => html_entity_decode( esc_attr( $token ) ),
		];

		foreach ( $transits as $label => $in_transit ) {
			$this->assertSame(
				\Newspack_Blocks\Modal_Checkout::normalize_after_success_url( $url ),
				\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '', $in_transit ),
				'The destination did not survive: ' . $label
			);
		}
	}

	/**
	 * Destination shapes that transit is known to alter.
	 *
	 * Each of these breaks at least one of the transforms above when the destination itself
	 * is what travels.
	 *
	 * @return array[]
	 */
	public function awkward_destinations() {
		return [
			'two query params'    => [ 'https://elsewhere.example.test/thanks?utm_campaign=spring&utm_source=email' ],
			'an apostrophe'       => [ "https://elsewhere.example.test/thanks?utm_campaign=don't" ],
			'an encoded space'    => [ 'https://elsewhere.example.test/thank%20you/' ],
			'a non-ASCII path'    => [ 'https://elsewhere.example.test/gracias-señor/' ],
			'a fragment'          => [ 'https://elsewhere.example.test/thanks#supporters' ],
			'a port'              => [ 'https://elsewhere.example.test:8443/thanks' ],
			// The token alphabet is inert to all four transforms, so the shapes above exercise
			// one property rather than six. These two do vary the outcome: normalising a
			// protocol-relative destination has to keep the `//`, or the value read back is a
			// relative path rather than the destination that was vouched for.
			'protocol relative'   => [ '//ELSEWHERE.example.test/thanks' ],
			'protocol relative with a port' => [ '//ELSEWHERE.example.test:8443/thanks' ],
		];
	}

	/**
	 * Host normalisation touches the authority and nothing else.
	 */
	public function test_normalisation_lowercases_only_the_host() {
		// A literal `://` in the query, not `%3A//`: the encoded form never matched the
		// replacement this guards against, so it passed either way.
		$this->assertSame(
			'https://example.test/go?next=https://Example.test/x',
			\Newspack_Blocks\Modal_Checkout::normalize_after_success_url( 'https://Example.test/go?next=https://Example.test/x' ),
			'Host lowercasing reached into the query string.'
		);

		$this->assertStringContainsString(
			'@example.test',
			\Newspack_Blocks\Modal_Checkout::normalize_after_success_url( 'https://User@Example.test/' ),
			'A host behind userinfo was left capitalised.'
		);
	}

	/**
	 * Normalising twice gives the same string as normalising once.
	 *
	 * The token signs a normalised destination at mint and normalises again at read, so a
	 * normaliser that changes its own output cannot verify.
	 *
	 * @dataProvider awkward_destinations
	 *
	 * @param string $url The destination to normalise.
	 */
	public function test_normalisation_is_idempotent( $url ) {
		$once = \Newspack_Blocks\Modal_Checkout::normalize_after_success_url( $url );

		$this->assertSame(
			$once,
			\Newspack_Blocks\Modal_Checkout::normalize_after_success_url( $once ),
			'Normalising twice changed the destination.'
		);
	}

	/**
	 * A destination this site will not redirect to is announced, however it is shaped.
	 */
	public function test_announces_a_refused_protocol_relative_destination() {
		$seen = [];
		add_action(
			'newspack_blocks_modal_checkout_after_success_url_rejected',
			function ( $url ) use ( &$seen ) {
				$seen[] = $url;
			}
		);

		$result = \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '//ELSEWHERE.example.test/thanks' );

		remove_all_actions( 'newspack_blocks_modal_checkout_after_success_url_rejected' );

		$this->assertSame( '', $result, 'An off-site destination was kept.' );
		$this->assertNotEmpty( $seen, 'A refused destination slipped past the hook that reports refusals.' );
	}

	/**
	 * A vouched destination is not announced as refused.
	 */
	public function test_does_not_announce_a_vouched_destination() {
		$seen = [];
		add_action(
			'newspack_blocks_modal_checkout_after_success_url_rejected',
			function ( $url ) use ( &$seen ) {
				$seen[] = $url;
			}
		);

		$token = \Newspack_Blocks\Modal_Checkout::get_after_success_token( 'https://elsewhere.example.test/thanks', $this->published_post() );
		\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( '', $token );

		remove_all_actions( 'newspack_blocks_modal_checkout_after_success_url_rejected' );

		$this->assertSame( [], $seen, 'A destination this site vouched for was reported as refused.' );
	}

	/**
	 * The checkout carries the token through with the destination it vouches for.
	 */
	public function test_checkout_carries_a_vouched_destination() {
		$url   = 'https://elsewhere.example.test/thanks';
		$token = \Newspack_Blocks\Modal_Checkout::get_after_success_token( $url, $this->published_post() );

		$_REQUEST['after_success_behavior'] = 'custom';
		$_REQUEST['after_success_url']      = $url;
		$_REQUEST['after_success_token']    = $token;

		$params = $this->get_after_success_params();

		unset( $_REQUEST['after_success_behavior'], $_REQUEST['after_success_url'], $_REQUEST['after_success_token'] );

		$this->assertSame( $url, $params['after_success_url'] ?? '', 'A vouched destination did not reach the page.' );
		$this->assertSame( 'custom', $params['after_success_behavior'] ?? '' );
		$this->assertNotEmpty( $params['after_success_token'] ?? '', 'The token was not carried onward.' );
	}

	/**
	 * Read the after-success params the checkout passes to the page.
	 *
	 * @return array
	 */
	private function get_after_success_params() {
		$method = new ReflectionMethod( '\Newspack_Blocks\Modal_Checkout', 'get_after_success_params' );
		$method->setAccessible( true );

		return $method->invoke( null );
	}

	/**
	 * The checkout applies the rule, not just the helper.
	 *
	 * Without this the suite would stay green if the call were dropped from the one place
	 * that decides what the page receives.
	 */
	public function test_checkout_drops_an_off_site_destination() {
		$_REQUEST['after_success_behavior'] = 'custom';
		$_REQUEST['after_success_url']      = 'https://elsewhere.example.test/collect';

		$params = $this->get_after_success_params();

		unset( $_REQUEST['after_success_behavior'], $_REQUEST['after_success_url'] );

		$this->assertArrayNotHasKey( 'after_success_url', $params, 'An off-site destination reached the page.' );
		$this->assertArrayNotHasKey(
			'after_success_behavior',
			$params,
			'A dropped destination left the reader with a custom behavior and nowhere to go.'
		);
	}

	/**
	 * The site's own host typed in capitals is still the site's own host.
	 */
	public function test_keeps_a_destination_whose_host_is_capitalised() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url  = 'https://' . strtoupper( $host ) . '/thank-you/';

		$this->assertNotSame(
			'',
			\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url ),
			'The site\'s own host was treated as somewhere else because of its case.'
		);
	}

	/**
	 * A behavior the reader can't act on is dropped along with its label.
	 *
	 * Leaving the behavior in place renders a modal that neither navigates nor closes;
	 * leaving the label in place labels the close button for a page nobody visits.
	 *
	 * @dataProvider unusable_behaviors
	 *
	 * @param string $behavior The requested behavior.
	 * @param string $url      The requested destination.
	 */
	public function test_checkout_drops_a_behavior_the_reader_cannot_act_on( $behavior, $url ) {
		$_REQUEST['after_success_behavior']     = $behavior;
		$_REQUEST['after_success_url']          = $url;
		$_REQUEST['after_success_button_label'] = 'Read the member guide';

		$params = $this->get_after_success_params();

		unset( $_REQUEST['after_success_behavior'], $_REQUEST['after_success_url'], $_REQUEST['after_success_button_label'] );

		$this->assertArrayNotHasKey( 'after_success_behavior', $params );
		$this->assertArrayNotHasKey( 'after_success_url', $params );
		$this->assertArrayNotHasKey(
			'after_success_button_label',
			$params,
			'The close button kept a label naming a destination the reader never reaches.'
		);
	}

	/**
	 * Behaviors that leave the reader with nowhere to go.
	 *
	 * @return array[]
	 */
	public function unusable_behaviors() {
		return [
			'a destination this site refuses' => [ 'custom', 'https://elsewhere.example.test/collect' ],
			'a custom behavior with no url'   => [ 'custom', '' ],
			'an unknown behavior'             => [ 'somewhere', '/thank-you/' ],
		];
	}

	/**
	 * The referrer behavior has somewhere to go without a destination of its own.
	 */
	public function test_checkout_keeps_the_referrer_behavior() {
		$_REQUEST['after_success_behavior'] = 'referrer';

		$params = $this->get_after_success_params();

		unset( $_REQUEST['after_success_behavior'] );

		$this->assertSame( 'referrer', $params['after_success_behavior'] ?? '' );
	}

	/**
	 * A destination on this site still reaches the page.
	 */
	public function test_checkout_keeps_a_destination_on_this_site() {
		$_REQUEST['after_success_behavior'] = 'custom';
		$_REQUEST['after_success_url']      = home_url( '/thank-you/' );

		$params = $this->get_after_success_params();

		unset( $_REQUEST['after_success_behavior'], $_REQUEST['after_success_url'] );

		$this->assertSame( home_url( '/thank-you/' ), $params['after_success_url'] ?? '' );
		$this->assertSame( 'custom', $params['after_success_behavior'] ?? '' );
	}
}
