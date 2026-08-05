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
 * Without an override the package wraps the bare `<hr>` in a table cell but adds no email-safe
 * dimensions, and `.wp-block-separator` CSS is absent in email clients. The Newspack override
 * emits an explicit border-top on a `<td>` so color, width, and alignment survive.
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
	 * A colored separator emits the color as border-top or background-color on a `<td>` — color on a
	 * bare `<hr>` relies on missing `.wp-block-separator` CSS for dimensions in email.
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
	 * A default-style separator has an explicit, constrained width — without CSS a bare `<hr>` stretches to 100%.
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
	 * A wide separator (is-style-wide) spans 100% via an explicit width on the table structure.
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
	 * Default and wide separators produce measurably different widths in email output.
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
	 * An unresolvable color slug falls back to the default gray — a letters-only slug must not be
	 * emitted as an invalid CSS color, which email clients would drop leaving no rule.
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
