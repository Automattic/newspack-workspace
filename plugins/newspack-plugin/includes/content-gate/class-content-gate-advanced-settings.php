<?php
/**
 * Newspack Content Gate.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Content_Gate_Advanced_Settings {
	/**
	 * Option prefix for content gate options.
	 */
	const OPTION_PREFIX = 'newspack_content_gate_';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'the_content_feed', [ __CLASS__, 'restrict_feed_content' ], PHP_INT_MAX );
		add_filter( 'the_excerpt_rss', [ __CLASS__, 'restrict_feed_excerpt' ], PHP_INT_MAX );
	}

	/**
	 * Get the advanced settings.
	 *
	 * @return array The advanced settings.
	 */
	public static function get_settings() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		// RSS.
		$settings = [
			'restrict_feeds' => get_option( self::OPTION_PREFIX . 'restrict_feeds', 1 ),
		];

		self::$settings = $settings;
		return self::$settings;
	}

	/**
	 * Update the advanced settings.
	 *
	 * @param array $settings The advanced settings.
	 */
	public static function update_settings( $settings ) {
		if ( isset( $settings['restrict_feeds'] ) ) {
			update_option( self::OPTION_PREFIX . 'restrict_feeds', boolval( $settings['restrict_feeds'] ) ? 1 : 0, false );
		}
		self::reset_cache();
		return self::get_settings();
	}

	/**
	 * Reset the settings cache.
	 */
	public static function reset_cache() {
		self::$settings = null;
	}

	/**
	 * Truncate post content in RSS feeds when restrict_feeds is enabled.
	 *
	 * Uses the gate's excerpt settings (<!--more--> tag or paragraph count) to
	 * match what logged-out visitors see on the front-end. The inline gate HTML
	 * is intentionally omitted — feeds should not contain login prompts.
	 *
	 * @param string $content Feed item content.
	 *
	 * @return string
	 */
	public static function restrict_feed_content( $content ) {
		$settings = self::get_settings();
		if ( empty( $settings['restrict_feeds'] ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || ! Content_Gate::is_post_restricted( $post->ID ) ) {
			return $content;
		}
		return Content_Gate::get_restricted_post_excerpt_for_gate( $post, Content_Gate::get_gate_layout_id( $post->ID ) );
	}

	/**
	 * Truncate post excerpt in RSS feeds when restrict_feeds is enabled.
	 *
	 * @param string $excerpt Feed item excerpt.
	 *
	 * @return string
	 */
	public static function restrict_feed_excerpt( $excerpt ) {
		$settings = self::get_settings();
		if ( empty( $settings['restrict_feeds'] ) ) {
			return $excerpt;
		}
		$post = get_post();
		if ( ! $post || ! Content_Gate::is_post_restricted( $post->ID ) ) {
			return $excerpt;
		}
		return Content_Gate::get_restricted_post_excerpt_for_gate( $post, Content_Gate::get_gate_layout_id( $post->ID ) );
	}
}
Content_Gate_Advanced_Settings::init();
