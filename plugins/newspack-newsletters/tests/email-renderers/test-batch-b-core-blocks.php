<?php
/**
 * Class Batch B Core Blocks Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Characterization tests for the Batch B core blocks (NEWS-1904).
 *
 * The audit found the WC email-editor package already renders `core/separator`,
 * `core/button` / `core/buttons`, and `core/social-links` in an email-correct
 * way, so Newspack ships no override for any of them. These tests lock in that
 * decision: they render each block through the WC engine and assert the
 * email-safe output the audit relied on, so a package regression that breaks one
 * of these blocks (and would justify adding an override) fails loudly here.
 *
 * The reference model is vanilla WordPress output, not legacy MJML. Where the
 * package diverges from vanilla WP it does so in email's favor — most notably
 * social links, where vanilla emits inline `<svg>` (stripped by Gmail/Outlook)
 * and the package emits hosted PNG `<img>` icons instead.
 */
class Test_Batch_B_Core_Blocks extends WP_UnitTestCase {
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
				'post_title'   => 'Batch B newsletter',
				'post_content' => $content,
			]
		);
		return Renderer_Controller::render_wc( get_post( $post_id ) );
	}

	/**
	 * A default separator renders as a plain rule, matching vanilla WP.
	 *
	 * Vanilla WP emits a bare `<hr class="wp-block-separator …">` with no inline
	 * border styling, and so does the WC engine — the package passes the `<hr>`
	 * through unchanged. No override is needed.
	 */
	public function test_separator_default_passes_through() {
		$html = $this->render_newsletter( '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->' );
		$this->assertStringContainsString( '<hr class="wp-block-separator', $html, 'Expected the separator <hr> to render with its block class.' );
	}

	/**
	 * A colored separator carries its color as inline styles, matching vanilla WP.
	 *
	 * The preset background/text color must be inlined on the `<hr>` so it shows
	 * in email clients (which ignore the class-based color stylesheet). The
	 * package already does this, so no override is needed.
	 */
	public function test_separator_color_is_inlined() {
		$content = '<!-- wp:separator {"backgroundColor":"vivid-red","className":"is-style-wide"} --><hr class="wp-block-separator has-text-color has-vivid-red-color has-alpha-channel-opacity has-vivid-red-background-color has-background is-style-wide"/><!-- /wp:separator -->';
		$html    = $this->render_newsletter( $content );
		$this->assertMatchesRegularExpression( '/<hr class="wp-block-separator[^"]*"[^>]*style="[^"]*background-color: ?#cf2e2e/', $html, 'Expected the separator background color to be inlined for email.' );
	}

	/**
	 * A button renders as an email-safe linked table cell, matching email needs.
	 *
	 * The package wraps the button in a table cell carrying the background color
	 * and renders the link with an explicit `target="_blank"` and inline color —
	 * the email-safe shape. The link text and href survive. No override is needed.
	 */
	public function test_button_renders_email_safe_link() {
		$content = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com">Click me</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		$html    = $this->render_newsletter( $content );
		$this->assertStringContainsString( 'href="https://example.com"', $html, 'Expected the button link href to survive.' );
		$this->assertStringContainsString( 'target="_blank"', $html, 'Expected the button link to open in a new tab.' );
		$this->assertStringContainsString( '>Click me</a>', $html, 'Expected the button label to render.' );
		$this->assertStringContainsString( 'class=" wp-block-button"', $html, 'Expected the button to render inside a wp-block-button table cell.' );
	}

	/**
	 * A button with a preset background/text color inlines those colors.
	 *
	 * Preset colors must reach the rendered cell/link as inline styles so they
	 * appear in email. The package inlines them already, so no override is needed.
	 */
	public function test_button_preset_colors_are_inlined() {
		$content = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"vivid-red","textColor":"white"} --><div class="wp-block-button"><a class="wp-block-button__link has-white-color has-vivid-red-background-color has-text-color has-background wp-element-button" href="https://example.com">Buy</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		$html    = $this->render_newsletter( $content );
		$this->assertStringContainsString( 'background-color: #cf2e2e', $html, 'Expected the button background color to be inlined.' );
		$this->assertStringContainsString( 'color: #ffffff', $html, 'Expected the button text color to be inlined.' );
	}

	/**
	 * Social links render as hosted PNG icons, not stripped inline SVG.
	 *
	 * This is the audit's headline finding: vanilla WP emits inline `<svg>` icons
	 * that Gmail and Outlook strip, leaving empty links. The package instead emits
	 * `<img>` tags pointing at hosted PNG icons with the service brand color as the
	 * pill background — the email-correct representation. So the package output is
	 * better than vanilla for email and needs no override.
	 */
	public function test_social_links_render_png_icons_not_svg() {
		$content = '<!-- wp:social-links --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://twitter.com/x","service":"twitter"} /--><!-- wp:social-link {"url":"https://facebook.com/x","service":"facebook"} /--></ul><!-- /wp:social-links -->';
		$html    = $this->render_newsletter( $content );

		$this->assertStringNotContainsString( '<svg', $html, 'Expected social icons to render as <img>, never stripped-by-email <svg>.' );
		$this->assertMatchesRegularExpression( '#<img[^>]+src="[^"]*/icons/twitter/twitter-white\.png"#', $html, 'Expected a hosted Twitter PNG icon.' );
		$this->assertMatchesRegularExpression( '#<img[^>]+src="[^"]*/icons/facebook/facebook-white\.png"#', $html, 'Expected a hosted Facebook PNG icon.' );
		$this->assertStringContainsString( 'href="https://twitter.com/x"', $html, 'Expected the Twitter link href to survive.' );
		$this->assertStringContainsString( 'href="https://facebook.com/x"', $html, 'Expected the Facebook link href to survive.' );
	}

	/**
	 * Social links render service labels when showLabels is enabled.
	 *
	 * With showLabels the package renders a text label next to each icon (a custom
	 * label overrides the service name). The audit confirmed this works, so no
	 * override is needed.
	 */
	public function test_social_links_render_labels_when_enabled() {
		$content = '<!-- wp:social-links {"showLabels":true} --><ul class="wp-block-social-links has-visible-labels"><!-- wp:social-link {"url":"https://twitter.com/x","service":"twitter","label":"Follow us"} /--></ul><!-- /wp:social-links -->';
		$html    = $this->render_newsletter( $content );
		$this->assertStringContainsString( 'Follow us', $html, 'Expected the custom social link label to render.' );
	}
}
