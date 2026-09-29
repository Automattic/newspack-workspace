<?php
/**
 * Case 1: a guard whose argument is a class constant, not a literal.
 *
 * @package Newspack_Workspace
 */

/**
 * Holder.
 */
class Newspack_Fixture_Indirect {
	const FLAG = 'NEWSPACK_FIXTURE_INDIRECT';

	/**
	 * Is it on?
	 *
	 * @return bool
	 */
	public static function is_on(): bool {
		/**
		 * Indirectly guarded flag.
		 *
		 * @constant NEWSPACK_FIXTURE_INDIRECT
		 * @type     bool
		 * @default  false
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_FIXTURE_INDIRECT', true );
		 */
		return defined( self::FLAG ) && constant( self::FLAG );
	}
}
