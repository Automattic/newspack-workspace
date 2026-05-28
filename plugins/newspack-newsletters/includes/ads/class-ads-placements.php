<?php
/**
 * Newspack Newsletter Ads Placements.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack_Newsletters;

/**
 * Ads Placements for Newsletters.
 */
final class Ads_Placements {

	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	const TAXONOMY = 'newspack_nl_ad_placement';

	/**
	 * Initialize hooks.
	 */
	public static function init_hooks() {
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
	}

	/**
	 * Register placements.
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			[ Ads::CPT ],
			[
				'labels'            => [
					'name'                     => __( 'Ad Placements', 'newspack-newsletters' ),
					'singular_name'            => __( 'Ad Placement', 'newspack-newsletters' ),
					'search_items'             => __( 'Search Ad Placements', 'newspack-newsletters' ),
					'popular_items'            => __( 'Popular Ad Placements', 'newspack-newsletters' ),
					'all_items'                => __( 'All Ad Placements', 'newspack-newsletters' ),
					'parent_items'             => __( 'Parent Ad Placements', 'newspack-newsletters' ),
					'parent_item'              => __( 'Parent Ad Placement', 'newspack-newsletters' ),
					'name_field_description'   => __( 'The ad placement name', 'newspack-newsletters' ),
					'slug_field_description'   => '', // There's no ad placement URL so let's skip slug field description.
					'parent_field_description' => __( 'Assign a parent ad placement', 'newspack-newsletters' ),
					'desc_field_description'   => __( 'Optional description for this ad placement', 'newspack-newsletters' ),
					'edit_item'                => __( 'Edit Ad Placement', 'newspack-newsletters' ),
					'view_item'                => __( 'View Ad Placement', 'newspack-newsletters' ),
					'update_item'              => __( 'Update Ad Placement', 'newspack-newsletters' ),
					'add_new_item'             => __( 'Add New Ad Placement', 'newspack-newsletters' ),
					'new_item_name'            => __( 'New Ad Placement Name', 'newspack-newsletters' ),
					'not_found'                => __( 'No ad placements found', 'newspack-newsletters' ),
					'no_terms'                 => __( 'No ad placements', 'newspack-newsletters' ),
					'filter_by_item'           => __( 'Filter by ad placement', 'newspack-newsletters' ),
				],
				'public'            => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_menu'      => false,
				'show_in_nav_menus' => false,
				'show_ui'           => false,
				'rest_base'         => 'ad_placement',
			]
		);
	}

	/**
	 * Get ad by placement.
	 *
	 * @param int      $placement_id  Placement ID.
	 * @param int|null $newsletter_id Optional newsletter ID to match category and advertiser.
	 *
	 * @return \WP_Post|null Ad post object if found, null otherwise.
	 */
	public static function get_ad_by_placement( $placement_id, $newsletter_id = null ) {
		$placement_ads = get_posts(
			[
				'post_type'      => Ads::CPT,
				'posts_per_page' => -1,
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $placement_id,
					],
				],
			]
		);

		if ( empty( $placement_ads ) ) {
			return null;
		}

		$ads = [];
		foreach ( $placement_ads as $ad ) {
			if ( ! Ads::is_ad_active( $ad->ID, $newsletter_id ) ) {
				continue;
			}

			// Bail if the ad insertion strategy is not "placement".
			if ( 'placement' !== get_post_meta( $ad->ID, 'insertion_strategy', true ) ) {
				continue;
			}

			if ( ! empty( $newsletter_id ) ) {
				$ad_categories = wp_get_post_terms( $ad->ID, 'category' );
				// Skip if the ad is not in the same category as the newsletter.
				if ( ! empty( $ad_categories ) ) {
					$newsletter_categories = wp_get_post_terms( $newsletter_id, 'category' );
					if ( empty( array_intersect( wp_list_pluck( $ad_categories, 'term_id' ), wp_list_pluck( $newsletter_categories, 'term_id' ) ) ) ) {
						continue;
					}
				}
				$newsletter_advertisers = wp_get_post_terms( $newsletter_id, Ads::ADVERTISER_TAX );
				// Skip if the newsletter has advertisers that does not match the ad.
				if ( ! empty( $newsletter_advertisers ) ) {
					$ad_advertisers = wp_get_post_terms( $ad->ID, Ads::ADVERTISER_TAX );
					if ( empty( array_intersect( wp_list_pluck( $newsletter_advertisers, 'term_id' ), wp_list_pluck( $ad_advertisers, 'term_id' ) ) ) ) {
						continue;
					}
				}
			}

			$ads[] = $ad;
		}

		if ( empty( $ads ) ) {
			return null;
		}

		return $ads[0];
	}
}
Ads_Placements::init_hooks();
