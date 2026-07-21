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
	 * Set the run's total row count, pinned to what the first page counted.
	 *
	 * Paging is by offset over a live query, so the total is recounted on
	 * every page. That is fine while the result set only grows (new rows are
	 * ID-ascending and land past the moving window), but a set that *shrinks*
	 * mid-run — a hard delete, or more commonly a status change dropping a row
	 * out of a status-filtered view when a renewal/expiration action fires —
	 * shrinks the total too, and the run's cumulative exported count can pass
	 * it. The export would then report 100% and hand the publisher a truncated
	 * CSV presented as complete.
	 *
	 * Pinning the total to page 1 keeps the run paging until a page comes back
	 * empty (see CSV_Exports::ajax_export() and the CLI's no-progress guard),
	 * which is where both surfaces flag the result as a possibly-incomplete
	 * snapshot. Rows that slid back into an already-consumed offset are still
	 * missed — closing that would mean snapshotting the entire ID set and
	 * replaying it as post__in on every page, which does not scale on the
	 * large sites where the race is likeliest.
	 *
	 * The pin is a single int in a transient keyed by the export filename,
	 * which is unique per run, so a later run always recounts.
	 *
	 * @param int $live_total Total rows the current page's query reported.
	 */
	protected function pin_total_rows( int $live_total ): void {
		$transient = 'newspack_export_total_' . md5( $this->get_filename() );
		if ( 1 === $this->get_page() ) {
			\set_transient( $transient, $live_total, HOUR_IN_SECONDS );
			$this->total_rows = $live_total;
			return;
		}
		$pinned           = \get_transient( $transient );
		$this->total_rows = false === $pinned ? $live_total : (int) $pinned;
	}

	/**
	 * Whether the page just generated wrote no rows at all, meaning the offset
	 * walked past the end of a result set that shrank mid-run.
	 *
	 * The parent's get_total_exported() assumes every prior page was full, so
	 * comparing it against the pages already consumed isolates exactly "this
	 * page contributed nothing".
	 *
	 * @return bool
	 */
	public function page_was_empty(): bool {
		return $this->get_total_exported() <= ( $this->get_page() - 1 ) * $this->get_limit();
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
	 * Stream the assembled export (headers row + data) to the browser, delete
	 * the temp files, and exit. Used by the admin download in place of the
	 * parent's export().
	 *
	 * The parent WC_CSV_Batch_Exporter::export() sends get_headers_row_file()
	 * . get_file(), and get_file() reads the entire data file into a single
	 * PHP string — so peak memory is roughly the full file size and a large
	 * PII export (100k+ rows) can exceed memory_limit and fail the download
	 * after the multi-step build already succeeded. Streaming the data file
	 * keeps memory flat regardless of export size (the same reason save_to()
	 * streams for the CLI path).
	 *
	 * @return void
	 */
	public function stream_export(): void {
		$this->send_headers();

		// Discard any active output buffering so fpassthru() streams straight
		// to the client instead of buffering the whole file back into memory.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// The headers row is small; get_headers_row_file() also regenerates it
		// when the temp file is missing.
		echo $this->get_headers_row_file(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru
		$data_file = $this->get_file_path();
		$data      = file_exists( $data_file ) ? fopen( $data_file, 'r' ) : false;
		if ( $data ) {
			fpassthru( $data );
			fclose( $data );
		}
		foreach ( [ $this->get_file_path(), $this->get_headers_row_file_path() ] as $temp_file ) {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
		}
		// phpcs:enable
		die();
	}

	/**
	 * Save the assembled export (headers row + data) to a path and remove
	 * the temp files. Used by the WP-CLI commands; the admin flow streams
	 * via stream_export() instead.
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
		$saved     = false !== fwrite( $destination, $this->get_headers_row_file() );
		$data_file = $this->get_file_path();
		$data      = file_exists( $data_file ) ? fopen( $data_file, 'r' ) : false;
		if ( $data ) {
			$saved = $saved && false !== stream_copy_to_stream( $data, $destination );
			fclose( $data );
		} else {
			// The WC exporter always creates the data temp file (even for a
			// zero-row export), so a missing/unopenable file means the export
			// data is gone (permissions, cleanup race) — fail rather than
			// silently deliver a headers-only CSV.
			$saved = false;
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
