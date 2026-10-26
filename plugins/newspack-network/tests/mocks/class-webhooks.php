<?php
/**
 * Stand-in for newspack-plugin's webhook queue, which the suite doesn't load.
 *
 * @package Newspack_Network
 */

namespace Newspack\Data_Events;

if ( ! class_exists( 'Newspack\Data_Events\Webhooks' ) ) {
	/**
	 * Records dispatches instead of queueing webhook requests.
	 */
	class Webhooks {
		const REQUEST_POST_TYPE = 'np_webhook_request';

		/**
		 * Data of each dispatched event, in order.
		 *
		 * @var array
		 */
		public static $dispatched = [];

		/**
		 * Record a dispatch.
		 *
		 * @param string $action    Action name.
		 * @param int    $timestamp Timestamp.
		 * @param array  $data      Data.
		 */
		public static function handle_dispatch( $action, $timestamp, $data ) {
			self::$dispatched[] = $data;
		}
	}
}
