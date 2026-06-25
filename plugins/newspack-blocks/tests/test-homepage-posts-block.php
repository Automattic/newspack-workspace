<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class HomepagePostsBlockTest
 *
 * @package Newspack_Blocks
 */

require_once __DIR__ . '/class-newspack-tag-labels-stub.php';

/**
 * Homepage Posts Block test case.
 */
class HomepagePostsBlockTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * HPB query from attributes.
	 */
	public function test_hpb_build_articles_query() {
		$cases = [
			[
				'block_attributes'        => [
					'postsToShow' => 5,
				],
				'resulting_query_partial' => [
					'posts_per_page' => 5,
					'post_status'    => [ 'publish' ],
					'post_type'      => [ 'post' ],
					'tax_query'      => [],
				],
				'description'             => 'Default attributes',
			],
			[
				'block_attributes'        => [
					'postsToShow' => 1,
					'postType'    => 'some-type',
					'authors'     => [ 1 ],
				],
				'resulting_query_partial' => [
					'posts_per_page' => 1,
					'post_type'      => 'some-type',
					'author__in'     => [ 1 ],
				],
				'description'             => 'With custom post type and author',
				'ignore_tax_query'        => true,
			],
		];

		foreach ( $cases as $case ) {
			$result = Newspack_Blocks::build_articles_query( $case['block_attributes'], 'newspack-blocks/homepage-articles' );
			if ( isset( $case['ignore_tax_query'] ) && $case['ignore_tax_query'] ) {
				// Tax query is an implementation detail in some cases.
				unset( $result['tax_query'] );
			}
			$this->assertEquals(
				self::get_args_with_defaults( $case['resulting_query_partial'] ),
				$result,
				$case['description']
			);
		}
	}

	/**
	 * Test the query manipulation.
	 */
	public function test_hpb_wp_query() {
		$cap_author = self::create_guest_author();
		$post_id    = self::create_post( $cap_author['term_id'] );

		global $coauthors_plus;
		$coauthors_plus = new CoAuthors_Plus_Mock(); // phpcs:ignore

		// Create another post.
		self::create_post();

		$block_attributes = [
			'postsToShow' => 1,
			'authors'     => [ $cap_author['id'] ],
		];
		$query_args       = Newspack_Blocks::build_articles_query( $block_attributes, 'newspack-blocks/homepage-articles' );
		$query            = new WP_Query( $query_args );

		self::assertEquals( 1, count( $query->posts ), 'There is one post returned.' );
		self::assertEquals( $post_id, $query->posts[0]->ID, 'The post returned is the one with the CAP author assigned.' );
	}

	/**
	 * The newspack_tag_labels REST field exposes the { flag, link } shape
	 * returned by \Newspack\Tag_Labels, normalized to a 0-indexed list.
	 *
	 * Locks in the cross-repo contract: the field passes through whatever
	 * \Newspack\Tag_Labels::get_labels_for_post() returns, so the plugin,
	 * blocks, and theme must agree on this shape.
	 */
	public function test_tag_labels_rest_field_shape() {
		if ( ! property_exists( '\Newspack\Tag_Labels', 'stub_labels' ) ) {
			$this->markTestSkipped( 'Real \Newspack\Tag_Labels present; stub-based contract test skipped.' );
		}
		$post_id = self::factory()->post->create();

		// Keyed input (as returned by Tag_Labels::get_labels_for_post()).
		\Newspack\Tag_Labels::$stub_labels = [
			42 => [
				'flag' => 'Breaking',
				'link' => 'https://example.org/tag/breaking/',
			],
		];

		$result = Newspack_Blocks_API::newspack_blocks_get_tag_labels( [ 'id' => $post_id ] );

		self::assertIsArray( $result );
		self::assertSame( [ 0 ], array_keys( $result ), 'Keyed input is normalized to a 0-indexed list.' );
		self::assertArrayHasKey( 'flag', $result[0] );
		self::assertArrayHasKey( 'link', $result[0] );
		self::assertSame( 'Breaking', $result[0]['flag'] );
		self::assertSame( 'https://example.org/tag/breaking/', $result[0]['link'] );

		\Newspack\Tag_Labels::$stub_labels = null;
	}

	/**
	 * The newspack_tag_labels REST field returns false when there are no labels.
	 */
	public function test_tag_labels_rest_field_empty_returns_false() {
		if ( ! property_exists( '\Newspack\Tag_Labels', 'stub_labels' ) ) {
			$this->markTestSkipped( 'Real \Newspack\Tag_Labels present; stub-based contract test skipped.' );
		}
		$post_id = self::factory()->post->create();

		\Newspack\Tag_Labels::$stub_labels = [];
		self::assertFalse( Newspack_Blocks_API::newspack_blocks_get_tag_labels( [ 'id' => $post_id ] ) );

		\Newspack\Tag_Labels::$stub_labels = null;
		self::assertFalse( Newspack_Blocks_API::newspack_blocks_get_tag_labels( [ 'id' => $post_id ] ) );
	}
}
