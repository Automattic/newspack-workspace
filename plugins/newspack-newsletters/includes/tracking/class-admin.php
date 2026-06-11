<?php
/**
 * Newspack Newsletters Tracking Admin UI Tweaks.
 *
 * @package Newspack
 */

namespace Newspack_Newsletters\Tracking;

/**
 * Tracking Admin Class.
 */
final class Admin {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'add_option_newspack_newsletters_use_tracking_pixel', [ __CLASS__, 'updated_option' ] );
		add_action( 'add_option_newspack_newsletters_use_click_tracking', [ __CLASS__, 'updated_option' ] );
		add_action( 'update_option_newspack_newsletters_use_tracking_pixel', [ __CLASS__, 'updated_option' ] );
		add_action( 'update_option_newspack_newsletters_use_click_tracking', [ __CLASS__, 'updated_option' ] );

		// Newsletters Ads columns.
		add_action( 'manage_' . \Newspack_Newsletters\Ads::CPT . '_posts_columns', [ __CLASS__, 'manage_ads_columns' ] );
		add_action( 'manage_' . \Newspack_Newsletters\Ads::CPT . '_posts_custom_column', [ __CLASS__, 'custom_ads_column' ], 10, 2 );
		add_action( 'manage_edit-' . \Newspack_Newsletters\Ads::CPT . '_sortable_columns', [ __CLASS__, 'sortable_ads_columns' ] );

		// Sorting.
		add_action( 'pre_get_posts', [ __CLASS__, 'handle_sorting' ] );
	}

	/**
	 * Whether tracking pixel is enabled.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public static function is_tracking_pixel_enabled() {
		return (bool) get_option( 'newspack_newsletters_use_tracking_pixel', true );
	}

	/**
	 * Whether click tracking is enabled.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public static function is_tracking_click_enabled() {
		return (bool) get_option( 'newspack_newsletters_use_click_tracking', true );
	}

	/**
	 * Flush rewrite rules upon successful update of tracking options.
	 */
	public static function updated_option() {
		\flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
	}

	/**
	 * Manage ads columns.
	 *
	 * @param array $columns Columns.
	 */
	public static function manage_ads_columns( $columns ) {
		$columns['impressions'] = __( 'Impressions', 'newspack-newsletters' );
		$columns['clicks']      = __( 'Clicks', 'newspack-newsletters' );
		return $columns;
	}

	/**
	 * Custom ads column content.
	 *
	 * @param array $column_name Column name.
	 * @param int   $post_id     Post ID.
	 */
	public static function custom_ads_column( $column_name, $post_id ) {
		if ( 'impressions' === $column_name ) {
			echo intval( get_post_meta( $post_id, 'tracking_impressions', true ) );
		} elseif ( 'clicks' === $column_name ) {
			echo intval( get_post_meta( $post_id, 'tracking_clicks', true ) );
		}
	}

	/**
	 * Sortable ads columns.
	 *
	 * @param array $columns Columns.
	 */
	public static function sortable_ads_columns( $columns ) {
		$columns['impressions'] = 'impressions';
		$columns['clicks']      = 'clicks';
		return $columns;
	}

	/**
	 * Handle sorting.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function handle_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT === $query->get( 'post_type' ) ) {
			$orderby = $query->get( 'orderby' );
			if ( 'opened' === $orderby ) {
				$query->set( 'meta_key', 'tracking_pixel_seen' );
				$query->set( 'orderby', 'meta_value_num' );
			} elseif ( 'clicks' === $orderby ) {
				$query->set( 'meta_key', 'tracking_clicks' );
				$query->set( 'orderby', 'meta_value_num' );
			}
		}

		if ( \Newspack_Newsletters\Ads::CPT === $query->get( 'post_type' ) ) {
			$orderby = $query->get( 'orderby' );
			if ( 'impressions' === $orderby ) {
				$query->set( 'meta_key', 'tracking_impressions' );
				$query->set( 'orderby', 'meta_value_num' );
			} elseif ( 'clicks' === $orderby ) {
				$query->set( 'meta_key', 'clicks' );
				$query->set( 'orderby', 'meta_value_num' );
			}
		}
	}
}
Admin::init();
