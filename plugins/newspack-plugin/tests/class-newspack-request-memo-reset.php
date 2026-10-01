<?php
/**
 * Resets request-scoped memos and WooCommerce mock stores between tests.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;
use Newspack\User_Gate_Access;
use PHPUnit\Runner\BeforeTestHook;

/**
 * Production code memoizes per request, and a request ends on its own, so nothing in
 * production has to invalidate anything. A PHPUnit run is one request for the whole
 * suite, while fixtures are per test class: a memo built from one class's catalogue
 * would answer the next class's assertions, and the failure names neither.
 *
 * Resetting here makes the test boundary the request boundary the memos assume, so a
 * new test class inherits the guarantee instead of each one having to remember it.
 *
 * The WooCommerce mocks' stores stand in for database tables, which WordPress rolls
 * back after each test. Clearing them here is stricter than that rollback: records
 * created in set_up_before_class() are gone before the first test, so seed them in
 * set_up(). Without the reset, a class's last test leaves its orders and
 * subscriptions to later classes, where they attach to a later user with the same ID.
 */
class Newspack_Request_Memo_Reset implements BeforeTestHook {
	/**
	 * Globals that tests/mocks/wc-mocks.php uses as WooCommerce's order, subscription,
	 * product, and order item tables.
	 *
	 * @var string[]
	 */
	const WC_MOCK_STORES = [
		'orders_database',
		'subscriptions_database',
		'products_database',
		'order_items_database',
	];

	/**
	 * Flush every request-scoped memo and mock store before each test.
	 *
	 * Runs after a class's set_up_before_class() and before its set_up(), so a class
	 * that needs mock records in every test must create them in set_up().
	 *
	 * @param string $test The test being run, as `Class::method`.
	 *
	 * @return void
	 */
	public function executeBeforeTest( string $test ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Defined by PHPUnit's BeforeTestHook.
		Access_Rules::flush_product_options_memos();
		Access_Rules::flush_one_time_purchase_memo();
		User_Gate_Access::reset_memo();

		// Every full or filtered run has these stores. A run scoped to files that don't
		// create them at file scope may lack them; leave them absent there rather than
		// adding mock globals to a run that isn't using the mocks.
		foreach ( self::WC_MOCK_STORES as $store ) {
			if ( array_key_exists( $store, $GLOBALS ) ) {
				$GLOBALS[ $store ] = [];
			}
		}
	}
}
