<?php
/**
 * Stand-in for Newspack's Yoast configuration manager, backed by an in-memory
 * option store.
 *
 * It hands back whatever it was given, and makes no attempt to reproduce Yoast's
 * own validation: the section decides a save was rejected by comparing the list it
 * sent with the list the next read reports, which is a comparison of plain arrays.
 *
 * @package Newspack\Tests
 */

/**
 * Yoast configuration manager test double.
 */
class Yoast_Configuration_Manager_Mock {

	/**
	 * Stored options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param array $options Options to start from.
	 */
	public function __construct( $options = [] ) {
		$this->options = $options;
	}

	/**
	 * Read an option.
	 *
	 * @param string $key           Key of the option to return.
	 * @param mixed  $default_value Value to return when the option is unset.
	 * @return mixed The option value.
	 */
	public function get_option( $key, $default_value = '' ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default_value;
	}
}
