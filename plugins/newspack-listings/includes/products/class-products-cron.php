<?php
/**
 * Newspack Listings - Handles automated cron jobs and other scheduled tasks.
 * Mainly for expiring single-purchase listings.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Settings;
use Newspack_Listings\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Products class.
 * Sets up WooCommerce products for listings.
 */
final class Products_Cron extends Products {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// String representing the cron jobs to expire single-purchase listings.
		$this->cron_hook = 'newspack_expire_listings';

		// Handle expiration for single-purchase listings.
		add_action( 'init', [ $this, 'cron_init' ] );
		add_action( $this->cron_hook, [ $this, 'expire_single_purchase_listings' ] );
	}

	/**
	 * Set up the cron job. Will run once daily and automatically unpublish single-purchase listings
	 * whose publish dates are older than the expiration period defined in plugin settings.
	 */
	public function cron_init() {
		register_deactivation_hook( NEWSPACK_LISTINGS_FILE, [ $this, 'cron_deactivate' ] );

		$single_expiration_period = Settings::get_settings( 'newspack_listings_single_purchase_expiration' );

		// If WC Subscriptions is inactive, $single_expiration_period may be a WP error.
		// Let's not schedule the cron job in this case.
		if ( is_wp_error( $single_expiration_period ) ) {
			$single_expiration_period = 0;
		}

		if ( 0 < $single_expiration_period ) {
			if ( ! wp_next_scheduled( $this->cron_hook ) ) {
				wp_schedule_event( Utils\get_next_midnight(), 'daily', $this->cron_hook );
			}
		} elseif ( wp_next_scheduled( $this->cron_hook ) ) {
				$this->cron_deactivate(); // If the option has been updated to 0, no need to run the cron job.

		}
	}

	/**
	 * Clear the cron job when this plugin is deactivated.
	 */
	public function cron_deactivate() {
		wp_clear_scheduled_hook( $this->cron_hook );
	}

	/**
	 * Callback function to expire single-purchase listings whose publish date is older than the set expiration period.
	 * Single-purchase listings can be distinguished because they should have an order ID meta value, but no subscription ID.
	 * Subscription primary listings have both an order ID and a subscription ID.
	 * Premium subscription "included" listings have a subscription ID, but no order ID.
	 */
	public function expire_single_purchase_listings() {
		$single_expiration_period = Settings::get_settings( 'newspack_listings_single_purchase_expiration' );

		if ( 0 < $single_expiration_period ) {
			$args = [
				'post_status' => 'publish',
				'post_type'   => [
					Core::NEWSPACK_LISTINGS_POST_TYPES['event'],
					Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'],
				],
				'date_query'  => [
					'before' => (string) $single_expiration_period . ' days ago',
				],
				'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					// Only get listings that are associated with a WooCommerce order.
					[
						'key'     => self::POST_META_KEYS['listing_order'],
						'compare' => 'EXISTS',
					],

					// Exclude listings that are associated with a subscription, which are active as long as the subscription is active.
					[
						'key'     => self::POST_META_KEYS['listing_subscription'],
						'compare' => 'NOT EXISTS',
					],

					// Exclude listings with a set expiration date, as those are handled by the Core::expire_listings_with_expiration_date method.
					[
						'key'     => 'newspack_listings_expiration_date',
						'compare' => 'NOT EXISTS',
					],
				],
			];

			Utils\execute_callback_with_paged_query( $args, [ $this, 'expire_single_purchase_listing' ] );
		} else {
			$this->cron_deactivate(); // If the option has been updated to 0, no need to run the cron job.
		}
	}

	/**
	 * Given a post ID for a published post, unpublish it and flag it as expired.
	 *
	 * @param int $post_id ID for the post to expire.
	 */
	public function expire_single_purchase_listing( $post_id ) {
		if ( $post_id ) {
			$updated = wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => 'draft',
				]
			);

			if ( $updated ) {
				update_post_meta( $post_id, self::POST_META_KEYS['listing_has_expired'], 1 );
			} else {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						// Translators: error message logged when we're unable to expire a listing via cron job.
						__( 'Newspack Listings: Error expiring listing with ID %d.', 'newspack-listings' ),
						$post_id
					)
				);
			}
		}
	}
}
