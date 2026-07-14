<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ContentInsertion Test
 *
 * @package Newspack_Popups
 */

/**
 * ContentInsertion test case.
 */
class ContentInsertionTest extends WP_UnitTestCase {
	private static $popups = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		self::$popups = [
			self::create_inline_popup( 'scroll', '0' ),
			self::create_inline_popup( 'scroll', '70' ),
			self::create_inline_popup( 'scroll', '100' ),
		];
	}


	/**
	 * Create an inline popup configuration object.
	 *
	 * @param string $trigger_type Popup insertion trigger type (e.g. `scroll`).
	 * @param string $placement Placement, as percentage or blocks count in content.
	 */
	private static function create_inline_popup( $trigger_type, $placement ) {
		return [
			'id'      => wp_rand(),
			'content' => 'Some content.',
			'options' => [
				'placement'               => 'inline',
				'trigger_type'            => $trigger_type,
				'trigger_scroll_progress' => 'scroll' === $trigger_type ? $placement : '0',
				'trigger_blocks_count'    => 'blocks_count' === $trigger_type ? $placement : '0',
			],
		];
	}

	/**
	 * Get the popup as shortcode - that's how inline popups are inserted into content.
	 *
	 * @param string $id ID.
	 */
	public static function rendered_popup( $id ) {
		return '<!-- wp:shortcode -->[newspack-popup id="' . $id . '"]<!-- /wp:shortcode -->';
	}

	/**
	 * Assert that serialized blocks match the block names.
	 *
	 * @param string[] $expected List of block names.
	 * @param array    $actual   Parsed blocks for assertion.
	 * @param string   $message  Message.
	 */
	private static function assertEqualBlockNames( $expected, $actual, $message = '' ) {
		$parsed_blocks = parse_blocks(
			str_replace(
				array( "\n", "\r" ),
				'',
				$actual
			)
		);
		$actual_names  = wp_list_pluck( $parsed_blocks, 'blockName' );
		self::assertEquals( $expected, $actual_names, $message );
	}

	/**
	 * Insertion into block-based post content.
	 */
	public function test_insertion_into_block_content() {
		$post_content = '
<!-- wp:image {"align":"right"} -->
<div class="wp-block-image">image</div>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>Paragraph 1</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>A heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Paragraph 2</p>
<!-- /wp:paragraph -->
';
		self::assertEqualBlockNames(
			[
				'core/shortcode', // Popup 1.
				'core/image',
				'core/paragraph',
				'core/shortcode', // Popup 2 – inserted before the heading, not after it.
				'core/heading',
				'core/paragraph',
				'core/shortcode', // Popup 3.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				self::$popups
			),
			'The popups are inserted into the content at expected positions.'
		);
	}

	/**
	 * Insertion into block-based post content, a longer version.
	 */
	public function test_insertion_into_block_content_long() {
		$post_content = '
<!-- wp:image {"align":"right"} -->
<div class="wp-block-image">image</div>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>A heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->
';
		self::assertEqualBlockNames(
			[
				'core/image',
				'core/paragraph',
				'core/shortcode', // Popup.
				'core/paragraph',
				'core/shortcode', // Popup.
				'core/paragraph',
				'core/shortcode', // Popup.
				'core/heading',
				'core/paragraph',
				'core/paragraph',
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				[
					self::create_inline_popup( 'scroll', '24' ),
					self::create_inline_popup( 'scroll', '50' ),
					self::create_inline_popup( 'scroll', '60' ),
				]
			),
			'The popups are inserted into the content at expected positions.'
		);
	}

	/**
	 * Insertion into block-based post content, when a heading is the last block.
	 */
	public function test_insertion_into_block_content_ending_with_heading() {
		$post_content = '
<!-- wp:image {"align":"right"} -->
<div class="wp-block-image">image</div>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>A heading</h2>
<!-- /wp:heading -->
';
		self::assertEqualBlockNames(
			[
				'core/shortcode', // Popup 1.
				'core/image',
				'core/paragraph',
				'core/shortcode', // Popup 2.
				'core/heading',
				'core/shortcode', // Popup 3.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				self::$popups
			),
			'The popups are inserted into the content at expected positions when a heading is the last block.'
		);
	}

	/**
	 * Insertion into block-based post content, when a heading is the first block.
	 */
	public function test_insertion_into_block_content_starting_with_heading() {
		$post_content = '
<!-- wp:heading -->
<h2>A heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->
';
		self::assertEqualBlockNames(
			[
				'core/shortcode', // Popup 1.
				'core/shortcode', // Popup 2.
				'core/heading',
				'core/paragraph',
				'core/shortcode', // Popup 3.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				self::$popups
			),
			'The popups are inserted into the content at expected positions when a heading is the first block.'
		);
	}

	/**
	 * Insertion into block-based post content, when there are consecutive insertion-preventing blocks.
	 */
	public function test_insertion_into_block_content_consecutive() {
		$post_content = '
<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>A heading</h2>
<!-- /wp:heading -->

<!-- wp:image {"align":"right"} -->
<div class="wp-block-image">image</div>
<!-- /wp:image -->

<!-- wp:heading -->
<h2>A heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Paragraph</p>
<!-- /wp:paragraph -->
';
		self::assertEqualBlockNames(
			[
				'core/shortcode', // Popup 1.
				'core/paragraph',
				'core/shortcode', // Popup 2.
				'core/heading',
				'core/image',
				'core/heading',
				'core/paragraph',
				'core/paragraph',
				'core/shortcode', // Popup 3.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				self::$popups
			),
			'The popups are inserted into the content at expected positions when there are consecutive insertion-preventing blocks.'
		);
	}

	/**
	 * Insertion into classic (legacy) post content.
	 */
	public function test_insertion_into_classic_content() {
		$post_content = 'Paragraph 1
<h2>A heading</h2>
Paragraph 2
<blockquote>A quote</blockquote>';
		$popups       = [
			// A popup before any content.
			self::create_inline_popup( 'scroll', '0' ),
			// A popup that should not be inserted right after a heading.
			self::create_inline_popup( 'scroll', '30' ),
			// A popup after all content.
			self::create_inline_popup( 'scroll', '100' ),
		];
		self::assertEqualBlockNames(
			[
				'core/shortcode', // Popup 1.
				'core/html',
				'core/shortcode', // Popup 2.
				'core/heading',
				'core/html',
				'core/html',
				'core/shortcode', // Popup 3.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				$popups
			),
			'The popups are inserted into the content at expected positions.'
		);
	}

	/**
	 * Insertion into block-based post content.
	 */
	public function test_insertion_into_block_content_based_on_blocks_count() {
		$popups = [
			self::create_inline_popup( 'blocks_count', '0' ),
			self::create_inline_popup( 'blocks_count', '1' ),
			self::create_inline_popup( 'blocks_count', '3' ),
		];

		$post_content = '
<!-- wp:image {"align":"right"} -->
<div class="wp-block-image">image</div>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>Paragraph 1</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>A heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Paragraph 2</p>
<!-- /wp:paragraph -->
';
		self::assertEqualBlockNames(
			[
				'core/shortcode', // Popup 1.
				'core/image',
				'core/paragraph',
				'core/shortcode', // Popup 2 – inserted before the heading, not after it.
				'core/heading',
				'core/paragraph',
				'core/shortcode', // Popup 3.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				$popups
			),
			'The popups are inserted into the content at expected positions.'
		);
	}

	/**
	 * Insertion into classic (legacy) post content.
	 */
	public function test_insertion_into_classic_content_based_on_blocks_count() {
		$post_content = 'Paragraph 1
<h2>A heading</h2>
Paragraph 2
<blockquote>A quote</blockquote>';
		$popups       = [
			// A popup before any content.
			self::create_inline_popup( 'blocks_count', '0' ),
			// A popup that should not be inserted right after a heading.
			self::create_inline_popup( 'blocks_count', '1' ),
			// A popup after all content.
			self::create_inline_popup( 'blocks_count', '4' ),
		];
		self::assertEqualBlockNames(
			[
				'core/shortcode', // Popup 1.
				'core/html',
				'core/shortcode', // Popup 2.
				'core/heading',
				'core/html',
				'core/html',
				'core/shortcode', // Popup 3.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				$popups
			),
			'The popups are inserted into the content at expected positions.'
		);
	}

	/**
	 * A paragraph of filler text, long enough to carry weight in the position
	 * calculation, which counts stripped-of-tags bytes.
	 *
	 * @param string $label Identifies the text in a failure diff.
	 *
	 * @return string
	 */
	private static function filler( $label ) {
		return $label . ' ' . str_repeat( 'lorem ipsum dolor sit amet consectetur adipiscing elit ', 4 );
	}

	/**
	 * Post content whose bulk sits inside a list block: a short paragraph, a list
	 * carrying most of the text, then a short paragraph.
	 *
	 * @param bool $is_legacy Build a pre-WP-6.0 list (items in the block's own
	 *                        innerHTML) rather than a modern one (items as
	 *                        `core/list-item` inner blocks).
	 *
	 * @return string
	 */
	private static function list_heavy_content( $is_legacy = false ) {
		$items = '';
		for ( $i = 1; $i <= 4; $i++ ) {
			$item   = '<li>' . self::filler( "List item {$i}" ) . '</li>';
			$items .= $is_legacy ? $item : "<!-- wp:list-item -->{$item}<!-- /wp:list-item -->";
		}

		$list = $is_legacy
			? "<!-- wp:list --><ul>{$items}</ul><!-- /wp:list -->"
			: "<!-- wp:list --><ul class=\"wp-block-list\">{$items}</ul><!-- /wp:list -->";

		return '<!-- wp:paragraph --><p>' . self::filler( 'Paragraph 1' ) . '</p><!-- /wp:paragraph -->'
			. $list
			. '<!-- wp:paragraph --><p>' . self::filler( 'Paragraph 2' ) . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * A prompt positioned by percentage must account for text held in a list
	 * block's inner `core/list-item` blocks. Otherwise the list inflates the
	 * target position while contributing nothing towards reaching it, and the
	 * prompt is pushed to the end of the post. See NPPM-596.
	 */
	public function test_insertion_accounts_for_list_inner_blocks() {
		self::assertEqualBlockNames(
			[
				'core/paragraph',
				'core/shortcode', // Prompt – inserted at the halfway mark, which falls inside the list.
				'core/list',
				'core/paragraph',
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				self::list_heavy_content(),
				[ self::create_inline_popup( 'scroll', '50' ) ]
			),
			'A prompt at 50% is inserted mid-content, not pushed to the end, when the content is mostly a list.'
		);
	}

	/**
	 * Gutenberg moved list items into `core/list-item` inner blocks in WP 6.0.
	 * Both markup styles render the same text to a reader, so a prompt must land
	 * in the same place for both. Before NPPM-596 only the legacy markup was
	 * counted, so placement silently depended on whether a post's list block had
	 * ever been upgraded.
	 */
	public function test_insertion_is_the_same_for_legacy_and_modern_lists() {
		// Assert the absolute position for each variant rather than only that the two
		// agree with each other: if a regression stopped counting list text of either
		// vintage, both arms would degrade identically and a mutual comparison would
		// stay green while the NPPM-596 symptom was back.
		$expected = [
			'core/paragraph',
			'core/shortcode', // Prompt – at the halfway mark, which falls inside the list.
			'core/list',
			'core/paragraph',
		];

		$vintages = [
			'legacy' => true,
			'modern' => false,
		];

		foreach ( $vintages as $vintage => $is_legacy ) {
			self::assertEqualBlockNames(
				$expected,
				Newspack_Popups_Inserter::insert_popups_in_post_content(
					self::list_heavy_content( $is_legacy ),
					[ self::create_inline_popup( 'scroll', '50' ) ]
				),
				"A prompt at 50% lands in the expected position for a {$vintage} list."
			);
		}
	}

	/**
	 * A post may mix list vintages – an old post edited after WP 6.0 can hold both.
	 * Blocks are measured individually, so both must be counted.
	 */
	public function test_insertion_accounts_for_mixed_legacy_and_modern_lists() {
		$legacy_items = '';
		$modern_items = '';
		for ( $i = 1; $i <= 2; $i++ ) {
			$legacy_items .= '<li>' . self::filler( "Legacy item {$i}" ) . '</li>';
			$modern_items .= '<!-- wp:list-item --><li>' . self::filler( "Modern item {$i}" ) . '</li><!-- /wp:list-item -->';
		}

		$post_content = '<!-- wp:paragraph --><p>' . self::filler( 'Paragraph 1' ) . '</p><!-- /wp:paragraph -->'
			. "<!-- wp:list --><ul>{$legacy_items}</ul><!-- /wp:list -->"
			. "<!-- wp:list --><ul class=\"wp-block-list\">{$modern_items}</ul><!-- /wp:list -->"
			. '<!-- wp:paragraph --><p>' . self::filler( 'Paragraph 2' ) . '</p><!-- /wp:paragraph -->';

		self::assertEqualBlockNames(
			[
				'core/paragraph',
				'core/list', // Legacy list – its text carries the cursor past the halfway mark.
				'core/shortcode', // Prompt.
				'core/list',
				'core/paragraph',
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				[ self::create_inline_popup( 'scroll', '50' ) ]
			),
			'Both list vintages are counted when a single post mixes them.'
		);
	}

	/**
	 * Quote blocks also hold their text in inner blocks, and must be counted the
	 * same way lists are.
	 */
	public function test_insertion_accounts_for_quote_inner_blocks() {
		$quote = '<!-- wp:quote --><blockquote class="wp-block-quote">'
			. '<!-- wp:paragraph --><p>' . self::filler( 'Quoted 1' ) . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>' . self::filler( 'Quoted 2' ) . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>' . self::filler( 'Quoted 3' ) . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>' . self::filler( 'Quoted 4' ) . '</p><!-- /wp:paragraph -->'
			. '</blockquote><!-- /wp:quote -->';

		$post_content = '<!-- wp:paragraph --><p>' . self::filler( 'Paragraph 1' ) . '</p><!-- /wp:paragraph -->'
			. $quote
			. '<!-- wp:paragraph --><p>' . self::filler( 'Paragraph 2' ) . '</p><!-- /wp:paragraph -->';

		self::assertEqualBlockNames(
			[
				'core/paragraph',
				'core/shortcode', // Prompt.
				'core/quote',
				'core/paragraph',
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				[ self::create_inline_popup( 'scroll', '50' ) ]
			),
			'A prompt at 50% accounts for text held in a quote block\'s inner blocks.'
		);
	}

	/**
	 * Guard test. Layout containers (`core/columns`, `core/group`) are NOT counted
	 * towards the insertion cursor, so prompts fall to the end of container-heavy
	 * layouts – which is what homepages are built from, and what publishers have
	 * come to rely on. Counting them relocates every existing homepage prompt,
	 * which is why https://github.com/Automattic/newspack-popups/pull/855 was
	 * reverted within a day.
	 *
	 * If this test fails, you have re-introduced that regression. Do not "fix" it
	 * by editing the expectation.
	 */
	public function test_insertion_leaves_container_heavy_layouts_alone() {
		$columns = '';
		for ( $i = 1; $i <= 3; $i++ ) {
			$columns .= '<!-- wp:columns --><div class="wp-block-columns">'
				. '<!-- wp:column --><div class="wp-block-column">'
				. '<!-- wp:paragraph --><p>' . self::filler( "Column {$i} text" ) . '</p><!-- /wp:paragraph -->'
				. '</div><!-- /wp:column -->'
				. '</div><!-- /wp:columns -->';
		}

		self::assertEqualBlockNames(
			[
				'core/columns',
				'core/columns',
				'core/columns',
				'core/shortcode', // Prompt – falls to the end, as it does today.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$columns,
				[ self::create_inline_popup( 'scroll', '50' ) ]
			),
			'A prompt on a container-heavy layout still falls to the end of the content.'
		);
	}

	/**
	 * Test prompt insertion at 0% with a single Group block.
	 * Group blocks are treated as single blocks to provide editors with a way
	 * to prevent prompt insertion between specific bits of content.
	 */
	public function test_prompt_insertion_zero_group() {
		$post_content = '
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Paragraph 1</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Paragraph 2</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Paragraph 3</p>
<!-- /wp:paragraph -->

<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 id="inner-group">Inner group</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Inner Paragraph 1</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Inner Paragraph 2</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:paragraph -->
<p>Paragraph 4</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Paragraph 5</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->';

		self::assertEqualBlockNames(
			[
				'core/shortcode', // Popup 1 - inserted before the group block.
				'core/group',
				'core/shortcode', // Popup 2 - inserted after the group block.
				'core/shortcode', // Popup 3.
			],
			Newspack_Popups_Inserter::insert_popups_in_post_content(
				$post_content,
				self::$popups
			),
			'The popups are inserted into the content at expected positions.'
		);
	}
}
