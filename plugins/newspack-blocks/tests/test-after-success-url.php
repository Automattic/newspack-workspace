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
	 * A relative destination is kept, and resolved against this site.
	 */
	public function test_keeps_a_relative_destination() {
		$this->assertStringContainsString(
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
}
