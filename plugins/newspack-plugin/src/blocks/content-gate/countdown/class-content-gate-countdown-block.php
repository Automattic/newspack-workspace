<?php
/**
 * Content Gate Countdown Block
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Content Gate Countdown Block class.
 */
class Content_Gate_Countdown_Block {
	/**
	 * Initialize the block.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue block scripts and styles.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! self::is_active() || ! Memberships::is_active() || ! is_singular() ) {
			return;
		}
		wp_enqueue_script(
			'newspack-content-gate-countdown-block',
			\Newspack\Newspack::plugin_url() . '/dist/content-gate-countdown-block.js',
			[ 'wp-i18n', 'newspack-content-gate-metering' ],
			NEWSPACK_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Register the block.
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array  $attributes The block attributes.
	 * @param string $content    The block content.
	 *
	 * @return string The block HTML.
	 */
	public static function render_block( array $attributes, string $content ) {
		if ( ! Metering::is_metering() || ! Content_Gate::is_post_restricted() ) {
			return '';
		}
		if ( Content_Gate::is_gated() ) {
			return '';
		}
		$total_views = Metering::get_total_metered_views( \is_user_logged_in() );
		if ( false === $total_views ) {
			return '';
		}
		$views     = Metering::get_current_user_metered_views();
		$countdown = sprintf(
			/* translators: 1: current number of metered views, 2: total metered views. */
			__( '%1$d/%2$d', 'newspack-plugin' ),
			$views,
			$total_views
		);
		$block_wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => 'newspack-content-gate-countdown__wrapper',
			]
		);
		return (
			"<div $block_wrapper_attributes>
				<span class='newspack-content-gate-countdown'>$countdown</span>
			</div>"
		);
	}

	/**
	 * Whether the block is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		/**
		 * Enables the content gate countdown block which displays a
		 * countdown timer for metered content access limits.
		 *
		 * @constant NEWSPACK_CONTENT_GATE_COUNTDOWN_BLOCK
		 * @type     bool
		 * @default  Countdown block disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_CONTENT_GATE_COUNTDOWN_BLOCK', true );
		 */
		return defined( 'NEWSPACK_CONTENT_GATE_COUNTDOWN_BLOCK' ) && NEWSPACK_CONTENT_GATE_COUNTDOWN_BLOCK;
	}
}

Content_Gate_Countdown_Block::init();
