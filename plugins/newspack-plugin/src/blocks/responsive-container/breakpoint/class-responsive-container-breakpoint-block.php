<?php
/**
 * Responsive Container Breakpoint Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Responsive_Container;

defined( 'ABSPATH' ) || exit;

/**
 * Responsive_Container_Breakpoint_Block Class.
 *
 * Registers the child breakpoint block. Content is saved statically; this server-side
 * registration exists so block supports are recognized.
 */
final class Responsive_Container_Breakpoint_Block {

	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block type from metadata.
	 *
	 * @return void
	 */
	public static function register_block() {
		register_block_type_from_metadata( __DIR__ . '/block.json' );
	}
}
Responsive_Container_Breakpoint_Block::init();
