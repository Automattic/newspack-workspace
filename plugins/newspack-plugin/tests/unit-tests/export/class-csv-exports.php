<?php
/**
 * Tests the CSV_Exports controller helpers and the shared exporter base.
 *
 * @package Newspack\Tests
 */

use Newspack\CSV_Exports;
use Newspack\CSV_Batch_Exporter;
use Newspack\Users_CSV_Exporter;
use Newspack\User_Meta_Columns;

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
require_once dirname( __DIR__, 3 ) . '/includes/export/class-users-csv-exporter.php';

/**
 * Test the export-filename contract (the security property binding a
 * filename to its capability-checked type), the streamed save_to(), the
 * stale-file cleanup sweep, and the export-options boundary the dialog and
 * the CLI both post through.
 *
 * @group csv-export
 */
class Newspack_Test_CSV_Exports extends WP_UnitTestCase {

	/**
	 * The meta-key list is cached in a transient that outlives the per-test
	 * transaction rollback.
	 */
	public function set_up() {
		parent::set_up();
		User_Meta_Columns::flush_available_keys();
	}

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

	/**
	 * The export dialog is client-supplied input, so every option it sends is
	 * validated against a known set and dropped when it does not match. This
	 * is the boundary that keeps an arbitrary role, status, delimiter or date
	 * out of the export query and out of gmdate().
	 */
	public function test_sanitize_export_config_rejects_unknown_values() {
		$config = CSV_Exports::sanitize_export_config(
			[
				'date_from' => '2026-02-30',
				'date_to'   => 'yesterday',
				'delimiter' => 'tilde',
				'roles'     => [ 'subscriber', 'not_a_role' ],
				'statuses'  => [ 'active', 'not_a_status' ],
				'meta_keys' => [ 'never_written' ],
			],
			'users'
		);

		$this->assertArrayNotHasKey( 'date_from', $config, 'February 30th is not a date.' );
		$this->assertArrayNotHasKey( 'date_to', $config );
		$this->assertArrayNotHasKey( 'delimiter', $config, 'An unoffered delimiter falls back to the exporter default.' );
		$this->assertSame( [ 'subscriber' ], $config['roles'] );
		$this->assertSame( CSV_Exports::DEFAULT_DATE_FORMAT, $config['date_format'] );
		$this->assertArrayNotHasKey( 'statuses', $config, 'Statuses belong to the subscriptions export only.' );
		$this->assertSame( [], $config['meta_keys'], 'A meta key the site does not store is not exportable.' );

		$subscriptions = CSV_Exports::sanitize_export_config( [ 'statuses' => [ 'active', 'not_a_status' ] ], 'subscriptions' );
		$this->assertSame( [ 'wc-active' ], $subscriptions['statuses'] );
		$this->assertArrayNotHasKey( 'roles', $subscriptions );
	}

	/**
	 * A range entered back to front is read as the range the admin meant,
	 * rather than exporting nothing.
	 */
	public function test_sanitize_export_config_swaps_a_reversed_date_range() {
		$config = CSV_Exports::sanitize_export_config(
			[
				'date_from' => '2026-06-30',
				'date_to'   => '2026-01-01',
			],
			'users'
		);

		$this->assertSame( '2026-01-01', $config['date_from'] );
		$this->assertSame( '2026-06-30', $config['date_to'] );
	}

	/**
	 * The custom date format is free text, so it is length-capped; an empty
	 * one falls back to the default rather than producing blank dates.
	 */
	public function test_sanitize_export_config_custom_date_format() {
		$custom = CSV_Exports::sanitize_export_config(
			[
				'date_format'        => 'custom',
				'date_format_custom' => str_repeat( 'Y', 100 ),
			],
			'users'
		);
		$this->assertSame( str_repeat( 'Y', CSV_Exports::MAX_CUSTOM_DATE_FORMAT_LENGTH ), $custom['date_format'] );

		$empty = CSV_Exports::sanitize_export_config(
			[
				'date_format'        => 'custom',
				'date_format_custom' => '',
			],
			'users'
		);
		$this->assertSame( CSV_Exports::DEFAULT_DATE_FORMAT, $empty['date_format'] );
	}

	/**
	 * Date columns follow the chosen format, and a value that cannot be parsed
	 * is passed through untouched rather than becoming an epoch date.
	 */
	public function test_format_export_date() {
		$exporter = new Users_CSV_Exporter();

		$this->assertSame( '2026-05-14 09:00:00', $exporter->format_export_date( '2026-05-14 09:00:00' ), 'Without a config the default format is a passthrough.' );

		$exporter->set_export_config( [ 'date_format' => 'd/m/Y' ] );
		$this->assertSame( '14/05/2026', $exporter->format_export_date( '2026-05-14 09:00:00' ) );
		$this->assertSame( '', $exporter->format_export_date( '' ) );
		$this->assertSame( 'not a date', $exporter->format_export_date( 'not a date' ) );
	}

	/**
	 * The WooCommerce exporter constructor pins the column set, but the export
	 * options that decide which optional columns exist only arrive afterwards.
	 * Setting the config therefore has to recompute the columns, or the header
	 * row and the prepared rows disagree about the file's shape.
	 */
	public function test_setting_the_config_recomputes_the_column_set() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'reader_zip_code', '07079' );

		$exporter = new Users_CSV_Exporter();
		$this->assertArrayNotHasKey( 'meta_reader_zip_code', $exporter->get_column_names() );

		$exporter->set_export_config( [ 'meta_keys' => [ 'reader_zip_code' ] ] );
		$this->assertArrayHasKey( 'meta_reader_zip_code', $exporter->get_column_names() );
	}

	/**
	 * The delimiter chosen in the dialog reaches the file writer.
	 */
	public function test_export_config_sets_the_delimiter() {
		$exporter = new Users_CSV_Exporter();
		$this->assertSame( ',', $exporter->get_delimiter() );

		$exporter->set_export_config( CSV_Exports::sanitize_export_config( [ 'delimiter' => 'semicolon' ], 'users' ) );
		$this->assertSame( ';', $exporter->get_delimiter() );
	}
}
