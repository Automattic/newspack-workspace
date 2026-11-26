<?php
/**
 * Tests the text color contrast helper.
 *
 * @package Newspack\Tests
 */

use Newspack\Emails;

require_once __DIR__ . '/../mocks/newsletters-mocks.php';

/**
 * Tests the text color contrast helper.
 */
class Newspack_Test_Color_Contrast extends WP_UnitTestCase {
	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function color_provider() {
		return [
			'black background' => [ '#000000', 'white' ],
			'white background' => [ '#ffffff', 'black' ],
			'light gray'       => [ '#f0f0f0', 'black' ],
			'yellow'           => [ '#ffcc00', 'black' ],
			'green'            => [ '#178f15', 'white' ],
			'blue'             => [ '#3366cc', 'white' ],
			'red'              => [ '#dd3333', 'white' ],
			'short white'      => [ '#fff', 'black' ],
			'short blue'       => [ '#36c', 'white' ],
			'not a color'      => [ 'not-a-color', 'black' ],
			'empty'            => [ '', 'black' ],
			'alpha channel'    => [ '#33333380', 'white' ],
			'uppercase'        => [ '#FFCC00', 'black' ],
			'unprefixed blue'  => [ '3366cc', 'white' ],
			'unprefixed green' => [ '178f15', 'white' ],
			'padded'           => [ ' #178f15 ', 'white' ],
			'orange'           => [ '#e65100', 'white' ],
		];
	}

	/**
	 * Test the picked text color.
	 *
	 * @dataProvider color_provider
	 *
	 * @param string $hex      Background color.
	 * @param string $expected Expected text color.
	 */
	public function test_get_color_contrast( $hex, $expected ) {
		$this->assertSame( $expected, \Newspack\newspack_get_color_contrast( $hex ) );
	}

	/**
	 * Test that a brand color change swaps the text color that was actually rendered.
	 */
	public function test_email_template_swap_uses_rendered_text_colors() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, \Newspack_Newsletters::EMAIL_HTML_META, '<a style="background:#0099cc;color:black">Go</a>' );
		\Newspack_Newsletters::update_color_palette(
			[
				'primary-text'   => 'black',
				'secondary-text' => 'white',
			]
		);

		Emails::maybe_update_email_templates(
			[
				'primary_color_hex'   => '#0099cc',
				'secondary_color_hex' => '#222222',
			],
			[
				'primary_color_hex'   => '#3366cc',
				'secondary_color_hex' => '#222222',
			]
		);

		$this->assertSame(
			'<a style="background:#3366cc;color:white">Go</a>',
			get_post_meta( $post_id, \Newspack_Newsletters::EMAIL_HTML_META, true )
		);
	}
}
