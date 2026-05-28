<?php
/**
 * Newspack block patterns
 *
 * @package Newspack_Blocks
 */

/**
 * Selectively register block patterns and categories, by post type.
 */
class Newspack_Blocks_Patterns {
	/**
	 * Add hooks and filters.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register_block_patterns_and_categories' ] );
	}

	/**
	 * Registers block patterns and categories.
	 *
	 * @package Newspack_Blocks
	 */
	public static function register_block_patterns_and_categories() {
		/*
		* Register block patterns.
		*/
		if ( function_exists( 'register_block_pattern' ) ) {
			/*
			* Register block patterns for particular post types.
			* We need to get the post type using the post ID from $_REQUEST
			* since the global $post is not available inside the admin_init hook.
			*/
			$post_type = isset( $_REQUEST['post_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['post_type'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id   = isset( $_REQUEST['post'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['post'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( empty( $post_type ) && ! empty( $post_id ) ) {
				$post_type = get_post_type( $post_id );
			}

			/*
			* Check if donations have been configured.
			* If not, we won't show the Donations patterns.
			*/
			$donations_configured = false;
			if ( class_exists( 'Newspack\Donations' ) ) {
				$settings = Newspack\Donations::get_donation_settings();
				if ( ! is_wp_error( $settings ) ) {
					$donations_configured = true;
				}
			}

			$block_patterns            = [];
			$post_types_with_campaigns = [ 'page', 'post' ];

			// If Campaigns plugin is active, allow patterns in prompts.
			if ( class_exists( 'Newspack_Popups' ) ) {
				$post_types_with_campaigns[] = Newspack_Popups::NEWSPACK_POPUPS_CPT;
			}

			// Donations: pages, posts, and prompts if Campaigns is active.
			if (
				$donations_configured &&
				( null === $post_type || in_array( $post_type, $post_types_with_campaigns, true ) )
			) {
				array_push(
					$block_patterns,
					'donations-1',
					'donations-2',
					'donations-3',
					'donations-4',
					'donations-5'
				);
			}

			// Content Loop: pages only.
			if ( null === $post_type || 'page' === $post_type ) {
				array_push(
					$block_patterns,
					'homepage-posts-1',
					'homepage-posts-2',
					'homepage-posts-3',
					'homepage-posts-4',
					'homepage-posts-5',
					'homepage-posts-6',
					'homepage-posts-7',
					'homepage-posts-8',
					'homepage-posts-9',
					'homepage-posts-10',
					'homepage-posts-11',
					'homepage-posts-12',
					'homepage-posts-13',
					'homepage-posts-14',
					'homepage-posts-15',
					'homepage-posts-16',
					'homepage-posts-17',
					'homepage-posts-18',
					'homepage-posts-19',
					'homepage-posts-20',
					'homepage-posts-21',
					'homepage-posts-22',
					'homepage-posts-23',
					'homepage-posts-24',
					'homepage-posts-25',
					'homepage-posts-26',
					'homepage-posts-27',
					'homepage-posts-28',
					'homepage-posts-29',
					'homepage-posts-30',
					'homepage-posts-31'
				);
			}

			// Subscribe: pages, posts, and prompts if Campaigns is active.
			if ( null === $post_type || in_array( $post_type, $post_types_with_campaigns, true ) ) {
				array_push(
					$block_patterns,
					'subscribe-1',
					'subscribe-2',
					'subscribe-3',
					'subscribe-4',
					'subscribe-5',
					'subscribe-6',
					'subscribe-7'
				);
			}

			// Patterns: common variables.
			// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			$donations_title = esc_html__( 'Support our publication', 'newspack-blocks' );
			$donations_desc  = esc_html__( 'With the support of readers like you, we provide thoughtfully researched articles for a more informed and connected community. This is your chance to support credible, community-based, public-service journalism. Please join us!', 'newspack-blocks' );
			$donations_image = esc_url( plugins_url( 'src/assets/placeholder-donations-image.jpg', NEWSPACK_BLOCKS__PLUGIN_FILE ) );
			$donations_cover = esc_url( plugins_url( 'src/assets/placeholder-donations-cover.jpg', NEWSPACK_BLOCKS__PLUGIN_FILE ) );
			$subscribe_title = esc_html__( 'Subscribe to our newsletter', 'newspack-blocks' );
			$subscribe_desc  = esc_html__( 'Be the first to know about breaking news, articles, and updates.', 'newspack-blocks' );
			$subscribe_image = esc_url( plugins_url( 'src/assets/placeholder-subscribe-image.jpg', NEWSPACK_BLOCKS__PLUGIN_FILE ) );
			$subscribe_cover = esc_url( plugins_url( 'src/assets/placeholder-subscribe-cover.jpg', NEWSPACK_BLOCKS__PLUGIN_FILE ) );
			// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

			foreach ( $block_patterns as $block_pattern ) {
				register_block_pattern(
					'newspack/' . $block_pattern,
					require NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/block-patterns/' . $block_pattern . '.php'
				);
			}
		}

		/*
		* Register block pattern categories.
		*/
		if ( function_exists( 'register_block_pattern_category' ) ) {
			register_block_pattern_category(
				'newspack-donations',
				[ 'label' => __( 'Newspack Donations', 'newspack-blocks' ) ]
			);
			register_block_pattern_category(
				'newspack-homepage-posts',
				[ 'label' => __( 'Newspack Content Loop', 'newspack-blocks' ) ]
			);
			register_block_pattern_category(
				'newspack-subscribe',
				[ 'label' => __( 'Newspack Subscribe', 'newspack-blocks' ) ]
			);
		}
	}
}
Newspack_Blocks_Patterns::init();
