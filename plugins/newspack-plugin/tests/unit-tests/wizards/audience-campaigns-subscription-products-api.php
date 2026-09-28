<?php
/**
 * Tests the Campaigns wizard's subscription products endpoint.
 *
 * @package Newspack\Tests\Wizards
 */

namespace Newspack\Tests\Wizards;

/**
 * The endpoint feeds the segment editor's "Has active subscription" picker two ways: a
 * list of products to offer, and a lookup that names the products a segment already
 * holds. Private products belong in both. Other statuses belong only in the lookup, so
 * a saved segment keeps naming a product after it's drafted or trashed.
 *
 * @group wizards
 * @group Audience_Campaigns_Subscription_Products
 */
class Test_Audience_Campaigns_Subscription_Products_API extends \WP_UnitTestCase {

	const ROUTE = '/newspack/v1/wizard/newspack-audience-campaigns/subscription-products';

	/**
	 * Build a REST server with the wizard's routes and the product post type and taxonomy
	 * the endpoint queries.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		register_post_type( 'product', [ 'public' => true ] );
		register_taxonomy( 'product_type', 'product' );
		new \Newspack\Audience_Campaigns(); // Hooks its routes to `rest_api_init`.
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Reset the server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a subscription product post.
	 *
	 * @param string $name   Product name.
	 * @param string $status Post status.
	 *
	 * @return int
	 */
	private function create_subscription_product( $name, $status = 'publish' ) {
		$product_id = $this->factory->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => $name,
				'post_status' => $status,
				// WordPress publishes a `future` post whose date has already passed.
				'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', strtotime( '+1 week' ) ) : '',
			]
		);
		wp_set_object_terms( $product_id, 'subscription', 'product_type' );
		return $product_id;
	}

	/**
	 * Dispatch the endpoint and return its items keyed by ID.
	 *
	 * @param array $params Request parameters.
	 *
	 * @return array
	 */
	private function get_products( $params = [] ) {
		$request = new \WP_REST_Request( 'GET', self::ROUTE );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return array_column( (array) $response->get_data(), null, 'id' );
	}

	/**
	 * Private legacy products are what readers migrated from Woo Memberships still hold,
	 * so a segment has to be able to target them.
	 */
	public function test_lists_published_and_private_products() {
		$published_id = $this->create_subscription_product( 'Current Plan' );
		$private_id   = $this->create_subscription_product( 'Legacy Plan', 'private' );
		$draft_id     = $this->create_subscription_product( 'Draft Plan', 'draft' );
		$trashed_id   = $this->create_subscription_product( 'Trashed Plan', 'trash' );

		$products = $this->get_products();

		$this->assertSame( 'Current Plan', $products[ $published_id ]['title'] ?? null );
		$this->assertSame( 'Legacy Plan', $products[ $private_id ]['title'] ?? null, 'A private product is listed under its plain name.' );
		$this->assertArrayNotHasKey( $draft_id, $products, 'A draft product is not offered.' );
		$this->assertArrayNotHasKey( $trashed_id, $products, 'A trashed product is not offered.' );
	}

	/**
	 * A saved segment keeps naming a product that's no longer offered, marked with its
	 * status, instead of reading as "Deleted subscription".
	 */
	public function test_include_names_saved_products_whatever_their_status() {
		$private_id = $this->create_subscription_product( 'Legacy Plan', 'private' );
		$draft_id   = $this->create_subscription_product( 'Draft Plan', 'draft' );
		$future_id  = $this->create_subscription_product( 'Scheduled Plan', 'future' );
		$trashed_id = $this->create_subscription_product( 'Trashed Plan', 'trash' );
		$other_id   = $this->create_subscription_product( 'Unrelated Plan' );

		$products = $this->get_products( [ 'include' => [ $private_id, $draft_id, $future_id, $trashed_id ] ] );

		$this->assertSame( 'Legacy Plan', $products[ $private_id ]['title'] ?? null );
		$this->assertSame( 'Draft Plan [invalid status: draft]', $products[ $draft_id ]['title'] ?? null );
		$this->assertSame( 'Scheduled Plan [invalid status: future]', $products[ $future_id ]['title'] ?? null );
		$this->assertSame( 'Trashed Plan [invalid status: trash]', $products[ $trashed_id ]['title'] ?? null );
		$this->assertSame( 'trash', $products[ $trashed_id ]['status'] ?? null );
		$this->assertArrayNotHasKey( $other_id, $products, 'The lookup returns only the requested products.' );
	}
}
