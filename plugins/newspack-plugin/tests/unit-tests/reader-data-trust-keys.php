<?php
/**
 * Tests for the reader data keys the server manages and reader-data rules trust.
 *
 * @package Newspack\Tests
 * @group reader-data-trust
 */

use Newspack\Donations;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Data;
use Newspack\Reader_Data_Trust;

/**
 * Tests for Reader_Data_Trust key sets.
 *
 * @group reader-data-trust
 */
class Newspack_Test_Reader_Data_Trust_Keys extends WP_UnitTestCase {

	const INCOMING_OPTION = 'newspack_integration_incoming_fields_esp';
	const OUTGOING_OPTION = 'newspack_integration_outgoing_fields_esp';

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		Integrations::enable( 'esp' );
		Reader_Data_Trust::reset_cache();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( self::INCOMING_OPTION );
		delete_option( self::OUTGOING_OPTION );
		remove_all_filters( 'newspack_has_server_side_donor_tracking' );
		Donations::set_platform_slug( 'wc' );
		Reader_Data_Trust::reset_cache();
		parent::tear_down();
	}

	/**
	 * Store enabled incoming fields for the ESP integration.
	 *
	 * @param array $fields Map of key => raw field data.
	 */
	private function seed_incoming_fields( $fields ) {
		update_option( self::INCOMING_OPTION, $fields, false );
		Reader_Data_Trust::reset_cache();
	}

	/**
	 * The newsletter subscriber flag is server-managed.
	 */
	public function test_newsletter_subscriber_is_read_only() {
		$this->assertTrue( in_array( 'is_newsletter_subscriber', Reader_Data::get_read_only_keys(), true ) );
	}

	/**
	 * An enabled incoming field is read-only and trusted.
	 */
	public function test_incoming_field_key_is_read_only_and_trusted() {
		$this->seed_incoming_fields( [ 'plan' => [ 'key' => 'plan' ] ] );

		$this->assertContains( 'plan', Reader_Data_Trust::get_incoming_field_keys() );
		$this->assertContains( 'plan', Reader_Data::get_read_only_keys() );
		$this->assertContains( 'plan', Reader_Data_Trust::get_trusted_keys() );
	}

	/**
	 * Incoming fields of an inactive integration are not server-managed.
	 */
	public function test_inactive_integration_fields_are_not_read_only() {
		$this->seed_incoming_fields( [ 'plan' => [ 'key' => 'plan' ] ] );
		Integrations::disable( 'esp' );
		Reader_Data_Trust::reset_cache();

		$this->assertNotContains( 'plan', Reader_Data::get_read_only_keys() );
	}

	/**
	 * An incoming field named like a key the browser writes stays writable.
	 */
	public function test_colliding_incoming_key_stays_writable_and_untrusted() {
		$this->seed_incoming_fields( [ 'referrer' => [ 'key' => 'referrer' ] ] );

		$this->assertNotContains( 'referrer', Reader_Data::get_read_only_keys() );
		$this->assertNotContains( 'referrer', Reader_Data_Trust::get_trusted_keys() );
	}

	/**
	 * Saving incoming fields refreshes the cached key set.
	 */
	public function test_saving_incoming_fields_clears_cache() {
		$this->assertNotContains( 'plan', Reader_Data::get_read_only_keys() );

		Integrations::get_integration( 'esp' )->update_enabled_incoming_fields( [ 'plan' ] );

		$this->assertContains( 'plan', Reader_Data::get_read_only_keys() );
	}

	/**
	 * The donor flag is trusted only where the server tracks donors.
	 */
	public function test_is_donor_trusted_only_with_server_tracking() {
		add_filter( 'newspack_has_server_side_donor_tracking', '__return_false' );
		$this->assertNotContains( 'is_donor', Reader_Data_Trust::get_trusted_keys() );

		remove_filter( 'newspack_has_server_side_donor_tracking', '__return_false' );
		add_filter( 'newspack_has_server_side_donor_tracking', '__return_true' );
		$this->assertContains( 'is_donor', Reader_Data_Trust::get_trusted_keys() );
	}

	/**
	 * A key another feature makes read-only through the filter is trusted.
	 */
	public function test_filter_added_read_only_key_is_trusted() {
		$this->assertContains( 'accessible_gates', Reader_Data_Trust::get_trusted_keys() );
	}

	/**
	 * An incoming field that is the ESP copy of a browser-fed outgoing field is
	 * read-only but not trusted, whichever way the provider identifies it.
	 *
	 * @dataProvider round_trip_field_provider
	 *
	 * @param string $key      Incoming field key.
	 * @param array  $raw_data Incoming field raw data.
	 */
	public function test_round_trip_key_is_read_only_but_untrusted( $key, $raw_data ) {
		update_option( self::OUTGOING_OPTION, [ 'First Visit Date' ], false );
		$this->seed_incoming_fields( [ $key => $raw_data ] );

		$this->assertContains( $key, Reader_Data_Trust::get_round_trip_keys() );
		$this->assertContains( $key, Reader_Data::get_read_only_keys() );
		$this->assertNotContains( $key, Reader_Data_Trust::get_trusted_keys() );
	}

	/**
	 * Provider-side identifiers of the outgoing "First Visit Date" field.
	 *
	 * @return array[]
	 */
	public function round_trip_field_provider() {
		return [
			'matched by provider field name' => [
				'MMERGE7',
				[
					'key'  => 'MMERGE7',
					'name' => 'NP_First Visit Date',
				],
			],
			'matched by generated tag'       => [
				'NP_FIRST_VISIT_DATE',
				[
					'key'  => 'NP_FIRST_VISIT_DATE',
					'name' => 'Renamed in the ESP',
				],
			],
			'matched by key'                 => [ 'NP_First Visit Date', [] ],
		];
	}

	/**
	 * The ESP copy of a browser-fed field is trusted while that field is not sent.
	 */
	public function test_round_trip_requires_enabled_outgoing_field() {
		update_option( self::OUTGOING_OPTION, [ 'Last Payment Page' ], false );
		$this->seed_incoming_fields(
			[
				'MMERGE7' => [
					'key'  => 'MMERGE7',
					'name' => 'NP_First Visit Date',
				],
			]
		);

		$this->assertSame( [], Reader_Data_Trust::get_round_trip_keys() );
		$this->assertContains( 'MMERGE7', Reader_Data_Trust::get_trusted_keys() );
	}

	/**
	 * Every key the browser writes through the reader data store is listed.
	 */
	public function test_browser_keys_cover_every_store_set_literal() {
		$plugin_root = dirname( __DIR__, 2 );
		$roots       = [ $plugin_root . '/src' ];
		if ( is_dir( dirname( $plugin_root ) . '/newspack-popups/src' ) ) {
			$roots[] = dirname( $plugin_root ) . '/newspack-popups/src';
		}

		$found = [];
		foreach ( $roots as $root ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$name = $file->getFilename();
				if ( ! preg_match( '/\.(js|jsx|ts|tsx)$/', $name ) || preg_match( '/\.test\./', $name ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local source files.
				preg_match_all( '/store\.(?:set|add)\(\s*[\'"]([^\'"]+)[\'"]/', file_get_contents( $file->getPathname() ), $matches );
				$found = array_merge( $found, $matches[1] );
			}
		}

		$this->assertContains( 'referrer', $found, 'The scan should find browser writes.' );
		$excluded = array_merge( Reader_Data::get_read_only_keys(), [ 'newsletter_signup_client' ] );
		$missing  = array_diff( array_unique( $found ), $excluded, Reader_Data_Trust::BROWSER_KEYS );
		$this->assertSame( [], array_values( $missing ), 'Browser-written keys missing from Reader_Data_Trust::BROWSER_KEYS.' );
	}

	/**
	 * Saving an incoming field that collides with a browser key logs it once.
	 */
	public function test_collision_is_logged_on_save() {
		$logged   = [];
		$listener = function ( $code, $message, $params ) use ( &$logged ) {
			if ( 'newspack_reader_data_incoming_key_collision' === $code ) {
				$logged[] = $params['data']['keys'];
			}
		};
		add_action( 'newspack_log', $listener, 10, 3 );
		Integrations::get_integration( 'esp' )->update_enabled_incoming_fields( [ 'referrer', 'plan' ] );
		remove_action( 'newspack_log', $listener, 10 );

		$this->assertSame( [ [ 'referrer' ] ], $logged );
	}
}
