<?php
/**
 * Case 1, undocumented: an indirect guard with no docblock must still be
 * visible, so it can be reported as undocumented rather than vanishing.
 *
 * @package Newspack_Workspace
 */

/**
 * Holder.
 */
class Newspack_Fixture_Indirect_Undocumented {
	const FEATURE_FLAG_NAME = 'NEWSPACK_FIXTURE_INDIRECT_UNDOCUMENTED';

	/**
	 * Is it on?
	 *
	 * @return bool
	 */
	public static function is_on(): bool {
		return defined( self::FEATURE_FLAG_NAME ) && true === constant( self::FEATURE_FLAG_NAME );
	}
}
