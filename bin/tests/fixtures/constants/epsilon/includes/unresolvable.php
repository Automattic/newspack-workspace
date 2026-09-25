<?php
/**
 * Indirect guards that cannot resolve to a NEWSPACK_ name must invent nothing.
 *
 * @package Newspack_Workspace
 */

/**
 * Holder.
 */
class Newspack_Fixture_Unresolvable {
	const OTHER_FLAG = 'SOMETHING_ELSE_ENTIRELY';

	/**
	 * A class constant holding a non-Newspack name.
	 *
	 * @return bool
	 */
	public static function other(): bool {
		return defined( self::OTHER_FLAG );
	}

	/**
	 * A variable argument, which is not statically resolvable.
	 *
	 * @param string $flag Flag name.
	 * @return bool
	 */
	public static function dynamic( string $flag ): bool {
		return defined( $flag );
	}

	/**
	 * A class constant that is never declared in this file.
	 *
	 * @return bool
	 */
	public static function missing(): bool {
		return defined( self::NOT_DECLARED_HERE );
	}
}
