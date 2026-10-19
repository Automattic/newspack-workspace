<?php
/**
 * Tests the export CLI's flag validation.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Export;
use Newspack\CSV_Exports;
use Newspack\User_Meta_Columns;

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
require_once dirname( __DIR__, 3 ) . '/includes/cli/class-export.php';

/**
 * The dialog can drop an unrecognized value silently, because its inputs come
 * from a list the server rendered. A hand-typed flag cannot: `--role=subscrber`
 * dropped is `--role` never applied, which writes every user to a CSV instead
 * of none. get_rejected_flag_values() is what catches that, by re-deriving how
 * each supplied value normalizes and looking for it in the sanitized config.
 *
 * @group csv-export
 */
class Newspack_Test_Export_CLI extends WP_UnitTestCase {

	/**
	 * The meta-key list is cached in a transient that outlives the per-test
	 * transaction rollback.
	 */
	public function set_up() {
		parent::set_up();
		User_Meta_Columns::flush_available_keys();
	}

	/**
	 * Run the flags through the same sanitizer the command uses, and report
	 * what did not come back.
	 *
	 * @param array  $raw  Raw config, as build_export_config() assembles it.
	 * @param string $type Export type.
	 * @return array<string,string[]>
	 */
	private function rejected_values( array $raw, string $type = 'users' ): array {
		return Export::get_rejected_flag_values( $raw, CSV_Exports::sanitize_export_config( $raw, $type ) );
	}

	/**
	 * Values are compared after normalization, so a status the operator wrote
	 * the way the admin list shows it is accepted while a typo is not.
	 */
	public function test_values_are_compared_after_normalization() {
		$this->assertSame(
			[],
			$this->rejected_values( [ 'statuses' => [ 'active', 'wc-expired' ] ], 'subscriptions' )
		);
		$this->assertSame(
			[ 'status' => [ 'activ' ] ],
			$this->rejected_values( [ 'statuses' => [ 'active', 'activ' ] ], 'subscriptions' )
		);
		$this->assertSame(
			[ 'role' => [ 'subscrber' ] ],
			$this->rejected_values( [ 'roles' => [ 'subscriber', 'subscrber' ] ] )
		);
	}

	/**
	 * Compared value by value rather than by count. Status sanitization
	 * collapses a repeat, so a count comparison would read
	 * `--status=active,active` as a value lost and refuse to run.
	 */
	public function test_a_repeated_value_is_accepted_and_still_does_not_mask_a_typo() {
		$this->assertSame( [], $this->rejected_values( [ 'statuses' => [ 'active', 'active' ] ], 'subscriptions' ) );
		$this->assertSame(
			[ 'status' => [ 'activ' ] ],
			$this->rejected_values( [ 'statuses' => [ 'active', 'active', 'activ' ] ], 'subscriptions' )
		);
	}

	/**
	 * A meta key is rejected both when the site has never written it and when
	 * the site stores it but does not offer it as a column.
	 */
	public function test_meta_keys_outside_the_offered_set_are_rejected() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'reader_zip_code', '07079' );
		update_user_meta( $user_id, 'session_tokens', [ 'abc' => [ 'expiration' => 0 ] ] );

		$this->assertSame(
			[ 'meta' => [ 'never_written', 'session_tokens' ] ],
			$this->rejected_values( [ 'meta_keys' => [ 'reader_zip_code', 'never_written', 'session_tokens' ] ] )
		);
	}

	/**
	 * The remaining options each fail in their own way: a date that is not a
	 * calendar date, a delimiter that is not one of the four, and a custom date
	 * format too long to store — which would not fail loudly, it would format
	 * every date cell wrongly.
	 */
	public function test_dates_delimiter_and_custom_format_are_each_reported() {
		$this->assertSame(
			[
				'date-from'   => [ '2026-02-30' ],
				'delimiter'   => [ 'colon' ],
				'date-format' => [ str_repeat( 'Y', CSV_Exports::MAX_CUSTOM_DATE_FORMAT_LENGTH + 1 ) ],
			],
			$this->rejected_values(
				[
					'date_from'          => '2026-02-30',
					'date_to'            => '2026-03-01',
					'delimiter'          => 'colon',
					'date_format'        => 'custom',
					'date_format_custom' => str_repeat( 'Y', CSV_Exports::MAX_CUSTOM_DATE_FORMAT_LENGTH + 1 ),
				]
			)
		);
	}
}
