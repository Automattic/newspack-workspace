<?php
/**
 * Tests the CSV_Exports controller helpers and the shared exporter base.
 *
 * @package Newspack\Tests
 */

use Newspack\CSV_Exports;
use Newspack\CSV_Batch_Exporter;
use Newspack\Users_CSV_Exporter;

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
require_once dirname( __DIR__, 3 ) . '/includes/export/class-users-csv-exporter.php';

/**
 * Test the export-filename contract (the security property binding a
 * filename to its capability-checked type), the streamed save_to(), and the
 * stale-file cleanup sweep.
 *
 * @group csv-export
 */
class Newspack_Test_CSV_Exports extends WP_UnitTestCase {

	/**
	 * Generated filenames carry the type prefix (binding them to their
	 * export type) and an unguessable random suffix.
	 */
	public function test_generate_export_filename() {
		$first  = CSV_Exports::generate_export_filename( 'subscriptions' );
		$second = CSV_Exports::generate_export_filename( 'subscriptions' );

		$this->assertStringStartsWith( 'newspack-subscriptions-export-', $first );
		$this->assertStringEndsWith( '.csv', $first );
		$this->assertNotSame( $first, $second, 'The random suffix must differ between runs.' );
		$this->assertTrue( CSV_Exports::validate_export_filename( $first, 'subscriptions' ) );
	}

	/**
	 * A filename generated for one export type must not validate for
	 * another: capability checks are per-type, so accepting a cross-type
	 * filename would let a subscriptions-capable user download a users
	 * export through the subscriptions code path.
	 */
	public function test_validate_export_filename_binds_type() {
		$users_filename = CSV_Exports::generate_export_filename( 'users' );
		$this->assertTrue( CSV_Exports::validate_export_filename( $users_filename, 'users' ) );
		$this->assertFalse( CSV_Exports::validate_export_filename( $users_filename, 'subscriptions' ) );
		$this->assertFalse( CSV_Exports::validate_export_filename( 'evil.csv', 'users' ) );
	}

	/**
	 * Streaming save_to() writes headers + data to the destination and removes the
	 * temp files only on success; a failed destination keeps them so the
	 * completed multi-batch export isn't destroyed.
	 */
	public function test_save_to_streams_and_preserves_temp_files_on_failure() {
		$exporter = new Users_CSV_Exporter();
		$exporter->set_filename( CSV_Exports::generate_export_filename( 'users' ) );
		$temp_file = $exporter->get_export_file_path();
		file_put_contents( $temp_file, "row1,data\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		// Failed write (nonexistent directory): temp file survives.
		$this->assertFalse( $exporter->save_to( '/nonexistent-dir-' . wp_rand() . '/out.csv' ) );
		$this->assertFileExists( $temp_file, 'A failed write must not destroy the assembled export.' );

		// Successful write: headers + data land in the destination, temp file removed.
		$destination = trailingslashit( sys_get_temp_dir() ) . 'csv-exports-test-' . wp_rand() . '.csv';
		$this->assertTrue( $exporter->save_to( $destination ) );
		$saved_csv = file_get_contents( $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		$this->assertStringContainsString( 'User ID', $saved_csv, 'The headers row must be written first.' );
		$this->assertStringContainsString( 'row1,data', $saved_csv );
		$this->assertFileDoesNotExist( $temp_file );
		unlink( $destination ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * A missing data temp file means the export data is gone; save_to() must
	 * fail rather than deliver a headers-only CSV reported as success.
	 */
	public function test_save_to_fails_without_data_file() {
		$exporter = new Users_CSV_Exporter();
		$exporter->set_filename( CSV_Exports::generate_export_filename( 'users' ) );
		$destination = trailingslashit( sys_get_temp_dir() ) . 'csv-exports-test-' . wp_rand() . '.csv';

		$this->assertFalse( $exporter->save_to( $destination ) );

		if ( file_exists( $destination ) ) {
			unlink( $destination ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
	}

	/**
	 * The cleanup sweep removes only export files older than a day.
	 */
	public function test_cleanup_stale_files() {
		$dir           = trailingslashit( CSV_Batch_Exporter::get_exports_dir() );
		$stale         = $dir . 'stale-test.csv';
		$stale_headers = $dir . 'stale-test.csv.headers';
		$fresh         = $dir . 'fresh-test.csv';
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_touch, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		file_put_contents( $stale, 'x' );
		touch( $stale, time() - 2 * DAY_IN_SECONDS );
		file_put_contents( $stale_headers, 'x' );
		touch( $stale_headers, time() - 2 * DAY_IN_SECONDS );
		file_put_contents( $fresh, 'x' );

		CSV_Exports::cleanup_stale_files();

		$this->assertFileDoesNotExist( $stale );
		$this->assertFileDoesNotExist( $stale_headers, 'The sweep must also cover the .csv.headers companion files.' );
		$this->assertFileExists( $fresh );
		unlink( $fresh );
		// phpcs:enable
	}
}
