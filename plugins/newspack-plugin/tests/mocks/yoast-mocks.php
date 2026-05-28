<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing

/**
 * Minimal mock for WPSEO_Primary_Term, used when Yoast SEO is not loaded
 * in the test environment.
 */
class WPSEO_Primary_Term {
	private $taxonomy;
	private $post_id;

	public function __construct( $taxonomy, $post_id ) {
		$this->taxonomy = $taxonomy;
		$this->post_id  = $post_id;
	}

	public function get_primary_term() {
		return get_post_meta( $this->post_id, '_yoast_wpseo_primary_' . $this->taxonomy, true );
	}
}
