<?php
/**
 * Resets request-scoped memos between tests.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;
use PHPUnit\Runner\BeforeTestHook;

/**
 * Production code memoizes per request, and a request ends on its own, so nothing in
 * production has to invalidate anything. A PHPUnit run is one request for the whole
 * suite, while fixtures are per test class: a memo built from one class's catalogue
 * would answer the next class's assertions, and the failure names neither.
 *
 * Resetting here makes the test boundary the request boundary the memos assume, so a
 * new test class inherits the guarantee instead of each one having to remember it.
 */
class Newspack_Request_Memo_Reset implements BeforeTestHook {
	/**
	 * Flush every request-scoped memo before each test.
	 *
	 * @param string $test The test being run, as `Class::method`.
	 *
	 * @return void
	 */
	public function executeBeforeTest( string $test ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Defined by PHPUnit's BeforeTestHook.
		Access_Rules::flush_product_options_memos();
		Access_Rules::flush_one_time_purchase_memo();
	}
}
