<?php
/**
 * Mock BlockBindings class for testing RDB integration.
 *
 * @package Newspack_Newsletters
 */

namespace RemoteDataBlocks\Editor\DataBinding;

if ( ! class_exists( '\RemoteDataBlocks\Editor\DataBinding\BlockBindings' ) ) {
	/**
	 * Mock BlockBindings class to stub RDB's get_value() method for testing.
	 * This must be loaded before the real RDB plugin to take precedence.
	 */
	class BlockBindings {
		/**
		 * Stubbed return values keyed by attribute name.
		 *
		 * @var array
		 */
		public static $stub_values = [];

		/**
		 * Recorded calls for assertion.
		 *
		 * @var array
		 */
		public static $calls = [];

		/**
		 * Reset stub state between tests.
		 */
		public static function reset() {
			self::$stub_values = [];
			self::$calls       = [];
		}

		/**
		 * Set stub values for get_value() calls.
		 *
		 * @param array $values Map of attribute names to return values.
		 */
		public static function set_stub_values( $values ) {
			self::$stub_values = $values;
		}

		/**
		 * Mock implementation of get_value().
		 *
		 * @param array  $source_args    The source arguments from the binding.
		 * @param mixed  $block          The block (WP_Block or array).
		 * @param string $attribute_name The attribute being bound.
		 * @return string|null The stubbed value or null.
		 */
		public static function get_value( $source_args, $block, $attribute_name ) {
			self::$calls[] = [
				'source_args'    => $source_args,
				'block'          => $block,
				'attribute_name' => $attribute_name,
			];

			return self::$stub_values[ $attribute_name ] ?? null;
		}
	}
}
