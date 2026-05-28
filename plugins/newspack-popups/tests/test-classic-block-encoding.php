<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ClassicBlockEncoding Test.
 *
 * Tests that the DOMDocument UTF-8 encoding fix in convert_classic_blocks
 * correctly preserves multi-byte characters (emojis, CJK, Cyrillic, etc.)
 * in different content types and charset configurations.
 *
 * @package Newspack_Popups
 */

/**
 * ClassicBlockEncoding test case.
 */
class ClassicBlockEncodingTest extends WP_UnitTestCase {

	/**
	 * ReflectionMethod to access the private convert_classic_blocks method.
	 *
	 * @var ReflectionMethod
	 */
	private static $convert_classic_blocks;

	/**
	 * Set up.
	 */
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		if ( ! self::$convert_classic_blocks ) {
			self::$convert_classic_blocks = new ReflectionMethod( 'Newspack_Popups_Inserter', 'convert_classic_blocks' );
			self::$convert_classic_blocks->setAccessible( true );
		}
	}

	/**
	 * Lightbulb emoji in <p> survives round-trip; specific hex bytes F0 9F 92 A1 are intact.
	 */
	public function test_emoji_survives_classic_block_conversion() {
		$emoji  = "\xF0\x9F\x92\xA1"; // U+1F4A1 Light Bulb — 4 bytes in UTF-8.
		$output = $this->convert_to_html( '<p>' . $emoji . '</p>' );

		$this->assertStringContainsString( $emoji, $output, 'Emoji should survive classic block conversion.' );
		$this->assertTrue( mb_check_encoding( $output, 'UTF-8' ), 'Output should be valid UTF-8.' );
	}

	/**
	 * Pure ASCII classic content is unchanged.
	 */
	public function test_ascii_content_unchanged() {
		$output = $this->convert_to_html( '<p>Hello world</p>' );

		$this->assertStringContainsString( 'Hello world', $output );
		$this->assertTrue( mb_check_encoding( $output, 'UTF-8' ), 'Output should be valid UTF-8.' );
	}

	/**
	 * Single emoji in paragraph is preserved.
	 */
	public function test_single_emoji_preserved() {
		$output = $this->convert_to_html( '<p>Great idea 💡 let us try</p>' );

		$this->assertStringContainsString( '💡', $output, 'Emoji should be preserved.' );
		$this->assertStringContainsString( 'Great idea', $output, 'Text before emoji should be preserved.' );
		$this->assertStringContainsString( 'let us try', $output, 'Text after emoji should be preserved.' );
	}

	/**
	 * Multiple emojis including are preserved, including flag sequence, skin-tone modifier, and ZWJ compound.
	 */
	public function test_multiple_emojis_preserved() {
		$output = $this->convert_to_html( '<p>🎉🔥💡👍🏽🇺🇸👨‍👩‍👧‍👦</p>' );

		$this->assertStringContainsString( '🎉', $output, 'Party emoji should be preserved.' );
		$this->assertStringContainsString( '🔥', $output, 'Fire emoji should be preserved.' );
		$this->assertStringContainsString( '💡', $output, 'Lightbulb emoji should be preserved.' );
		$this->assertStringContainsString( '👍🏽', $output, 'Skin-tone emoji should be preserved.' );
		$this->assertStringContainsString( '🇺🇸', $output, 'Flag emoji should be preserved.' );
		$this->assertStringContainsString( '👨‍👩‍👧‍👦', $output, 'ZWJ compound emoji should be preserved.' );
	}

	/**
	 * Accented Latin characters (café, résumé, naïve) are preserved.
	 */
	public function test_accented_characters_preserved() {
		$output = $this->convert_to_html( '<p>News café résumé naïve</p>' );

		$this->assertStringContainsString( 'News', $output, 'News should be preserved.' );
		$this->assertStringContainsString( 'café', $output, 'café should be preserved.' );
		$this->assertStringContainsString( 'résumé', $output, 'résumé should be preserved.' );
		$this->assertStringContainsString( 'naïve', $output, 'naïve should be preserved.' );
	}

	/**
	 * CJK (Chinese, Japanese, Korean) characters are preserved.
	 */
	public function test_cjk_characters_preserved() {
		$output = $this->convert_to_html( '<p>我喜欢新闻 ; 私はニュースが大好きです</p>' );

		$this->assertStringContainsString( '我喜欢新闻', $output, 'Chinese characters should be preserved.' );
		$this->assertStringContainsString( '私はニュースが大好きです', $output, 'Japanese characters should be preserved.' );
	}

	/**
	 * Non-Latin scripts (Cyrillic, Arabic, Greek, Thai, Hebrew) are preserved.
	 */
	public function test_non_latin_scripts_preserved() {
		$output = $this->convert_to_html( '<p>News Новости أخبار Νέα ข่าว חֲדָשׁוֹת</p>' );

		$this->assertStringContainsString( 'News', $output, 'News should be preserved.' );
		$this->assertStringContainsString( 'Новости', $output, 'Cyrillic should be preserved.' );
		$this->assertStringContainsString( 'أخبار', $output, 'Arabic should be preserved.' );
		$this->assertStringContainsString( 'Νέα', $output, 'Greek should be preserved.' );
		$this->assertStringContainsString( 'ข่าว', $output, 'Thai should be preserved.' );
		$this->assertStringContainsString( 'חֲדָשׁוֹת', $output, 'Hebrew should be preserved.' );
	}

	/**
	 * Mixed content with all scripts and emojis in a single block is preserved.
	 */
	public function test_mixed_content_all_scripts() {
		$output = $this->convert_to_html( '<p>News café 💡 我喜欢新闻 私はニュースが大好きです Новости أخبار Νέα ข่าว חֲדָשׁוֹת ♠★</p>' );

		$this->assertStringContainsString( 'News', $output, 'News characters should be preserved.' );
		$this->assertStringContainsString( 'café', $output, 'Accented characters should be preserved.' );
		$this->assertStringContainsString( '💡', $output, 'Emoji should be preserved.' );
		$this->assertStringContainsString( '我喜欢新闻', $output, 'CJK should be preserved.' );
		$this->assertStringContainsString( '私はニュースが大好きです', $output, 'Japanese should be preserved.' );
		$this->assertStringContainsString( 'Новости', $output, 'Cyrillic should be preserved.' );
		$this->assertStringContainsString( 'أخبار', $output, 'Arabic should be preserved.' );
		$this->assertStringContainsString( 'Νέα', $output, 'Greek should be preserved.' );
		$this->assertStringContainsString( 'ข่าว', $output, 'Thai should be preserved.' );
		$this->assertStringContainsString( 'חֲדָשׁוֹת', $output, 'Hebrew should be preserved.' );
		$this->assertStringContainsString( '♠★', $output, 'Card suit and star symbols should be preserved.' );
	}

	/**
	 * Nested HTML structures (links, strong, em) with emoji in content are preserved.
	 */
	public function test_nested_html_with_emoji() {
		$output = $this->convert_to_html( '<p><strong>Bold 💡</strong> and <em>italic 🎉</em></p>' );

		$this->assertStringContainsString( '💡', $output, 'Emoji in <strong> should be preserved.' );
		$this->assertStringContainsString( '🎉', $output, 'Emoji in <em> should be preserved.' );
		$this->assertStringContainsString( '<strong>', $output, 'Strong tag should survive DOMDocument.' );
		$this->assertStringContainsString( '<em>', $output, 'Em tag should survive DOMDocument.' );

		$link_output = $this->convert_to_html( '<p><a href="https://example.com">Click 💡 here</a></p>' );

		$this->assertStringContainsString( '💡', $link_output, 'Emoji in link text should be preserved.' );
		$this->assertStringContainsString( 'href="https://example.com"', $link_output, 'Link href should survive DOMDocument.' );
	}

	/**
	 * <h2> with emoji is still correctly classified as core/heading by the block-name regex.
	 */
	public function test_heading_with_emoji_classified_correctly() {
		$blocks = $this->convert( '<h2>Section 💡 Title</h2>' );

		$this->assertNotEmpty( $blocks, 'Should produce at least one block.' );

		$heading_found = false;
		foreach ( $blocks as $block ) {
			if ( 'core/heading' === $block['blockName'] ) {
				$heading_found = true;
				$this->assertStringContainsString( '💡', $block['innerHTML'], 'Emoji should be preserved in heading innerHTML.' );
				$this->assertStringContainsString( 'Section', $block['innerHTML'], 'Text before emoji should be preserved.' );
				$this->assertStringContainsString( 'Title', $block['innerHTML'], 'Text after emoji should be preserved.' );
			}
		}
		$this->assertTrue( $heading_found, 'Heading with emoji should be classified as core/heading.' );
	}

	/**
	 * The UTF-8 meta tag used internally does not leak into any output block innerHTML.
	 */
	public function test_meta_tag_not_leaked_to_output() {
		$test_inputs = [
			'<p>Content 💡 with emoji</p>',
			'<p>café résumé</p>',
			'<h2>Heading 🎉</h2>',
			'<p>你好世界</p>',
		];

		foreach ( $test_inputs as $input ) {
			$blocks = $this->convert( $input );
			foreach ( $blocks as $block ) {
				$this->assertStringNotContainsString(
					'<meta',
					$block['innerHTML'],
					'Meta tag should not appear in output block innerHTML for input: ' . $input
				);
			}
		}
	}

	/**
	 * HTML numeric entities for emojis (&#x1f4a1;) as produced by wp_encode_emoji() survive the conversion.
	 */
	public function test_entity_encoded_emoji_preserved() {
		$output = $this->convert_to_html( '<p>&#x1f4a1; idea</p>' );

		// DOMDocument resolves numeric entities to actual characters when the charset is declared.
		// The emoji should appear as the rendered character or remain as a numeric entity.
		$has_emoji  = false !== strpos( $output, "\xF0\x9F\x92\xA1" );
		$has_entity = false !== strpos( $output, '&#x1f4a1;' ) || false !== strpos( $output, '&#128161;' );
		$this->assertTrue(
			$has_emoji || $has_entity,
			'Entity-encoded emoji should survive as rendered character or numeric entity.'
		);
		$this->assertStringContainsString( 'idea', $output, 'Surrounding text should be preserved.' );
	}

	/**
	 * With blog_charset mocked to ISO-8859-1, latin1 accented bytes (\xE9 = é)
	 * are correctly converted to valid UTF-8 in the output.
	 */
	public function test_latin1_charset_accented_content() {
		update_option( 'blog_charset', 'ISO-8859-1' );

		// \xE9 is "é" in ISO-8859-1.
		$output = $this->convert_to_html( "<p>caf\xE9 r\xE9sum\xE9</p>" );

		$this->assertTrue( mb_check_encoding( $output, 'UTF-8' ), 'Output should be valid UTF-8 after latin1 conversion.' );
		$this->assertStringContainsString( 'café', $output, 'Accented character should be correctly converted from latin1 to UTF-8.' );
		$this->assertStringContainsString( 'résumé', $output, 'Multiple accented characters should be converted.' );
	}

	/**
	 * End-to-end: classic content with emoji passed through the public
	 * insert_popups_in_post_content method with a popup; emoji is preserved in the output.
	 */
	public function test_insert_popups_preserves_emoji_in_classic_content() {
		$classic_content = "First paragraph with 💡 emoji\n<h2>A heading</h2>\nSecond paragraph";
		$popups          = [ self::create_test_popup( '0' ) ];

		$result = Newspack_Popups_Inserter::insert_popups_in_post_content( $classic_content, $popups );

		$this->assertStringContainsString( '💡', $result, 'Emoji should be preserved through the full public API pipeline.' );
		$this->assertStringNotContainsString( '<meta', $result, 'Meta tag should not leak into the final serialized output.' );
	}

	/**
	 * Create a classic (freeform) block array as produced by parse_blocks().
	 *
	 * @param string $html The raw HTML content.
	 * @return array Block array.
	 */
	private static function make_classic_block( $html ) {
		return [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * Run convert_classic_blocks via Reflection and return the resulting blocks.
	 *
	 * @param string $html Classic block HTML content.
	 * @return array Array of converted block arrays.
	 */
	private function convert( $html ) {
		return self::$convert_classic_blocks->invoke( null, [ self::make_classic_block( $html ) ] );
	}

	/**
	 * Get the combined innerHTML from all blocks returned by convert_classic_blocks.
	 *
	 * @param string $html Classic block HTML content.
	 * @return string Combined innerHTML.
	 */
	private function convert_to_html( $html ) {
		$blocks = $this->convert( $html );
		return implode( '', array_column( $blocks, 'innerHTML' ) );
	}

	/**
	 * Create an inline popup configuration for integration tests.
	 *
	 * @param string $scroll_pct Scroll percentage for placement.
	 * @return array Popup config.
	 */
	private static function create_test_popup( $scroll_pct = '0' ) {
		return [
			'id'      => wp_rand(),
			'content' => 'Popup content.',
			'options' => [
				'placement'               => 'inline',
				'trigger_type'            => 'scroll',
				'trigger_scroll_progress' => $scroll_pct,
				'trigger_blocks_count'    => '0',
			],
		];
	}
}
