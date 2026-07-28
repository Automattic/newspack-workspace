<?php
/**
 * Tests for above-header prompt detection (NPPM-2934).
 *
 * @package Newspack_Popups
 */

/**
 * Above-header prompt detection test case.
 */
class AboveHeaderDetectionTest extends WP_UnitTestCase {
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		// Clear any value cached by a previous test (transients survive the DB
		// transaction rollback when a persistent object cache is in use).
		Newspack_Popups_Model::flush_above_header_cache();
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		Newspack_Popups_Model::flush_above_header_cache();
		parent::tear_down();
	}

	/**
	 * Create a prompt with a given placement and status.
	 *
	 * Deliberately does NOT flush the detection cache: the production invalidation
	 * hooks (save_post, placement-meta writes) must do that, so the cache-invalidation
	 * tests actually exercise them rather than a test-only flush.
	 *
	 * @param string $placement Placement meta value.
	 * @param string $status    Post status.
	 * @return int Prompt ID.
	 */
	private function create_prompt( $placement, $status = 'publish' ) {
		$prompt_id = self::factory()->post->create(
			[
				'post_type'   => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_title'  => 'A prompt',
				'post_status' => $status,
			]
		);
		update_post_meta( $prompt_id, 'placement', $placement );
		return $prompt_id;
	}

	/**
	 * With no prompts, there are no published above-header prompts.
	 */
	public function test_returns_false_without_prompts() {
		$this->assertFalse( Newspack_Popups_Model::has_published_above_header_prompts() );
	}

	/**
	 * A published above-header prompt is detected.
	 */
	public function test_detects_published_above_header_prompt() {
		$this->create_prompt( 'above_header', 'publish' );
		$this->assertTrue( Newspack_Popups_Model::has_published_above_header_prompts() );
	}

	/**
	 * A draft above-header prompt is not counted.
	 */
	public function test_ignores_unpublished_above_header_prompt() {
		$this->create_prompt( 'above_header', 'draft' );
		$this->assertFalse( Newspack_Popups_Model::has_published_above_header_prompts() );
	}

	/**
	 * A published prompt with a different placement is not counted.
	 */
	public function test_ignores_non_above_header_prompt() {
		$this->create_prompt( 'inline', 'publish' );
		$this->assertFalse( Newspack_Popups_Model::has_published_above_header_prompts() );
	}

	/**
	 * Publishing an above-header prompt invalidates a cached "false" result so the
	 * Perfmatters integration picks up the change without waiting for the TTL. No manual
	 * flush here – the production invalidation hooks (save_post and the placement-meta
	 * write) must do it.
	 */
	public function test_publishing_prompt_invalidates_cache() {
		// Prime the cache with a negative result.
		$this->assertFalse( Newspack_Popups_Model::has_published_above_header_prompts() );

		$this->create_prompt( 'above_header', 'publish' );

		$this->assertTrue( Newspack_Popups_Model::has_published_above_header_prompts() );
	}

	/**
	 * Trashing the only above-header prompt invalidates the cached "true" result via
	 * the transition_post_status hook.
	 */
	public function test_trashing_prompt_invalidates_cache() {
		$prompt_id = $this->create_prompt( 'above_header', 'publish' );
		$this->assertTrue( Newspack_Popups_Model::has_published_above_header_prompts() );

		wp_trash_post( $prompt_id );

		$this->assertFalse( Newspack_Popups_Model::has_published_above_header_prompts() );
	}

	/**
	 * Permanently deleting the only above-header prompt invalidates the cache. This
	 * covers the force-delete path that transition_post_status does not (handled by the
	 * before_delete_post hook).
	 */
	public function test_force_deleting_prompt_invalidates_cache() {
		$prompt_id = $this->create_prompt( 'above_header', 'publish' );
		$this->assertTrue( Newspack_Popups_Model::has_published_above_header_prompts() );

		wp_delete_post( $prompt_id, true );

		$this->assertFalse( Newspack_Popups_Model::has_published_above_header_prompts() );
	}

	/**
	 * The Perfmatters integration reads this on every get_option( 'perfmatters_options' ),
	 * many times per request, so the result is memoized for the request: a repeat call
	 * must not hit the database at all.
	 */
	public function test_result_is_memoized_within_the_request() {
		$this->create_prompt( 'above_header', 'publish' );
		$this->assertTrue( Newspack_Popups_Model::has_published_above_header_prompts() );

		$queries_after_first_call = get_num_queries();
		Newspack_Popups_Model::has_published_above_header_prompts();

		$this->assertSame( $queries_after_first_call, get_num_queries() );
	}

	/**
	 * Changing a published prompt's placement away from above_header invalidates the
	 * cache via the placement-meta hook, even without a post save.
	 */
	public function test_changing_placement_away_invalidates_cache() {
		$prompt_id = $this->create_prompt( 'above_header', 'publish' );
		$this->assertTrue( Newspack_Popups_Model::has_published_above_header_prompts() );

		update_post_meta( $prompt_id, 'placement', 'inline' );

		$this->assertFalse( Newspack_Popups_Model::has_published_above_header_prompts() );
	}
}
