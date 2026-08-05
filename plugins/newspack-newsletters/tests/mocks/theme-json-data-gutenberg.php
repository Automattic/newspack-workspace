<?php // phpcs:disable Squiz.Commenting, Universal.Files, Generic.Files

// Stands in for the Gutenberg plugin's WP_Theme_JSON_Data_Gutenberg.
//
// Gutenberg's theme.json resolver fires the same wp_theme_json_data_* filters as core
// but passes its OWN data class, which is standalone — it does NOT extend core's
// WP_Theme_JSON_Data. A callback that type-hints the core class therefore throws a
// TypeError before its body ever runs, on every request, on any Gutenberg-active site.
//
// The test suite has no Gutenberg, so this stub reproduces the one thing that matters:
// the same public surface, no inheritance from WP_Theme_JSON_Data. It wraps core's
// WP_Theme_JSON so merge behavior stays real and assertions on the merged output hold.
//
// Declared under the real class name and guarded, so a test environment that does load
// Gutenberg uses the real class instead.

if ( ! class_exists( 'WP_Theme_JSON_Data_Gutenberg' ) ) {
	class WP_Theme_JSON_Data_Gutenberg {
		private $theme_json = null;
		private $origin     = '';

		public function __construct( $data = [ 'version' => 3 ], $origin = 'theme' ) {
			$this->origin     = $origin;
			$this->theme_json = new WP_Theme_JSON( $data, $this->origin );
		}

		public function update_with( $new_data ) {
			$this->theme_json->merge( new WP_Theme_JSON( $new_data, $this->origin ) );
			return $this;
		}

		public function get_data() {
			return $this->theme_json->get_raw_data();
		}

		public function get_theme_json() {
			return $this->theme_json;
		}
	}
}
