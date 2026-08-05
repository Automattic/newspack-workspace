<?php
/**
 * Test fixture: a theme.json data object that is NOT a WP_Theme_JSON_Data.
 *
 * Stands in for `WP_Theme_JSON_Data_Gutenberg`, which the Gutenberg plugin
 * passes to the `wp_theme_json_data_*` filters instead of core's class. It is a
 * sibling implementation, NOT a subclass of `WP_Theme_JSON_Data`, so any
 * callback that type-hints the core class fatals with a TypeError before its
 * own guards can run.
 *
 * Lives in `fixtures/` and is required explicitly by the tests that need it.
 *
 * @package Newspack_Newsletters
 */

defined( 'ABSPATH' ) || exit;

/**
 * Duck-typed theme.json data object outside the WP_Theme_JSON_Data hierarchy.
 */
class Foreign_Theme_JSON_Data {

	/**
	 * Theme.json data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Origin of the data ('default', 'blocks', 'theme', 'custom').
	 *
	 * @var string
	 */
	private $origin;

	/**
	 * Constructor.
	 *
	 * @param array  $data   Theme.json data.
	 * @param string $origin Origin of the data.
	 */
	public function __construct( array $data = [], string $origin = 'default' ) {
		$this->data   = $data;
		$this->origin = $origin;
	}

	/**
	 * Merge new data in, mirroring WP_Theme_JSON_Data::update_with().
	 *
	 * Left untyped on purpose: core's `WP_Theme_JSON_Data::update_with( $new_data )`
	 * declares no parameter type, and a stand-in that is stricter than the class it
	 * imitates would defeat the point of the fixture.
	 *
	 * @param array $new_data Data to merge in.
	 * @return self
	 */
	public function update_with( $new_data ) {
		$this->data = array_replace_recursive( $this->data, $new_data );
		return $this;
	}

	/**
	 * Return the underlying data.
	 *
	 * @return array
	 */
	public function get_data() {
		return $this->data;
	}
}
