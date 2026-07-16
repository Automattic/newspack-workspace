<?php
/**
 * Tests for the subscription-product integrity audit + operator-mapped repair CLI (NPPD-2062).
 *
 * Access Control's paid-access rule is product-keyed: a gate grants access on an
 * active subscription to one of the products configured on the gate. Two field data
 * shapes break that link, so the reader has an active subscription today but AC can
 * never match it and silently loses access at the flip:
 *
 *   - Variant A (orphaned line item): the subscription's line item carries no product
 *     reference (product hard-deleted, or the subscription was created by hand).
 *   - Variant B (trashed product): the line item points at a product in the trash, which
 *     the gate's product picker can never offer, so no gate can be configured with it.
 *
 * These tests exercise the pure audit/repair helpers directly (the WP-CLI command method
 * is thin glue verified end-to-end on a real site). The WC mocks model line items via the
 * `items` key on WC_Subscription and the `$products_database` global.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\CLI\WooCommerce_Subscriptions;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';

/**
 * Test the subscription-product audit and operator-mapped repair.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Audit_Subscription_Products extends WP_UnitTestCase {

	/**
	 * A reader user to own the fixture subscriptions.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Reset the mock databases and create a fixture user before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		$this->user_id          = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Clean up the mock databases after each test.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		parent::tear_down();
	}

	/**
	 * Register a mock product in the products database.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $name       Product name.
	 * @param string $status     Post status (publish|draft|pending|private|trash).
	 * @param string $type       Product type (subscription|variation|...).
	 * @return WC_Product
	 */
	private function register_product( int $product_id, string $name, string $status = 'publish', string $type = 'subscription' ): WC_Product {
		global $products_database;
		$product                          = new WC_Product(
			[
				'id'     => $product_id,
				'name'   => $name,
				'type'   => $type,
				'status' => $status,
			]
		);
		$products_database[ $product_id ] = $product;
		return $product;
	}

	/**
	 * Register an active mock subscription with the given line items.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param array  $items           Array of WC_Order_Item_Product.
	 * @param string $status         Subscription status.
	 * @return WC_Subscription
	 */
	private function register_subscription( int $subscription_id, array $items, string $status = 'active' ): WC_Subscription {
		global $subscriptions_database;
		$subscription                          = new WC_Subscription(
			[
				'id'          => $subscription_id,
				'customer_id' => $this->user_id,
				'status'      => $status,
				'items'       => $items,
			]
		);
		$subscriptions_database[ $subscription_id ] = $subscription;
		return $subscription;
	}

	/**
	 * Build a line item with a name and (optionally) a parent product ID and variation ID.
	 *
	 * @param string $name         Line-item name (the human-readable product name).
	 * @param int    $product_id   Parent product ID, or 0 for an orphaned line item.
	 * @param int    $variation_id Variation ID for a variable-subscription line item (default 0).
	 * @return WC_Order_Item_Product
	 */
	private function line_item( string $name, int $product_id = 0, int $variation_id = 0 ): WC_Order_Item_Product {
		return new WC_Order_Item_Product(
			[
				'name'         => $name,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			]
		);
	}

	/**
	 * A line item with no product reference is flagged as variant A, and the guess
	 * resolves from the line-item name to a live product of the same name.
	 */
	public function test_orphaned_line_item_is_variant_a() {
		$live_annual_id = 1234;
		$this->register_product( $live_annual_id, 'Digital Annual' );
		$this->register_subscription( 51, [ $this->line_item( 'Digital Annual', 0 ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 51 => $GLOBALS['subscriptions_database'][51] ],
			[
				[
					'id'   => $live_annual_id,
					'name' => 'Digital Annual',
				],
			]
		);

		$this->assertCount( 1, $rows, 'An orphaned line item should produce exactly one audit row.' );
		$orphan_row = $rows[0];
		$this->assertSame( 51, $orphan_row['subscription_id'] );
		$this->assertSame( 'A', $orphan_row['variant'] );
		$this->assertSame( $live_annual_id, $orphan_row['guess_product_id'], 'The guess should match the live product with the same name.' );
	}

	/**
	 * A line item pointing at a trashed product is flagged as variant B, and the guess
	 * resolves to a live product of the same name (the intended replacement).
	 */
	public function test_trashed_product_line_item_is_variant_b() {
		$trashed_product_id     = 36426;
		$replacement_product_id = 500;
		$this->register_product( $trashed_product_id, 'VAN Membership', 'trash' );
		$this->register_product( $replacement_product_id, 'VAN Membership', 'publish' );
		$this->register_subscription( 73, [ $this->line_item( 'VAN Membership', $trashed_product_id ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 73 => $GLOBALS['subscriptions_database'][73] ],
			[
				[
					'id'   => $replacement_product_id,
					'name' => 'VAN Membership',
				],
			]
		);

		$this->assertCount( 1, $rows, 'A trashed-product line item should produce exactly one audit row.' );
		$trashed_row = $rows[0];
		$this->assertSame( 73, $trashed_row['subscription_id'] );
		$this->assertSame( 'B', $trashed_row['variant'] );
		$this->assertSame( $replacement_product_id, $trashed_row['guess_product_id'], 'The guess should point at the live replacement product.' );
	}

	/**
	 * A line item whose product ID points at a hard-deleted product (the post is gone, so
	 * `wc_get_product` returns false) is the "product deleted" shape of variant A.
	 */
	public function test_deleted_product_line_item_is_variant_a() {
		$live_annual_id   = 1234;
		$deleted_product_id = 77777; // Never registered in $products_database — simulates a hard-deleted product.
		$this->register_product( $live_annual_id, 'Digital Annual' );
		$this->register_subscription( 74, [ $this->line_item( 'Digital Annual', $deleted_product_id ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 74 => $GLOBALS['subscriptions_database'][74] ],
			[
				[
					'id'   => $live_annual_id,
					'name' => 'Digital Annual',
				],
			]
		);

		$this->assertCount( 1, $rows, 'A line item on a hard-deleted product should be flagged.' );
		$deleted_row = $rows[0];
		$this->assertSame( 'A', $deleted_row['variant'] );
		$this->assertSame( $live_annual_id, $deleted_row['guess_product_id'] );
	}

	/**
	 * Variable subscription with a trashed PARENT but a live variation is still flagged (B):
	 * gates key on the parent product ID and the picker can never offer a trashed parent, so
	 * the live variation is irrelevant to matchability. Keying on the variation would miss it.
	 */
	public function test_variable_subscription_flags_on_trashed_parent_despite_live_variation() {
		$trashed_parent_id = 800;
		$live_variation_id = 801;
		$this->register_product( $trashed_parent_id, 'Membership Variable', 'trash' );
		$this->register_product( $live_variation_id, 'Membership Variable - Annual', 'publish' );
		$this->register_subscription( 90, [ $this->line_item( 'Membership Variable - Annual', $trashed_parent_id, $live_variation_id ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 90 => $GLOBALS['subscriptions_database'][90] ],
			[]
		);

		$this->assertCount( 1, $rows, 'A trashed parent must be flagged even when the variation is live.' );
		$this->assertSame( 'B', $rows[0]['variant'] );
	}

	/**
	 * Variable subscription with a live PARENT is not flagged, even if the specific variation
	 * is trashed — AC matches on the parent, so the reader keeps access.
	 */
	public function test_variable_subscription_not_flagged_when_parent_is_live() {
		$live_parent_id      = 810;
		$trashed_variation_id = 811;
		$this->register_product( $live_parent_id, 'Membership Variable', 'publish' );
		$this->register_product( $trashed_variation_id, 'Membership Variable - Annual', 'trash' );
		$this->register_subscription( 91, [ $this->line_item( 'Membership Variable - Annual', $live_parent_id, $trashed_variation_id ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 91 => $GLOBALS['subscriptions_database'][91] ],
			[]
		);

		$this->assertSame( [], $rows, 'A live parent product means the subscription is matchable; a trashed variation is irrelevant.' );
	}

	/**
	 * A subscription whose line item points at a live (published) product is not flagged.
	 */
	public function test_healthy_subscription_is_not_flagged() {
		$this->register_product( 1234, 'Digital Annual' );
		$this->register_subscription( 60, [ $this->line_item( 'Digital Annual', 1234 ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 60 => $GLOBALS['subscriptions_database'][60] ],
			[
				[
					'id'   => 1234,
					'name' => 'Digital Annual',
				],
			]
		);

		$this->assertSame( [], $rows, 'A subscription on a live product should not be flagged.' );
	}

	/**
	 * Draft/pending/private products are in the picker's allowlist and enforce fine, so a
	 * draft-product line item is not flagged.
	 */
	public function test_draft_product_is_not_flagged() {
		$this->register_product( 1235, 'Digital Monthly', 'draft' );
		$this->register_subscription( 61, [ $this->line_item( 'Digital Monthly', 1235 ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 61 => $GLOBALS['subscriptions_database'][61] ],
			[
				[
					'id'   => 1235,
					'name' => 'Digital Monthly',
				],
			]
		);

		$this->assertSame( [], $rows, 'A draft product is selectable and should not be flagged.' );
	}

	/**
	 * A product in a status the picker never lists (e.g. auto-draft) is neither trashed nor
	 * selectable, so a line item on it is flagged (B) — status is matched against the
	 * picker's allowlist, not just `trash`.
	 */
	public function test_non_selectable_status_product_is_flagged_variant_b() {
		$this->register_product( 1240, 'Legacy Digital', 'auto-draft' );
		$this->register_subscription( 65, [ $this->line_item( 'Legacy Digital', 1240 ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 65 => $GLOBALS['subscriptions_database'][65] ],
			[]
		);

		$this->assertCount( 1, $rows, 'A line item on a non-selectable-status product must be flagged.' );
		$this->assertSame( 'B', $rows[0]['variant'] );
		$this->assertStringContainsString( 'auto-draft', $rows[0]['evidence'], 'The evidence should name the actual non-selectable status.' );
	}

	/**
	 * A line item on a live but non-subscription-typed product (e.g. a product retyped to
	 * `simple` after purchase) is flagged (B): the picker only lists subscription types, so
	 * no gate can reference it. Selectability is type + status, not status alone.
	 */
	public function test_non_subscription_type_product_is_flagged_variant_b() {
		$this->register_product( 1250, 'Retyped Plan', 'publish', 'simple' );
		$this->register_subscription( 67, [ $this->line_item( 'Retyped Plan', 1250 ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 67 => $GLOBALS['subscriptions_database'][67] ],
			[]
		);

		$this->assertCount( 1, $rows, 'A line item on a non-subscription-typed product must be flagged.' );
		$this->assertSame( 'B', $rows[0]['variant'] );
		$this->assertStringContainsString( 'simple', $rows[0]['evidence'], 'The evidence should name the non-selectable type.' );
	}

	/**
	 * A subscription with no line items at all is as unmatchable as an orphaned one, so it is
	 * flagged variant A with no guess.
	 */
	public function test_subscription_with_no_line_items_is_flagged_variant_a() {
		$this->register_subscription( 66, [] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 66 => $GLOBALS['subscriptions_database'][66] ],
			[
				[
					'id'   => 1234,
					'name' => 'Digital Annual',
				],
			]
		);

		$this->assertCount( 1, $rows, 'A subscription with no line items must be flagged.' );
		$this->assertSame( 'A', $rows[0]['variant'] );
		$this->assertNull( $rows[0]['guess_product_id'], 'A subscription with no line items has no name to guess from.' );
	}

	/**
	 * A subscription that carries both a broken line item and a live-product line item
	 * is not at risk — AC can still match on the live product, so it is not flagged.
	 */
	public function test_subscription_with_a_live_product_line_item_is_not_flagged() {
		$this->register_product( 1234, 'Digital Annual' );
		$this->register_subscription(
			62,
			[
				$this->line_item( 'Legacy Add-on', 0 ),
				$this->line_item( 'Digital Annual', 1234 ),
			]
		);

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 62 => $GLOBALS['subscriptions_database'][62] ],
			[
				[
					'id'   => 1234,
					'name' => 'Digital Annual',
				],
			]
		);

		$this->assertSame( [], $rows, 'A subscription with any live-product line item is still matchable and should not be flagged.' );
	}

	/**
	 * When no live product name matches the broken line item, the guess is empty
	 * (evidence only — the tool must never repair from a guess it cannot make).
	 */
	public function test_guess_is_empty_when_no_live_product_name_matches() {
		$this->register_subscription( 63, [ $this->line_item( 'Ghost Plan', 0 ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 63 => $GLOBALS['subscriptions_database'][63] ],
			[
				[
					'id'   => 1234,
					'name' => 'Digital Annual',
				],
			]
		);

		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]['guess_product_id'], 'No matching live product means no guess.' );
	}

	/**
	 * Cancelled/expired subscriptions are out of scope — only active-status subscriptions
	 * are audited (they are the ones that will silently lose access at the flip).
	 */
	public function test_inactive_subscription_is_skipped() {
		$this->register_subscription( 64, [ $this->line_item( 'Digital Annual', 0 ) ], 'cancelled' );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 64 => $GLOBALS['subscriptions_database'][64] ],
			[
				[
					'id'   => 1234,
					'name' => 'Digital Annual',
				],
			]
		);

		$this->assertSame( [], $rows, 'A cancelled subscription should not be audited.' );
	}

	/**
	 * Live repair re-attaches the mapped live product onto an orphaned line item.
	 */
	public function test_repair_reattaches_orphaned_product_when_live() {
		$live_annual_id = 1234;
		$this->register_product( $live_annual_id, 'Digital Annual' );
		$subscription = $this->register_subscription( 51, [ $this->line_item( 'Digital Annual', 0 ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $live_annual_id, false );

		$this->assertTrue( $result['ok'], 'Repair onto a live product should succeed.' );
		$this->assertTrue( $result['applied'], 'A live (non-dry-run) repair should be applied.' );
		$this->assertSame( 0, $result['old_product_id'] );
		$this->assertSame( $live_annual_id, $result['new_product_id'] );
		$items = $subscription->get_items();
		$this->assertSame( $live_annual_id, $items[0]->get_product_id(), 'The line item should now carry the mapped product ID.' );
	}

	/**
	 * Live repair swaps a trashed-product line item onto a live product.
	 */
	public function test_repair_swaps_trashed_product_onto_live() {
		$trashed_product_id     = 36426;
		$replacement_product_id = 500;
		$this->register_product( $trashed_product_id, 'VAN Membership', 'trash' );
		$this->register_product( $replacement_product_id, 'VAN Membership', 'publish' );
		$subscription = $this->register_subscription( 73, [ $this->line_item( 'VAN Membership', $trashed_product_id ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $replacement_product_id, false );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $trashed_product_id, $result['old_product_id'] );
		$this->assertSame( $replacement_product_id, $result['new_product_id'] );
		$items = $subscription->get_items();
		$this->assertSame( $replacement_product_id, $items[0]->get_product_id() );
	}

	/**
	 * A dry-run repair reports what it would do but changes nothing.
	 */
	public function test_repair_dry_run_changes_nothing() {
		$live_annual_id = 1234;
		$this->register_product( $live_annual_id, 'Digital Annual' );
		$subscription = $this->register_subscription( 51, [ $this->line_item( 'Digital Annual', 0 ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $live_annual_id, true );

		$this->assertTrue( $result['ok'], 'A dry-run against a valid mapping is still reported as ok.' );
		$this->assertFalse( $result['applied'], 'A dry-run must not apply.' );
		$items = $subscription->get_items();
		$this->assertSame( 0, $items[0]->get_product_id(), 'The line item must be untouched in a dry-run.' );
	}

	/**
	 * A mapping onto a trashed product is rejected — the swap target must be live.
	 */
	public function test_repair_rejects_trashed_target() {
		$trashed_product_id = 36426;
		$this->register_product( $trashed_product_id, 'VAN Membership', 'trash' );
		$subscription = $this->register_subscription( 51, [ $this->line_item( 'Digital Annual', 0 ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $trashed_product_id, false );

		$this->assertFalse( $result['ok'], 'Mapping onto a trashed product must be rejected.' );
		$items = $subscription->get_items();
		$this->assertSame( 0, $items[0]->get_product_id(), 'A rejected repair must not touch the line item.' );
	}

	/**
	 * A mapping onto a product variation is rejected — WC_Order_Item_Product::set_product_id()
	 * only accepts a `product` post type and would throw, aborting the batch. Gates key on
	 * parents anyway.
	 */
	public function test_repair_rejects_variation_target() {
		$variation_id = 811;
		$this->register_product( $variation_id, 'Membership - Annual', 'publish', 'variation' );
		$subscription = $this->register_subscription( 51, [ $this->line_item( 'Digital Annual', 0 ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $variation_id, false );

		$this->assertFalse( $result['ok'], 'Mapping onto a variation must be rejected.' );
		$items = $subscription->get_items();
		$this->assertSame( 0, $items[0]->get_product_id(), 'A rejected repair must not touch the line item.' );
	}

	/**
	 * A mapping onto a plain simple product is rejected — a gate can only reference a
	 * subscription/variable-subscription product, so a simple one would report a hollow
	 * success while leaving the reader unmatchable.
	 */
	public function test_repair_rejects_non_subscription_type_target() {
		$simple_id = 1300;
		$this->register_product( $simple_id, 'One-off Donation', 'publish', 'simple' );
		$subscription = $this->register_subscription( 51, [ $this->line_item( 'Digital Annual', 0 ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $simple_id, false );

		$this->assertFalse( $result['ok'], 'Mapping onto a non-subscription product must be rejected.' );
		$items = $subscription->get_items();
		$this->assertSame( 0, $items[0]->get_product_id(), 'A rejected repair must not touch the line item.' );
	}

	/**
	 * A subscription with no line items cannot be repaired via --map — there is nothing to
	 * re-point — so the mapping is refused rather than fataling on a null item.
	 */
	public function test_repair_rejects_subscription_with_no_line_items() {
		$live_annual_id = 1234;
		$this->register_product( $live_annual_id, 'Digital Annual' );
		$subscription = $this->register_subscription( 51, [] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $live_annual_id, false );

		$this->assertFalse( $result['ok'], 'A subscription with no line item must be refused.' );
	}

	/**
	 * A subscription with more than one broken line item is refused — the operator must
	 * resolve the ambiguity by hand rather than have one mapped product applied to all.
	 */
	public function test_repair_rejects_subscription_with_multiple_broken_line_items() {
		$live_annual_id = 1234;
		$this->register_product( $live_annual_id, 'Digital Annual' );
		$subscription = $this->register_subscription(
			51,
			[
				$this->line_item( 'Digital Annual', 0 ),
				$this->line_item( 'Legacy Add-on', 0 ),
			]
		);

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $live_annual_id, false );

		$this->assertFalse( $result['ok'], 'A subscription with multiple broken line items must be refused.' );
		$items = $subscription->get_items();
		$this->assertSame( 0, $items[0]->get_product_id(), 'A refused repair must not touch any line item.' );
		$this->assertSame( 0, $items[1]->get_product_id() );
	}

	/**
	 * A mapping onto a non-existent product is rejected.
	 */
	public function test_repair_rejects_missing_target() {
		$subscription = $this->register_subscription( 51, [ $this->line_item( 'Digital Annual', 0 ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, 999999, false );

		$this->assertFalse( $result['ok'], 'Mapping onto a non-existent product must be rejected.' );
	}

	/**
	 * A mapping against a subscription that is not at risk is rejected — the tool only
	 * repairs subscriptions the audit actually flagged.
	 */
	public function test_repair_rejects_non_at_risk_subscription() {
		$this->register_product( 1234, 'Digital Annual' );
		$subscription = $this->register_subscription( 60, [ $this->line_item( 'Digital Annual', 1234 ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, 1234, false );

		$this->assertFalse( $result['ok'], 'A healthy subscription is not eligible for repair.' );
	}

	/**
	 * The --map argument parser accepts explicit sub:product pairs and ignores blanks;
	 * malformed tokens are dropped so only well-formed operator mappings are executed.
	 */
	public function test_parse_map_argument() {
		$parsed = WooCommerce_Subscriptions::parse_map_argument( '51:1234, 73:500 ,,bad,90:' );

		$this->assertSame(
			[
				51 => 1234,
				73 => 500,
			],
			$parsed,
			'Only well-formed sub_id:product_id pairs should be parsed.'
		);
	}

	/**
	 * The audit paginates: with the page size exactly filled by healthy subscriptions, an
	 * at-risk one on the next page is still found — proving the loop continues past a full
	 * first page and terminates on the short final page (drives the mock's status/paging
	 * support).
	 */
	public function test_audit_paginates_through_multiple_pages() {
		$live_annual_id = 1234;
		$this->register_product( $live_annual_id, 'Digital Annual' );
		// 100 healthy active subscriptions fill the first page exactly (page size is 100).
		for ( $sub_id = 1; $sub_id <= 100; $sub_id++ ) {
			$this->register_subscription( $sub_id, [ $this->line_item( 'Digital Annual', $live_annual_id ) ] );
		}
		// One at-risk (orphaned) subscription lands on the second page.
		$this->register_subscription( 101, [ $this->line_item( 'Digital Annual', 0 ) ] );

		$audit_active_subscriptions = new ReflectionMethod( WooCommerce_Subscriptions::class, 'audit_active_subscriptions' );
		$audit_active_subscriptions->setAccessible( true );
		$rows = $audit_active_subscriptions->invoke(
			null,
			[
				[
					'id'   => $live_annual_id,
					'name' => 'Digital Annual',
				],
			] 
		);

		$this->assertCount( 1, $rows, 'The at-risk subscription on the second page must be found — pagination must continue past a full first page.' );
		$this->assertSame( 101, $rows[0]['subscription_id'] );
	}
}
