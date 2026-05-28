<?php
/**
 * Content Distribution Custom Handling for Gutenberg Blocks
 *
 * @package Newspack_Network
 */

namespace Newspack_Network\Content_Distribution;

/**
 * Blocks class.
 */
class Blocks {
	/**
	 * Registered block processors
	 *
	 * @var array<string, Block_Processor[]> Array of block processors indexed by block name.
	 */
	private static $block_processors = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		Image_Block::init();

		// Register block processors.
		self::register_block_processor( 'jetpack/slideshow', [ __CLASS__, 'process_jetpack_galleries' ] );
		self::register_block_processor( 'jetpack/tiled-gallery', [ __CLASS__, 'process_jetpack_galleries' ] );
	}

	/**
	 * Register a block processor.
	 *
	 * @param string        $block_name        The name of the block to process.
	 * @param callable|null $outgoing_callback The callback to transform the outgoing block.
	 * @param callable|null $incoming_callback The callback to transform the incoming block.
	 *
	 * @return void
	 */
	public static function register_block_processor( $block_name, $outgoing_callback = null, $incoming_callback = null ) {
		$block_processor = new Block_Processor( $block_name, $outgoing_callback, $incoming_callback );
		if ( ! isset( self::$block_processors[ $block_name ] ) ) {
			self::$block_processors[ $block_name ] = [];
		}
		self::$block_processors[ $block_name ][] = $block_processor;
	}

	/**
	 * Reset the block processors for a block name.
	 *
	 * @param string $block_name The name of the block.
	 *
	 * @return void
	 */
	public static function reset_block_processors( $block_name ) {
		self::$block_processors[ $block_name ] = [];
	}

	/**
	 * Process an outgoing block.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public static function process_outgoing_block( $block ) {
		$block_name = $block['blockName'];

		$processors = self::get_block_processors( $block_name );
		if ( empty( $processors ) ) {
			return $block;
		}

		foreach ( $processors as $processor ) {
			$block = $processor->process_outgoing_block( $block );
		}
		return $block;
	}

	/**
	 * Process an incoming block.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public static function process_incoming_block( $block ) {
		$block_name = $block['blockName'];

		$processors = self::get_block_processors( $block_name );
		if ( empty( $processors ) ) {
			return $block;
		}

		foreach ( $processors as $processor ) {
			$block = $processor->process_incoming_block( $block );
		}
		return $block;
	}

	/**
	 * Get the processors for a block.
	 *
	 * @param string $block_name The name of the block.
	 *
	 * @return Block_Processor[] The block processors.
	 */
	public static function get_block_processors( $block_name ) {
		return self::$block_processors[ $block_name ] ?? [];
	}

	/**
	 * Process Jetpack galleries blocks.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public static function process_jetpack_galleries( $block ) {
		unset( $block['attrs']['ids'] );
		return $block;
	}
}
