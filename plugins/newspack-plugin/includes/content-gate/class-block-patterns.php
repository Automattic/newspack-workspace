<?php
/**
 * WooCommerce Memberships Block Patterns.
 *
 * @package Newspack
 */

namespace Newspack\Content_Gate;

/**
 * WooCommerce Memberships Block Patterns class.
 */
class Block_Patterns {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register_block_patterns' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_styles' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
	}

	/**
	 * Enqueue styles.
	 */
	public static function enqueue_styles() {
		/**
		 * Filters whether to enqueue the reader auth scripts.
		 *
		 * @param bool $should_enqueue_styles Whether to enqueue the reader auth scripts.
		 */
		if ( ! apply_filters( 'newspack_enqueue_content_gate_block_patterns', true ) ) {
			return false;
		}
		wp_enqueue_style(
			'newspack-content_gate-block-patterns',
			\Newspack\Newspack::plugin_url() . '/dist/content-gate-block-patterns.css',
			[],
			\Newspack\Newspack::asset_version( 'content-gate-block-patterns' )
		);
	}


	/**
	 * Get block patterns.
	 *
	 * Each pattern content should be a PHP file in the block-patterns directory
	 * named after the pattern slug.
	 *
	 * @return array
	 */
	public static function get_block_patterns() {
		return [
			'registration-banner'             => __( 'Registration Banner', 'newspack-plugin' ),
			'registration-wall'               => __( 'Registration Wall', 'newspack-plugin' ),
			'donation-wall'                   => __( 'Donation Wall', 'newspack-plugin' ),
			'pay-wall-one-tier'               => __( 'Paywall with One Tier', 'newspack-plugin' ),
			'pay-wall-one-tier-metering'      => __( 'Paywall with One Tier and Metering', 'newspack-plugin' ),
			'pay-wall-one-tier-metering-wide' => __( 'Paywall with One Tier and Metering (Wide)', 'newspack-plugin' ),
			'pay-wall-two-tiers'              => __( 'Paywall with Two Tiers', 'newspack-plugin' ),
			'pay-wall-two-tiers-alt'          => __( 'Paywall with Two Tiers (Alt)', 'newspack-plugin' ),
			'pay-wall-three-tiers'            => __( 'Paywall with Three Tiers', 'newspack-plugin' ),
			'pay-wall-three-tiers-alt'        => __( 'Paywall with Three Tiers (Alt)', 'newspack-plugin' ),
		];
	}

	/**
	 * Strip extra whitespace from pattern content.
	 *
	 * Pattern template files use indentation and line breaks for readability,
	 * but this whitespace is rendered as visible text nodes in the block editor.
	 * This method collapses newlines and leading whitespace between HTML tags
	 * and their content so the editor renders cleanly.
	 *
	 * @param string $content The pattern content.
	 * @return string The content with extra whitespace stripped.
	 */
	public static function strip_pattern_whitespace( $content ) {
		// Collapse whitespace (newlines + tabs/spaces) between an opening tag and content.
		$content = preg_replace( '/(<[^\/][^>]*>)\s+/', '$1', $content );
		// Collapse whitespace before closing tags.
		$content = preg_replace( '/\s+(<\/[^>]+>)/', '$1', $content );
		return $content;
	}

	/**
	 * Get the first purchasable subscription product ID from custom access rules.
	 *
	 * @param array $pattern_context The pattern context with custom_access_settings.
	 * @return int The product ID, or 0 if none found.
	 */
	public static function get_subscription_product_id( $pattern_context ) {
		if ( empty( $pattern_context['custom_access_settings']['access_rules'] ) || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		foreach ( $pattern_context['custom_access_settings']['access_rules'] as $group ) {
			foreach ( $group as $rule ) {
				if ( 'subscription' === ( $rule['slug'] ?? '' ) && ! empty( $rule['value'] ) ) {
					$product = \wc_get_product( absint( is_array( $rule['value'] ) ? reset( $rule['value'] ) : $rule['value'] ) );
					if ( $product && $product->is_purchasable() ) {
						return $product->get_id();
					}
				}
			}
		}
		return 0;
	}

	/**
	 * Extract metering settings from custom access context, with defaults.
	 *
	 * @param array $pattern_context The pattern context with custom_access_settings.
	 * @return array { count: int, period: string }
	 */
	public static function get_metering_settings( $pattern_context ) {
		$count  = 4;
		$period = __( 'month', 'newspack-plugin' );
		if ( ! empty( $pattern_context['custom_access_settings']['metering'] ) ) {
			$metering = $pattern_context['custom_access_settings']['metering'];
			if ( ! empty( $metering['count'] ) ) {
				$count = absint( $metering['count'] );
			}
			if ( ! empty( $metering['period'] ) ) {
				$period = sanitize_text_field( $metering['period'] );
			}
		}
		return [
			'count'  => $count,
			'period' => $period,
		];
	}

	/**
	 * Register block patterns.
	 */
	public static function register_block_patterns() {
		\register_block_pattern_category( 'newspack-content-gate', [ 'label' => __( 'Newspack Access Control', 'newspack-plugin' ) ] );
		$patterns = self::get_block_patterns();
		foreach ( $patterns as $slug => $title ) {
			$path = __DIR__ . '/block-patterns/' . $slug . '.php';
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$pattern_context = []; // No gate context available for the pattern inserter.
			ob_start();
			require $path;
			$content = ob_get_clean();
			if ( empty( $content ) ) {
				continue;
			}
			\register_block_pattern(
				'newspack-content-gate/' . $slug,
				[
					'categories'  => [ 'newspack-content-gate' ],
					'title'       => $title,
					'description' => _x( 'Invite your reader to become a member before continuing reading the article', 'Block pattern description', 'newspack-plugin' ),
					'content'     => self::strip_pattern_whitespace( $content ),
				]
			);
		}
	}
}
Block_Patterns::init();
