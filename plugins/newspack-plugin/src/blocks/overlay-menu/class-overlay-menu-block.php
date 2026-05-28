<?php
/**
 * Overlay Menu Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Overlay_Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Overlay_Menu_Block Class.
 */
final class Overlay_Menu_Block {

	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block type.
	 *
	 * @return void
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
	 * Renders the outer wrapper. Trigger and panel are rendered by their own
	 * child-block render callbacks and arrive pre-rendered in $content.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Pre-rendered inner blocks HTML.
	 *
	 * @return string Block HTML.
	 */
	public static function render_block( array $attributes, string $content ) {
		// The compiled view script depends on dist/commons.js (a webpack split chunk).
		// Ensure commons is enqueued so the entry callback can execute on the frontend.
		\Newspack\Newspack::load_common_assets();

		$instance_id = esc_attr( $attributes['instanceId'] ?? '' );

		// Only add data-overlay-id when instanceId is set to avoid empty selectors.
		$extra_attrs = $instance_id ? [ 'data-overlay-id' => $instance_id ] : [];

		$wrapper_attributes = get_block_wrapper_attributes( $extra_attrs );

		return sprintf(
			'<div %s>%s</div>',
			$wrapper_attributes,
			$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}

Overlay_Menu_Block::init();
