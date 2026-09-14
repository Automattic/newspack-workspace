<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Incoming_Field stub standing in for newspack-plugin.
 *
 * The schema mappers probe newspack-plugin for date-range support before
 * emitting the date_range operator (see
 * Newspack_Newsletters_Service_Provider::integrations_supports_date_range()).
 * This suite runs without newspack-plugin, which would exercise only the
 * degraded path; the stub declares the real class and method names so the
 * probe resolves exactly as on a site with a current newspack-plugin — a
 * mistyped name in the probe fails the date-mapping tests. The degraded path
 * is pinned separately through the probe's filter.
 *
 * @package Newspack_Newsletters\Tests
 */

namespace Newspack\Reader_Activation\Integrations;

if ( ! class_exists( __NAMESPACE__ . '\Incoming_Field' ) ) {
	/**
	 * Minimal Incoming_Field stub — only the probe surface.
	 */
	class Incoming_Field {
		/**
		 * The capability marker the schema mappers probe for.
		 *
		 * @param string $date_format A PHP date format string, or '' for ISO 8601.
		 * @return static
		 */
		public function set_date_format( $date_format ) {
			return $this;
		}
	}
}
