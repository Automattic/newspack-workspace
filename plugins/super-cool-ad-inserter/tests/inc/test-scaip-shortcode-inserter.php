<?php
/**
 * Test shortcode inserter.
 *
 * @package super-cool-ad-inserter
 */

/**
 * Test shortcode inserter functions.
 */
class ScaipShortcodeInserterTestFunctions extends WP_UnitTestCase {
	/**
	 * Test the scaip_insert_shortcode function.
	 */
	public function test_scaip_insert_shortcode() {
		// Create a long test post thing, please.
		// Check that it does nothing on posts outside The Loop.

		$ret = scaip_insert_shortcode( '' );
		$this->assertEquals( $ret, '' );

		$this->markTestIncomplete( 'This test has not been implemented yet.' );
	}

	/**
	 * Test the scaip_maybe_insert_shortcode function.
	 */
	public function test_scaip__maybe_insert_shortcode() {
		// Check that it does nothing on posts outside The Loop.
		// check that the filter works.
		// check that it doesn't work on posts of other post types.

		$ret = scaip_maybe_insert_shortcode( '' );
		$this->assertEquals( $ret, '' );

		$this->markTestIncomplete( 'This test has not been implemented yet.' );
	}

	/**
	 * Classic content made only of line breaks is empty after wpautop(), which DOMDocument::loadHTML() rejects.
	 *
	 * @dataProvider line_break_only_content
	 * @param string $content Classic content.
	 */
	public function test_line_break_only_classic_content_is_returned_unchanged( $content ) {
		update_option( 'scaip_settings_min_paragraphs', 0 );
		$this->assertSame( '', force_balance_tags( wpautop( $content ) ), 'Precondition: content is empty after wpautop().' );
		$this->assertSame( $content, scaip_insert_shortcode( $content ) );
	}

	/**
	 * Content that is non-empty after wpautop() is still split into blocks and gets the shortcode.
	 */
	public function test_classic_content_still_gets_the_shortcode() {
		update_option( 'scaip_settings_min_paragraphs', 0 );
		update_option( 'scaip_settings_start', 1 );
		update_option( 'scaip_settings_period', 1 );
		update_option( 'scaip_settings_repetitions', 1 );
		$output = scaip_insert_shortcode( "<p>one</p>\n\n<p>two</p>\n\n<p>three</p>" );
		$this->assertStringContainsString( '<p>one</p>', $output );
		$this->assertStringContainsString( '[ad number="1"]', $output );
	}

	/**
	 * Data provider for line-break-only classic content that wpautop() empties.
	 *
	 * @return array
	 */
	public function line_break_only_content() {
		return [
			'two br'           => [ '<br><br>' ],
			'two self-closed'  => [ "<br />\n<br />" ],
			'br blank line br' => [ "<br>\n\n<br>\n" ],
			'four br'          => [ '<br><br><br><br>' ],
		];
	}
}
