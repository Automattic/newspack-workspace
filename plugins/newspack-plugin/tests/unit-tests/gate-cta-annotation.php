<?php
/**
 * Tests the no-double-count invariant behind gate CTA attribution (NPPD-1887).
 *
 * @package Newspack\Tests
 */

use Newspack\CTA_Intent_Classifier;

/**
 * Guards the invariant that makes landing-page attribution safe.
 */
class Newspack_Test_Gate_CTA_Annotation extends WP_UnitTestCase {

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		CTA_Intent_Classifier::reset_cache();
	}

	/**
	 * THE load-bearing invariant.
	 *
	 * Two attribution paths write the same order meta:
	 *   - in-gate  : the checkout button's own <form> carries a hidden gate_post_id
	 *   - off-gate : a CTA click persists the gate id, replayed on the landing page
	 *
	 * They must never both fire for one conversion. Today they can't, because the
	 * in-gate checkout button renders as `<button type="submit">` while the CTA click
	 * listener binds only to `<a data-newspack-cta>`. That is a property of
	 * newspack-blocks' markup, not a guarantee — if the checkout button ever becomes an
	 * anchor, a single in-gate checkout would both stamp the form AND persist a replay
	 * record, and the two paths would silently double-attribute.
	 *
	 * This test fails loudly if that markup changes. Read the NPPD-1887 issue before
	 * "fixing" it: the fix is to exclude checkout-button anchors from the click
	 * listener, not to delete the assertion.
	 */
	public function test_in_gate_checkout_button_is_not_an_anchor() {
		$view = dirname( NEWSPACK_PLUGIN_FILE ) . '/../newspack-blocks/src/blocks/checkout-button/view.php';
		if ( ! file_exists( $view ) ) {
			$this->markTestSkipped( 'newspack-blocks sibling checkout is not available.' );
		}
		// Reading a local sibling file in a test; not remote, nothing to cache.
		$source = file_get_contents( $view ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown

		$this->assertStringContainsString(
			'<button class="%1$s" style="%2$s" type="submit">%3$s</button>',
			$source,
			'The checkout button must render as <button>. An <a> would be picked up by the gate CTA click listener and double-attribute.'
		);
	}

	/**
	 * A gate whose only paywall path is a link gets its anchor stamped, which is what
	 * makes both the click listener and `gate_has_checkout_link` fire.
	 */
	public function test_link_only_gate_anchor_is_stamped() {
		$gate_html = '<div class="wp-block-buttons"><div class="wp-block-button">'
			. '<a class="wp-block-button__link" href="https://example.org/subscribe/">Subscribe</a>'
			. '</div></div>';

		$out = CTA_Intent_Classifier::annotate_button_anchors( $gate_html );
		$this->assertStringContainsString( 'data-newspack-cta="subscription"', $out );
	}

	/**
	 * The classifier is a pure function of the href: it never mutates the anchor's
	 * href, class, or text. A rewritten CTA target would silently break the reader's
	 * navigation, which matters far more than the attribution.
	 */
	public function test_annotation_preserves_href_and_content() {
		$href      = 'https://example.org/subscribe/?plan=annual&amp;ref=gate';
		$gate_html = sprintf(
			'<div class="wp-block-button"><a class="wp-block-button__link" href="%s">Subscribe now</a></div>',
			$href
		);

		$out = CTA_Intent_Classifier::annotate_button_anchors( $gate_html );
		$this->assertStringContainsString( 'href="' . $href . '"', $out );
		$this->assertStringContainsString( '>Subscribe now</a>', $out );
		$this->assertStringContainsString( 'wp-block-button__link', $out );
	}
}
