<?php
/**
 * Test-only exception used to intercept the subscribe block's redirect branch.
 *
 * @package Newspack_Newsletters
 */

/**
 * Thrown from the `wp_redirect` filter installed by
 * Subscribe_Block_Response_Test::test_non_json_request_does_not_emit_json() to
 * unwind out of send_form_response() before its `exit;` statement would end
 * the test process for real. `wp_redirect()` applies this filter before it
 * ever calls `header()`, so throwing here needs no cooperation from PHP's
 * warning-to-exception conversion — unlike the WPDieException path the JSON
 * branch relies on, this one fires deterministically regardless of whether
 * headers have already been sent in this process.
 */
class Newspack_Newsletters_Subscribe_Block_Redirect_Interrupt extends Exception {
	/**
	 * The location argument the `wp_redirect` filter was called with.
	 *
	 * @var string
	 */
	public $location = '';
}
