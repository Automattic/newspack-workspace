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
	 * declare their own `.tag-labels` styling, so re-adding the class here would
	 * open that path on every caller at once.
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

	/**
	 * A caller that asks for `cat-links` gets it, in the order given.
	 *
	 * The theme's `newspack_generate_tag_labels()` forwards its outer classes
	 * here with a `span` wrapper, and child themes pass `cat-links` through it on
	 * purpose. Dropping the class from the defaults must not strip it from
	 * callers who ask for it.
	 */
	public function test_generate_html_keeps_caller_supplied_cat_links() {
		$html = Tag_Labels::generate_html( $this->make_labels(), true, [ 'cat-links', 'tag-labels' ], [ 'tag-label', 'flag' ], 'span' );

		self::assertSame(
			'<span class="cat-links tag-labels"><a class="tag-label flag" href="https://example.com/tag/breaking/" rel="tag">Breaking</a></span><!-- .tag-labels -->',
			$html
		);
	}

	/**
	 * Inner classes reach every label, linked or not.
	 */
	public function test_generate_html_applies_inner_classes_to_each_label() {
		$labels = [
			[
				'flag' => 'Breaking',
				'link' => 'https://example.com/tag/breaking/',
			],
			[ 'flag' => 'Opinion' ],
		];

		$html = Tag_Labels::generate_html( $labels, true, [ 'tag-labels' ], [ 'custom-label', 'flag' ] );

		self::assertStringContainsString( '<a class="custom-label flag" href="https://example.com/tag/breaking/" rel="tag">Breaking</a>', $html );
		self::assertStringContainsString( '<span class="custom-label flag">Opinion</span>', $html );
		self::assertSame( 2, substr_count( $html, 'class="custom-label flag"' ) );
	}

	/**
	 * `div` is honoured as the outer element.
	 */
	public function test_generate_html_accepts_div_outer_element() {
		$html = Tag_Labels::generate_html( $this->make_labels(), true, [ 'tag-labels' ], [ 'tag-label', 'flag' ], 'div' );

		self::assertStringStartsWith( '<div class="tag-labels">', $html );
		self::assertStringEndsWith( '</div><!-- .tag-labels -->', $html );
	}

	/**
	 * Outer elements the renderer rejects.
	 *
	 * @return array Test cases.
	 */
	public function invalid_outer_element_provider() {
		return [
			'paragraph'           => [ 'p' ],
			'section'             => [ 'section' ],
			'script'              => [ 'script' ],
			'uppercase DIV'       => [ 'DIV' ],
			'empty string'        => [ '' ],
			'attribute injection' => [ 'div onclick="x"' ],
		];
	}

	/**
	 * Anything other than `span` or `div` falls back to `span`.
	 *
	 * @dataProvider invalid_outer_element_provider
	 *
	 * @param string $outer_element Rejected outer element.
	 */
	public function test_generate_html_falls_back_to_span_outer_element( $outer_element ) {
		$html = Tag_Labels::generate_html( $this->make_labels(), true, [ 'tag-labels' ], [ 'tag-label', 'flag' ], $outer_element );

		self::assertStringStartsWith( '<span class="tag-labels">', $html );
		self::assertStringEndsWith( '</span><!-- .tag-labels -->', $html );
	}

	/**
	 * A label with a link renders an anchor; one without renders a span.
	 *
	 * The flag-only label is the NPPM-3051 path: it used to raise an "undefined
	 * array key" warning before falling through to the span, and this suite
	 * converts warnings to exceptions, so the warning fails the test.
	 */
	public function test_generate_html_links_only_labels_that_have_a_link() {
		$labels = [
			[
				'flag' => 'Breaking',
				'link' => 'https://example.com/tag/breaking/',
			],
			[ 'flag' => 'Opinion' ],
		];

		$html = Tag_Labels::generate_html( $labels );

		self::assertSame(
			'<span class="tag-labels"><a class="tag-label flag" href="https://example.com/tag/breaking/" rel="tag">Breaking</a><span class="tag-label flag">Opinion</span></span><!-- .tag-labels -->',
			$html
		);
	}

	/**
	 * With links turned off, a label that has a link still renders as a span.
	 */
	public function test_generate_html_without_links_renders_spans() {
		$html = Tag_Labels::generate_html( $this->make_labels(), false );

		self::assertStringContainsString( '<span class="tag-label flag">Breaking</span>', $html );
		self::assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * Empty input returns an empty string, not a bare wrapper.
	 */
	public function test_generate_html_returns_empty_string_for_empty_input() {
		self::assertSame( '', Tag_Labels::generate_html( null ) );
		self::assertSame( '', Tag_Labels::generate_html( [] ) );
	}

	/**
	 * A list where no entry has a flag renders nothing, not an empty wrapper.
	 */
	public function test_generate_html_returns_empty_string_when_no_label_renders() {
		$labels = [
			[ 'link' => 'https://example.com/tag/breaking/' ],
			[],
		];

		self::assertSame( '', Tag_Labels::generate_html( $labels ) );
		self::assertSame( '', Tag_Labels::generate_html( $labels, false, [ 'cat-links', 'tag-labels' ], [ 'tag-label' ], 'div' ) );
	}

	/**
	 * The wrapper closes with a `<!-- .tag-labels -->` comment whatever the
	 * outer element and classes are.
	 */
	public function test_generate_html_ends_with_tag_labels_comment() {
		$html = Tag_Labels::generate_html( $this->make_labels(), true, [ 'custom-wrapper' ], [ 'tag-label', 'flag' ], 'div' );

		self::assertStringEndsWith( '</div><!-- .tag-labels -->', $html );
	}
}
