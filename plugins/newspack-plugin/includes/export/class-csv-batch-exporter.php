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
	public function set_list_params( array $params ): void {
		$this->list_params = $params;
	}

	/**
	 * The billing/shipping address columns shared by the exporters
	 * (subscriptions read them from the subscription, users from user meta).
	 *
	 * @return array Column id => translated label.
	 */
	public static function get_address_column_labels(): array {
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
	public static function get_exports_dir(): string {
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
	protected function get_file_path(): string {
		return \trailingslashit( self::get_exports_dir() ) . $this->get_filename();
	}

	/**
	 * Public accessor for the export file path (the parent's is protected).
	 *
	 * @return string
	 */
	public function get_export_file_path(): string {
		return $this->get_file_path();
	}

	/**
	 * Save the assembled export (headers row + data) to a path and remove
	 * the temp files. Used by the WP-CLI commands; the admin flow streams
	 * via export() instead.
	 *
	 * Streams the data file instead of concatenating in memory (a large
	 * export would otherwise peak at ~2x file size), and keeps the temp
	 * files when the write fails so a failed --output path doesn't destroy
	 * the completed multi-batch export.
	 *
	 * @param string $path Destination file path.
	 * @return bool Whether the file was written.
	 */
	public function save_to( string $path ): bool {
		$destination_dir = dirname( $path );
		if ( ! is_dir( $destination_dir ) || ! is_writable( $destination_dir ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable
			return false;
		}
		// The destination is admin-chosen (WP-CLI --output); direct file ops are intended here.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
		$destination = fopen( $path, 'w' );
		if ( ! $destination ) {
			return false;
		}
		// The headers row is small (get_headers_row_file() also regenerates it
		// when the temp file is missing); the data file is streamed.
		$saved = false !== fwrite( $destination, $this->get_headers_row_file() );
		$data  = fopen( $this->get_file_path(), 'r' );
		if ( $data ) {
			$saved = $saved && false !== stream_copy_to_stream( $data, $destination );
			fclose( $data );
		}
		fclose( $destination );

		if ( $saved ) {
			foreach ( [ $this->get_file_path(), $this->get_headers_row_file_path() ] as $temp_file ) {
				if ( file_exists( $temp_file ) ) {
					unlink( $temp_file );
				}
			}
		}
		// phpcs:enable
		return $saved;
	}
}
