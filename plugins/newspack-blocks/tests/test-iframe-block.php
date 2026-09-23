<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class IframeBlockTest
 *
 * @package Newspack_Blocks
 */

/**
 * Tests the Iframe block's rendered source URL.
 */
class IframeBlockTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Render an Iframe block with the given attributes.
	 *
	 * @param array $attrs Block attributes.
	 * @return string Rendered markup.
	 */
	private function render_iframe( $attrs ) {
		return render_block(
			[
				'blockName'    => 'newspack-blocks/iframe',
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Sources outside the allowed URL schemes.
	 *
	 * @return array
	 */
	public function unsupported_sources() {
		return [
			'script scheme'      => [ 'javascript:void(0)' ],
			'mixed-case scheme'  => [ 'JavaScript:void(0)' ],
			'leading whitespace' => [ '  javascript:void(0)' ],
			'embedded tab'       => [ "java\tscript:void(0)" ],
			'data scheme'        => [ 'data:text/html,<p>x</p>' ],
			'vbscript scheme'    => [ 'vbscript:msgbox(1)' ],
		];
	}

	/**
	 * A source outside the allowed schemes renders nothing, as an empty source does.
	 *
	 * @dataProvider unsupported_sources
	 * @param string $src Source URL.
	 */
	public function test_unsupported_source_scheme_renders_nothing( $src ) {
		$this->assertSame( '', $this->render_iframe( [ 'src' => $src ] ) );
	}

	/**
	 * Sources the block embeds today keep rendering.
	 *
	 * @return array
	 */
	public function supported_sources() {
		return [
			'https'            => [ 'https://example.test/embed?id=1' ],
			'http'             => [ 'http://example.test/embed' ],
			'uploads-relative' => [ '/wp-content/uploads/iframe/demo/index.html' ],
		];
	}

	/**
	 * A supported source is printed into the iframe.
	 *
	 * @dataProvider supported_sources
	 * @param string $src Source URL.
	 */
	public function test_supported_source_renders( $src ) {
		$this->assertStringContainsString( "src = '" . $src . "'", $this->render_iframe( [ 'src' => $src ] ) );
	}

	/**
	 * Sources the render completes or re-encodes on the way out.
	 *
	 * @return array
	 */
	public function normalized_sources() {
		return [
			'scheme-less'       => [ 'example.test/embed', 'https://example.test/embed' ],
			'protocol-relative' => [ '//example.test/embed', '//example.test/embed' ],
			'query ampersand'   => [ 'https://example.test/embed?a=1&b=2', 'https://example.test/embed?a=1&#038;b=2' ],
		];
	}

	/**
	 * A source is printed in the form the browser will load.
	 *
	 * @dataProvider normalized_sources
	 * @param string $src      Source URL as saved.
	 * @param string $expected Source as printed.
	 */
	public function test_source_is_printed_normalized( $src, $expected ) {
		$this->assertStringContainsString( "src = '" . $expected . "'", $this->render_iframe( [ 'src' => $src ] ) );
	}

	/**
	 * Document mode still wraps the source in the viewer URL.
	 */
	public function test_document_mode_wraps_source_in_viewer() {
		$html = $this->render_iframe(
			[
				'src'  => 'https://example.test/files/report.pdf',
				'mode' => 'document',
			]
		);
		$this->assertStringContainsString( "src = 'https://docs.google.com/gview?embedded=true", $html );
		$this->assertStringContainsString( 'url=https%3A%2F%2Fexample.test%2Ffiles%2Freport.pdf', $html );
	}
}
