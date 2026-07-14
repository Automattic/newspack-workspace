<?php
/**
 * Compatibility shim for filter_input( INPUT_POST, ... ) in the Reader
 * Registration block namespace during PHPUnit tests.
 *
 * PHP populates INPUT_POST from the SAPI's original request body, which is
 * empty under the CLI/PHPUnit runner, so tests that write to $_POST cannot
 * drive filter_input( INPUT_POST, ... ). Routing those reads through $_POST
 * lets us exercise Newspack\Blocks\ReaderRegistration\process_form() directly.
 *
 * Mirrors tests/mocks/filter-input-mock.php (which covers the Newspack
 * namespace / INPUT_GET).
 *
 * @package Newspack\Tests
 */

namespace Newspack\Blocks\ReaderRegistration;

if ( ! function_exists( __NAMESPACE__ . '\\filter_input' ) ) {
	/**
	 * Provides access to $_POST during PHPUnit runs where filter_input() is not populated.
	 *
	 * @param int       $type          One of INPUT_* constants.
	 * @param string    $variable_name Variable name.
	 * @param int       $filter        Filter ID. Default: FILTER_DEFAULT.
	 * @param array|int $options       Filter options (defaults to 0, matching PHP's signature).
	 * @return mixed Sanitized value or null.
	 */
	function filter_input( $type, $variable_name, $filter = FILTER_DEFAULT, $options = 0 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( INPUT_POST === $type && array_key_exists( $variable_name, $_POST ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return \filter_var( $_POST[ $variable_name ], $filter, $options );
		}

		return \filter_input( $type, $variable_name, $filter, $options );
	}
}
