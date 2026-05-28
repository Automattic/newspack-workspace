<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class CachingTest
 *
 * @package Newspack_Blocks
 */

/**
 * Newspack_Blocks_Caching test case.
 *
 * @group caching
 */
class CachingTest extends WP_UnitTestCase { // phpcs:ignore

	/**
	 * A self-referencing synced pattern should not cause infinite recursion
	 * in check_all_blocks_cache_status().
	 */
	public function test_self_referencing_reusable_block_does_not_recurse() {
		// Create a synced pattern (wp_block) with placeholder content.
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Recursive Pattern',
				'post_content' => '<!-- wp:newspack-blocks/homepage-articles /-->',
			]
		);

		// Update the pattern to include a reference to itself.
		wp_update_post(
			[
				'ID'           => $pattern_id,
				'post_content' => sprintf(
					'<!-- wp:newspack-blocks/homepage-articles /--><!-- wp:block {"ref":%d} /-->',
					$pattern_id
				),
			]
		);

		// Create a post that embeds the recursive pattern.
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => sprintf(
					'<!-- wp:block {"ref":%d} /-->',
					$pattern_id
				),
			]
		);

		// Simulate a singular request for this post.
		$this->go_to( get_permalink( $post_id ) );

		// If the recursion guard is working, this completes without a fatal error.
		Newspack_Blocks_Caching::check_all_blocks_cache_status();

		// Reaching this assertion means no infinite recursion occurred.
		$this->assertTrue( true, 'check_all_blocks_cache_status() completed without infinite recursion.' );
	}

	/**
	 * Two distinct synced patterns embedded in the same post should both
	 * be traversed by check_all_blocks_cache_status().
	 */
	public function test_non_recursive_reusable_blocks_are_traversed() {
		$pattern_a = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern A',
				'post_content' => '<!-- wp:newspack-blocks/homepage-articles /-->',
			]
		);
		$pattern_b = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern B',
				'post_content' => '<!-- wp:newspack-blocks/homepage-articles /-->',
			]
		);

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => sprintf(
					'<!-- wp:block {"ref":%d} /--><!-- wp:block {"ref":%d} /-->',
					$pattern_a,
					$pattern_b
				),
			]
		);

		$this->go_to( get_permalink( $post_id ) );

		// Should complete without error — neither pattern references the other.
		Newspack_Blocks_Caching::check_all_blocks_cache_status();
		$this->assertTrue( true, 'Non-recursive patterns traversed without error.' );
	}

	/**
	 * A cycle between two synced patterns (A references B, B references A)
	 * should be caught by the recursion guard.
	 */
	public function test_mutual_recursion_between_patterns_is_caught() {
		// Create two patterns with placeholder content first.
		$pattern_a = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern A',
				'post_content' => '<!-- wp:paragraph --><p>placeholder</p><!-- /wp:paragraph -->',
			]
		);
		$pattern_b = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern B',
				'post_content' => '<!-- wp:paragraph --><p>placeholder</p><!-- /wp:paragraph -->',
			]
		);

		// Now wire them into a cycle: A includes B, B includes A.
		wp_update_post(
			[
				'ID'           => $pattern_a,
				'post_content' => sprintf(
					'<!-- wp:newspack-blocks/homepage-articles /--><!-- wp:block {"ref":%d} /-->',
					$pattern_b
				),
			]
		);
		wp_update_post(
			[
				'ID'           => $pattern_b,
				'post_content' => sprintf(
					'<!-- wp:newspack-blocks/homepage-articles /--><!-- wp:block {"ref":%d} /-->',
					$pattern_a
				),
			]
		);

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => sprintf(
					'<!-- wp:block {"ref":%d} /-->',
					$pattern_a
				),
			]
		);

		$this->go_to( get_permalink( $post_id ) );

		Newspack_Blocks_Caching::check_all_blocks_cache_status();
		$this->assertTrue( true, 'Mutual recursion between two patterns was caught.' );
	}
}
