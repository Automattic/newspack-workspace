<?php
/**
 * Content Distribution Block Processor
 *
 * @package Newspack_Network
 */

namespace Newspack_Network\Content_Distribution;

/**
 * Block processor class.
 */
class Block_Processor {

	/**
	 * The name of the block to process.
	 *
	 * @var string
	 */
	protected $block_name;

	/**
	 * The callback to transform the outgoing block.
	 *
	 * @var callable
	 */
	protected $outgoing_callback;

	/**
	 * The callback to transform the incoming block.
	 *
	 * @var callable
	 */
	protected $incoming_callback;

	/**
	 * Constructor.
	 *
	 * @param string        $block_name        The name of the block to process.
	 * @param callable|null $outgoing_callback The callback to transform the outgoing block.
	 * @param callable|null $incoming_callback The callback to transform the incoming block.
	 */
	public function __construct( $block_name, $outgoing_callback = null, $incoming_callback = null ) {
		$this->block_name = $block_name;

		if ( $outgoing_callback ) {
			$this->set_outgoing_callback( $outgoing_callback );
		}
		if ( $incoming_callback ) {
			$this->set_incoming_callback( $incoming_callback );
		}
	}

	/**
	 * Set the outgoing callback.
	 *
	 * @param callable|null $callback The callback to transform the outgoing block.
	 *
	 * @return void
	 */
	public function set_outgoing_callback( $callback = null ) {
		$this->outgoing_callback = $callback;
	}

	/**
	 * Set the incoming callback.
	 *
	 * @param callable|null $callback The callback to transform the incoming block.
	 *
	 * @return void
	 */
	public function set_incoming_callback( $callback = null ) {
		$this->incoming_callback = $callback;
	}

	/**
	 * Process an outgoing block.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public function process_outgoing_block( $block ) {
		if ( ! is_callable( $this->outgoing_callback ) ) {
			return $block;
		}
		return call_user_func( $this->outgoing_callback, $block );
	}

	/**
	 * Process an incoming block.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public function process_incoming_block( $block ) {
		if ( ! is_callable( $this->incoming_callback ) ) {
			return $block;
		}
		return call_user_func( $this->incoming_callback, $block );
	}
}
