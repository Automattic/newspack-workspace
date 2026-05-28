<?php
/**
 * Tests for Copyright Date block.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\CopyrightDate\Copyright_Date_Block
 */

use Newspack\Blocks\CopyrightDate\Copyright_Date_Block;

/**
 * Test class for the Copyright Date Block.
 *
 * @group copyright-date-block
 */
class Newspack_Test_Copyright_Date_Block extends WP_UnitTestCase {

	/**
	 * Block CSS class derived from block name.
	 *
	 * @var string
	 */
	private $block_class;

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once NEWSPACK_ABSPATH . 'src/blocks/copyright-date/class-copyright-date-block.php';
		$this->block_class = wp_get_block_default_classname( Copyright_Date_Block::BLOCK_NAME );

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( Copyright_Date_Block::BLOCK_NAME ) ) {
			Copyright_Date_Block::register_block();
		}
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( Copyright_Date_Block::BLOCK_NAME ) ) {
			unregister_block_type( Copyright_Date_Block::BLOCK_NAME );
		}

		parent::tear_down();
	}

	/**
	 * Build block markup for do_blocks().
	 *
	 * @param array $attributes Optional block attributes.
	 * @return string Rendered block HTML.
	 */
	private function render_block( array $attributes = [] ) {
		$attrs = $attributes ? ' ' . wp_json_encode( $attributes ) : '';
		return do_blocks( '<!-- wp:' . Copyright_Date_Block::BLOCK_NAME . $attrs . ' /-->' );
	}

	/**
	 * Test default render includes current year and copyright symbol prefix.
	 */
	public function test_render_default_attributes() {
		$output = $this->render_block();

		$this->assertNotEmpty( $output, 'Block should produce output.' );
		$this->assertStringContainsString( $this->block_class, $output, 'Output should contain the block class.' );
		$this->assertStringContainsString( wp_date( 'Y' ), $output, 'Output should contain the current year.' );
		$this->assertStringContainsString( '©', $output, 'Default prefix should be the copyright symbol.' );
	}

	/**
	 * Test year is rendered in its own span.
	 */
	public function test_render_year_span() {
		$output = $this->render_block();
		$year   = wp_date( 'Y' );

		$this->assertStringContainsString(
			'<span class="' . $this->block_class . '__year">' . $year . '</span>',
			$output,
			'Year should be wrapped in a span with the __year BEM class.'
		);
	}

	/**
	 * Test prefix rendering: BEM span, custom text, and omitted when empty.
	 */
	public function test_render_prefix() {
		$default = $this->render_block( [ 'prefix' => "\u{00a9}" ] );
		$this->assertStringContainsString(
			'<span class="' . $this->block_class . '__prefix">©</span>',
			$default,
			'Default prefix should render © in a __prefix span.'
		);

		$custom = $this->render_block( [ 'prefix' => 'Copyright' ] );
		$this->assertStringContainsString( '<span class="' . $this->block_class . '__prefix">Copyright</span>', $custom, 'Custom prefix text should render in a __prefix span.' );
		$this->assertStringNotContainsString( '©', $custom, 'Custom prefix should not contain the copyright symbol.' );

		$empty = $this->render_block( [ 'prefix' => '' ] );
		$this->assertStringNotContainsString( $this->block_class . '__prefix', $empty, 'Empty prefix should omit the __prefix span.' );
		$this->assertStringContainsString( $this->block_class . '__year', $empty, 'Year should still render when prefix is empty.' );
	}

	/**
	 * Test suffix rendering: BEM span when provided, omitted when empty.
	 */
	public function test_render_suffix() {
		$with_suffix = $this->render_block(
			[
				'prefix' => "\u{00a9}",
				'suffix' => 'My Publication',
			]
		);
		$this->assertStringContainsString( '<span class="' . $this->block_class . '__suffix">My Publication</span>', $with_suffix, 'Suffix should render in a __suffix span.' );

		$empty = $this->render_block(
			[
				'prefix' => "\u{00a9}",
				'suffix' => '',
			]
		);
		$this->assertStringNotContainsString( '<span class="' . $this->block_class . '__suffix">', $empty, 'Empty suffix should omit the __suffix span.' );
	}

	/**
	 * Test only link tags are allowed; all other HTML is stripped.
	 */
	public function test_only_links_are_allowed() {
		$link   = '<a href="https://example.com">Acme Inc</a>';
		$output = $this->render_block(
			[
				'prefix' => $link,
				'suffix' => $link,
			]
		);
		$this->assertSame( 2, substr_count( $output, $link ), 'Links should be preserved in both prefix and suffix.' );

		$other_html = '<strong>bold</strong> <em>italic</em> <script>alert(1)</script>';
		$output     = $this->render_block(
			[
				'prefix' => $other_html,
				'suffix' => $other_html,
			]
		);
		$this->assertStringNotContainsString( '<strong>', $output, 'Strong tags should be stripped.' );
		$this->assertStringNotContainsString( '<em>', $output, 'Em tags should be stripped.' );
		$this->assertStringNotContainsString( '<script>', $output, 'Script tags should be stripped.' );
	}

	/**
	 * Test output wraps in div with block wrapper attributes.
	 */
	public function test_render_wrapper_element() {
		$output = $this->render_block();

		$this->assertMatchesRegularExpression(
			'/<div\s[^>]*class="[^"]*' . $this->block_class . '[^"]*"/',
			$output,
			'Output should be wrapped in a div with the block class.'
		);
	}

	/**
	 * Test parts render in correct order with space separation.
	 */
	public function test_render_parts_order() {
		$output = $this->render_block(
			[
				'prefix' => "\u{00a9}",
				'suffix' => 'Acme Inc',
			]
		);

		$this->assertStringContainsString( '</span><span class="' . $this->block_class . '__year">', $output, 'Prefix and year should be adjacent (spacing controlled by prefix content).' );
		$this->assertStringContainsString( '</span> <span class="' . $this->block_class . '__suffix">', $output, 'Space should separate year and suffix.' );

		$prefix_pos = strpos( $output, $this->block_class . '__prefix' );
		$year_pos   = strpos( $output, $this->block_class . '__year' );
		$suffix_pos = strpos( $output, $this->block_class . '__suffix' );

		$this->assertGreaterThan( $prefix_pos, $year_pos, 'Year should appear after prefix.' );
		$this->assertGreaterThan( $year_pos, $suffix_pos, 'Suffix should appear after year.' );
	}

	/**
	 * Test render with only year (no prefix, no suffix).
	 */
	public function test_render_year_only() {
		$output = $this->render_block(
			[
				'prefix' => '',
				'suffix' => '',
			]
		);
		$year   = wp_date( 'Y' );

		$this->assertStringContainsString( $year, $output, 'Year should render even without prefix and suffix.' );
		$this->assertStringNotContainsString( $this->block_class . '__prefix', $output, 'Prefix span should be omitted.' );
		$this->assertStringNotContainsString( $this->block_class . '__suffix', $output, 'Suffix span should be omitted.' );
	}
}
