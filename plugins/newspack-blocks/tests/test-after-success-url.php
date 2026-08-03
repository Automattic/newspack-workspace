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
			'newspack_blocks_after_success_url_rejected',
			function ( $url ) use ( &$seen ) {
				$seen[] = $url;
			}
		);

		\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( 'https://elsewhere.example.test/collect' );
		\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( home_url( '/thanks/' ) );

		remove_all_actions( 'newspack_blocks_after_success_url_rejected' );

		$this->assertSame(
			[ 'https://elsewhere.example.test/collect' ],
			$seen,
			'The refused destination was not announced, or an accepted one was.'
		);
	}

	/**
	 * A destination this site signed is honoured wherever it points.
	 *
	 * This is what lets a publisher send readers to a host they own without anyone adding
	 * that host to this site's allowlist in code.
	 */
	public function test_keeps_a_signed_destination_off_site() {
		$url       = 'https://elsewhere.example.test/thanks';
		$signature = \Newspack_Blocks\Modal_Checkout::get_after_success_url_signature( $url );

		$this->assertSame(
			$url,
			\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url, $signature ),
			'A destination this site signed was refused.'
		);
	}

	/**
	 * The same destination without the signature is still refused.
	 *
	 * A link can carry the destination; it can't carry the signature.
	 */
	public function test_drops_the_same_destination_unsigned() {
		$url = 'https://elsewhere.example.test/thanks';

		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url ) );
		$this->assertSame( '', \Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url, 'not-a-signature' ) );
	}

	/**
	 * A signature belongs to the destination it was made for.
	 */
	public function test_does_not_let_a_signature_transfer_to_another_destination() {
		$signature = \Newspack_Blocks\Modal_Checkout::get_after_success_url_signature( 'https://elsewhere.example.test/thanks' );

		$this->assertSame(
			'',
			\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( 'https://attacker.example.test/collect', $signature ),
			'A signature made for one destination was accepted for another.'
		);
	}

	/**
	 * Signing survives the sanitising the value passes through in transit.
	 *
	 * The signature covers an exact string, and the destination is sanitised between the
	 * block that emits it and the page that checks it. If the two sides ever normalise
	 * differently, a publisher's own destination starts failing closed.
	 *
	 * @dataProvider awkward_destinations
	 *
	 * @param string $url A destination that sanitising might alter.
	 */
	public function test_signing_survives_sanitising( $url ) {
		$signature = \Newspack_Blocks\Modal_Checkout::get_after_success_url_signature( $url );

		// What the page receives has been through sanitising on the way.
		$in_transit = sanitize_url( $url );

		$this->assertNotSame(
			'',
			\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $in_transit, $signature ),
			'A signed destination stopped verifying after sanitising.'
		);
	}

	/**
	 * Destinations whose exact string sanitising may not leave alone.
	 *
	 * @return array[]
	 */
	public function awkward_destinations() {
		return [
			'a query string'      => [ 'https://elsewhere.example.test/thanks?utm_campaign=spring&utm_source=email' ],
			'a fragment'          => [ 'https://elsewhere.example.test/thanks#supporters' ],
			'an encoded space'    => [ 'https://elsewhere.example.test/thank%20you/' ],
			'a capitalised host'  => [ 'https://ELSEWHERE.example.test/thanks' ],
			'a port'              => [ 'https://elsewhere.example.test:8443/thanks' ],
			'a trailing slash'    => [ 'https://elsewhere.example.test/thanks/' ],
		];
	}

	/**
	 * A signed destination is not announced as refused.
	 */
	public function test_does_not_announce_a_signed_destination() {
		$seen = [];
		add_action(
			'newspack_blocks_after_success_url_rejected',
			function ( $url ) use ( &$seen ) {
				$seen[] = $url;
			}
		);

		$url = 'https://elsewhere.example.test/thanks';
		\Newspack_Blocks\Modal_Checkout::sanitize_after_success_url( $url, \Newspack_Blocks\Modal_Checkout::get_after_success_url_signature( $url ) );

		remove_all_actions( 'newspack_blocks_after_success_url_rejected' );

		$this->assertSame( [], $seen, 'A destination this site signed was reported as refused.' );
	}

	/**
	 * The checkout carries the signature through with the destination it signs.
	 */
	public function test_checkout_carries_a_signed_destination() {
		$url = 'https://elsewhere.example.test/thanks';

		$_REQUEST['after_success_behavior']  = 'custom';
		$_REQUEST['after_success_url']       = $url;
		$_REQUEST['after_success_signature'] = \Newspack_Blocks\Modal_Checkout::get_after_success_url_signature( $url );

		$params = $this->get_after_success_params();

		unset( $_REQUEST['after_success_behavior'], $_REQUEST['after_success_url'], $_REQUEST['after_success_signature'] );

		$this->assertSame( $url, $params['after_success_url'] ?? '', 'A signed destination did not reach the page.' );
		$this->assertSame( 'custom', $params['after_success_behavior'] ?? '' );
		$this->assertNotEmpty( $params['after_success_signature'] ?? '', 'The signature was not carried onward with the destination.' );
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
