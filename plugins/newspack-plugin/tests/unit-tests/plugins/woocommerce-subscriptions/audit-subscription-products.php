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
 * The exception to variant B is a product ID a gate already stores: gates match raw IDs and
 * never re-validate them, so such a reader has access today and must neither be counted as
 * at risk nor repaired.
 *
 * These tests exercise the pure audit/repair helpers directly (the WP-CLI command method
 * is thin glue verified end-to-end on a real site). The WC mocks model line items via the
 * `items` key on WC_Subscription and the `$products_database` global.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\CLI\WooCommerce_Subscriptions;
use Newspack\Access_Rules;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../../mocks/wp-cli-utils-mocks.php';

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
		global $subscriptions_database, $products_database, $wcs_mock_ignore_offset, $wcs_mock_query_log;
		$subscriptions_database = [];
		$products_database      = [];
		$wcs_mock_ignore_offset = false;
		$wcs_mock_query_log     = [];
		WP_CLI::reset();
		$this->user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Clean up the mock databases after each test.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database, $wcs_mock_ignore_offset, $wcs_mock_query_log;
		$subscriptions_database = [];
		$products_database      = [];
		$wcs_mock_ignore_offset = false;
		$wcs_mock_query_log     = [];
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
		$this->assertSame( [ $live_annual_id ], $orphan_row['guess_product_ids'], 'The guess should match the live product with the same name.' );
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
		$this->assertSame( [ $replacement_product_id ], $trashed_row['guess_product_ids'], 'The guess should point at the live replacement product.' );
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
		$this->assertSame( [ $live_annual_id ], $deleted_row['guess_product_ids'] );
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
		$this->assertSame( [], $rows[0]['guess_product_ids'], 'A subscription with no line items has no name to guess from.' );
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
		$this->assertSame( [], $rows[0]['guess_product_ids'], 'No matching live product means no guess.' );
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
	 * A mapping onto a variable-subscription parent is rejected: variation-carrying line
	 * items are refused, so the only shape such a repair could mint is a variable parent
	 * with variation_id = 0 — a pairing normal purchase flow never records, and one the
	 * variation-first consumers (tier-switch lookup, teams renewal match) don't expect.
	 */
	public function test_repair_rejects_variable_subscription_target() {
		$variable_parent_id = 812;
		$replacement_sub_id = 51;
		$this->register_product( $variable_parent_id, 'Membership Variable', 'publish', 'variable-subscription' );
		$subscription = $this->register_subscription( $replacement_sub_id, [ $this->line_item( 'Membership Variable', 0 ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $variable_parent_id, false );

		$this->assertFalse( $result['ok'], 'A variable-subscription target must be refused: attaching the parent without a variation creates a line-item shape purchases never produce.' );
		$this->assertStringContainsString( 'variable subscription', $result['message'] );
		$items = $subscription->get_items();
		$this->assertSame( 0, $items[0]->get_product_id(), 'The line item must be left untouched.' );
	}

	/**
	 * A mapping onto a product variation is rejected — WC_Order_Item_Product::set_product_id()
	 * would throw on the non-`product` post type and abort the batch.
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
	 * A trashed line-item product that a published, active gate still references is NOT at
	 * risk: gates store raw product IDs and `has_active_subscription()` never re-validates
	 * them, so the reader is matched today. The row is reported as fragile instead, naming
	 * the gate that still holds the ID.
	 */
	public function test_gate_referenced_product_is_not_at_risk() {
		$trashed_product_id = 36426;
		$this->register_product( $trashed_product_id, 'VAN Membership', 'trash' );
		$this->register_subscription( 75, [ $this->line_item( 'VAN Membership', $trashed_product_id ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 75 => $GLOBALS['subscriptions_database'][75] ],
			[],
			[ $trashed_product_id => [ 'gate #42' ] ]
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'access_referenced', $rows[0]['status'], 'A gate-referenced product still grants access, so the row must not be counted as at risk.' );
		$this->assertStringContainsString( 'gate #42', $rows[0]['evidence'], 'The evidence should name the gate still holding the product ID.' );
	}

	/**
	 * The same gate cross-check applies to a hard-deleted product: `has_product()` compares
	 * raw IDs, so a gate listing the ID keeps matching even with no product behind it.
	 */
	public function test_gate_referenced_deleted_product_is_not_at_risk() {
		$deleted_product_id = 77777; // Never registered — the product post is gone.
		$this->register_subscription( 76, [ $this->line_item( 'Ghost Plan', $deleted_product_id ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 76 => $GLOBALS['subscriptions_database'][76] ],
			[],
			[ $deleted_product_id => [ 'gate #42' ] ]
		);

		$this->assertSame( 'access_referenced', $rows[0]['status'] );
	}

	/**
	 * `has_product()` compares a stored rule value against the line item's variation_id as
	 * well as its product_id, so a legacy or hand-edited rule holding a VARIATION ID still
	 * grants access at runtime. A subscription in that state is fragile, not at risk —
	 * listing it as at-risk would overstate the population and invite a repair that moves
	 * the line item off the very ID the rule matches on.
	 */
	public function test_variation_id_referenced_by_rule_is_not_at_risk() {
		$trashed_parent_id = 900;
		$variation_id      = 901;
		$this->register_product( $trashed_parent_id, 'Membership Variable', 'trash' );
		$this->register_product( $variation_id, 'Membership Variable - Annual', 'publish' );
		$this->register_subscription( 78, [ $this->line_item( 'Membership Variable - Annual', $trashed_parent_id, $variation_id ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 78 => $GLOBALS['subscriptions_database'][78] ],
			[],
			[ $variation_id => [ 'gate #42' ] ]
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'access_referenced', $rows[0]['status'], 'A rule holding the variation ID matches at runtime, so the row must not be counted as at risk.' );
		$this->assertStringContainsString( sprintf( 'variation #%d', $variation_id ), $rows[0]['evidence'], 'The evidence should name the variation ID the rule matches on.' );
		$this->assertStringContainsString( 'gate #42', $rows[0]['evidence'], 'The evidence should name the surface still holding the ID.' );
	}

	/**
	 * Repairing a gate-referenced subscription would move it off the very ID the gate
	 * matches on and revoke the access it has today, so --map refuses it.
	 */
	public function test_repair_refuses_gate_referenced_subscription() {
		$trashed_product_id     = 36426;
		$replacement_product_id = 500;
		$this->register_product( $trashed_product_id, 'VAN Membership', 'trash' );
		$this->register_product( $replacement_product_id, 'VAN Membership', 'publish' );
		$subscription = $this->register_subscription( 75, [ $this->line_item( 'VAN Membership', $trashed_product_id ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product(
			$subscription,
			$replacement_product_id,
			false,
			[ $trashed_product_id => [ 'gate #42' ] ]
		);

		$this->assertFalse( $result['ok'], 'A subscription a gate still matches must never be repaired.' );
		$items = $subscription->get_items();
		$this->assertSame( $trashed_product_id, $items[0]->get_product_id(), 'The line item must keep the ID the gate matches on.' );
	}

	/**
	 * Two live products sharing the broken line item's name are both surfaced: the guess is
	 * the only column an operator acts on, so silently picking one could send a --map onto
	 * the wrong twin.
	 */
	public function test_ambiguous_name_guess_returns_every_match() {
		$this->register_subscription( 77, [ $this->line_item( 'Monthly Membership', 0 ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 77 => $GLOBALS['subscriptions_database'][77] ],
			[
				[
					'id'   => 33,
					'name' => 'Monthly Membership',
				],
				[
					'id'   => 91,
					'name' => 'Monthly Membership',
				],
			]
		);

		$this->assertSame( [ 33, 91 ], $rows[0]['guess_product_ids'], 'Every name match must be surfaced, not just the first.' );
	}

	/**
	 * A line item carrying a variation ID is refused rather than repaired: the variation ID
	 * is the only record of which variation the reader bought and is read by the team
	 * renewal match, the membership-expiry safeguard and the tier switch lookup.
	 */
	public function test_repair_refuses_line_item_with_a_variation_id() {
		$trashed_parent_id      = 800;
		$live_variation_id      = 801;
		$replacement_product_id = 500;
		$this->register_product( $trashed_parent_id, 'Membership Variable', 'trash' );
		// A plain `subscription` target, so the refusal under test is the variation-carrying
		// line item — not the (separate, earlier) variable-subscription target refusal.
		$this->register_product( $replacement_product_id, 'Membership Variable', 'publish' );
		$subscription = $this->register_subscription( 92, [ $this->line_item( 'Membership Variable - Annual', $trashed_parent_id, $live_variation_id ) ] );

		$result = WooCommerce_Subscriptions::repair_subscription_product( $subscription, $replacement_product_id, false );

		$this->assertFalse( $result['ok'], 'A line item carrying a variation ID must be refused.' );
		$this->assertStringContainsString( 'variation ID', $result['message'], 'The refusal must be the variation-line-item guard, not the target check.' );
		$items = $subscription->get_items();
		$this->assertSame( $trashed_parent_id, $items[0]->get_product_id(), 'A refused repair must not touch the line item.' );
		$this->assertSame( $live_variation_id, $items[0]->get_variation_id(), 'The variation ID must survive a refused repair.' );
	}

	/**
	 * An applied repair writes an order note recording the prior product ID, so a --live run
	 * remains reconstructible after the terminal session is gone.
	 */
	public function test_applied_repair_records_an_order_note() {
		$trashed_product_id     = 36426;
		$replacement_product_id = 500;
		$this->register_product( $trashed_product_id, 'VAN Membership', 'trash' );
		$this->register_product( $replacement_product_id, 'VAN Membership', 'publish' );
		$subscription = $this->register_subscription( 78, [ $this->line_item( 'VAN Membership', $trashed_product_id ) ] );

		WooCommerce_Subscriptions::repair_subscription_product( $subscription, $replacement_product_id, false );

		$order_notes = $subscription->data['order_notes'] ?? [];
		$this->assertCount( 1, $order_notes, 'An applied repair must leave a durable record on the subscription.' );
		$this->assertStringContainsString( (string) $trashed_product_id, $order_notes[0], 'The note must record the prior product ID.' );
		$this->assertStringContainsString( (string) $replacement_product_id, $order_notes[0], 'The note must record the new product ID.' );
	}

	/**
	 * A dry run writes no order note either.
	 */
	public function test_dry_run_repair_records_no_order_note() {
		$live_annual_id = 1234;
		$this->register_product( $live_annual_id, 'Digital Annual' );
		$subscription = $this->register_subscription( 79, [ $this->line_item( 'Digital Annual', 0 ) ] );

		WooCommerce_Subscriptions::repair_subscription_product( $subscription, $live_annual_id, true );

		$this->assertSame( [], $subscription->data['order_notes'] ?? [], 'A dry run must write nothing at all.' );
	}

	/**
	 * The CLI's live-product set must stay identical to the gate picker's. The allowlist
	 * constants restate `Access_Rules::get_subscription_products_options()`'s implicit
	 * defaults, so this pins Newspack-side drift: a `status` argument or a third type added
	 * there (and not here) makes the sets diverge and this test fail. It cannot catch
	 * WooCommerce-side drift — the `wc_get_products` mock hardcodes WC's current no-`status`
	 * default (draft/pending/private/publish), so a change to `WC_Product_Query`'s defaults
	 * would move the real picker while the mock (and this test) stand still.
	 */
	public function test_live_product_set_matches_the_gate_picker() {
		$this->register_product( 10, 'Published Sub', 'publish' );
		$this->register_product( 11, 'Draft Sub', 'draft' );
		$this->register_product( 12, 'Pending Sub', 'pending' );
		$this->register_product( 13, 'Private Sub', 'private' );
		$this->register_product( 14, 'Variable Sub', 'publish', 'variable-subscription' );
		$this->register_product( 15, 'Trashed Sub', 'trash' );
		$this->register_product( 16, 'Simple Product', 'publish', 'simple' );

		$get_live_subscription_products = new ReflectionMethod( WooCommerce_Subscriptions::class, 'get_live_subscription_products' );
		$get_live_subscription_products->setAccessible( true );
		$cli_ids = wp_list_pluck( $get_live_subscription_products->invoke( null ), 'id' );

		$picker_ids = wp_list_pluck( Access_Rules::get_subscription_products_options(), 'value' );

		sort( $cli_ids );
		sort( $picker_ids );
		$this->assertSame( [ 10, 11, 12, 13, 14 ], $cli_ids, 'Only gate-selectable types and statuses belong in the live set.' );
		$this->assertSame( $picker_ids, $cli_ids, 'The audit\'s live-product set must be exactly what the gate picker offers.' );
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
	 * Every discarded token is handed back so the caller can warn about it: a silently
	 * dropped typo plus a zero exit reads as "the repair ran and there was nothing to do".
	 * A repeated subscription ID is reported too, rather than last-write-wins.
	 */
	public function test_parse_map_argument_reports_discarded_tokens() {
		$parsed = WooCommerce_Subscriptions::parse_map_argument_verbose( '51:1234,51-1234, 73:abc ,,90:,73:500,73:600' );

		$this->assertSame(
			[
				51 => 1234,
				73 => 500,
			],
			$parsed['map']
		);
		$this->assertSame(
			[ '51-1234', '73:abc', '90:', '73:600' ],
			$parsed['rejected'],
			'Malformed tokens and a duplicate subscription ID must be reported, not dropped in silence.'
		);
	}

	/**
	 * The audit paginates: with the page size exactly filled by healthy subscriptions, an
	 * at-risk one on the next page is still found — proving the loop continues past a full
	 * first page and terminates on the short final page.
	 *
	 * The mock implements `offset` and deliberately not `paged`, matching the real
	 * `wcs_get_subscriptions()`, so a loop that regressed to `paged` would re-fetch page one
	 * forever here rather than passing quietly.
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
		$audit = $audit_active_subscriptions->invoke(
			null,
			[
				[
					'id'   => $live_annual_id,
					'name' => 'Digital Annual',
				],
			]
		);
		$rows  = $audit['rows'];

		$this->assertCount( 1, $rows, 'The at-risk subscription on the second page must be found — pagination must continue past a full first page.' );
		$this->assertSame( 101, $rows[0]['subscription_id'] );
		$this->assertSame( 101, $audit['scanned'], 'The scan count reported to the operator must cover every subscription visited.' );

		// Pin the query contract, not just the outcome: the WCS default sort
		// (start_date DESC) has no ID tiebreaker, so same-second rows — the norm on
		// bulk-imported stores — can swap across offset windows and slip a subscription
		// between pages. Every page of the scan must request the deterministic sort.
		global $wcs_mock_query_log;
		$this->assertNotEmpty( $wcs_mock_query_log );
		foreach ( $wcs_mock_query_log as $query_args ) {
			$this->assertSame( 'ID', $query_args['orderby'] ?? null, 'Every audit page must sort by ID — a timestamp sort has no guaranteed order across offset windows.' );
			$this->assertSame( 'ASC', $query_args['order'] ?? null, 'Ascending ID keeps subscriptions created mid-scan at the unvisited end of the window.' );
		}
	}

	/**
	 * A query that stops advancing — the `paged` shape, or a third-party
	 * `woocommerce_get_subscriptions_query_args` filter dropping `offset` — must halt the
	 * audit rather than loop forever on a publisher's site. Halting (rather than breaking
	 * out quietly) also stops an incomplete scan from being read as a clean bill of health.
	 */
	public function test_audit_halts_when_the_query_stops_advancing() {
		global $wcs_mock_ignore_offset;
		$live_annual_id = 1234;
		$this->register_product( $live_annual_id, 'Digital Annual' );
		// A full first page, so the loop asks for a second one.
		for ( $sub_id = 1; $sub_id <= 100; $sub_id++ ) {
			$this->register_subscription( $sub_id, [ $this->line_item( 'Digital Annual', $live_annual_id ) ] );
		}
		$wcs_mock_ignore_offset = true;

		$audit_active_subscriptions = new ReflectionMethod( WooCommerce_Subscriptions::class, 'audit_active_subscriptions' );
		$audit_active_subscriptions->setAccessible( true );

		// The WP_CLI stub throws on error() the way real WP-CLI halts non-zero.
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/stopped advancing/' );
		$audit_active_subscriptions->invoke( null, [] );
	}

	/**
	 * Gates are not the only place a product ID is persisted as a subscription access rule:
	 * the group/row/stack blocks carry the same rule shape inline as the
	 * `newspackAccessControlRules` attribute, and Block_Visibility evaluates it through the
	 * identical `Access_Rules` engine — so a trashed product referenced only by a block still
	 * grants access. The scan must find those IDs, keyed to the post that carries the block,
	 * or the audit repeats the gate over-report on a second surface.
	 */
	public function test_block_referenced_product_is_collected_from_post_content() {
		$product_id = 36426;
		$content    = '<!-- wp:group {"newspackAccessControlMode":"custom","newspackAccessControlRules":{"custom_access":{"active":true,"access_rules":[[{"slug":"subscription","value":[' . $product_id . ']}]]}}} --><div class="wp-block-group"><!-- wp:paragraph --><p>Members only.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$post_id    = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);

		$get_block_referenced_product_ids = new ReflectionMethod( WooCommerce_Subscriptions::class, 'get_block_referenced_product_ids' );
		$get_block_referenced_product_ids->setAccessible( true );
		$referenced = $get_block_referenced_product_ids->invoke( null );

		$this->assertArrayHasKey( $product_id, $referenced, 'A product referenced only by a block-level access rule must be collected.' );
		$this->assertSame( [ 'block on post #' . $post_id ], $referenced[ $product_id ], 'The reference must name the post that carries the block.' );
	}

	/**
	 * The sweep must not narrow to published posts: `Block_Visibility::filter_render_block()`
	 * applies no status filter, so a custom-visibility block on a private or scheduled post
	 * runs the same `Access_Rules` match whenever that post renders. Missing it would list
	 * the subscription as at-risk AND disarm the --map refusal that protects it — a repair
	 * would then strand the reader when the post goes live.
	 */
	public function test_block_scan_collects_rules_from_unpublished_posts() {
		$product_id = 36427;
		$content    = '<!-- wp:group {"newspackAccessControlMode":"custom","newspackAccessControlRules":{"custom_access":{"active":true,"access_rules":[[{"slug":"subscription","value":[' . $product_id . ']}]]}}} --><div class="wp-block-group"></div><!-- /wp:group -->';
		$private_id = self::factory()->post->create(
			[
				'post_status'  => 'private',
				'post_content' => $content,
			]
		);
		$future_id  = self::factory()->post->create(
			[
				'post_status'  => 'future',
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_content' => $content,
			]
		);

		$get_block_referenced_product_ids = new ReflectionMethod( WooCommerce_Subscriptions::class, 'get_block_referenced_product_ids' );
		$get_block_referenced_product_ids->setAccessible( true );
		$referenced = $get_block_referenced_product_ids->invoke( null );

		$this->assertArrayHasKey( $product_id, $referenced, 'Block rules on unpublished posts still enforce at render time and must be collected.' );
		$this->assertContains( 'block on post #' . $private_id, $referenced[ $product_id ], 'The private post carrying the rule must be named.' );
		$this->assertContains( 'block on post #' . $future_id, $referenced[ $product_id ], 'The scheduled post carrying the rule must be named.' );
	}

	/**
	 * A block-mode block references gate IDs, not products; those products are already found
	 * by the gate scan, so the block scan must not double-collect them off the block. A
	 * custom-mode block whose access section is inactive grants nobody access and is skipped
	 * too — mirroring Block_Visibility's own activation check.
	 */
	public function test_block_scan_ignores_gate_mode_and_inactive_rules() {
		$gate_mode  = '<!-- wp:group {"newspackAccessControlMode":"gate","newspackAccessControlGateIds":[42]} --><div class="wp-block-group"></div><!-- /wp:group -->';
		$inactive   = '<!-- wp:group {"newspackAccessControlMode":"custom","newspackAccessControlRules":{"custom_access":{"active":false,"access_rules":[[{"slug":"subscription","value":[555]}]]}}} --><div class="wp-block-group"></div><!-- /wp:group -->';
		self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $gate_mode . $inactive,
			]
		);

		$get_block_referenced_product_ids = new ReflectionMethod( WooCommerce_Subscriptions::class, 'get_block_referenced_product_ids' );
		$get_block_referenced_product_ids->setAccessible( true );

		$this->assertSame( [], $get_block_referenced_product_ids->invoke( null ), 'Gate-mode and inactive custom-mode blocks reference no live subscription products.' );
	}

	/**
	 * The block surface is folded into the same fragile bucket as gates: a subscription kept
	 * matchable only by a block-level rule is reported (not at risk) and its evidence names
	 * the block, exactly as the gate case names the gate.
	 */
	public function test_block_referenced_product_is_not_at_risk() {
		$trashed_product_id = 36426;
		$this->register_product( $trashed_product_id, 'VAN Membership', 'trash' );
		$this->register_subscription( 78, [ $this->line_item( 'VAN Membership', $trashed_product_id ) ] );

		$rows = WooCommerce_Subscriptions::build_audit_rows(
			[ 78 => $GLOBALS['subscriptions_database'][78] ],
			[],
			[ $trashed_product_id => [ 'block on post #99' ] ]
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'access_referenced', $rows[0]['status'], 'A block-referenced product still grants access, so the row must not be counted as at risk.' );
		$this->assertStringContainsString( 'block on post #99', $rows[0]['evidence'], 'The evidence should name the block still holding the product ID.' );
	}
}
