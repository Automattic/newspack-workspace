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
	 * The billing/shipping address columns shared by the exporters
	 * (subscriptions read them from the subscription, users from user meta).
	 *
	 * @return array Column id => translated label.
	 */
	public static function get_address_column_labels() {
		return [
			'billing_first_name'  => __( 'Billing First Name', 'newspack-plugin' ),
			'billing_last_name'   => __( 'Billing Last Name', 'newspack-plugin' ),
			'billing_company'     => __( 'Billing Company', 'newspack-plugin' ),
			'billing_address_1'   => __( 'Billing Address 1', 'newspack-plugin' ),
			'billing_address_2'   => __( 'Billing Address 2', 'newspack-plugin' ),
			'billing_city'        => __( 'Billing City', 'newspack-plugin' ),
			'billing_state'       => __( 'Billing State', 'newspack-plugin' ),
			'billing_postcode'    => __( 'Billing Postcode', 'newspack-plugin' ),
			'billing_country'     => __( 'Billing Country', 'newspack-plugin' ),
			'billing_email'       => __( 'Billing Email', 'newspack-plugin' ),
			'billing_phone'       => __( 'Billing Phone', 'newspack-plugin' ),
			'shipping_first_name' => __( 'Shipping First Name', 'newspack-plugin' ),
			'shipping_last_name'  => __( 'Shipping Last Name', 'newspack-plugin' ),
			'shipping_company'    => __( 'Shipping Company', 'newspack-plugin' ),
			'shipping_address_1'  => __( 'Shipping Address 1', 'newspack-plugin' ),
			'shipping_address_2'  => __( 'Shipping Address 2', 'newspack-plugin' ),
			'shipping_city'       => __( 'Shipping City', 'newspack-plugin' ),
			'shipping_state'      => __( 'Shipping State', 'newspack-plugin' ),
			'shipping_postcode'   => __( 'Shipping Postcode', 'newspack-plugin' ),
			'shipping_country'    => __( 'Shipping Country', 'newspack-plugin' ),
		];
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
			if ( ! \wp_mkdir_p( $dir ) ) {
				// Downstream file writes will fail and surface their own errors.
				return $dir;
			}
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

	/**
	 * Save the assembled export (headers row + data) to a path and remove
	 * the temp files. Used by the WP-CLI commands; the admin flow streams
	 * via export() instead.
	 *
	 * @param string $path Destination file path.
	 * @return bool Whether the file was written.
	 */
	public function save_to( $path ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink, Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
		$saved = file_put_contents( $path, $this->get_headers_row_file() . $this->get_file() );
		@unlink( $this->get_file_path() );
		@unlink( $this->get_headers_row_file_path() );
		// phpcs:enable
		return false !== $saved;
	}
}
