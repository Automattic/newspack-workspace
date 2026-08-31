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
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- Test helper counting a handful of fixture posts.
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
	 * the reason and aborts non-zero via WP_CLI::error carrying the error tally —
	 * it must never render as a success in an access-granting migration, and a
	 * scripted caller must be able to detect the partial failure from the exit.
	 */
	public function test_migrate_institutions_command_reports_create_errors() {
		$team_id = $this->create_team( 'Command Error University' );
		update_option( Institutions_Migration::ACCESS_BY_IP_OPTION, [ $team_id => '10.5.0.0/16' ] );

		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$abort_message = '';
		try {
			( new Institutions_Migration() )->migrate_institutions( [], [ 'live' => true ] );
			$this->fail( 'A run with a failed create must abort via WP_CLI::error (non-zero exit).' );
		} catch ( WP_CLI_Mock_Exception $abort ) {
			$abort_message = $abort->getMessage();
		} finally {
			remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		}

		$this->assertStringContainsString( '1 error(s)', $abort_message, 'The aborting Done tally must count the error.' );
		$this->assertStringContainsString( 'NOT migrated', $abort_message, 'The abort must state that errored teams were not migrated.' );

		$warning_messages = array_column( array_filter( WP_CLI::$messages, fn( $entry ) => 'warning' === $entry[0] ), 1 );
		$success_messages = array_column( array_filter( WP_CLI::$messages, fn( $entry ) => 'success' === $entry[0] ), 1 );

		$failed_warnings = array_filter( $warning_messages, fn( $message ) => str_contains( $message, 'FAILED to create institution' ) );
		$this->assertCount( 1, $failed_warnings, 'The failed create must be reported as a warning.' );
		$this->assertStringContainsString( 'empty', reset( $failed_warnings ), 'The WP_Error reason must be printed.' );

		foreach ( $success_messages as $success_message ) {
			$this->assertStringNotContainsString( 'created institution', $success_message, 'A failed create must never print as a created-institution success.' );
		}

		$this->assertCount( 0, $this->get_all_institutions(), 'Nothing should have been created.' );
	}

	/**
	 * Range breadth is judged by the numeric mask the access check will use, not
	 * by the range's spelling: zero-length masks (however written), private and
	 * reserved subnets, and very wide public blocks are all flagged; ordinary
	 * public ranges and non-IP values are not.
	 */
	public function test_get_range_breadth_warning_flags_dangerous_ranges() {
		$this->assertStringContainsString( 'EVERY visitor IP', Institutions_Migration::get_range_breadth_warning( '0.0.0.0/0' ), 'A zero mask must be flagged as matching everyone.' );
		$this->assertStringContainsString( 'EVERY visitor IP', Institutions_Migration::get_range_breadth_warning( '0.0.0.0 / 0' ), 'Whitespace around the slash must not evade the breadth check — the access check trims and matches this spelling.' );
		$this->assertStringContainsString( 'private or reserved', Institutions_Migration::get_range_breadth_warning( '10.0.0.0/8' ), 'A private subnet must be flagged.' );
		$this->assertStringContainsString( 'private or reserved', Institutions_Migration::get_range_breadth_warning( '127.0.0.1' ), 'A reserved address must be flagged.' );
		$this->assertStringContainsString( 'very wide block', Institutions_Migration::get_range_breadth_warning( '8.0.0.0/8' ), 'A very wide public block must be flagged.' );
		$this->assertSame( '', Institutions_Migration::get_range_breadth_warning( '198.51.100.7' ), 'An ordinary public address is unremarkable.' );
		$this->assertSame( '', Institutions_Migration::get_range_breadth_warning( '128.100.0.0/16' ), 'An ordinary public /16 is unremarkable.' );
		$this->assertSame( '', Institutions_Migration::get_range_breadth_warning( 'not-an-ip' ), 'Non-IP values (operator-owned meta on existing institutions) are left alone.' );
	}

	/**
	 * A leading-zero mask like `/00` must be canonicalized on validation: stored
	 * as `/0` so no string-shape check downstream can be evaded, and flagged by
	 * the command's breadth warning like any other zero mask.
	 */
	public function test_normalize_ip_ranges_canonicalizes_leading_zero_bits() {
		$normalized = Institutions_Migration::normalize_ip_ranges( '0.0.0.0/00, 10.0.0.0/016' );
		$this->assertSame( [ '0.0.0.0/0', '10.0.0.0/16' ], $normalized['valid'], 'Leading-zero mask bits must be canonicalized to their numeric value.' );
		$this->assertSame( [], $normalized['invalid'] );
	}

	/**
	 * Command-level: dangerously broad ranges are flagged per team in the run
	 * output — including a zero mask written with a leading zero.
	 */
	public function test_migrate_institutions_command_warns_on_broad_ranges() {
		$everyone_team_id = $this->create_team( 'Everyone University' );
		$private_team_id  = $this->create_team( 'Intranet College' );
		update_option(
			Institutions_Migration::ACCESS_BY_IP_OPTION,
			[
				$everyone_team_id => '0.0.0.0/00',
				$private_team_id  => '192.168.0.0/16',
			]
		);

		( new Institutions_Migration() )->migrate_institutions( [], [] );

		$warning_messages  = array_column( array_filter( WP_CLI::$messages, fn( $entry ) => 'warning' === $entry[0] ), 1 );
		$everyone_warnings = array_filter( $warning_messages, fn( $message ) => str_contains( $message, 'EVERY visitor IP' ) && str_contains( $message, 'range "0.0.0.0/0"' ) );
		$private_warnings  = array_filter( $warning_messages, fn( $message ) => str_contains( $message, 'private or reserved' ) && str_contains( $message, '192.168.0.0/16' ) );
		$this->assertCount( 1, $everyone_warnings, 'A zero mask written as /00 must be flagged as matching everyone, under its CANONICAL spelling — the quoted-range assertion fails if canonicalization regresses to storing /00.' );
		$this->assertCount( 1, $private_warnings, 'A private range must be flagged in the run output.' );

		// The content-gates pre-flight warning: NEWSPACK_CONTENT_GATES is undefined
		// in the test env, so every command run must carry it.
		$gates_warnings = array_filter( $warning_messages, fn( $message ) => str_contains( $message, 'NEWSPACK_CONTENT_GATES' ) );
		$this->assertCount( 1, $gates_warnings, 'The command must warn when the content gates feature is disabled.' );

		// The closing reminder that gates still need configuring.
		$reminder_lines = array_filter( WP_CLI::$logs, fn( $line ) => str_contains( $line, 'admits EVERY visitor' ) );
		$this->assertCount( 1, $reminder_lines, 'The run must close by naming the gate-configuration step.' );

		$this->assertCount( 0, $this->get_all_institutions(), 'The dry-run must still write nothing.' );
	}

	/**
	 * A --domains-csv path that is a directory must be a hard error, exactly like
	 * a missing file — is_readable() alone passes for a directory, which would
	 * otherwise "read" as an empty CSV and silently migrate zero domains.
	 */
	public function test_parse_domains_csv_directory_is_an_error() {
		$parse_result = Institutions_Migration::parse_domains_csv( untrailingslashit( get_temp_dir() ) );
		$this->assertWPError( $parse_result, 'A directory path must be a hard error, not an empty map.' );
	}

	/**
	 * Command-level: a supplied CSV that yields no team → domain rows (empty file,
	 * header-only file, or all rows malformed) aborts the run before any team is
	 * processed — an operator typo must not silently migrate zero domains.
	 */
	public function test_migrate_institutions_command_aborts_on_empty_domains_csv() {
		$team_id = $this->create_team( 'Empty CSV University' );
		update_option( Institutions_Migration::ACCESS_BY_IP_OPTION, [ $team_id => '128.100.0.0/16' ] );
		$csv_path = $this->write_csv( "team_id,domain\n" );

		try {
			( new Institutions_Migration() )->migrate_institutions(
				[],
				[
					'domains-csv' => $csv_path,
					'live'        => true,
				]
			);
			$this->fail( 'A supplied-but-empty domains CSV must abort the run.' );
		} catch ( WP_CLI_Mock_Exception $abort ) {
			$this->assertStringContainsString( 'yielded no team', $abort->getMessage(), 'The abort must name the empty-CSV cause.' );
		}

		$this->assertCount( 0, $this->get_all_institutions(), 'Nothing may be migrated when the CSV was supplied but empty.' );
	}

	/**
	 * A malformed FIRST data row is reported, not absorbed as a phantom header:
	 * only a cell that actually reads as a header (`team_id` and variants) is
	 * skipped silently — wherever it appears, including after a leading blank line.
	 */
	public function test_parse_domains_csv_reports_malformed_first_row() {
		$parse_result = Institutions_Migration::parse_domains_csv(
			$this->write_csv( "12a,uni.edu\n34,ok.edu\n" )
		);
		$this->assertSame( [ 34 => [ 'ok.edu' ] ], $parse_result['map'], 'Only the valid row should be mapped.' );
		$this->assertCount( 1, $parse_result['errors'], 'A typo\'d team ID on row 1 must be reported, not swallowed as a header.' );
		$this->assertStringContainsString( '12a', $parse_result['errors'][0] );

		$blank_lead_result = Institutions_Migration::parse_domains_csv(
			$this->write_csv( "\nTeam ID,domain\n12,uni.edu\n" )
		);
		$this->assertSame( [ 12 => [ 'uni.edu' ] ], $blank_lead_result['map'], 'A real header after a leading blank line still parses.' );
		$this->assertSame( [], $blank_lead_result['errors'], 'A recognized header must not be reported as a malformed row.' );
	}

	/**
	 * An already-migrated team never has new source values applied — but the
	 * values the re-run declined to add are computed and surfaced, so a second
	 * run with a newly-obtained CSV cannot be mistaken for a successful apply.
	 */
	public function test_migrate_team_exists_reports_withheld_values() {
		$team_id = $this->create_team( 'Withheld University' );

		$first_run_result = Institutions_Migration::migrate_team( $team_id, '128.100.0.0/16', [ 'old.edu' ], true );
		$this->assertSame( 'created', $first_run_result['status'] );

		$second_run_result = Institutions_Migration::migrate_team( $team_id, '128.100.0.0/16, 198.51.100.9', [ 'old.edu', 'new.edu' ], true );

		$this->assertSame( 'exists', $second_run_result['status'] );
		$this->assertSame( [ '198.51.100.9' ], $second_run_result['withheld_ranges'], 'A newly-supplied range the institution lacks must be reported as withheld.' );
		$this->assertSame( [ 'new.edu' ], $second_run_result['withheld_domains'], 'A newly-supplied domain the institution lacks must be reported as withheld.' );
		$this->assertSame(
			'128.100.0.0/16',
			get_post_meta( $second_run_result['institution_id'], Institution::META_PREFIX . 'ip_range', true ),
			'The withheld values must NOT be applied.'
		);
		$this->assertSame(
			'old.edu',
			get_post_meta( $second_run_result['institution_id'], Institution::META_PREFIX . 'email_domain', true ),
			'The withheld domains must NOT be applied.'
		);
	}

	/**
	 * A value the operator deliberately removed after migration must NOT be
	 * re-listed as withheld on re-runs: the diff runs against the
	 * applied-at-creation record, so the proxy-egress workflow (replace the
	 * internal range with the egress IP) gets no standing prompt to restore
	 * the range it removed.
	 */
	public function test_migrate_team_does_not_relist_operator_removed_values() {
		$team_id = $this->create_team( 'Egress Fix University' );

		$first_run_result = Institutions_Migration::migrate_team( $team_id, '10.2.0.0/16', [ 'egress.edu' ], true );
		$institution_id   = $first_run_result['institution_id'];

		// The operator replaces the internal range with the proxy egress IP.
		update_post_meta( $institution_id, Institution::META_PREFIX . 'ip_range', '203.0.113.7' );

		$second_run_result = Institutions_Migration::migrate_team( $team_id, '10.2.0.0/16', [ 'egress.edu' ], true );

		$this->assertSame( 'exists', $second_run_result['status'] );
		$this->assertSame( [], $second_run_result['withheld_ranges'], 'A range applied at creation and later removed by the operator must not be re-suggested.' );
		$this->assertSame( [], $second_run_result['withheld_domains'], 'Unchanged domains must not read as withheld.' );

		// A genuinely NEW source value still surfaces.
		$third_run_result = Institutions_Migration::migrate_team( $team_id, '10.2.0.0/16, 198.51.100.9', [ 'egress.edu' ], true );
		$this->assertSame( [ '198.51.100.9' ], $third_run_result['withheld_ranges'], 'A source range that was never applied must still be reported.' );
	}

	/**
	 * Institutions migrated before the applied-at-creation record existed fall
	 * back to a current-rules diff, so legacy re-runs keep reporting rather
	 * than failing silent.
	 */
	public function test_migrate_team_withheld_falls_back_without_applied_record() {
		$team_id = $this->create_team( 'Legacy Record University' );

		$first_run_result = Institutions_Migration::migrate_team( $team_id, '128.100.0.0/16', [], true );
		$institution_id   = $first_run_result['institution_id'];
		delete_post_meta( $institution_id, Institutions_Migration::APPLIED_IP_RANGES_META_KEY );
		delete_post_meta( $institution_id, Institutions_Migration::APPLIED_DOMAINS_META_KEY );

		$second_run_result = Institutions_Migration::migrate_team( $team_id, '128.100.0.0/16, 198.51.100.9', [ 'late.edu' ], true );

		$this->assertSame( [ '198.51.100.9' ], $second_run_result['withheld_ranges'], 'Without the applied record, the diff must fall back to current rules.' );
		$this->assertSame( [ 'late.edu' ], $second_run_result['withheld_domains'] );
	}

	/**
	 * Command-level: a live run with a partially-bad domains CSV (malformed
	 * rows or rows referencing non-existent teams) aborts before any team is
	 * stamped — the same irrecoverability as the empty-CSV case — while a
	 * dry-run proceeds and reports, and --ignore-csv-errors overrides.
	 */
	public function test_migrate_institutions_command_aborts_live_on_partial_csv_errors() {
		$team_id = $this->create_team( 'Partial CSV University' );
		update_option( Institutions_Migration::ACCESS_BY_IP_OPTION, [ $team_id => '128.100.0.0/16' ] );
		$csv_path = $this->write_csv( "$team_id,good.edu\nnot-a-team-id,bad.edu\n424242,orphan.edu\n" );

		// Dry-run proceeds (warnings only).
		( new Institutions_Migration() )->migrate_institutions( [], [ 'domains-csv' => $csv_path ] );
		$this->assertCount( 0, $this->get_all_institutions(), 'Dry-run writes nothing.' );

		// Live aborts before any write.
		WP_CLI::reset();
		try {
			( new Institutions_Migration() )->migrate_institutions(
				[],
				[
					'domains-csv' => $csv_path,
					'live'        => true,
				]
			);
			$this->fail( 'A live run with CSV errors must abort.' );
		} catch ( WP_CLI_Mock_Exception $abort ) {
			$this->assertStringContainsString( '1 malformed row(s)', $abort->getMessage(), 'The abort must count the malformed rows.' );
			$this->assertStringContainsString( '1 row(s) referencing non-existent teams', $abort->getMessage(), 'The abort must count the orphan rows.' );
		}
		$this->assertCount( 0, $this->get_all_institutions(), 'Nothing may be stamped when the live run aborts on CSV errors.' );

		// The explicit override proceeds and migrates the valid rows.
		WP_CLI::reset();
		( new Institutions_Migration() )->migrate_institutions(
			[],
			[
				'domains-csv'       => $csv_path,
				'live'              => true,
				'ignore-csv-errors' => true,
			]
		);
		$institutions = $this->get_all_institutions();
		$this->assertCount( 1, $institutions, 'With --ignore-csv-errors the valid rows must migrate.' );
		$this->assertSame( 'good.edu', get_post_meta( $institutions[0]->ID, Institution::META_PREFIX . 'email_domain', true ), 'The valid CSV row must be applied.' );
	}

	/**
	 * Command-level: the withheld values are named in the run output so the
	 * operator sees exactly what the re-run declined to add.
	 */
	public function test_migrate_institutions_command_names_withheld_values() {
		$team_id = $this->create_team( 'Second Pass University' );
		update_option( Institutions_Migration::ACCESS_BY_IP_OPTION, [ $team_id => '128.100.0.0/16' ] );

		( new Institutions_Migration() )->migrate_institutions( [], [ 'live' => true ] );
		WP_CLI::reset();

		$csv_path = $this->write_csv( "$team_id,latecomer.edu\n" );
		( new Institutions_Migration() )->migrate_institutions(
			[],
			[
				'domains-csv' => $csv_path,
				'live'        => true,
			]
		);

		$warning_messages  = array_column( array_filter( WP_CLI::$messages, fn( $entry ) => 'warning' === $entry[0] ), 1 );
		$withheld_warnings = array_filter( $warning_messages, fn( $message ) => str_contains( $message, 'NOT applied' ) && str_contains( $message, 'latecomer.edu' ) );
		$this->assertCount( 1, $withheld_warnings, 'The re-run must name the withheld domain in a warning.' );
		$this->assertCount( 1, $this->get_all_institutions(), 'No duplicate institution may be created by the re-run.' );
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
