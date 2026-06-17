<?php
/**
 * Mock of the newspack-newsletters Tracking\Utils helper for popups tests.
 *
 * Mirrors the real helper's Mailchimp merge-tag syntax so the donor-segment
 * link handler can be tested without the newspack-newsletters plugin loaded.
 *
 * @package Newspack_Popups
 */

namespace Newspack_Newsletters\Tracking;

if ( ! class_exists( __NAMESPACE__ . '\Utils' ) ) {
	/**
	 * Minimal stand-in for the newspack-newsletters tracking helper.
	 */
	class Utils {
		/**
		 * Wrap a field in Mailchimp merge-tag delimiters.
		 *
		 * @param string $field Merge field tag.
		 * @return string
		 */
		public static function get_merge_tag( $field ) {
			$field = trim( (string) $field );
			return '' === $field ? '' : '*|' . $field . '|*';
		}
	}
}
