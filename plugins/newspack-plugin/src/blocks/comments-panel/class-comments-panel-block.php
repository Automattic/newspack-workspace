<?php
/**
 * Comments Panel Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Comments_Panel;

defined( 'ABSPATH' ) || exit;

/**
 * Comments_Panel_Block Class.
 */
final class Comments_Panel_Block {

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
	 * Block render callback. Renders the outer wrapper around the pre-rendered
	 * trigger and content children.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Pre-rendered inner blocks HTML.
	 *
	 * @return string Block HTML.
	 */
	public static function render_block( array $attributes, string $content ) {
		// The compiled view script depends on dist/commons.js (a webpack split chunk).
		\Newspack\Newspack::load_common_assets();

		$wrapper_attributes = get_block_wrapper_attributes();

		return sprintf(
			'<div %s>%s</div>',
			$wrapper_attributes,
			$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}

Comments_Panel_Block::init();
