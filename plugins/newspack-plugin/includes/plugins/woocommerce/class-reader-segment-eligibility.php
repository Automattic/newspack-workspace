<?php
/**
 * Reader Segment Eligibility — "is this reader in any of these segments?" against
 * the reader's segment snapshot. No dynamic-pricing-engine dependency, so it
 * loads and unit-tests without the engine present; the engine-coupled matcher
 * (Reader_Segment_Condition_Matcher) adapts it.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Pure segment-membership check against the reader-data snapshot.
 */
final class Reader_Segment_Eligibility {
	/**
	 * Whether the reader's last-known segment snapshot intersects any selected ID.
	 *
	 * @param int   $user_id      Reader user ID (0 / guest → false).
	 * @param array $selected_ids Segment IDs to match against (any-of).
	 * @return bool
	 */
	public static function is_in_any( int $user_id, array $selected_ids ): bool {
		if ( $user_id <= 0 || empty( $selected_ids ) ) {
			return false;
		}
		$selected = array_map( 'strval', $selected_ids );
		return (bool) array_intersect( $selected, Reader_Data::get_matched_segments( $user_id ) );
	}

	/**
	 * Preview-mode check: does any required segment appear in the assumed set?
	 * Used by the impact preview, which prices as-if a reader in a given segment
	 * instead of reading a real reader's snapshot. Engine-free; ids normalized to
	 * ints on both sides so string vs int ids compare equal.
	 *
	 * @param array $required_ids Segment IDs the rule requires (any-of).
	 * @param array $assumed_ids  Segment IDs the preview assumes the reader is in.
	 */
	public static function matches_assumed( array $required_ids, array $assumed_ids ): bool {
		$required = array_map( 'intval', $required_ids );
		$assumed  = array_map( 'intval', $assumed_ids );
		return (bool) array_intersect( $required, $assumed );
	}
}
