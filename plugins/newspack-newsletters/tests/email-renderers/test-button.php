<?php
/**
 * Class Button Block Renderer Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Button block (core/button) email render tests.
 *
 * The package renders filled buttons email-safe on its own; the Newspack override
 * only adds the missing `is-style-outline` treatment (transparent background +
 * colored border). The reference model is vanilla WP output, not legacy MJML.
 */
class Test_Button extends WP_UnitTestCase {
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
				'post_title'   => 'Button test newsletter',
				'post_content' => $content,
			]
		);
		return Renderer_Controller::render_wc( get_post( $post_id ) );
	}

	/**
	 * A button renders as an email-safe linked table cell with inline color and target="_blank". No override needed.
	 */
	public function test_button_renders_email_safe_link() {
		$content = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com">Click me</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		$html    = $this->render_newsletter( $content );
		// process_links() appends UTM params (parity with MJML), so the href carries the
		// destination plus query params rather than the bare URL.
		$this->assertMatchesRegularExpression( '#href="https://example\.com[^"]*"#', $html, 'Expected the button link href to survive.' );
		$this->assertStringContainsString( 'utm_medium=email', $html, 'Expected the button link to be UTM-tagged via process_links.' );
		$this->assertStringContainsString( 'target="_blank"', $html, 'Expected the button link to open in a new tab.' );
		$this->assertStringContainsString( '>Click me</a>', $html, 'Expected the button label to render.' );
		$this->assertMatchesRegularExpression( '/class="[^"]*\bwp-block-button\b/', $html, 'Expected the button to render inside a wp-block-button table cell.' );
	}

	/**
	 * A button with preset background/text colors inlines those colors for email. No override needed.
	 */
	public function test_button_preset_colors_are_inlined() {
		$content = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"vivid-red","textColor":"white"} --><div class="wp-block-button"><a class="wp-block-button__link has-white-color has-vivid-red-background-color has-text-color has-background wp-element-button" href="https://example.com">Buy</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		$html    = $this->render_newsletter( $content );
		$this->assertMatchesRegularExpression( '/background-color:\s*#cf2e2e/', $html, 'Expected the button background color to be inlined.' );
		$this->assertMatchesRegularExpression( '/(?<!-)color:\s*#ffffff/', $html, 'Expected the button text color to be inlined.' );
	}

	/**
	 * An outline button renders with transparent background and a colored 2px border — the package
	 * ignores is-style-outline; the Newspack override applies the correct styles from theme.json.
	 */
	public function test_button_outline_renders_transparent_with_border() {
		$content = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline","style":{"color":{"background":"#cc0000"}}} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-background wp-element-button" style="background-color:#cc0000" href="https://example.com">Outline</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		$html    = $this->render_newsletter( $content );
		$this->assertMatchesRegularExpression( '/background-color:\s*transparent/', $html, 'Expected the outline button background to be transparent, not a solid fill.' );
		$this->assertMatchesRegularExpression( '/border-width:\s*2px/', $html, 'Expected the outline button to have a 2px border.' );
		$this->assertMatchesRegularExpression( '/border-color:\s*#cc0000/i', $html, 'Expected the outline border to use the button accent colour.' );
		$this->assertMatchesRegularExpression( '/(?<!-)color:\s*#cc0000/i', $html, 'Expected the outline text to use the accent colour, not the filled white.' );
	}

	/**
	 * An outline button with no custom colour falls back to the theme button colour
	 * for its border and text, keeping a transparent background.
	 */
	public function test_button_outline_falls_back_to_theme_color() {
		$inject = function ( $theme ) {
			$theme->merge(
				new \WP_Theme_JSON(
					[
						'version' => 3,
						'styles'  => [ 'blocks' => [ 'core/button' => [ 'color' => [ 'background' => '#112233' ] ] ] ],
					],
					'default'
				)
			);
			return $theme;
		};
		add_filter( 'woocommerce_email_editor_theme_json', $inject, 12 );
		try {
			$content = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="https://example.com">Outline</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
			$html    = $this->render_newsletter( $content );
			$this->assertMatchesRegularExpression( '/background-color:\s*transparent/', $html, 'Expected a transparent background even with no custom colour.' );
			$this->assertMatchesRegularExpression( '/border-color:\s*#112233/i', $html, 'Expected the outline border to fall back to the theme button colour.' );
			$this->assertMatchesRegularExpression( '/(?<!-)color:\s*#112233/i', $html, 'Expected the outline text to fall back to the theme button colour.' );
		} finally {
			remove_filter( 'woocommerce_email_editor_theme_json', $inject, 12 );
		}
	}
}
