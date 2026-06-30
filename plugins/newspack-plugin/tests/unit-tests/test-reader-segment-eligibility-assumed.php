<?php
/**
 * Reader_Segment_Eligibility::matches_assumed() — preview-mode segment check
 * (required ∩ assumed), engine-free.
 */

use Newspack\Reader_Segment_Eligibility;

class Reader_Segment_Eligibility_Assumed_Test extends WP_UnitTestCase {
	public function test_intersection_decides_the_match(): void {
		$this->assertTrue( Reader_Segment_Eligibility::matches_assumed( [ 1, 2 ], [ 2, 3 ] ) );
		$this->assertFalse( Reader_Segment_Eligibility::matches_assumed( [ 1 ], [ 2, 3 ] ) );
		$this->assertFalse( Reader_Segment_Eligibility::matches_assumed( [ 1 ], [] ) );
		$this->assertTrue( Reader_Segment_Eligibility::matches_assumed( [ '5' ], [ 5 ] ), 'String/int ids compare equal.' );
	}
}
