<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Gravity Forms class stub for tests.
 *
 * Stand-in for Gravity Forms' main class: the editor extension loads only
 * where the GF block exists, which is what this class signals. Tests that
 * exercise code paths gated on `class_exists( 'GFForms' )` include this
 * file. The class is defined globally once, so the GF-absent branch is not
 * exercised by any test in the same process.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'GFForms' ) ) {
	/**
	 * Minimal Gravity Forms stub. Nothing on it is called; its presence is
	 * the whole signal.
	 */
	class GFForms {}
}
