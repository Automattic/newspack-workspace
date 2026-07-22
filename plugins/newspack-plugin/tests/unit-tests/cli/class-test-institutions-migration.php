<?php
/**
 * Tests for the teams-based institutional access → AC Institutions migration CLI (NPPD-2054).
 *
 * Covers the data-layer helpers behind `wp newspack migrate-institutions`: the
 * domains-CSV parser, IP-range normalization/validation, and the per-team
 * migration (rule mapping, subscription-link recording, unmappable reporting,
 * dry-run safety, and idempotency). The WP_CLI output machinery is exercised
 * end-to-end on a real site by the CLI, not here.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Institutions_Migration;
use Newspack\Institution;

require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';

/**
 * Test the migrate-institutions data-layer helpers and command-level reporting.
 */
class Test_Institutions_Migration extends WP_UnitTestCase {

	/**
	 * Team post IDs to clean up.
	 *
	 * @var int[]
	 */
	private $team_ids = [];

	/**
	 * Temp CSV file paths to clean up.
	 *
	 * @var string[]
	 */
	private $csv_paths = [];

	/**
	 * Include the WC mocks (for the linked-subscription test).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset the mock subscription store between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		WP_CLI::reset();
	}

	/**
	 * Clean up fixtures: teams, created institutions, CSVs, the option, the cache.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		foreach ( $this->team_ids as $team_id ) {
			wp_delete_post( $team_id, true );
		}
		$this->team_ids = [];
		foreach ( $this->get_all_institutions() as $institution_post ) {
			wp_delete_post( $institution_post->ID, true );
		}
		foreach ( $this->csv_paths as $csv_path ) {
			if ( file_exists( $csv_path ) ) {
				unlink( $csv_path ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
		}
		$this->csv_paths = [];
		delete_option( Institutions_Migration::ACCESS_BY_IP_OPTION );
		delete_transient( Institution::TRANSIENT_KEY );
		parent::tear_down();
	}

	/**
	 * Get all institution posts, every status included (trash too, so the
	 * trashed-institution idempotency test can count accurately).
	 *
	 * @return WP_Post[]
	 */
	private function get_all_institutions() {
		return get_posts(
			[
				'post_type'      => Institution::POST_TYPE,
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => -1,
			]
		);
	}

	/**
	 * Create a wc_memberships_team post, optionally linked to a subscription.
	 *
	 * @param string   $team_name Team title.
	 * @param int|null $sub_id    Optional linked subscription ID (`_subscription_id` meta).
	 * @return int Team post ID.
	 */
	private function create_team( string $team_name, ?int $sub_id = null ): int {
		$team_id = wp_insert_post(
			[
				'post_type'   => 'wc_memberships_team',
				'post_status' => 'publish',
				'post_title'  => $team_name,
			]
		);
		$this->assertNotWPError( $team_id, 'Fixture team creation should succeed.' );
		$this->team_ids[] = $team_id;
		if ( $sub_id ) {
			update_post_meta( $team_id, '_subscription_id', $sub_id );
		}
		return $team_id;
	}

	/**
	 * Write a temp CSV file with the given content and return its path.
	 *
	 * @param string $content CSV content.
	 * @return string File path.
	 */
	private function write_csv( string $content ): string {
		$csv_path = wp_tempnam( 'institutions-domains' );
		file_put_contents( $csv_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		$this->csv_paths[] = $csv_path;
		return $csv_path;
	}

	/**
	 * The domains CSV parser maps team IDs to normalized domains: multiple domain
	 * columns per row, rows for the same team accumulate, a header row is skipped,
	 * `@` prefixes are stripped, and domains are lowercased.
	 */
	public function test_parse_domains_csv_maps_team_ids_to_domains() {
		$csv_path = $this->write_csv(
			"team_id,domains\n" .
			"12,University.EDU,uni.ac.uk\n" .
			"12,@library.org\n" .
			"34,example.com\n"
		);

		$parse_result = Institutions_Migration::parse_domains_csv( $csv_path );

		$this->assertNotWPError( $parse_result, 'A readable CSV should parse.' );
		$this->assertSame(
			[
				12 => [ 'university.edu', 'uni.ac.uk', 'library.org' ],
				34 => [ 'example.com' ],
			],
			$parse_result['map'],
			'Domains should be lowercased, @-stripped, and accumulated per team.'
		);
		$this->assertSame( [], $parse_result['errors'], 'A clean CSV should produce no errors.' );
	}

	/**
	 * Malformed CSV rows (non-numeric team ID past the header, invalid domain) are
	 * reported in the errors list, never silently dropped.
	 */
	public function test_parse_domains_csv_reports_malformed_rows() {
		$csv_path = $this->write_csv(
			"12,uni.edu\n" .
			"not-a-team-id,foo.com\n" .
			"34,not_a_domain\n"
		);

		$parse_result = Institutions_Migration::parse_domains_csv( $csv_path );

		$this->assertNotWPError( $parse_result, 'The CSV should still parse.' );
		$this->assertSame( [ 12 => [ 'uni.edu' ] ], $parse_result['map'], 'Only the valid row should be mapped.' );
		$this->assertCount( 2, $parse_result['errors'], 'Both malformed rows should be reported.' );
	}

	/**
	 * A missing CSV file returns a WP_Error rather than an empty map — an operator
	 * typo in the path must not silently migrate zero domains.
	 */
	public function test_parse_domains_csv_missing_file_is_an_error() {
		$parse_result = Institutions_Migration::parse_domains_csv( '/nonexistent/domains.csv' );
		$this->assertWPError( $parse_result, 'A missing file should be a hard error.' );
	}

	/**
	 * IP-range normalization accepts both option-value shapes (string and array),
	 * splits on commas/newlines, and separates valid IPv4/CIDR entries from invalid
	 * ones (bad CIDR bits, hostnames, IPv6 — unsupported by IP_Access_Rule) so the
	 * command can report rather than silently drop them.
	 */
	public function test_normalize_ip_ranges_splits_valid_and_invalid() {
		$string_result = Institutions_Migration::normalize_ip_ranges( "192.168.1.0/24, 10.0.0.5\n172.16.0.0/12" );
		$this->assertSame( [ '192.168.1.0/24', '10.0.0.5', '172.16.0.0/12' ], $string_result['valid'] );
		$this->assertSame( [], $string_result['invalid'] );

		$array_result = Institutions_Migration::normalize_ip_ranges( [ '128.100.0.0/16', 'not-an-ip', '10.0.0.0/33', '2001:db8::/32' ] );
		$this->assertSame( [ '128.100.0.0/16' ], $array_result['valid'] );
		$this->assertSame( [ 'not-an-ip', '10.0.0.0/33', '2001:db8::/32' ], $array_result['invalid'], 'Invalid entries (including IPv6) should be returned for reporting.' );
	}

	/**
	 * A live migration creates one institution per team carrying the team's name,
	 * the valid IP ranges, the CSV domains, and the migrated-from-team marker meta.
	 */
	public function test_migrate_team_creates_institution_with_rules() {
		$team_id = $this->create_team( 'Springfield University' );

		$migration_result = Institutions_Migration::migrate_team(
			$team_id,
			'128.100.0.0/16, 128.101.2.3',
			[ 'springfield.edu' ],
			true
		);

		$this->assertSame( 'created', $migration_result['status'], 'The team should be migrated.' );
		$institution_id = $migration_result['institution_id'];
		$this->assertGreaterThan( 0, $institution_id, 'A real institution post should exist.' );
		$this->assertSame( Institution::POST_TYPE, get_post_type( $institution_id ) );
		$this->assertSame( 'Springfield University', get_post( $institution_id )->post_title, 'The institution should carry the team name.' );
		$this->assertSame( '128.100.0.0/16,128.101.2.3', get_post_meta( $institution_id, Institution::META_PREFIX . 'ip_range', true ), 'The IP ranges should land in the institution ip_range rule.' );
		$this->assertSame( 'springfield.edu', get_post_meta( $institution_id, Institution::META_PREFIX . 'email_domain', true ), 'The CSV domains should land in the email_domain rule.' );
		$this->assertSame( (string) $team_id, get_post_meta( $institution_id, Institutions_Migration::MIGRATED_FROM_TEAM_META_KEY, true ), 'The source team should be recorded for idempotency.' );
	}

	/**
	 * A team linked to a subscription via `_subscription_id` has that link recorded
	 * on the institution (informational meta — the Institution entity has no
	 * functional subscription field) and reported in the result.
	 */
	public function test_migrate_team_records_linked_subscription() {
		$linked_subscription = wcs_create_subscription(
			[
				'customer_id'    => 1,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$team_id             = $this->create_team( 'Linked University', $linked_subscription->get_id() );

		$migration_result = Institutions_Migration::migrate_team( $team_id, '10.1.0.0/16', [], true );

		$this->assertSame( 'created', $migration_result['status'] );
		$this->assertSame( $linked_subscription->get_id(), $migration_result['subscription_id'], 'The linked subscription should be reported.' );
		$this->assertSame(
			(string) $linked_subscription->get_id(),
			get_post_meta( $migration_result['institution_id'], Institutions_Migration::MIGRATED_SUBSCRIPTION_META_KEY, true ),
			'The subscription link should be recorded on the institution.'
		);
	}

	/**
	 * A team with no valid IP ranges and no domain mapping is unmappable: it is
	 * reported with a reason and no institution is created.
	 */
	public function test_migrate_team_unmappable_is_reported_not_skipped() {
		$team_id = $this->create_team( 'No Rules Club' );

		$migration_result = Institutions_Migration::migrate_team( $team_id, 'not-an-ip-range', [], true );

		$this->assertSame( 'unmappable', $migration_result['status'], 'A team with no usable rules should be unmappable.' );
		$this->assertNotEmpty( $migration_result['reason'], 'The unmappable reason should be populated for reporting.' );
		$this->assertSame( [ 'not-an-ip-range' ], $migration_result['invalid_ranges'], 'The rejected ranges should be surfaced.' );
		$this->assertCount( 0, $this->get_all_institutions(), 'No institution should be created for an unmappable team.' );

		// When no --domains-csv was supplied at all, the reason must not claim the
		// CSV lacked the team — there was no CSV to lack it.
		$no_csv_result = Institutions_Migration::migrate_team( $team_id, '', [], true, false );
		$this->assertStringContainsString( 'no domains CSV supplied', $no_csv_result['reason'], 'The reason wording should reflect that no CSV was passed.' );
	}

	/**
	 * A dry-run reports what would be created but writes nothing.
	 */
	public function test_migrate_team_dry_run_creates_nothing() {
		$team_id = $this->create_team( 'Dry Run University' );

		$dry_run_result = Institutions_Migration::migrate_team( $team_id, '192.0.2.0/24', [ 'dryrun.edu' ], false );

		$this->assertSame( 'created', $dry_run_result['status'], 'The dry-run should report the would-be creation.' );
		$this->assertSame( 0, $dry_run_result['institution_id'], 'No institution ID should exist in a dry-run.' );
		$this->assertSame( [ '192.0.2.0/24' ], $dry_run_result['ip_ranges'], 'The would-be ranges should be reported (for the proxy-egress check).' );
		$this->assertCount( 0, $this->get_all_institutions(), 'A dry-run must not create any institution.' );
	}

	/**
	 * Re-running the migration for an already-migrated team reports the existing
	 * institution instead of creating a duplicate — and does NOT overwrite its
	 * rules, so operator fixes (e.g. swapping internal ranges for proxy egress
	 * IPs per NPPD-2039) survive a re-run.
	 */
	public function test_migrate_team_is_idempotent_and_preserves_operator_edits() {
		$team_id = $this->create_team( 'Rerun University' );

		$first_run_result = Institutions_Migration::migrate_team( $team_id, '10.2.0.0/16', [], true );
		$this->assertSame( 'created', $first_run_result['status'] );
		$institution_id = $first_run_result['institution_id'];

		// Simulate the operator replacing the internal range with the proxy egress IP.
		update_post_meta( $institution_id, Institution::META_PREFIX . 'ip_range', '203.0.113.7' );

		$second_run_result = Institutions_Migration::migrate_team( $team_id, '10.2.0.0/16', [], true );

		$this->assertSame( 'exists', $second_run_result['status'], 'The second run should report the team as already migrated.' );
		$this->assertSame( $institution_id, $second_run_result['institution_id'], 'The existing institution should be referenced.' );
		$this->assertCount( 1, $this->get_all_institutions(), 'No duplicate institution should be created.' );
		$this->assertSame( '203.0.113.7', get_post_meta( $institution_id, Institution::META_PREFIX . 'ip_range', true ), 'The operator-edited ranges must survive the re-run.' );
		$this->assertSame( [ '203.0.113.7' ], $second_run_result['ip_ranges'], 'The re-run must report the institution\'s CURRENT ranges (the operator edit), not the stale source ranges.' );
		$this->assertSame( [], $second_run_result['invalid_ranges'], 'Source-data range warnings are irrelevant for an already-migrated team.' );
	}

	/**
	 * A trashed migrated institution still counts as migrated: a re-run must not
	 * create a duplicate, and the found institution's status is reported.
	 */
	public function test_migrate_team_treats_trashed_institution_as_migrated() {
		$team_id = $this->create_team( 'Trashed University' );

		$first_run_result = Institutions_Migration::migrate_team( $team_id, '10.3.0.0/16', [], true );
		$institution_id   = $first_run_result['institution_id'];
		wp_trash_post( $institution_id );

		$second_run_result = Institutions_Migration::migrate_team( $team_id, '10.3.0.0/16', [], true );

		$this->assertSame( 'exists', $second_run_result['status'], 'A trashed institution must still count as migrated.' );
		$this->assertSame( $institution_id, $second_run_result['institution_id'], 'The trashed institution should be the one referenced.' );
		$this->assertSame( 'trash', $second_run_result['institution_status'], 'The found institution\'s status should be reported.' );
		$this->assertCount( 1, $this->get_all_institutions(), 'No duplicate institution should be created for a trashed one.' );
	}

	/**
	 * When Institution::create fails, the result is an explicit 'error' status
	 * carrying the WP_Error reason — never a fake success.
	 */
	public function test_migrate_team_reports_create_failure_as_error() {
		$team_id = $this->create_team( 'Erroring University' );

		// Force wp_insert_post (inside Institution::create) to fail.
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$migration_result = Institutions_Migration::migrate_team( $team_id, '10.4.0.0/16', [], true );
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertSame( 'error', $migration_result['status'], 'A failed create must surface as an error status.' );
		$this->assertNotEmpty( $migration_result['reason'], 'The WP_Error reason must be carried for reporting.' );
		$this->assertSame( 0, $migration_result['institution_id'], 'No institution ID should be reported on failure.' );
		$this->assertCount( 0, $this->get_all_institutions(), 'No institution should exist after a failed create.' );
	}

	/**
	 * Command-level: a live run where creation fails prints a FAILED warning with
	 * the reason and tallies the error in the Done line — it must never render as
	 * a success in an access-granting migration.
	 */
	public function test_migrate_institutions_command_reports_create_errors() {
		$team_id = $this->create_team( 'Command Error University' );
		update_option( Institutions_Migration::ACCESS_BY_IP_OPTION, [ $team_id => '10.5.0.0/16' ] );

		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		( new Institutions_Migration() )->migrate_institutions( [], [ 'live' => true ] );
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$warning_messages = array_column( array_filter( WP_CLI::$messages, fn( $entry ) => 'warning' === $entry[0] ), 1 );
		$success_messages = array_column( array_filter( WP_CLI::$messages, fn( $entry ) => 'success' === $entry[0] ), 1 );

		$failed_warnings = array_filter( $warning_messages, fn( $message ) => str_contains( $message, 'FAILED to create institution' ) );
		$this->assertCount( 1, $failed_warnings, 'The failed create must be reported as a warning.' );
		$this->assertStringContainsString( 'empty', reset( $failed_warnings ), 'The WP_Error reason must be printed.' );

		foreach ( $success_messages as $success_message ) {
			$this->assertStringNotContainsString( 'created institution', $success_message, 'A failed create must never print as a created-institution success.' );
		}

		$done_warnings = array_filter( $warning_messages, fn( $message ) => str_contains( $message, '1 error(s)' ) );
		$this->assertCount( 1, $done_warnings, 'The Done tally must count the error and be emitted as a warning.' );
		$this->assertCount( 0, $this->get_all_institutions(), 'Nothing should have been created.' );
	}

	/**
	 * BOM handling: a UTF-8 BOM at the start of a headerless CSV (Excel/Sheets
	 * exports) must not swallow the first data row.
	 */
	public function test_parse_domains_csv_strips_utf8_bom() {
		$csv_path = $this->write_csv( "\xEF\xBB\xBF12,uni.edu\n34,example.com\n" );

		$parse_result = Institutions_Migration::parse_domains_csv( $csv_path );

		$this->assertNotWPError( $parse_result );
		$this->assertSame(
			[
				12 => [ 'uni.edu' ],
				34 => [ 'example.com' ],
			],
			$parse_result['map'],
			'The BOM must be stripped so row 1 parses as data.'
		);
		$this->assertSame( [], $parse_result['errors'] );
	}
}
