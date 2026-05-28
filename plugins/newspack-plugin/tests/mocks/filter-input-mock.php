<?php
/**
 * Compatibility shim for filter_input() in PHPUnit tests.
 *
 * @package Newspack\Tests
 */

namespace Newspack;

if ( ! function_exists( __NAMESPACE__ . '\\filter_input' ) ) {
	/**
	 * Provides access to $_GET during PHPUnit runs where filter_input() is not populated.
	 *
	 * @param int       $type          One of INPUT_* constants.
	 * @param string    $variable_name Variable name.
	 * @param int       $filter        Filter ID. Default: FILTER_DEFAULT.
	 * @param array|int $options       Filter options (defaults to 0, matching PHP's signature).
	 * @return mixed Sanitized value or null.
	 */
	function filter_input( $type, $variable_name, $filter = FILTER_DEFAULT, $options = 0 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( INPUT_GET === $type && array_key_exists( $variable_name, $_GET ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = $_GET[ $variable_name ];

			return \filter_var( $value, $filter, $options );
		}

		return \filter_input( $type, $variable_name, $filter, $options );
	}
}
