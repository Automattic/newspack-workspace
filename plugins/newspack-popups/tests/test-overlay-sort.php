<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class OverlaySort Test
 *
 * Tests that sort_overlays_by_specificity() orders overlay prompts correctly:
 * segment-assigned overlays first (ascending by segment count), unsegmented last.
 *
 * @package Newspack_Popups
 */

/**
 * OverlaySort test case.
 */
class OverlaySortTest extends WP_UnitTestCase {

	/**
	 * Simpler access to the private sort_overlays_by_specificity method.
	 *
	 * (See `test-classic-block-encoding.php` too.)
	 *
	 * @var ReflectionMethod
	 */
	private static $sort_overlays_by_specificity;

	/**
	 * Set up.
	 */
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		if ( ! self::$sort_overlays_by_specificity ) {
			self::$sort_overlays_by_specificity = new ReflectionMethod( 'Newspack_Popups_Inserter', 'sort_overlays_by_specificity' );
			self::$sort_overlays_by_specificity->setAccessible( true );
		}
	}

	/**
	 * Mock up the overlay structure.
	 *
	 * The only fields sort_overlays_by_specificity() inspects are 'segments' (via count())
	 * and, to track round-trip ordering of equals, 'id'.
	 *
	 * @param int $segment_count Number of segments to assign to the overlay.
	 * @param int $id            Optional identifier used to verify relative ordering.
	 * @return array Minimal overlay array.
	 */
	private function make_overlay( int $segment_count, int $id = 0 ): array {
		return [
			'id'       => $id,
			'segments' => array_fill( 0, $segment_count, [] ),
		];
	}

	/**
	 * Overlays with zero segments assigned must appear after all segmented overlays.
	 */
	public function test_unsegmented_overlays_sort_last() {
		$overlays = [
			$this->make_overlay( 0 ),
			$this->make_overlay( 2 ),
			$this->make_overlay( 0 ),
			$this->make_overlay( 1 ),
		];

		$result = self::$sort_overlays_by_specificity->invoke( null, $overlays );

		// Find the highest index occupied by a segmented overlay and the lowest
		// index occupied by an unsegmented overlay; the former must be less than
		// the latter for the sort to be correct.
		$last_segmented_index    = -1;
		$first_unsegmented_index = PHP_INT_MAX;

		foreach ( $result as $index => $overlay ) {
			if ( 0 === count( $overlay['segments'] ) ) {
				$first_unsegmented_index = min( $first_unsegmented_index, $index );
			} else {
				$last_segmented_index = max( $last_segmented_index, $index );
			}
		}

		$this->assertGreaterThan(
			$last_segmented_index,
			$first_unsegmented_index,
			'All unsegmented overlays must appear after all segmented overlays.'
		);
	}

	/**
	 * Among overlays with non-zero segment counts, the sort must be ascending by count.
	 */
	public function test_segmented_overlays_sorted_by_ascending_count() {
		$overlays = [
			$this->make_overlay( 3 ),
			$this->make_overlay( 1 ),
			$this->make_overlay( 2 ),
		];

		$result = self::$sort_overlays_by_specificity->invoke( null, $overlays );

		$counts = array_map( fn( $o ) => count( $o['segments'] ), $result );

		$this->assertSame(
			[ 1, 2, 3 ],
			$counts,
			'Segmented overlays must be sorted in ascending order by segment count.'
		);
	}

	/**
	 * Overlays with identical segment counts must retain their original relative order.
	 *
	 * Claude notes that only PHP 8.0+ guarantees a stable sort.
	 */
	public function test_equal_segment_count_preserves_relative_order() {
		$overlays = [
			$this->make_overlay( 2, 1 ),
			$this->make_overlay( 2, 2 ),
			$this->make_overlay( 2, 3 ),
		];

		$result = self::$sort_overlays_by_specificity->invoke( null, $overlays );

		$ids = array_column( $result, 'id' );

		$this->assertSame(
			[ 1, 2, 3 ],
			$ids,
			'Overlays with equal segment counts must remain in their original relative order.'
		);
	}
}
