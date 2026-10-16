<?php
/**
 * Complimentary access CLI commands.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use Newspack\Complimentary_Access;
use Newspack\Content_Gate;
use Newspack\WooCommerce_Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * Flags the complimentary access products a site already has.
 */
class Complimentary_Access_CLI {
	/**
	 * Flag every $0 subscription product or variation whose subscriptions were all granted by an admin.
	 *
	 * Sites that granted comp access before the flag existed did it through such products.
	 * A $0 product readers bought, such as a student or trial plan, is left alone, because
	 * flagging takes it off sale. So is a $0 product nobody holds yet; it is listed for a
	 * person to decide. Flagging a product queues the stamping of its existing
	 * subscriptions and their orders.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Flag the products. Without it, only lists what would be flagged.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack complimentary-access pre-mark
	 *     wp newspack complimentary-access pre-mark --live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function pre_mark( $args, $assoc_args ) {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			WP_CLI::error( 'Access Control is not enabled on this site (NEWSPACK_CONTENT_GATES). Aborting.' );
		}
		if ( ! WooCommerce_Subscriptions::is_active() ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active. Aborting.' );
		}

		$flagged = 0;
		foreach ( self::get_subscription_products() as $product ) {
			$label = sprintf( '"%s" (ID: %d)', $product->get_name(), $product->get_id() );
			if ( Complimentary_Access::is_complimentary_product( $product ) ) {
				WP_CLI::line( sprintf( 'Already flagged: %s', $label ) );
				continue;
			}
			if ( ! Complimentary_Access::qualifies_for_flag( $product ) ) {
				if ( Complimentary_Access::is_free_product( $product ) && 'none' === Complimentary_Access::get_subscription_holders( $product->get_id() ) ) {
					WP_CLI::line( sprintf( 'Needs review, $0 with no subscriptions: %s', $label ) );
				}
				continue;
			}
			if ( ! $dry_run ) {
				Complimentary_Access::flag_product( $product );
			}
			WP_CLI::line( sprintf( '%s %s', $dry_run ? 'Would flag:' : 'Flagged:', $label ) );
			++$flagged;
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d product(s) would be flagged. Pass --live to flag them.', $flagged ) );
		} else {
			WP_CLI::success( sprintf( '%d product(s) flagged. Their subscriptions are stamped in the background.', $flagged ) );
		}
	}

	/**
	 * Simple subscription products and the variations of variable ones.
	 *
	 * The flag lives on whatever a line item holds, so a variable product is judged
	 * per variation: it can sell a paid variation beside a $0 one.
	 *
	 * @return \WC_Product[]
	 */
	private static function get_subscription_products() {
		$products        = [];
		$parent_products = \wc_get_products(
			[
				'type'  => [ 'subscription', 'variable-subscription' ],
				'limit' => -1,
			]
		);
		foreach ( $parent_products as $product ) {
			if ( ! $product->is_type( 'variable-subscription' ) ) {
				$products[] = $product;
				continue;
			}
			foreach ( $product->get_children() as $variation_id ) {
				$variation = \wc_get_product( $variation_id );
				if ( $variation ) {
					$products[] = $variation;
				}
			}
		}
		return $products;
	}
}
