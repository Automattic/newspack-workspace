<?php
/**
 * Class Core Block Fidelity Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Core Block Fidelity Test.
 *
 * Locks in the Batch C audit finding (NEWS-1904): core/list and core/site-title render correctly
 * without a Newspack override. core/quote has a Newspack override only to un-italic the cite element
 * (the package theme.json forces fontStyle:italic); all structural quote traits tested here are
 * still the package's responsibility.
 */
class Test_Core_Block_Fidelity extends WP_UnitTestCase {
	/**
	 * Boot the editor package and override registry once per test.
	 */
	public function set_up() {
		parent::set_up();
		Editor_Bootstrap::init();
	}

	/**
	 * Render a block-markup string through the WC engine on a newsletter CPT.
	 *
	 * @param string $content Block markup (serialized comments + HTML).
	 * @return string The rendered email HTML.
	 */
	private function render( string $content ): string {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Core block fidelity',
				'post_content' => $content,
			]
		);
		return Renderer_Controller::render_wc( get_post( $post_id ) );
	}

	/**
	 * An unordered list keeps its `<ul>`, items, and 40px left padding for bullet markers.
	 * The package List_Block renderer matches vanilla WP and is email-safe without an override.
	 */
	public function test_unordered_list_preserves_markers_and_items() {
		$content = '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item --><li>First item</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Second item</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->';

		$html = $this->render( $content );

		$this->assertStringContainsString( '<ul class="wp-block-list"', $html, 'Expected the unordered list tag to survive.' );
		$this->assertStringContainsString( 'padding: 0 0 0 40px', $html, 'Expected the left padding that renders list markers in email clients.' );
		$this->assertStringContainsString( 'First item', $html, 'Expected the first list item text.' );
		$this->assertStringContainsString( 'Second item', $html, 'Expected the second list item text.' );
	}

	/**
	 * An ordered list renders as `<ol>` — guards the ordered/unordered distinction surviving the WC render.
	 */
	public function test_ordered_list_renders_ol() {
		$content = '<!-- wp:list {"ordered":true} --><ol class="wp-block-list">'
			. '<!-- wp:list-item --><li>Step one</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Step two</li><!-- /wp:list-item -->'
			. '</ol><!-- /wp:list -->';

		$html = $this->render( $content );

		$this->assertStringContainsString( '<ol class="wp-block-list"', $html, 'Expected the ordered list to render as <ol>.' );
		$this->assertStringContainsString( 'Step one', $html, 'Expected the first ordered item text.' );
		$this->assertStringContainsString( 'Step two', $html, 'Expected the second ordered item text.' );
	}

	/**
	 * A nested list keeps both levels, each with its own marker padding — the likeliest place a
	 * list renderer would drop structure.
	 */
	public function test_nested_list_preserves_both_levels() {
		$content = '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item --><li>Parent'
			. '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item --><li>Child</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list --></li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->';

		$html = $this->render( $content );

		$this->assertSame( 2, substr_count( $html, '<ul class="wp-block-list"' ), 'Expected both the parent and child <ul> to render.' );
		$this->assertSame( 2, substr_count( $html, 'padding: 0 0 0 40px' ), 'Expected marker padding on both list levels.' );
		$this->assertStringContainsString( 'Parent', $html, 'Expected the parent item text.' );
		$this->assertStringContainsString( 'Child', $html, 'Expected the nested child item text.' );
	}

	/**
	 * A quote renders the email-block-quote table wrapper, the quoted paragraph, and the cite element.
	 * These structural traits are the package's responsibility; the Newspack override only fixes cite italic.
	 */
	public function test_quote_renders_wrapper_content_and_citation() {
		$content = '<!-- wp:quote --><blockquote class="wp-block-quote">'
			. '<!-- wp:paragraph --><p>Quoted text here.</p><!-- /wp:paragraph -->'
			. '<cite>A citation</cite></blockquote><!-- /wp:quote -->';

		$html = $this->render( $content );

		// Assert the main quote wrapper class specifically — `email-block-quote`
		// followed by a class boundary (space or quote). A bare substring check
		// would be satisfied by `email-block-quote-citation` alone, masking a
		// regression of the actual wrapper.
		$this->assertSame(
			1,
			preg_match( '/class="[^"]*\bemail-block-quote(\s|")/', $html ),
			'Expected the main email-block-quote wrapper class (not just the citation class).'
		);
		$this->assertStringContainsString( 'Quoted text here.', $html, 'Expected the quoted paragraph text.' );
		$this->assertStringContainsString( 'email-block-quote-citation', $html, 'Expected the citation wrapper.' );
		$this->assertStringContainsString( 'A citation', $html, 'Expected the citation text.' );
	}

	/**
	 * The dynamic site-title block resolves to the real blog name inside an h1.wp-block-site-title
	 * linked home — the WC engine routes it through the Text renderer.
	 */
	public function test_site_title_resolves_name_and_link() {
		$html = $this->render( '<!-- wp:site-title /-->' );

		$this->assertStringContainsString( 'wp-block-site-title', $html, 'Expected the site-title class to survive.' );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $html, 'Expected the dynamic block to resolve to the site name.' );
		$this->assertStringContainsString( 'rel="home"', $html, 'Expected the home link the site-title renders by default.' );
	}

	/**
	 * With level:2 the site-title renders `<h2>` — the WC engine respects the authored heading level.
	 */
	public function test_site_title_honors_heading_level() {
		$html = $this->render( '<!-- wp:site-title {"level":2} /-->' );

		$this->assertStringContainsString( '<h2 class="wp-block-site-title"', $html, 'Expected level:2 to render an <h2>.' );
		$this->assertStringNotContainsString( '<h1 class="wp-block-site-title"', $html, 'Expected no <h1> when level:2 is authored.' );
	}

	/**
	 * With isLink:false the site-title renders as plain text with no anchor, matching vanilla WP.
	 */
	public function test_site_title_honors_is_link_false() {
		$html = $this->render( '<!-- wp:site-title {"isLink":false} /-->' );

		$this->assertStringContainsString( 'wp-block-site-title', $html, 'Expected the site-title to still render.' );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $html, 'Expected the site name to still resolve.' );

		// Prove the title is genuinely unlinked: the site-title heading must contain
		// no anchor at all. Asserting only the absence of rel="home" would pass for
		// an <a> without that attribute. Isolate the heading and assert no <a> in it.
		$this->assertSame(
			1,
			preg_match( '/<h1[^>]*\bwp-block-site-title\b[^>]*>(.*?)<\/h1>/s', $html, $heading ),
			'Expected the site-title heading to be present.'
		);
		$this->assertStringNotContainsString( '<a', $heading[1], 'Expected no anchor tag inside the site-title heading when isLink is false.' );
	}
}
