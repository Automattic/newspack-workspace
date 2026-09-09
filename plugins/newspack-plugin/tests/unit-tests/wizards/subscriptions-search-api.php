<?php
/**
 * Tests the Subscriptions wizard's product search endpoints.
 *
 * @package Newspack\Tests\Wizards
 */

namespace Newspack\Tests\Wizards;

/**
 * The search endpoints serve two callers with one query: a picker offering
 * products to choose, and a hydrator naming ids a saved rule already holds.
 * The statuses they may see differ, and conflating them either hides a rule's
 * own audience or offers a product nobody should newly pick.
 *
 * @group wizards
 * @group Audience_Subscriptions_Search
 */
class Test_Subscriptions_Search_API extends \WP_UnitTestCase {

	const ROUTE = '/newspack/v1/wizard/newspack-audience-subscriptions/subscriptions-search';

	/**
	 * Enable the content gates flag and load the WooCommerce mocks, which the
	 * routes' availability check reads.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Build a REST server and register the wizard's routes on it directly.
	 * `Wizards::init()` only constructs this wizard where Memberships or the
	 * subscriber-commerce admin is available, neither of which holds here, so
	 * `rest_api_init` alone would leave the routes unregistered.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		register_post_type( 'product', [ 'public' => true ] );
		register_post_type( 'product_variation', [ 'public' => true ] );
		register_taxonomy( 'product_type', 'product' );
		( new \Newspack\Audience_Subscriptions() )->register_api_endpoints();
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
			]
		);
		wp_set_object_terms( $product_id, 'subscription', 'product_type' );
		// The endpoint reads each hit back through `wc_get_product()`, which the
		// mocks answer from their own store rather than from the post.
		\wc_create_mock_product(
			[
				'id'     => $product_id,
				'type'   => 'subscription',
				'name'   => $name,
				'status' => $status,
			]
		);
		return $product_id;
	}

	/**
	 * Dispatch the endpoint and return its data.
	 *
	 * @param array $params Request parameters.
	 *
	 * @return array
	 */
	private function search( $params ) {
		$request = new \WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'per_page', 100 );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return (array) rest_get_server()->dispatch( $request )->get_data();
	}

	/**
	 * A trashed product with live subscribers is still a rule's audience, so
	 * hydrating a saved id has to name it. Left unresolved, the editor renders
	 * the bare id and the publisher cannot tell what the rule covers.
	 */
	public function test_include_names_a_trashed_product() {
		$trashed_id = $this->create_subscription_product( 'Retired Annual Plan', 'trash' );

		$names = wp_list_pluck( $this->search( [ 'include' => (string) $trashed_id ] ), 'name', 'id' );

		$this->assertSame( 'Retired Annual Plan', $names[ $trashed_id ] ?? null );
	}

	/**
	 * The picker is the other caller of the same query, and it must not offer a
	 * trashed product — nobody should be able to newly select one.
	 */
	public function test_suggestions_exclude_trashed_products() {
		$this->create_subscription_product( 'Retired Annual Plan', 'trash' );
		$live_id = $this->create_subscription_product( 'Current Annual Plan' );

		$ids = wp_list_pluck( $this->search( [ 'search' => 'Annual Plan' ] ), 'id' );

		$this->assertSame( [ $live_id ], $ids );
	}
}
