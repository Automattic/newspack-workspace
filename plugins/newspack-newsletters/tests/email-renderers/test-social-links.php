<?php
/**
 * Class Social Links Block Renderer Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Social links block (core/social-links) email render tests.
 *
 * Key audit finding: vanilla WP emits inline `<svg>` icons that Gmail/Outlook strip,
 * leaving empty links — the package emits hosted PNG `<img>` icons instead. The
 * Newspack override adds inter-icon spacing and maps `justifyContent` to alignment.
 */
class Test_Social_Links extends WP_UnitTestCase {
	/**
	 * Boot the WC editor package so render_wc() can render newsletters.
	 */
	public function set_up() {
		parent::set_up();
		Editor_Bootstrap::init();
	}

	/**
	 * Render newsletter content through the WC engine.
	 *
	 * @param string $content Block markup for the newsletter body.
	 * @return string Rendered email HTML.
	 */
	private function render_newsletter( string $content ): string {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Social links test newsletter',
				'post_content' => $content,
			]
		);
		return Renderer_Controller::render_wc( get_post( $post_id ) );
	}

	/**
	 * Social links render as hosted PNG icons, not inline SVG — Gmail and Outlook strip inline SVG,
	 * leaving empty links. The package emits `<img>` PNG icons instead, which is correct for email.
	 */
	public function test_social_links_render_png_icons_not_svg() {
		$content = '<!-- wp:social-links --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://twitter.com/x","service":"twitter"} /--><!-- wp:social-link {"url":"https://facebook.com/x","service":"facebook"} /--></ul><!-- /wp:social-links -->';
		$html    = $this->render_newsletter( $content );

		$this->assertStringNotContainsString( '<svg', $html, 'Expected social icons to render as <img>, never stripped-by-email <svg>.' );
		$this->assertMatchesRegularExpression( '#<img[^>]+src="[^"]*/icons/twitter/twitter-white\.png"#', $html, 'Expected a hosted Twitter PNG icon.' );
		$this->assertMatchesRegularExpression( '#<img[^>]+src="[^"]*/icons/facebook/facebook-white\.png"#', $html, 'Expected a hosted Facebook PNG icon.' );
		// process_links() appends UTM params (parity with MJML), so hrefs carry the
		// destination plus query params rather than the bare URL.
		$this->assertMatchesRegularExpression( '#href="https://twitter\.com/x[^"]*"#', $html, 'Expected the Twitter link href to survive.' );
		$this->assertMatchesRegularExpression( '#href="https://facebook\.com/x[^"]*"#', $html, 'Expected the Facebook link href to survive.' );
	}

	/**
	 * Social links render service labels when showLabels is enabled. No override needed.
	 */
	public function test_social_links_render_labels_when_enabled() {
		$content = '<!-- wp:social-links {"showLabels":true} --><ul class="wp-block-social-links has-visible-labels"><!-- wp:social-link {"url":"https://twitter.com/x","service":"twitter","label":"Follow us"} /--></ul><!-- /wp:social-links -->';
		$html    = $this->render_newsletter( $content );
		$this->assertStringContainsString( 'Follow us', $html, 'Expected the custom social link label to render.' );
	}

	/**
	 * Social link icons are spaced apart — the package emits pills flush with no gaps, unlike the
	 * editor canvas; the Newspack override injects a horizontal margin on each pill.
	 */
	public function test_social_links_icons_are_spaced() {
		$content = '<!-- wp:social-links --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://twitter.com/x","service":"twitter"} /--><!-- wp:social-link {"url":"https://facebook.com/x","service":"facebook"} /--></ul><!-- /wp:social-links -->';
		$html    = $this->render_newsletter( $content );
		$this->assertMatchesRegularExpression(
			'/display:\s*inline-table;\s*float:\s*none;\s*margin-left:\s*6px;\s*margin-right:\s*6px;/',
			$html,
			'Expected each social icon pill to carry a horizontal margin so the icons are spaced apart.'
		);
	}

	/**
	 * A centered social-links row carries text-align:center — the override maps justifyContent to
	 * textAlign since the package only reads textAlign/align for alignment.
	 */
	public function test_social_links_center_justification_is_applied() {
		$content = '<!-- wp:social-links {"layout":{"type":"flex","justifyContent":"center"}} --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://twitter.com/x","service":"twitter"} /--></ul><!-- /wp:social-links -->';
		$html    = $this->render_newsletter( $content );
		$this->assertMatchesRegularExpression( '/text-align:\s*center/', $html, 'Expected the centered social row to carry text-align:center.' );
	}

	/**
	 * A right-justified social row carries text-align:right.
	 */
	public function test_social_links_right_justification_is_applied() {
		$content = '<!-- wp:social-links {"layout":{"type":"flex","justifyContent":"right"}} --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://twitter.com/x","service":"twitter"} /--></ul><!-- /wp:social-links -->';
		$html    = $this->render_newsletter( $content );
		$this->assertMatchesRegularExpression( '/text-align:\s*right/', $html, 'Expected the right-justified social row to carry text-align:right.' );
	}

	/**
	 * An explicit textAlign is not overridden by justifyContent — the override only
	 * maps justifyContent when textAlign is unset.
	 */
	public function test_social_links_explicit_textalign_wins_over_justify() {
		$content = '<!-- wp:social-links {"textAlign":"right","layout":{"type":"flex","justifyContent":"center"}} --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://twitter.com/x","service":"twitter"} /--></ul><!-- /wp:social-links -->';
		$html    = $this->render_newsletter( $content );
		$this->assertMatchesRegularExpression( '/text-align:\s*right/', $html, 'Expected the explicit textAlign=right to win over justifyContent=center.' );
	}
}
