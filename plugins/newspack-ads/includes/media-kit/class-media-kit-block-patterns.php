<?php
/**
 * Gutenberg Blocks setup
 *
 * Adapted from https://github.com/10up/publisher-media-kit/.
 *
 * @package Newspack
 */

namespace Newspack_Ads;

/**
 * Newspack Ads Media Kit Page Class.
 */
final class Media_Kit_Block_Patterns {
	/**
	 * Initialize settings.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block_patterns_and_categories' ] );
	}

	/**
	 * Register block patterns.
	 */
	public static function register_block_patterns_and_categories() {
			// Register block pattern category for Publisher Media Kit.
			register_block_pattern_category(
				'newspack-ads',
				array( 'label' => __( 'Newspack Media Kit', 'newspack-ads' ) )
			);

			// Register block pattern for the intro.
			ob_start();
			include_once NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'intro.php';
			$intro = ob_get_clean();
			register_block_pattern(
				'newspack-ads/intro',
				array(
					'title'       => __( 'Media Kit - Intro', 'newspack-ads' ),
					'description' => __( 'The intro section for the Media Kit page.', 'newspack-ads' ),
					'categories'  => [ 'newspack-ads' ],
					'content'     => wp_kses_post( $intro ),
				)
			);

			// Register block pattern for the audience.
			ob_start();
			include_once NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'audience.php';
			$audience = ob_get_clean();
			register_block_pattern(
				'newspack-ads/audience',
				array(
					'title'       => __( 'Media Kit - Audience', 'newspack-ads' ),
					'description' => __( 'A 3-column layout showing the audience.', 'newspack-ads' ),
					'categories'  => [ 'newspack-ads' ],
					'content'     => wp_kses_post( $audience ),
				)
			);

			// Register block pattern for why us.
			ob_start();
			include_once NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'why-us.php';
			$why_us = ob_get_clean();
			register_block_pattern(
				'newspack-ads/why-us',
				array(
					'title'       => __( 'Media Kit - Why Us?', 'newspack-ads' ),
					'description' => __( 'A 2-column layout for the "Why Us?" section.', 'newspack-ads' ),
					'categories'  => [ 'newspack-ads' ],
					'content'     => wp_kses_post( $why_us ),
				)
			);

			// Register block pattern for tabs with table structure for the ad specs.
			ob_start();
			include_once NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'ad-specs.php';
			$ad_specs = ob_get_clean();
			register_block_pattern(
				'newspack-ads/ad-specs',
				array(
					'title'       => __( 'Media Kit - Ad Specs', 'newspack-ads' ),
					'description' => __( 'Ad Specs tabular structure with tabs management.', 'newspack-ads' ),
					'categories'  => [ 'newspack-ads' ],
					'content'     => wp_kses_post( $ad_specs ),
				)
			);

			// Register block pattern for tabs with table structure for the rates.
			ob_start();
			include_once NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'rates.php';
			$rates = ob_get_clean();
			register_block_pattern(
				'newspack-ads/rates',
				array(
					'title'       => __( 'Media Kit - Rates', 'newspack-ads' ),
					'description' => __( 'Rates tabular structure with tabs management.', 'newspack-ads' ),
					'categories'  => [ 'newspack-ads' ],
					'content'     => wp_kses_post( $rates ),
				)
			);

			// Register block pattern for the packages section.
			ob_start();
			include_once NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'packages.php';
			$packages = ob_get_clean();
			register_block_pattern(
				'newspack-ads/packages',
				array(
					'title'       => __( 'Media Kit - Packages', 'newspack-ads' ),
					'description' => __( 'Packages layout with a short note and a 3-column layout.', 'newspack-ads' ),
					'categories'  => [ 'newspack-ads' ],
					'content'     => wp_kses_post( $packages ),
				)
			);

			// Register block pattern for the contact (compact) section.
			ob_start();
			include_once NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'contact-compact.php';
			$contact_compact = ob_get_clean();
			register_block_pattern(
				'newspack-ads/contact-compact',
				array(
					'title'       => __( 'Media Kit - Contact (Compact)', 'newspack-ads' ),
					'description' => __( 'A compact Call-To-Action to get in touch.', 'newspack-ads' ),
					'categories'  => [ 'newspack-ads' ],
					'content'     => wp_kses_post( $contact_compact ),
				)
			);

			// Register block pattern for the contact section.
			ob_start();
			include_once NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'contact.php';
			$contact = ob_get_clean();
			register_block_pattern(
				'newspack-ads/contact',
				array(
					'title'       => __( 'Media Kit - Contact', 'newspack-ads' ),
					'description' => __( 'A Call-To-Action to get in touch.', 'newspack-ads' ),
					'categories'  => [ 'newspack-ads' ],
					'content'     => wp_kses_post( $contact ),
				)
			);
	}
}

Media_Kit_Block_Patterns::init();
