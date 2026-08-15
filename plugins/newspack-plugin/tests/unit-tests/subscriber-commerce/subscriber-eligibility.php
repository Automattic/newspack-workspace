<?php
/**
 * Tests subscriber eligibility for subscriber-commerce rules.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Subscriber_Eligibility;

/**
 * Tests the memoizing wrapper both subscriber-commerce features use to ask
 * "is this reader a subscriber of any of these products?".
 *
 * The underlying oracle (Access_Rules::has_active_subscription) is exercised by
 * its own tests; here the contract under test is the wrapper's: who is never
 * eligible, and that the memoization can't confuse one reader or product set
 * for another.
 *
 * @group subscriber-commerce
 * @group Subscriber_Eligibility
 */
class Test_Subscriber_Eligibility extends \WP_UnitTestCase {

	/**
	 * Subscription product IDs granting eligibility.
	 *
	 * @var int[]
	 */
	private $subscription_ids = [ 101, 102 ];

	/**
	 * A reader.
	 *
	 * @var int
	 */
	private $reader_id;

	/**
	 * Another reader.
	 *
	 * @var int
	 */
	private $other_reader_id;

	/**
	 * Calls the oracle saw, as [ user_id, product_ids ] pairs.
	 *
	 * @var array[]
	 */
	private $oracle_calls = [];

	/**
	 * Create readers and stand in for the subscription oracle.
	 */
	public function set_up() {
		parent::set_up();
		Subscriber_Eligibility::flush_cache();

		$this->reader_id       = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->other_reader_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		$this->oracle_calls = [];
		add_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'mock_oracle' ], 10, 3 );
	}

	/**
	 * Remove the oracle stand-in and reset the cache.
	 */
	public function tear_down() {
		remove_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'mock_oracle' ], 10 );
		Subscriber_Eligibility::flush_cache();
		parent::tear_down();
	}

	/**
	 * Stand in for the subscription oracle: only the first reader subscribes,
	 * and only to the first product.
	 *
	 * @param bool  $has_subscription Whether the user has an active subscription.
	 * @param int   $user_id          User ID.
	 * @param array $product_ids      Required product IDs.
	 *
	 * @return bool
	 */
	public function mock_oracle( $has_subscription, $user_id, $product_ids ) {
		$this->oracle_calls[] = [ $user_id, $product_ids ];
		return $user_id === $this->reader_id && in_array( 101, $product_ids, true );
	}

	/**
	 * A reader holding one of the listed subscriptions is eligible.
	 */
	public function test_subscriber_is_eligible() {
		$this->assertTrue( Subscriber_Eligibility::user_has( $this->reader_id, $this->subscription_ids ) );
	}

	/**
	 * A logged-in reader without any of the listed subscriptions is not.
	 */
	public function test_non_subscriber_is_not_eligible() {
		$this->assertFalse( Subscriber_Eligibility::user_has( $this->other_reader_id, $this->subscription_ids ) );
	}

	/**
	 * An anonymous reader holds no subscription, so the oracle is never asked.
	 */
	public function test_anonymous_reader_is_never_eligible() {
		$this->assertFalse( Subscriber_Eligibility::user_has( 0, $this->subscription_ids ) );
		$this->assertSame( [], $this->oracle_calls );
	}

	/**
	 * A rule naming no subscription names no way in, so nobody satisfies it —
	 * rather than the oracle's "any active subscription" reading of an empty
	 * product list, which would let every subscriber through.
	 */
	public function test_empty_subscription_list_grants_nobody() {
		$this->assertFalse( Subscriber_Eligibility::user_has( $this->reader_id, [] ) );
		$this->assertSame( [], $this->oracle_calls );
	}

	/**
	 * Repeat questions are answered from the cache: a shop archive asks the
	 * same one once per covered product.
	 */
	public function test_repeat_lookups_hit_the_cache() {
		Subscriber_Eligibility::user_has( $this->reader_id, $this->subscription_ids );
		Subscriber_Eligibility::user_has( $this->reader_id, $this->subscription_ids );

		$this->assertCount( 1, $this->oracle_calls );
	}

	/**
	 * The same product set ordered differently is the same question.
	 */
	public function test_cache_is_order_independent() {
		Subscriber_Eligibility::user_has( $this->reader_id, [ 101, 102 ] );
		Subscriber_Eligibility::user_has( $this->reader_id, [ 102, 101 ] );

		$this->assertCount( 1, $this->oracle_calls );
	}

	/**
	 * Different readers get their own verdicts — the cache key must include the
	 * user, or one reader's access would leak to the next in the same request.
	 */
	public function test_cache_distinguishes_readers() {
		$this->assertTrue( Subscriber_Eligibility::user_has( $this->reader_id, $this->subscription_ids ) );
		$this->assertFalse( Subscriber_Eligibility::user_has( $this->other_reader_id, $this->subscription_ids ) );
	}

	/**
	 * Different subscription sets get their own verdicts.
	 */
	public function test_cache_distinguishes_subscription_sets() {
		$this->assertTrue( Subscriber_Eligibility::user_has( $this->reader_id, [ 101 ] ) );
		$this->assertFalse( Subscriber_Eligibility::user_has( $this->reader_id, [ 102 ] ) );
	}
}
