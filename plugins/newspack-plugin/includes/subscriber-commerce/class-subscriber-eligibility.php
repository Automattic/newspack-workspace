<?php
/**
 * Newspack Subscriber Commerce - subscriber eligibility.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "is this reader a subscriber of any of these subscription products?"
 * for subscriber-commerce rules.
 *
 * Thin, memoizing wrapper over Access_Rules::has_active_subscription(), which
 * walks the reader's own WooCommerce subscriptions and the group subscriptions
 * they hold a seat in. That lookup is uncached and runs per call, while the
 * callers hit it once per product: a shop archive of N covered products would
 * otherwise repeat the same subscription queries N times for the same reader.
 *
 * Only *held* subscriptions count. A subscription sitting in the reader's cart,
 * or one being purchased in the same order, does not make them a subscriber.
 *
 * Two policies come with the underlying lookup rather than from here, and every
 * caller inherits them: a seat in a group subscription counts, and a subscription
 * on hold inside the payment-recovery window counts by default — so a reader whose
 * renewal is mid-retry keeps their subscriber pricing and access until the retries
 * are exhausted.
 */
class Subscriber_Eligibility {

	/**
	 * Eligibility verdicts, keyed by
	 * "{blog_id}:{user_id}:{sorted product IDs}:{payment-recovery grace}".
	 *
	 * @var array<string, bool>
	 */
	private static array $verdicts = [];

	/**
	 * Whether a user is an active subscriber of any of the given subscription products.
	 *
	 * @param int   $user_id     The user ID. 0 (anonymous) is never eligible.
	 * @param int[] $product_ids The subscription product IDs that grant eligibility.
	 *
	 * @return bool
	 */
	public static function user_has( $user_id, $product_ids ): bool {
		$user_id     = (int) $user_id;
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );

		// An anonymous reader holds no subscription. A rule with no subscription
		// products names no way in, so nobody satisfies it.
		if ( ! $user_id || empty( $product_ids ) ) {
			return false;
		}

		sort( $product_ids );

		// The verdict is not a function of the arguments alone:
		// has_active_subscription() also reads `payment_recovery_grace` from the
		// ambient evaluation context, which with_evaluation_context() swaps in and
		// out around each gate. Keying on it keeps a verdict reached inside one
		// gate's context from being served to a caller outside it — a reader in
		// the failed-payment window would otherwise get whichever answer the first
		// caller in the request happened to produce.
		$cache_key = implode(
			':',
			[
				get_current_blog_id(),
				$user_id,
				implode( ',', $product_ids ),
				Access_Rules::get_evaluation_context( 'payment_recovery_grace', true ) ? 'grace' : 'strict',
			]
		);
		if ( ! isset( self::$verdicts[ $cache_key ] ) ) {
			self::$verdicts[ $cache_key ] = (bool) Access_Rules::has_active_subscription( $user_id, $product_ids );
		}
		return self::$verdicts[ $cache_key ];
	}

	/**
	 * Flush the per-request cache. For tests and for callers that change a
	 * reader's subscriptions mid-request.
	 */
	public static function flush_cache(): void {
		self::$verdicts = [];
	}
}
