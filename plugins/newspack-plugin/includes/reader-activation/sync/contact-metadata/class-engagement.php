<?php
/**
 * Engagement contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Reader_Data;
use Newspack\Reader_Activation\Sync\Contact_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Engagement metadata class.
 */
class Engagement extends Contact_Metadata {

	/**
	 * Cache for the latest completed order.
	 *
	 * @var \WC_Order|null|false False means unresolved.
	 */
	private $latest_order_cache = false;

	/**
	 * Whether or not the metadata fields of this class are available to be synced.
	 *
	 * @return boolean
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * The name of the metadata class, used as a section name for the fields handled by this class when syncing and in the UI for selecting which fields to sync.
	 *
	 * @return string
	 */
	public static function get_section_name() {
		return __( 'Engagement', 'newspack' );
	}

	/**
	 * The fields handled by this metadata class.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return [
			'First_Visit_Date'     => 'First Visit Date',
			'Last_Active'          => 'Last Active',
			'Paywall_Hits'         => 'Paywall Hits',
			'Favorite_Categories'  => 'Favorite Categories',
			'Payment_Page'         => 'Payment Page',
			'Payment_UTM_Source'   => 'Payment UTM Source',
			'Payment_UTM_Medium'   => 'Payment UTM Medium',
			'Payment_UTM_Campaign' => 'Payment UTM Campaign',
			'Total_Paid'           => 'Total Paid',
		];
	}

	/**
	 * Get the metadata for the given user, customer or order.
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! $this->user ) {
			return [];
		}

		$order = $this->get_latest_order();

		return [
			'First_Visit_Date'     => $this->format_reader_data_timestamp( 'first_visit_date' ),
			'Last_Active'          => $this->format_reader_data_timestamp( 'last_active' ),
			'Paywall_Hits'         => $this->get_reader_data_int( 'paywall_hits' ),
			'Favorite_Categories'  => $this->get_favorite_categories(),
			'Payment_Page'         => $this->get_payment_page( $order ),
			'Payment_UTM_Source'   => $this->get_order_utm( $order, 'source' ),
			'Payment_UTM_Medium'   => $this->get_order_utm( $order, 'medium' ),
			'Payment_UTM_Campaign' => $this->get_order_utm( $order, 'campaign' ),
			'Total_Paid'           => $this->customer ? $this->customer->get_total_spent() : '',
		];
	}

	/**
	 * Format a reader data store timestamp (JS milliseconds) into a date string.
	 *
	 * @param string $key Reader data store key.
	 * @return string Formatted date or empty string.
	 */
	private function format_reader_data_timestamp( $key ) {
		$value = Reader_Data::get_data( $this->user->ID, $key );
		if ( empty( $value ) || ! is_numeric( $value ) ) {
			return '';
		}
		// Reader data timestamps are JS milliseconds.
		return $this->format_date( gmdate( 'Y-m-d H:i:s', intval( $value ) / 1000 ) );
	}

	/**
	 * Get an integer value from the reader data store.
	 *
	 * @param string $key Reader data store key.
	 * @return int
	 */
	private function get_reader_data_int( $key ) {
		$value = Reader_Data::get_data( $this->user->ID, $key );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Get favorite categories as a comma-separated string of category names.
	 *
	 * @return string
	 */
	private function get_favorite_categories() {
		$category_ids = Reader_Data::get_data( $this->user->ID, 'favorite_categories' );
		if ( is_string( $category_ids ) ) {
			$category_ids = json_decode( $category_ids, true );
		}
		if ( empty( $category_ids ) || ! is_array( $category_ids ) ) {
			return '';
		}
		$names = [];
		foreach ( $category_ids as $cat_id ) {
			$term = \get_term( (int) $cat_id, 'category' );
			if ( $term && ! \is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return implode( ',', $names );
	}

	/**
	 * Get the most recent completed order for the current user.
	 *
	 * @return \WC_Order|null
	 */
	private function get_latest_order() {
		if ( false !== $this->latest_order_cache ) {
			return $this->latest_order_cache;
		}

		$this->latest_order_cache = null;

		if ( ! $this->user || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$orders = \wc_get_orders(
			[
				'customer_id' => $this->user->ID,
				'status'      => [ 'wc-completed' ],
				'limit'       => 1,
				'order'       => 'DESC',
				'orderby'     => 'date',
				'return'      => 'objects',
			]
		);

		if ( ! empty( $orders ) ) {
			$this->latest_order_cache = $orders[0];
		}

		return $this->latest_order_cache;
	}

	/**
	 * Get the payment page URL from an order.
	 *
	 * @param \WC_Order|null $order Order object.
	 * @return string
	 */
	private function get_payment_page( $order ) {
		if ( ! $order ) {
			return '';
		}
		$referer = $order->get_meta( '_newspack_referer' );
		if ( ! empty( $referer ) ) {
			return $referer;
		}
		return function_exists( 'wc_get_checkout_url' ) ? \wc_get_checkout_url() : '';
	}

	/**
	 * Extract a UTM parameter from an order.
	 *
	 * @param \WC_Order|null $order Order object.
	 * @param string         $param UTM parameter name (e.g. 'source', 'medium', 'campaign').
	 * @return string
	 */
	private function get_order_utm( $order, $param ) {
		if ( ! $order ) {
			return '';
		}
		$utm = $order->get_meta( 'utm' );
		if ( ! empty( $utm ) && is_array( $utm ) && isset( $utm[ $param ] ) ) {
			return $utm[ $param ];
		}
		$value = $order->get_meta( 'utm_' . $param );
		return ! empty( $value ) ? $value : '';
	}
}
