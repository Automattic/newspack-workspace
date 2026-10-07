<?php
/**
 * Test_Tag_Labels class.
 *
 * @package Newspack
 */

use Newspack\Tag_Labels;

/**
 * Class Test_Tag_Labels
 */
class Test_Tag_Labels extends WP_UnitTestCase {
	/**
	 * A single label in the shape the renderer consumes: a `flag` to print and a
	 * `link` to wrap it in.
	 *
	 * @return array One label.
	 */
	private function make_labels() {
		return [
			[
				'flag' => 'Breaking',
				'link' => 'https://example.com/tag/breaking/',
			],
		];
	}

	/**
	 * The outer wrapper must not carry `cat-links`.
	 *
	 * That class hands an element every `.cat-links a` rule a publisher has
	 * written for categories, and per-section color overrides are common enough
	 * that labels would follow a palette they are not meant to follow. Callers
	 * declare their own `.newspack-tag-labels` styling, so re-adding the class
	 * here would open that path on every caller at once.
	 */
	public function test_display_does_not_emit_cat_links() {
		ob_start();
		Tag_Labels::display( $this->make_labels(), true, 'div' );
		$html = ob_get_clean();

		self::assertStringContainsString( 'tag-labels', $html, 'Wrapper carries the tag-labels class.' );
		self::assertStringNotContainsString( 'cat-links', $html, 'Wrapper must not carry cat-links.' );
	}

	/**
	 * Labels carry the namespaced classes that the stylesheets select.
	 *
	 * WordPress gives every post a `tag-{slug}` class, so a tag named "Labels"
	 * or "Label" puts `tag-labels` or `tag-label` on the whole article. The
	 * `newspack-` names cannot collide, because a tag-derived class always
	 * starts with `tag-`. The legacy names stay on the markup so existing
	 * custom CSS keeps matching. That no stylesheet selects them is enforced by
	 * the stylelint rule in newspack-blocks and both themes, for SCSS only.
	 */
	public function test_labels_carry_namespaced_and_legacy_classes() {
		ob_start();
		Tag_Labels::display( $this->make_labels(), true, 'div' );
		$html = ob_get_clean();

		self::assertStringContainsString( 'class="newspack-tag-labels tag-labels"', $html, 'Wrapper carries the namespaced class and keeps the legacy one.' );
		self::assertStringContainsString( 'class="newspack-tag-label tag-label flag"', $html, 'Each label carries the namespaced class and keeps the legacy ones.' );
		self::assertSame( Tag_Labels::generate_html( $this->make_labels(), true, [ 'newspack-tag-labels', 'tag-labels' ], [ 'newspack-tag-label', 'tag-label', 'flag' ], 'span' ), Tag_Labels::generate_html( $this->make_labels() ), 'generate_html() defaults match what display() emits.' );
	}

	/**
	 * An explicit outer-class list is still honoured.
	 */
	public function test_generate_html_honours_explicit_outer_classes() {
		$html = Tag_Labels::generate_html( $this->make_labels(), true, [ 'custom-wrapper' ], [ 'tag-label' ], 'span' );

		self::assertStringContainsString( 'custom-wrapper', $html );
		self::assertStringNotContainsString( 'cat-links', $html );
	}
}
