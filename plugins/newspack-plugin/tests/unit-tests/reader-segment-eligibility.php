<?php
/**
 * Tests for Reader_Segment_Eligibility.
 *
 * @package Newspack\Tests
 * @group reader-data
 */

use Newspack\Reader_Segment_Eligibility;
use Newspack\Reader_Data;

/**
 * Tests for Reader_Segment_Eligibility.
 *
 * @group reader-data
 */
class Newspack_Test_Reader_Segment_Eligibility extends WP_UnitTestCase {

	/**
	 * Create a reader whose snapshot holds the given segment IDs.
	 *
	 * @param array $segment_ids Segment IDs.
	 * @return int User ID.
	 */
	private function reader_in( array $segment_ids ): int {
		$uid = self::factory()->user->create();
		Reader_Data::update_item( $uid, 'matched_segments', wp_json_encode( $segment_ids ) );
		return $uid;
	}

	/**
	 * Guest (user_id=0) is never eligible regardless of selected segments.
	 */
	public function test_guest_is_never_eligible() {
		self::assertFalse( Reader_Segment_Eligibility::is_in_any( 0, [ '12' ] ) );
	}

	/**
	 * An empty selection list means no eligibility, even if the reader has segments.
	 */
	public function test_empty_selection_is_not_eligible() {
		$uid = $this->reader_in( [ '12' ] );
		self::assertFalse( Reader_Segment_Eligibility::is_in_any( $uid, [] ) );
	}

	/**
	 * Reader whose snapshot includes a selected segment is eligible.
	 */
	public function test_reader_in_selected_segment_is_eligible() {
		$uid = $this->reader_in( [ '12', '43' ] );
		self::assertTrue( Reader_Segment_Eligibility::is_in_any( $uid, [ '43' ] ) );
	}

	/**
	 * Reader whose snapshot does not overlap the selection is not eligible.
	 */
	public function test_reader_outside_selected_segments_is_not_eligible() {
		$uid = $this->reader_in( [ '12' ] );
		self::assertFalse( Reader_Segment_Eligibility::is_in_any( $uid, [ '99' ] ) );
	}

	/**
	 * Any-of semantics: match when reader is in at least one of several selected segments.
	 */
	public function test_any_of_matches_when_in_one_of_several() {
		$uid = $this->reader_in( [ '12' ] );
		self::assertTrue( Reader_Segment_Eligibility::is_in_any( $uid, [ '99', '12', '7' ] ) );
	}

	/**
	 * Integer segment IDs in the selection are coerced to strings for comparison.
	 */
	public function test_integer_ids_coerce_to_strings() {
		$uid = $this->reader_in( [ '12' ] );
		self::assertTrue( Reader_Segment_Eligibility::is_in_any( $uid, [ 12 ] ) );
	}
}
