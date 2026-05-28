<?php
/**
 * Content Gate Countdown Box Block
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use Newspack\Memberships;
use Newspack\Metering;
use Newspack\Content_Gate_Countdown_Block;

/**
 * Content Gate Countdown Box Block class.
 */
class Content_Gate_Countdown_Box_Block {
	/**
	 * Initialize the block.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
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
		if ( ! Content_Gate::is_post_restricted() || ! Metering::is_metering() ) {
			return '';
		}

		if ( Content_Gate::is_gated() ) {
			return '';
		}

		$block_wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => 'newspack-content-gate-countdown-box__wrapper',
			]
		);

		$block_content = "<div $block_wrapper_attributes>
			$content
		</div>";

		return $block_content;
	}
}

Content_Gate_Countdown_Box_Block::init();
