<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Stand-in for the Campaigns A/B tests class on a site with the flag on.
 *
 * The newspack-popups plugin is not loaded in this test suite. The GA4 dimension
 * provisioner reads `Newspack_Popups_AB_Tests::is_enabled()` to decide whether
 * to spend slots on the A/B parameters, so a test aliases this class to that
 * name, in a separate process, to exercise the automatic detection.
 *
 * @package Newspack\Tests
 */

/**
 * Minimal stub. Only the surface the provisioner touches.
 */
class Newspack_Test_GA4_Campaigns_AB_Tests_Stub {
	/**
	 * What `is_enabled()` reports. Off by default, so a test proves the
	 * provisioner reads the flag and not merely the class's presence.
	 *
	 * @var bool
	 */
	public static $enabled = false;

	/**
	 * The flag, as Campaigns reports it.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return self::$enabled;
	}
}
