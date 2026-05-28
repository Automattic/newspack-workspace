<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Merge Tags Test
 *
 * @package Newspack_Popups
 */

use Newspack\Campaigns\Merge_Tags;

/**
 * Model test case.
 */
class MergeTagsTest extends WP_UnitTestCase {
	public function test_parse_empty_string() {
		$this->assertEquals( '', Merge_Tags::parse_tags( '' ) );
	}

	public function test_no_tag() {
		$this->assertEquals( 'Test string', Merge_Tags::parse_tags( 'Test string' ) );
	}

	public function test_not_found_tag() {
		$this->assertEquals( 'Test string {{not_found}}', Merge_Tags::parse_tags( 'Test string {{not_found}}' ) );
	}

	public function test_single_empty_tag() {
		Merge_Tags::register_tag( 'test_tag' );

		$this->assertEquals( 'Tag: <span class="merge-tag" data-tag="test_tag" ></span>', Merge_Tags::parse_tags( 'Tag: {{test_tag}}' ) );
	}

	public function test_single_tag() {
		Merge_Tags::register_tag(
			'test_tag',
			[
				'callback' => function() {
					return 'Test tag';
				},
			]
		);

		$this->assertEquals( '<span class="merge-tag" data-tag="test_tag" >Test tag</span>', Merge_Tags::parse_tags( '{{test_tag}}' ) );
	}

	public function test_multiple_tags() {
		Merge_Tags::register_tag(
			'test_tag',
			[
				'callback' => function() {
					return 'Test tag';
				},
			]
		);

		Merge_Tags::register_tag(
			'another_tag',
			[
				'callback' => function() {
					return 'Another tag';
				},
			]
		);

		$this->assertEquals( '<span class="merge-tag" data-tag="test_tag" >Test tag</span> and <span class="merge-tag" data-tag="another_tag" >Another tag</span>', Merge_Tags::parse_tags( '{{test_tag}} and {{another_tag}}' ) );
	}

	public function test_repetitive_tag() {
		Merge_Tags::register_tag(
			'test_tag',
			[
				'callback' => function() {
					return 'Test tag';
				},
			]
		);

		$this->assertEquals( '<span class="merge-tag" data-tag="test_tag" >Test tag</span> with <span class="merge-tag" data-tag="test_tag" >Test tag</span>', Merge_Tags::parse_tags( '{{test_tag}} with {{test_tag}}' ) );
	}

	public function test_default_tags() {
		$this->assertEquals( '<span class="merge-tag" data-tag="site_name" >' . get_bloginfo( 'name' ) . '</span>', Merge_Tags::parse_tags( '{{site_name}}' ) );
		$this->assertEquals( '<span class="merge-tag" data-tag="site_description" >' . get_bloginfo( 'description' ) . '</span>', Merge_Tags::parse_tags( '{{site_description}}' ) );
	}

	public function test_uppercase_tag() {
		Merge_Tags::register_tag(
			'test_tag',
			[
				'callback' => function() {
					return 'Test tag';
				},
			]
		);

		$this->assertEquals( '<span class="merge-tag" data-tag="test_tag" >Test tag</span>', Merge_Tags::parse_tags( '{{TEST_TAG}}' ) );
	}
}
