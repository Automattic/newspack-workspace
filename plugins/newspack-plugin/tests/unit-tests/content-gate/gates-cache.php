<?php
/**
 * Tests for the Content_Gate::get_gates() request cache.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;

/**
 * The cache is off by default under PHPUnit (tests are rolled back at the
 * database level, which fires none of the write hooks it is invalidated by), so
 * these tests turn it on for their own duration and restore the default in
 * tear_down. Without that they could not exercise the cache read, the cache
 * write or any of the invalidation hooks at all.
 *
 * @group content-gate
 */
class Test_Gates_Cache extends \WP_UnitTestCase {

	/**
	 * Turn the cache on for real.
	 */
	public function set_up() {
		parent::set_up();
		Content_Gate::set_gates_cache_enabled( true );
	}

	/**
	 * Restore the test-env default (off) so no later test inherits a warm cache.
	 */
	public function tear_down() {
		Content_Gate::set_gates_cache_enabled();
		parent::tear_down();
	}

	/**
	 * Publish a gate and return its ID.
	 *
	 * @param string $title Gate title.
	 *
	 * @return int
	 */
	private function publish_gate( $title ) {
		$gate_id = Content_Gate::create_gate( [ 'title' => $title ] );
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'    => $title,
				'status'   => 'publish',
				'priority' => 0,
			]
		);
		return $gate_id;
	}

	/**
	 * A repeated call is served from the cache: with the cache warm, the second
	 * call must not re-run the underlying get_posts().
	 */
	public function test_repeated_call_is_served_from_cache() {
		$this->publish_gate( 'Cached Gate' );
		Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' );

		// posts_pre_query rather than the_posts: get_posts() suppresses filters, so
		// the_posts never fires for the gate lookup. Returning null leaves the
		// query to run as normal.
		$gate_queries       = 0;
		$count_gate_queries = function ( $posts, $query ) use ( &$gate_queries ) {
			if ( Content_Gate::GATE_CPT === $query->get( 'post_type' ) ) {
				++$gate_queries;
			}
			return $posts;
		};
		add_filter( 'posts_pre_query', $count_gate_queries, 10, 2 );

		Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' );

		remove_filter( 'posts_pre_query', $count_gate_queries, 10 );

		$this->assertSame( 0, $gate_queries, 'A warm cache must answer without re-querying the gate CPT.' );
	}

	/**
	 * Publishing a gate invalidates the cache. Covers the `save_post` hook and,
	 * more importantly, the reason the cache needs invalidating at all: a warm
	 * "no gates" answer would otherwise make Access Control look inert for the
	 * rest of the request that just created its first gate.
	 */
	public function test_publishing_a_gate_invalidates_the_cache() {
		$this->assertEmpty(
			Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ),
			'Sanity: no published gate yet.'
		);

		$gate_id = $this->publish_gate( 'Late Gate' );

		$this->assertSame(
			[ $gate_id ],
			wp_list_pluck( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ), 'id' ),
			'A gate published after the cache warmed must be visible to the next call.'
		);
	}

	/**
	 * Gate settings are persisted with bare update_post_meta() calls, which fire
	 * no post-save hook — so the meta hooks are the only thing keeping the cache
	 * honest for the most common kind of gate edit.
	 */
	public function test_updating_gate_meta_invalidates_the_cache() {
		$gate_id = $this->publish_gate( 'Priority Gate' );
		$gates   = Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' );
		$this->assertSame( 0, $gates[0]['priority'], 'Sanity: the gate starts at priority 0.' );

		update_post_meta( $gate_id, 'gate_priority', 7 );

		$gates = Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' );
		$this->assertSame( 7, $gates[0]['priority'], 'A bare update_post_meta() on a gate must invalidate the cache.' );
	}

	/**
	 * Deleting a gate invalidates the cache too — the path that matters to every
	 * consumer keying off "does this site have any gate at all".
	 */
	public function test_deleting_a_gate_invalidates_the_cache() {
		$gate_id = $this->publish_gate( 'Doomed Gate' );
		$this->assertNotEmpty( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ), 'Sanity: the gate is cached.' );

		wp_delete_post( $gate_id, true );

		$this->assertEmpty(
			Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ),
			'A deleted gate must not survive in the cache.'
		);
	}
}
