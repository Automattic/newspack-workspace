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
	 * Tag labels used to borrow that class for its styling, which also handed
	 * them every `.cat-links a` rule a publisher had written for categories --
	 * per-section colour overrides are common, and they recoloured labels that
	 * were never meant to follow the category palette. Callers now declare their
	 * own `.tag-labels` styling, so re-adding the class here would silently
	 * reopen the capture on every caller at once.
	 */
	public function test_display_does_not_emit_cat_links() {
		ob_start();
		Tag_Labels::display( $this->make_labels(), true, 'div' );
		$html = ob_get_clean();

		self::assertStringContainsString( 'tag-labels', $html, 'Wrapper carries the tag-labels class.' );
		self::assertStringNotContainsString( 'cat-links', $html, 'Wrapper must not carry cat-links.' );
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
