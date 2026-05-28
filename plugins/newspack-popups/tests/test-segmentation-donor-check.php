<?php
/**
 * Tests for donor merge field value checking in Newspack_Popups_Segmentation.
 *
 * @package Newspack_Popups
 */

/**
 * Test the is_donor_merge_field_value method.
 */
class SegmentationDonorCheckTest extends WP_UnitTestCase {

	/**
	 * Values that should be treated as positive donor indicators.
	 *
	 * @return array[] Test cases as [ value, description ].
	 */
	public function donor_positive_values() {
		return [
			'string yes'     => [ 'yes', 'string "yes"' ],
			'string Yes'     => [ 'Yes', 'string "Yes" (mixed case)' ],
			'string true'    => [ 'true', 'string "true"' ],
			'string True'    => [ 'True', 'string "True" (mixed case)' ],
			'numeric 1'      => [ '1', 'string "1"' ],
			'integer 1'      => [ 1, 'integer 1' ],
			'numeric 5'      => [ '5', 'string "5"' ],
			'date string'    => [ '2024-01-15', 'date string' ],
			'dollar amount'  => [ '$50.00', 'dollar amount' ],
			'numeric amount' => [ '50.00', 'numeric amount' ],
			'arbitrary text' => [ 'monthly', 'arbitrary text value' ],
			'string donor'   => [ 'donor', 'string "donor"' ],
			'boolean true'   => [ true, 'boolean true' ],
		];
	}

	/**
	 * Values that should NOT be treated as donor indicators.
	 *
	 * @return array[] Test cases as [ value, description ].
	 */
	public function donor_negative_values() {
		return [
			'empty string' => [ '', 'empty string' ],
			'string no'    => [ 'no', 'string "no"' ],
			'string No'    => [ 'No', 'string "No" (mixed case)' ],
			'string NO'    => [ 'NO', 'string "NO" (uppercase)' ],
			'string none'  => [ 'none', 'string "none"' ],
			'string None'  => [ 'None', 'string "None" (mixed case)' ],
			'string false' => [ 'false', 'string "false"' ],
			'string False' => [ 'False', 'string "False" (mixed case)' ],
			'string 0'     => [ '0', 'string "0"' ],
		];
	}

	/**
	 * Values that the OLD behavior (! empty()) would have treated as donor but the NEW
	 * behavior correctly rejects. These are the cases the change was designed to fix.
	 *
	 * @return array[] Test cases as [ value, description ].
	 */
	public function donor_previously_false_positive_values() {
		return [
			'string no'    => [ 'no', 'string "no"' ],
			'string No'    => [ 'No', 'string "No" (mixed case)' ],
			'string none'  => [ 'none', 'string "none"' ],
			'string None'  => [ 'None', 'string "None" (mixed case)' ],
			'string false' => [ 'false', 'string "false"' ],
			'string False' => [ 'False', 'string "False" (mixed case)' ],
		];
	}

	/**
	 * Test that positive values are treated as donor indicators.
	 *
	 * @dataProvider donor_positive_values
	 * @param mixed  $value       The merge field value.
	 * @param string $description Description of the test case.
	 */
	public function test_positive_donor_values( $value, $description ) {
		self::assertTrue(
			Newspack_Popups_Segmentation::is_donor_merge_field_value( $value ),
			"Value $description should be treated as a positive donor indicator."
		);
	}

	/**
	 * Test that negative/falsy values are not treated as donor indicators.
	 *
	 * @dataProvider donor_negative_values
	 * @param mixed  $value       The merge field value.
	 * @param string $description Description of the test case.
	 */
	public function test_negative_donor_values( $value, $description ) {
		self::assertFalse(
			Newspack_Popups_Segmentation::is_donor_merge_field_value( $value ),
			"Value $description should NOT be treated as a donor indicator."
		);
	}

	/**
	 * Test that values which were false positives under old behavior are now rejected.
	 *
	 * @dataProvider donor_previously_false_positive_values
	 * @param mixed  $value       The merge field value.
	 * @param string $description Description of the test case.
	 */
	public function test_previously_false_positive_values_are_now_rejected( $value, $description ) {
		// These values are non-empty (would pass `! empty()`) but should not indicate a donor.
		self::assertNotEmpty( $value, "Precondition: $description is non-empty, so old behavior would have flagged it as donor." );
		self::assertFalse(
			Newspack_Popups_Segmentation::is_donor_merge_field_value( $value ),
			"Value $description was a false positive under old behavior and should now be rejected."
		);
	}
}
