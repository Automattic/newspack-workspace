<?php
/**
 * Tests the AMP Polyfills.
 *
 * @package Newspack\Tests
 */

use Newspack\AMP_Polyfills;

/**
 * Tests the AMP Polyfills.
 */
class Newspack_AMP_Polyfills extends WP_UnitTestCase {

	/**
	 * Data provider for test_amp_polyfills_image.
	 *
	 * @return array
	 */
	public function image_data() {
		return [
			[
				'<amp-img src="https://example.com/image.jpg" width="100" height="100"></amp-img>',
				'<img src="https://example.com/image.jpg" width="100" height="100" />',
			],
			[
				'<amp-img class="test" src="https://example.com/image.jpg" width="100" height="100">something inside</amp-img>',
				'<img class="test" src="https://example.com/image.jpg" width="100" height="100" />',
			],
			[
				'<amp-img src="https://example.com/image.jpg"></amp-img><p>something</p><amp-img src="https://example.com/image.jpg"></amp-img>something',
				'<img src="https://example.com/image.jpg" /><p>something</p><img src="https://example.com/image.jpg" />something',
			],

		];
	}

	/**
	 * Tests the image polyfill.
	 *
	 * @param string $input Input content.
	 * @param string $expected Expected output.
	 * @return void
	 * @dataProvider image_data
	 */
	public function test_amp_polyfills_image( $input, $expected ) {
		$this->assertSame( $expected, AMP_Polyfills::amp_tags( $input ) );
	}

	/**
	 * Data provider for test_amp_polyfills_iframe.
	 *
	 * @return array
	 */
	public function iframe_data() {
		return [
			[
				'<amp-iframe src="https://example.com/image.jpg" width="100" height="100"></amp-iframe>',
				'<iframe src="https://example.com/image.jpg" width="100" height="100"></iframe>',
			],
			[
				'<amp-iframe class="test" src="https://example.com/image.jpg" width="100" height="100"></amp-iframe>',
				'<iframe class="test" src="https://example.com/image.jpg" width="100" height="100"></iframe>',
			],
			[
				'<amp-iframe src="https://example.com/image.jpg"></amp-iframe><p>something</p><amp-iframe src="https://example.com/image.jpg"></amp-iframe>something',
				'<iframe src="https://example.com/image.jpg"></iframe><p>something</p><iframe src="https://example.com/image.jpg"></iframe>something',
			],
			[
				'<amp-iframe src="https://example.com/image.jpg" width="100" height="100"><amp-img src="https://example.com/image.jpg" width="100" height="100"></amp-img></amp-iframe>',
				'<iframe src="https://example.com/image.jpg" width="100" height="100"></iframe>',
			],
		];
	}

	/**
	 * Tests the image polyfill.
	 *
	 * @param string $input Input content.
	 * @param string $expected Expected output.
	 * @return void
	 * @dataProvider iframe_data
	 */
	public function test_amp_polyfills_iframe( $input, $expected ) {
		$this->assertSame( $expected, AMP_Polyfills::amp_tags( $input ) );
	}

	/**
	 * Data provider for test_amp_polyfills_youtube.
	 *
	 * @return array
	 */
	public function youtube_data() {
		return [
			[
				'<amp-youtube data-videoid="mGENRKrdoGY" layout="responsive" width="480" height="270" ></amp-youtube>',
				'<div><div><!-- wp:embed {"url":"https://www.youtube.com/watch?v=mGENRKrdoGY","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
                            <figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
                            https://www.youtube.com/watch?v=mGENRKrdoGY
                            </div></figure>
                        <!-- /wp:embed --></div></div>',
			],

		];
	}

	/**
	 * Tests the image polyfill.
	 *
	 * @param string $input Input content.
	 * @param string $expected Expected output.
	 * @return void
	 * @dataProvider youtube_data
	 */
	public function test_amp_polyfills_youtube( $input, $expected ) {
		$actual = preg_replace( '/\s+/', '', AMP_Polyfills::amp_tags( $input ) );
		$this->assertSame( preg_replace( '/\s+/', '', $expected ), $actual );
	}
}
