<?php
/**
 * Newspack base class for batched CSV exporters.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Shared surface for Newspack CSV exporters: captured list params and temp
 * files staged in a hardened uploads subdirectory.
 *
 * This class must only be loaded after WooCommerce's WC_CSV_Batch_Exporter
 * abstract (see CSV_Exports::load_exporter_dependencies()).
 */
abstract class CSV_Batch_Exporter extends \WC_CSV_Batch_Exporter {

	/**
	 * Captured admin list query params (parsed query string).
	 *
	 * @var array
	 */
	protected $list_params = [];

	/**
	 * Set the captured list-table query params driving this export.
	 *
	 * @param array $params Parsed query-string params from the admin list.
	 */
	public function set_list_params( $params ) {
		$this->list_params = (array) $params;
	}

	/**
	 * Get (and create on first use) the exports directory under uploads.
	 *
	 * The directory ships an empty index.html and a deny-all .htaccess; on
	 * servers where .htaccess does not apply (nginx), the random filename
	 * suffix keeps in-progress files unguessable.
	 *
	 * @return string Directory path, no trailing slash.
	 */
	public static function get_exports_dir() {
		$upload_dir = \wp_upload_dir();
		$dir        = \trailingslashit( $upload_dir['basedir'] ) . CSV_Exports::EXPORTS_DIR;
		if ( ! is_dir( $dir ) ) {
			\wp_mkdir_p( $dir );
			// Direct file ops are fine here: the dir is under uploads.
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			file_put_contents( \trailingslashit( $dir ) . 'index.html', '' );
			file_put_contents( \trailingslashit( $dir ) . '.htaccess', 'deny from all' );
			// phpcs:enable
		}
		return $dir;
	}

	/**
	 * Stage temp files in the hardened exports subdirectory instead of the
	 * uploads root (the headers-row temp file derives from this path too).
	 *
	 * @return string
	 */
	protected function get_file_path() {
		return \trailingslashit( self::get_exports_dir() ) . $this->get_filename();
	}

	/**
	 * Public accessor for the export file path (the parent's is protected).
	 *
	 * @return string
	 */
	public function get_export_file_path() {
		return $this->get_file_path();
	}
}
