<?php
/**
 * Stub of newspack-plugin's bundled newspack-blocks loader, for the guard tests.
 *
 * @package Newspack_Content_Blocks
 */

namespace Newspack;

/**
 * Reports the bundled copy as loaded.
 */
final class Bundled_Blocks {
	/**
	 * Whether the bundled copy has been loaded.
	 *
	 * @return bool
	 */
	public static function is_loaded() {
		return true;
	}
}
