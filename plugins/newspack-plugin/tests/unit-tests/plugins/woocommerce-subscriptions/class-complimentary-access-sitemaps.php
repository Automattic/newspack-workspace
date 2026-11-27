<?php
/**
 * Tests that complimentary access products stay out of sitemaps.
 *
 * @package Newspack\Tests
 */

use Newspack\Complimentary_Access;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../../../includes/plugins/woocommerce-subscriptions/class-complimentary-access.php';

/**
 * A product page that sells nothing but comp access has no business in a sitemap. A
 * variable product with a paid variation beside a comp one still sells the paid one,
 * so it stays.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Complimentary_Access_Sitemaps extends WP_UnitTestCase {
	/**
	 * A product post with a matching mock product, flagged when `$flagged`.
	 *
	 * The flagged IDs come from post meta; everything else comes from the mock product.
	 *
	 * @param string $type      Product type.
	 * @param bool   $flagged   Whether the product carries the complimentary flag.
	 * @param int    $parent_id Parent product ID, for a variation.
	 * @param int[]  $children  Variation IDs, for a variable product.
	 * @return int Product ID.
	 */
	private function create_product( $type, $flagged = false, $parent_id = 0, $children = [] ) {
		$product_id = self::factory()->post->create(
			[
				'post_type'   => $parent_id ? 'product_variation' : 'product',
				'post_parent' => $parent_id,
			]
		);
		$meta       = $flagged ? [ Complimentary_Access::META_KEY => 'yes' ] : [];
		if ( $flagged ) {
			update_post_meta( $product_id, Complimentary_Access::META_KEY, 'yes' );
		}
		wc_create_mock_product(
			[
				'id'        => $product_id,
				'type'      => $type,
				'parent_id' => $parent_id,
				'children'  => $children,
				'meta'      => $meta,
			]
		);
		return $product_id;
	}

	/**
	 * A variable subscription with one variation per flag given.
	 *
	 * @param bool[] $variation_flags Whether each variation is flagged.
	 * @return int[] Parent ID, then variation IDs.
	 */
	private function create_variable_product( $variation_flags ) {
		$parent_id     = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$variation_ids = array_map( fn( $flagged ) => $this->create_product( 'subscription_variation', $flagged, $parent_id ), $variation_flags );
		wc_create_mock_product(
			[
				'id'       => $parent_id,
				'type'     => 'variable-subscription',
				'children' => $variation_ids,
			]
		);
		return array_merge( [ $parent_id ], $variation_ids );
	}

	/**
	 * Comp-only products leave the core and Yoast sitemaps, and a variable product leaves
	 * once its last paid variation is flagged.
	 */
	public function test_sitemaps_leave_out_comp_only_products() {
		$comp_id = $this->create_product( 'subscription', true );
		$this->create_product( 'subscription' );
		list( $all_comp_id )                    = $this->create_variable_product( [ true, true ] );
		list( $mixed_id, , $paid_variation_id ) = $this->create_variable_product( [ true, false ] );

		$excluded_ids = [ $comp_id, $all_comp_id ];
		$this->assertEqualsCanonicalizing( $excluded_ids, Complimentary_Access::get_sitemap_excluded_product_ids() );

		$product_query_args = Complimentary_Access::filter_sitemap_query_args( [ 'post__not_in' => [ 1 ] ], 'product' ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		$this->assertEqualsCanonicalizing( array_merge( [ 1 ], $excluded_ids ), $product_query_args['post__not_in'] );
		$this->assertSame( [], Complimentary_Access::filter_sitemap_query_args( [], 'post' ) );
		$this->assertEqualsCanonicalizing( array_merge( [ 1 ], $excluded_ids ), Complimentary_Access::filter_yoast_sitemap_post_ids( [ 1 ] ) );

		// Flagging the last paid variation takes its product out on the next sitemap request.
		update_post_meta( $paid_variation_id, Complimentary_Access::META_KEY, 'yes' );
		wc_get_product( $paid_variation_id )->update_meta_data( Complimentary_Access::META_KEY, 'yes' );
		$this->assertEqualsCanonicalizing( [ $comp_id, $all_comp_id, $mixed_id ], Complimentary_Access::get_sitemap_excluded_product_ids() );
	}
}
