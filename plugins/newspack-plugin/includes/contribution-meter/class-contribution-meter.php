<?php
/**
 * Newspack Contribution Meter.
 *
 * @package Newspack
 */

namespace Newspack\Contribution_Meter;

use Newspack\Donations;

defined( 'ABSPATH' ) || exit;

/**
 * Handles contribution meter data retrieval and calculations.
 */
class Contribution_Meter {

	/**
	 * REST route for contribution meter.
	 */
	const REST_ROUTE = '/contribution-meter';

	/**
	 * Default oldest allowed start date range (relative to today).
	 * E.g., '-2 months' means start date cannot be earlier than 2 months ago.
	 */
	const DEFAULT_START_DATE_RANGE = '-2 months';

	/**
	 * Start date range option name.
	 */
	const START_DATE_RANGE_OPTION = 'newspack_contribution_meter_start_date_range';

	/**
	 * Default data collection end date range (relative to today).
	 * E.g., 'today' means collect up to end of today, including today.
	 */
	const DEFAULT_END_DATE_RANGE = 'today';

	/**
	 * End date range option name.
	 */
	const END_DATE_RANGE_OPTION = 'newspack_contribution_meter_end_date_range';

	/**
	 * Default maximum allowed end date range (relative to today).
	 */
	const DEFAULT_MAX_END_DATE_RANGE = '+6 months';

	/**
	 * Maximum end date range option name.
	 */
	const MAX_END_DATE_RANGE_OPTION = 'newspack_contribution_meter_max_end_date_range';

	/**
	 * Cache group for contribution meter data.
	 */
	const CACHE_GROUP = 'newspack_contribution_meter';

	/**
	 * Option used to invalidate cached meter data.
	 */
	const CACHE_TIMESTAMP_OPTION = 'newspack_contribution_meter_last_cache_timestamp';

	/**
	 * Initialize hooks and REST API endpoints.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'maybe_update_cache_timestamp' ], 10, 4 );
	}

	/**
	 * Register REST API routes for the contribution meter.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'api_get_contribution_data' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => [
					'startDate' => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => [ __CLASS__, 'validate_date' ],
					],
					'endDate'   => [
						'required'          => false,
						'type'              => 'string',
						'validate_callback' => [ __CLASS__, 'validate_date' ],
					],
				],
			]
		);
	}

	/**
	 * Validate date format and value.
	 *
	 * @param string $date Date string to validate.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public static function validate_date( $date ) {
		// Allow empty string for optional dates.
		if ( empty( $date ) ) {
			return true;
		}

		// Validate basic date format.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid date format. Expected YYYY-MM-DD.', 'newspack-plugin' ) );
		}

		// Check if it's a valid date.
		$date_obj = \DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $date ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid date. Please provide a valid date.', 'newspack-plugin' ) );
		}

		return true;
	}

	/**
	 * Validate that end date is after start date and doesn't exceed configured maximum.
	 *
	 * @param string $start_date Start date in YYYY-MM-DD format.
	 * @param string $end_date   End date in YYYY-MM-DD format.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public static function validate_date_range( $start_date, $end_date ) {
		$start = new \DateTime( $start_date, wp_timezone() );
		$end   = new \DateTime( $end_date, wp_timezone() );

		// End date must be after start date.
		if ( $end < $start ) {
			return new \WP_Error(
				'invalid_date_range',
				__( 'End date must be after start date.', 'newspack-plugin' )
			);
		}

		// Get configured maximum end date range.
		$max_end_date_range = get_option( self::MAX_END_DATE_RANGE_OPTION, self::DEFAULT_MAX_END_DATE_RANGE );
		$max_end_date       = new \DateTime( $max_end_date_range, wp_timezone() );

		// End date cannot exceed configured maximum.
		if ( $end > $max_end_date ) {
			return new \WP_Error(
				'date_range_too_large',
				__( 'End date exceeds the configured maximum date range.', 'newspack-plugin' )
			);
		}

		return true;
	}

	/**
	 * REST API callback to get contribution data.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response object or error.
	 */
	public static function api_get_contribution_data( $request ) {
		$start_date = $request->get_param( 'startDate' );
		$end_date   = $request->get_param( 'endDate' );

		// Validate date range if end date is provided.
		if ( ! empty( $end_date ) ) {
			$validation = self::validate_date_range( $start_date, $end_date );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		$data = self::get_contribution_data( $start_date, $end_date );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Get contribution data with caching.
	 *
	 * @param string      $start_date Valid start date in YYYY-MM-DD format.
	 * @param string|null $end_date   Optional end date in YYYY-MM-DD format.
	 * @return array|\WP_Error Array of contribution data or WP_Error on failure.
	 */
	public static function get_contribution_data( $start_date, $end_date = null ) {
		$timezone    = wp_timezone();
		$start_obj   = new \DateTime( $start_date, $timezone );
		$end_cap     = new \DateTime( get_option( self::END_DATE_RANGE_OPTION, self::DEFAULT_END_DATE_RANGE ), $timezone );
		$max_end_obj = ( clone $start_obj )->modify( get_option( self::MAX_END_DATE_RANGE_OPTION, self::DEFAULT_MAX_END_DATE_RANGE ) );
		$cap_end_obj = $max_end_obj < $end_cap ? $max_end_obj : $end_cap;

		$end_obj = ! empty( $end_date ) ? new \DateTime( $end_date, $timezone ) : clone $cap_end_obj;
		if ( $end_obj > $cap_end_obj ) {
			$end_obj = clone $cap_end_obj;
		}
		if ( $end_obj < $start_obj ) {
			$end_obj = clone $start_obj;
		}

		$end_date = $end_obj->format( 'Y-m-d' );

		// Generate cache key including timestamp for automatic invalidation when donations change.
		$cache_key = $start_date . '_' . $end_date . '_' . get_option( self::CACHE_TIMESTAMP_OPTION, 0 );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// Fallback to transient for environments without persistent object cache.
		$has_persistent_cache = wp_using_ext_object_cache();
		if ( ! $has_persistent_cache ) {
			$cached = get_transient( self::CACHE_GROUP . '_' . $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		$amount_raised = self::get_donation_revenue( $start_date, $end_date );

		if ( is_wp_error( $amount_raised ) ) {
			return $amount_raised;
		}

		$data = [
			'amountRaised' => $amount_raised,
		];

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP );

		if ( ! $has_persistent_cache ) {
			set_transient( self::CACHE_GROUP . '_' . $cache_key, $data, DAY_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Get total donation revenue from a specific date range.
	 *
	 * @param string $start_date Start date in YYYY-MM-DD format (inclusive).
	 * @param string $end_date   End date in YYYY-MM-DD format (inclusive, entire day).
	 * @return float|\WP_Error Total revenue or WP_Error on failure.
	 */
	public static function get_donation_revenue( $start_date, $end_date ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'woocommerce_inactive', __( 'WooCommerce is not active.', 'newspack-plugin' ) );
		}

		// Get all donation product IDs (default + flagged).
		$donation_products    = Donations::get_donation_product_child_products_ids();
		$donation_product_ids = array_filter( array_map( 'intval', array_values( $donation_products ) ) );
		$flagged_product_ids  = Donations::get_flagged_donation_product_ids();
		$donation_product_ids = array_unique( array_merge( $donation_product_ids, $flagged_product_ids ) );

		if ( empty( $donation_product_ids ) ) {
			return new \WP_Error( 'no_donation_products', __( 'No donation products found.', 'newspack-plugin' ) );
		}

		return self::get_donation_revenue_via_order_query( $start_date, $end_date, $donation_product_ids );
	}

	/**
	 * Calculate donation revenue by iterating paginated WooCommerce orders.
	 *
	 * @param string $start_date  Start date in YYYY-MM-DD format (inclusive).
	 * @param string $end_date    End date in YYYY-MM-DD format (inclusive, all day).
	 * @param array  $product_ids Donation product IDs to include.
	 * @return float|\WP_Error Total revenue or WP_Error on failure.
	 */
	private static function get_donation_revenue_via_order_query( $start_date, $end_date, $product_ids ) {
		$statuses = apply_filters( 'newspack_contribution_meter_order_statuses', [ 'completed', 'processing' ] );

		$query_args = [
			'limit'        => 200,
			'paginate'     => true,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'return'       => 'ids',
			'status'       => $statuses,
			'type'         => 'shop_order',
			'date_created' => $start_date . '...' . $end_date,
		];

		$total_revenue = 0.0;
		$page          = 1;
		$max_pages     = 1;

		do {
			$query_args['page'] = $page;
			$results            = wc_get_orders( $query_args );

			if ( is_wp_error( $results ) ) {
				return $results;
			}

			if ( is_object( $results ) ) {
				$orders    = isset( $results->orders ) ? $results->orders : [];
				$max_pages = max( 1, isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 1 );
			} else {
				$orders    = (array) $results;
				$max_pages = 1;
			}

			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}

				foreach ( $order->get_items() as $item ) {
					$product_id = $item->get_product_id();
					if ( $product_id && in_array( (int) $product_id, $product_ids, true ) ) {
						$total_revenue += (float) $item->get_total();
					}
				}
			}

			$page++;
		} while ( $page <= $max_pages );

		return $total_revenue;
	}

	/**
	 * Invalidate cached meter data when a donation order reaches a counted status.
	 *
	 * @param int       $order_id    Order ID.
	 * @param string    $old_status  Previous status slug.
	 * @param string    $new_status  New status slug.
	 * @param \WC_Order $order       Order object.
	 * @return void
	 */
	public static function maybe_update_cache_timestamp( $order_id, $old_status, $new_status, $order ) {
		/**
		 * Filter the statuses that trigger cache timestamp update.
		 *
		 * @param array $relevant_statuses Array of statuses.
		 */
		$relevant_statuses = apply_filters( 'newspack_contribution_meter_order_statuses', [ 'completed', 'processing' ] );
		if ( ! in_array( $new_status, $relevant_statuses, true ) ) {
			return;
		}

		if ( ! $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order || ! Donations::is_donation_order( $order ) ) {
			return;
		}

		update_option( self::CACHE_TIMESTAMP_OPTION, time(), false );
	}

	/**
	 * Format currency value.
	 *
	 * @param float $amount Amount to format.
	 * @param array $args Optional formatting arguments.
	 * @return string Formatted currency.
	 */
	public static function format_currency( $amount, $args = [] ) {
		$defaults = [ 'decimals' => 0 ];
		$args     = wp_parse_args( $args, $defaults );

		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount, $args ) );
		}

		// Fallback formatting.
		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
		return $symbol . number_format( $amount, $args['decimals'], '.', ',' );
	}
}
