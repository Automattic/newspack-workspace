<?php
/**
 * Mocks for On_Hold_Duration tests.
 *
 * @package Newspack\Tests
 */

if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
	/**
	 * Return the subscriptions for a renewal order.
	 *
	 * Mirrors the teams-for-memberships mock contract: returns whatever the
	 * test placed in $GLOBALS['teams_mock_subscriptions'], ignoring the order
	 * argument. Guarded so it coexists with that mock regardless of load order.
	 *
	 * @param mixed $order Renewal order (ignored).
	 * @return array
	 */
	function wcs_get_subscriptions_for_renewal_order( $order ) {
		return $GLOBALS['teams_mock_subscriptions'] ?? [];
	}
}
