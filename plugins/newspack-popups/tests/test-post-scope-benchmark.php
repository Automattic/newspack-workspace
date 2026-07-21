<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class PostScopeBenchmark
 *
 * Measures whether accumulating many post-scoped prompts degrades the general
 * eligible-prompts query. Skipped unless NEWSPACK_BENCH=1 (it seeds thousands
 * of posts and is slow). Run:
 *   NEWSPACK_BENCH=1 vendor/bin/phpunit --filter PostScopeBenchmark
 *
 * @package Newspack_Popups
 */

/**
 * Post scope benchmark.
 */
class PostScopeBenchmark extends WP_UnitTestCase {
	const SITEWIDE_PROMPTS = 20;
	const STEPS            = [ 0, 500, 1000, 2000 ];
	const ITERATIONS       = 10;

	/**
	 * Seed site-wide inline prompts and time the eligible query as scoped
	 * prompts accumulate. The eligible set must stay correct (only the
	 * site-wide prompts) and the query time must not grow with scoped count.
	 */
	public function test_eligible_query_stays_flat_as_scoped_prompts_grow() {
		if ( '1' !== getenv( 'NEWSPACK_BENCH' ) ) {
			$this->markTestSkipped( 'Set NEWSPACK_BENCH=1 to run the scoping benchmark.' );
		}

		// Baseline population: site-wide inline prompts that should always be eligible.
		$sitewide_ids = [];
		for ( $i = 0; $i < self::SITEWIDE_PROMPTS; $i++ ) {
			$sitewide_ids[] = self::make_inline_prompt();
		}
		$target_post = self::factory()->post->create( [ 'post_type' => 'post' ] );

		fwrite( STDERR, "\n\nScoped-prompt scaling — eligible query over " . self::ITERATIONS . " runs\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
		fwrite( STDERR, str_pad( 'scoped prompts', 16 ) . str_pad( 'eligible count', 16 ) . str_pad( 'avg query ms', 14 ) . "scoped-lookup ms\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite

		$created_scoped = 0;
		$first_ms       = null;
		foreach ( self::STEPS as $target_count ) {
			while ( $created_scoped < $target_count ) {
				self::make_scoped_prompt( $target_post );
				$created_scoped++;
			}

			$eligible_ms = self::time_call(
				function () use ( &$eligible_count ) {
					$eligible       = Newspack_Popups_Model::retrieve_eligible_popups();
					$eligible_count = count( $eligible );
				}
			);

			$scoped_ms = self::time_call(
				function () use ( $target_post ) {
					Newspack_Popups_Model::retrieve_scoped_popups( $target_post );
				}
			);

			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
			fwrite(
				STDERR,
				str_pad( (string) $created_scoped, 16 )
				. str_pad( (string) $eligible_count, 16 )
				. str_pad( number_format( $eligible_ms, 2 ), 14 )
				. number_format( $scoped_ms, 2 ) . "\n"
			);

			// Correctness at scale: only the site-wide prompts are ever eligible.
			$this->assertSame( self::SITEWIDE_PROMPTS, $eligible_count, 'Eligible set must stay at ' . self::SITEWIDE_PROMPTS . " with $created_scoped scoped prompts present." );

			if ( null === $first_ms ) {
				$first_ms = $eligible_ms;
			}
		}

		// With vs. without the exclusion at max scale — quantify what exclusion buys.
		$with_ms    = self::time_call( fn() => Newspack_Popups_Model::retrieve_eligible_popups() );
		$without_ms = self::time_call( fn() => self::query_without_exclusion() );
		fwrite( STDERR, "\nAt $created_scoped scoped prompts: with exclusion " . number_format( $with_ms, 2 ) . ' ms, without exclusion ' . number_format( $without_ms, 2 ) . " ms\n\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite

		// The whole point: scoped-prompt volume must not blow up the eligible query.
		// Allow generous headroom for CI noise; we're guarding against order-of-magnitude growth.
		$last_ms = $eligible_ms;
		$this->assertLessThan(
			max( 5.0, $first_ms * 5 ),
			$last_ms,
			'Eligible query time should not grow materially with scoped-prompt count.'
		);
	}

	/**
	 * Time a callable in milliseconds, averaged over ITERATIONS, flushing the
	 * object cache before each run so we measure the query, not a cache hit.
	 *
	 * @param callable $fn Callable to time.
	 * @return float Average milliseconds.
	 */
	private static function time_call( $fn ) {
		$total = 0.0;
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			wp_cache_flush();
			$start = microtime( true );
			$fn();
			$total += ( microtime( true ) - $start ) * 1000;
		}
		return $total / self::ITERATIONS;
	}

	/**
	 * The eligible query as it would run WITHOUT the scoped exclusion, for comparison.
	 *
	 * @return array
	 */
	private static function query_without_exclusion() {
		$placements = [ 'top', 'bottom', 'center', 'bottom_right', 'bottom_left', 'top_right', 'top_left', 'center_right', 'center_left', 'inline', 'above_header', 'archives' ];
		$args       = [
			'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'meta_key'       => 'placement', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $placements, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_compare'   => 'IN',
		];
		return ( new WP_Query( $args ) )->posts;
	}

	/**
	 * Create a published site-wide inline prompt.
	 *
	 * @return int
	 */
	private static function make_inline_prompt() {
		$id = self::factory()->post->create(
			[
				'post_type'   => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $id, 'placement', 'inline' );
		return $id;
	}

	/**
	 * Create a published inline prompt scoped to a post (as a real inline prompt,
	 * so it would qualify for the eligible query if not for the exclusion).
	 *
	 * @param int $post_id Parent post.
	 * @return int
	 */
	private static function make_scoped_prompt( $post_id ) {
		$id = self::make_inline_prompt();
		wp_update_post(
			[
				'ID'          => $id,
				'post_parent' => $post_id,
			]
		);
		return $id;
	}
}
