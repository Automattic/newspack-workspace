<?php
/**
 * Stand-in for newspack-plugin's Complimentary_Access, which the suite doesn't load.
 *
 * Reads the 'yes' stamp the real class writes, so a stand-in order marks itself
 * complimentary by carrying the meta.
 *
 * @package Newspack_Network
 */

namespace Newspack;

if ( class_exists( 'Newspack\Complimentary_Access' ) ) {
	return;
}

/**
 * Stand-in for Newspack\Complimentary_Access.
 */
class Complimentary_Access {
	const META_KEY = '_newspack_complimentary';

	const SYNC_ACTION = 'newspack_complimentary_access_sync';

	/**
	 * Whether a subscription or order is stamped as complimentary.
	 *
	 * @param object $order Subscription or order.
	 * @return bool
	 */
	public static function is_complimentary_order( $order ): bool {
		return 'yes' === $order->get_meta( self::META_KEY );
	}
}
