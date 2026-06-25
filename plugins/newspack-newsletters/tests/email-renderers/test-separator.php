<?php
/**
 * Class Separator Block Renderer Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Separator block renderer override tests.
 *
 * The WC email-editor package passes `core/separator` through the fallback
 * renderer, which wraps the bare `<hr>` in a table cell but adds no email-safe
 * dimensions. The `.wp-block-separator` stylesheet (which gives it `height`,
 * `border`, and a short `width`) is NOT present in email clients, so a
 * default-style separator degrades to a full-width gray browser `<hr>`, and
 * a colored separator loses its color appearance.
 *
 * The Newspack override must emit an email-safe structure — a horizontal rule
 * built from a table `<td>` with an explicit `border-top` (rather than a bare
 * `<hr>`) — so color, width, and alignment all survive without any external CSS.
 */
class Test_Separator extends WP_UnitTestCase {
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
				'post_title'   => 'Separator test newsletter',
				'post_content' => $content,
			]
		);
		return Renderer_Controller::render_wc( get_post( $post_id ) );
	}

	/**
	 * A colored separator must carry its color on a table-based structure.
	 *
	 * The WC fallback path passes the `<hr>` through with Emogrifier inlining
	 * CSS, but `.wp-block-separator` (which sets `height`, `border`, and `width`)
	 * is not present in email, so color on a bare `<hr>` relies on browser
	 * defaults for dimensions. The override must emit the color as an explicit
	 * `border-top` or `background-color` on a table `<td>` instead.
	 */
	public function test_colored_separator_carries_color_on_table_cell() {
		$content = '<!-- wp:separator {"backgroundColor":"vivid-red","className":"is-style-wide"} -->'
			. '<hr class="wp-block-separator has-text-color has-vivid-red-color has-alpha-channel-opacity has-vivid-red-background-color has-background is-style-wide"/>'
			. '<!-- /wp:separator -->';

		$html = $this->render_newsletter( $content );

		// The color must appear as a table-based inline style (border-top or background-color on a <td>),
		// not only on a bare <hr> that relies on missing CSS to render dimensions.
		$this->assertMatchesRegularExpression(
			'/<td[^>]*style="[^"]*(?:border-top|background-color):[^"]*#cf2e2e/',
			$html,
			'Expected the separator color #cf2e2e to appear as an explicit border-top or background-color on a <td>, not only on a bare <hr>.'
		);
	}

	/**
	 * A default-style separator must have an explicit, constrained width.
	 *
	 * Without the `.wp-block-separator` stylesheet, a bare `<hr>` stretches to
	 * 100% in all email clients. The override must emit an explicit short width
	 * (matching the editor preview: ~100px) so it looks correct in email.
	 */
	public function test_default_separator_has_constrained_width() {
		$content = '<!-- wp:separator -->'
			. '<hr class="wp-block-separator has-alpha-channel-opacity"/>'
			. '<!-- /wp:separator -->';

		$html = $this->render_newsletter( $content );

		// Must carry a constrained pixel width on the rule element (not full-width).
		$this->assertMatchesRegularExpression(
			'/width:\s*1\d{2}px/',
			$html,
			'Expected the default separator to have a constrained width (e.g. 100px) so it does not degrade to full-width in email.'
		);
	}

	/**
	 * A wide separator spans the full width.
	 *
	 * `is-style-wide` stretches edge to edge in the editor preview. The override
	 * must honor this via an explicit `width: 100%` on the table-based structure.
	 */
	public function test_wide_separator_spans_full_width() {
		$content = '<!-- wp:separator {"className":"is-style-wide"} -->'
			. '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>'
			. '<!-- /wp:separator -->';

		$html = $this->render_newsletter( $content );

		// Wide separators must be explicitly 100% wide.
		$this->assertStringContainsString(
			'width: 100%',
			$html,
			'Expected the wide separator to span full width (100%) in the email output.'
		);
	}

	/**
	 * Default and wide separators differ in width.
	 *
	 * This is the parity assertion: the two variants must produce measurably
	 * different widths in the email output — not both full-width or both short.
	 */
	public function test_default_and_wide_separator_widths_differ() {
		$default_html = $this->render_newsletter(
			'<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->'
		);
		$wide_html    = $this->render_newsletter(
			'<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/><!-- /wp:separator -->'
		);

		$has_constrained = (bool) preg_match( '/width:\s*1\d{2}px/', $default_html );
		$has_full_width  = str_contains( $wide_html, 'width: 100%' );

		$this->assertTrue( $has_constrained, 'Expected the default separator to carry a constrained pixel width.' );
		$this->assertTrue( $has_full_width, 'Expected the wide separator to carry width: 100%.' );
	}

	/**
	 * An unresolvable color slug falls back to the default rule color.
	 *
	 * `translate_slug_to_color()` returns the slug unchanged when it isn't in the
	 * email theme palette, so without validation the renderer would emit an
	 * invalid `border-top: 1px solid <slug>` that email clients drop, leaving no
	 * rule. A letters-only slug (e.g. a palette name like `primary`) is the tricky
	 * case — it must not be mistaken for a CSS named color. The override must fall
	 * back to the default gray so the rule still renders.
	 */
	public function test_unresolved_color_slug_falls_back_to_default() {
		$content = '<!-- wp:separator {"backgroundColor":"notacolorslug"} -->'
			. '<hr class="wp-block-separator has-notacolorslug-background-color has-background"/>'
			. '<!-- /wp:separator -->';

		$html = $this->render_newsletter( $content );

		$this->assertStringNotContainsString(
			'solid notacolorslug',
			$html,
			'Expected an unresolved letters-only color slug not to be emitted as an invalid CSS color.'
		);
		$this->assertMatchesRegularExpression(
			'/border-top:\s*1px\s+solid\s+#dddddd/i',
			$html,
			'Expected the separator to fall back to the default gray when the color slug is unresolvable.'
		);
	}
}
