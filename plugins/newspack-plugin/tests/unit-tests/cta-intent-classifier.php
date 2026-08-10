<?php
/**
 * Tests the CTA_Intent_Classifier.
 *
 * Covers the annotation surface added by NPPD-1887. The classification rules
 * themselves (NPPD-1836/1837) are exercised end-to-end by the newspack-popups
 * suite, which loads this class from the sibling checkout; what matters here is
 * the attribute stamping, because that IS the attribution decision — a wrongly
 * stamped anchor credits a gate for a conversion it did not cause.
 *
 * @package Newspack\Tests
 */

use Newspack\CTA_Intent_Classifier;

/**
 * Test CTA_Intent_Classifier.
 */
class Newspack_Test_CTA_Intent_Classifier extends WP_UnitTestCase {

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		CTA_Intent_Classifier::reset_cache();
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::tear_down();
		CTA_Intent_Classifier::reset_cache();
	}

	/**
	 * Rendered core/button markup pointing at $href.
	 *
	 * @param string $href The button target.
	 * @return string
	 */
	private function button_html( $href ) {
		return sprintf(
			'<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%s">Act now</a></div></div>',
			esc_url( $href )
		);
	}

	/**
	 * A subscription-intent button anchor is stamped with intent and source.
	 */
	public function test_subscription_button_is_stamped() {
		$html = CTA_Intent_Classifier::annotate_button_anchors( $this->button_html( 'https://example.org/subscribe/' ) );
		$this->assertStringContainsString( 'data-newspack-cta="subscription"', $html );
		$this->assertStringContainsString( 'data-newspack-cta-source="pattern"', $html );
	}

	/**
	 * A donation-intent button anchor is stamped.
	 */
	public function test_donation_button_is_stamped() {
		$html = CTA_Intent_Classifier::annotate_button_anchors( $this->button_html( 'https://fundjournalism.org/donate/' ) );
		$this->assertStringContainsString( 'data-newspack-cta="donation"', $html );
		$this->assertStringContainsString( 'data-newspack-cta-source="processor"', $html );
	}

	/**
	 * The whole point of per-anchor classification: an editorial link inside a gate is
	 * NOT stamped, so clicking it can never persist attribution. A live probe found a
	 * publisher whose block-less prompts were almost entirely article links — blanket
	 * attribution on CTA clicks would have credited subscriptions to traffic reports.
	 */
	public function test_editorial_button_is_not_stamped() {
		$html = CTA_Intent_Classifier::annotate_button_anchors( $this->button_html( 'https://example.org/2026/06/12/some-long-slug/story' ) );
		$this->assertStringNotContainsString( 'data-newspack-cta', $html );
	}

	/**
	 * Non-conversion tells (event) are classified but never stamped.
	 */
	public function test_event_button_is_not_stamped() {
		$html = CTA_Intent_Classifier::annotate_button_anchors( $this->button_html( 'https://www.eventbrite.com/e/our-gala-123' ) );
		$this->assertStringNotContainsString( 'data-newspack-cta', $html );
	}

	/**
	 * Newsletter is a conversion intent, but out of the v1 PAID_INTENTS scope: a
	 * newsletter signup produces no Woo order, so there is nothing to attribute.
	 */
	public function test_newsletter_button_is_not_stamped_by_default() {
		$html = CTA_Intent_Classifier::annotate_button_anchors( $this->button_html( 'https://example.org/newsletter/' ) );
		$this->assertStringNotContainsString( 'data-newspack-cta', $html );

		// ...but it is stamped when explicitly requested.
		$html = CTA_Intent_Classifier::annotate_button_anchors(
			$this->button_html( 'https://example.org/newsletter/' ),
			[ 'newsletter' ]
		);
		$this->assertStringContainsString( 'data-newspack-cta="newsletter"', $html );
	}

	/**
	 * Body-copy links are out of scope even when their href would classify. Only
	 * `wp-block-button__link` anchors are candidates — the rendered counterpart of
	 * extract_button_hrefs()'s core/button-only scope.
	 */
	public function test_body_copy_link_is_not_stamped() {
		$html = CTA_Intent_Classifier::annotate_button_anchors( '<p>Please <a href="https://example.org/subscribe/">subscribe</a> today.</p>' );
		$this->assertStringNotContainsString( 'data-newspack-cta', $html );
	}

	/**
	 * Per-anchor, not per-container: a gate mixing a paid CTA with an editorial link
	 * stamps only the former. classify_content() would abstain or collapse here.
	 */
	public function test_mixed_buttons_stamp_only_the_paid_one() {
		$html  = $this->button_html( 'https://example.org/2026/06/12/some-long-slug/story' );
		$html .= $this->button_html( 'https://example.org/subscribe/' );
		$out   = CTA_Intent_Classifier::annotate_button_anchors( $html );

		$this->assertSame( 1, substr_count( $out, 'data-newspack-cta="subscription"' ) );
		// Exactly one intent attribute overall ('data-newspack-cta=' does not match the
		// longer 'data-newspack-cta-source='), i.e. the editorial anchor stayed bare.
		$this->assertSame( 1, substr_count( $out, 'data-newspack-cta=' ) );
		$this->assertStringContainsString( 'some-long-slug', $out );
	}

	/**
	 * Two different paid intents in one gate both get their own anchor stamped —
	 * there is no single verdict to conflict over.
	 */
	public function test_two_paid_intents_both_stamped() {
		$html  = $this->button_html( 'https://donorbox.org/give' );
		$html .= $this->button_html( 'https://example.org/subscribe/' );
		$out   = CTA_Intent_Classifier::annotate_button_anchors( $html );

		$this->assertStringContainsString( 'data-newspack-cta="donation"', $out );
		$this->assertStringContainsString( 'data-newspack-cta="subscription"', $out );
	}

	/**
	 * Nothing classifies -> the HTML is returned byte-identical (no reserialization).
	 */
	public function test_unchanged_when_nothing_classifies() {
		$html = $this->button_html( 'https://example.org/?form=123' );
		$this->assertSame( $html, CTA_Intent_Classifier::annotate_button_anchors( $html ) );
	}

	/**
	 * An anchor with no href is skipped rather than fatally classified.
	 */
	public function test_hrefless_button_is_skipped() {
		$html = '<div class="wp-block-button"><a class="wp-block-button__link">No target</a></div>';
		$this->assertSame( $html, CTA_Intent_Classifier::annotate_button_anchors( $html ) );
	}

	/**
	 * Empty input is a no-op, not a fatal.
	 */
	public function test_empty_html_is_a_noop() {
		$this->assertSame( '', CTA_Intent_Classifier::annotate_button_anchors( '' ) );
	}

	/**
	 * Classification is case-insensitive on the href, as classify_href() expects
	 * lowercase input and annotate_button_anchors() is responsible for lowering it.
	 */
	public function test_uppercase_href_still_classifies() {
		$html = CTA_Intent_Classifier::annotate_button_anchors( $this->button_html( 'https://FUNDJOURNALISM.org/DONATE/' ) );
		$this->assertStringContainsString( 'data-newspack-cta="donation"', $html );
	}

	/**
	 * The site's configured donation page wins over pattern matching, and carries the
	 * higher-confidence source. Guards the option read (no wp_insert_post side effect).
	 */
	public function test_configured_donation_page_is_site_config() {
		$this->set_permalink_structure( '/%postname%/' );
		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_name'  => 'support-us',
				'post_title' => 'Support us',
			]
		);
		update_option( \Newspack\Donations::DONATION_PAGE_ID_OPTION, $page_id );
		CTA_Intent_Classifier::reset_cache();

		$html = CTA_Intent_Classifier::annotate_button_anchors( $this->button_html( get_permalink( $page_id ) ) );
		$this->assertStringContainsString( 'data-newspack-cta="donation"', $html );
		$this->assertStringContainsString( 'data-newspack-cta-source="site_config"', $html );

		delete_option( \Newspack\Donations::DONATION_PAGE_ID_OPTION );
		$this->set_permalink_structure( '' );
	}

	/**
	 * PAID_INTENTS is the v1 attribution scope and must not silently widen: adding an
	 * intent here starts writing order meta for it.
	 */
	public function test_paid_intents_scope_is_locked() {
		$this->assertSame( [ 'donation', 'subscription' ], CTA_Intent_Classifier::PAID_INTENTS );
	}

	/**
	 * The most common paywall-gate destinations classify as `donation`, not
	 * `subscription`, because classify_href() tests its donation pattern first and that
	 * pattern matches `member|membership|donor|contribute|/support`.
	 *
	 * This is why `gate.js` derives `gate_has_checkout_link` from ANY paid intent rather
	 * than from `[data-newspack-cta="subscription"]`. Narrowing it would give these gates
	 * `checkout_impressions = 0`, and Gates_Metric::get_paywall_conversion_direct() skips
	 * any gate with `checkout_impressions <= 0` — silently dropping the conversions
	 * NPPD-1887 exists to capture. Raised in review on workspace#571; declined with this
	 * test as the guard.
	 *
	 * If someone makes these classify as `subscription` (defensible!), the capability
	 * selector in gate.js can then be narrowed. Until then it must not be.
	 *
	 * @dataProvider paywall_destinations_that_read_as_donation
	 * @param string $href A URL a paywall gate would plausibly link to.
	 */
	public function test_common_paywall_destinations_classify_as_donation( $href ) {
		$hit = CTA_Intent_Classifier::classify_href( $href );
		$this->assertSame( 'donation', $hit['intent'], "Expected donation for $href" );
		$this->assertContains( $hit['intent'], CTA_Intent_Classifier::PAID_INTENTS, 'Must still be a paid intent, or the anchor is never stamped.' );
	}

	/**
	 * Paywall destinations whose hrefs read as "donation" to the classifier.
	 *
	 * @return array
	 */
	public function paywall_destinations_that_read_as_donation() {
		return [
			'membership'      => [ 'https://example.org/membership/' ],
			'become a member' => [ 'https://example.org/become-a-member/' ],
			'support'         => [ 'https://example.org/support/' ],
		];
	}

	/**
	 * A dated same-host article whose slug contains a conversion token abstains to
	 * editorial, NOT the paid intent — the strong dated-article guard (/YYYY/MM/) runs
	 * before the donation/subscription patterns. Without it,
	 * /2026/06/12/school-board-member-profile/ classifies as `donation` on the "member"
	 * token, and a click on that editorial button would credit a gate for a conversion
	 * the reader never made. (dkoo review, #575.)
	 *
	 * Counterpart to test_common_paywall_destinations_classify_as_donation: the guard is
	 * scoped to the dated prefix precisely so real conversion pages (/become-a-member/,
	 * no date) still win. The WP test home host is example.org, so these are same-host.
	 *
	 * @dataProvider dated_articles_with_conversion_tokens
	 * @param string $href A same-host dated article URL containing a conversion token.
	 */
	public function test_dated_article_with_token_abstains_to_editorial( $href ) {
		$hit = CTA_Intent_Classifier::classify_href( $href );
		$this->assertSame( 'editorial', $hit['intent'], "Expected editorial for $href" );
		$this->assertNotContains( $hit['intent'], CTA_Intent_Classifier::PAID_INTENTS, 'A dated article must never be a paid CTA.' );
	}

	/**
	 * Same-host dated articles whose slugs contain a conversion token.
	 *
	 * @return array
	 */
	public function dated_articles_with_conversion_tokens() {
		return [
			'member token'    => [ 'https://example.org/2026/06/12/school-board-member-profile/' ],
			'donate token'    => [ 'https://example.org/2026/06/12/how-to-donate-blood-locally/' ],
			'subscribe token' => [ 'https://example.org/2026/01/03/why-i-subscribe-to-print/' ],
		];
	}
}
