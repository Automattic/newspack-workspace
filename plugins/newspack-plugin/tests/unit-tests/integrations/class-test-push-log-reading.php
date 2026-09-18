<?php
/**
 * Tests for reading the integrations push log.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integrations\Push_Log;

/**
 * Push log reading test case.
 *
 * The read side answers a publisher's questions: what was sent for this
 * reader, did it arrive, and what changed since the last push that did.
 *
 * @group integrations
 * @group push-log
 */
class Test_Push_Log_Reading extends \WP_UnitTestCase {

	/**
	 * The contact a sample integration would receive.
	 *
	 * @param array $metadata Metadata to merge over the defaults.
	 * @return array
	 */
	private function sample_payload( array $metadata = [] ): array {
		return [
			'email'    => 'reader@example.test',
			'name'     => 'Sample Reader',
			'metadata' => array_merge(
				[
					'NP_Membership Status' => 'active',
					'NP_Total Paid'        => '120',
				],
				$metadata
			),
		];
	}

	/**
	 * Record an attempt, overriding only what the test is about.
	 *
	 * @param array $overrides Arguments to override.
	 * @return int The row ID.
	 */
	private function record( array $overrides = [] ): int {
		return Push_Log::record_attempt(
			array_merge(
				[
					'integration_id' => 'sample',
					'email'          => 'reader@example.test',
					'user_id'        => 7,
					'operation'      => Push_Log::OPERATION_UPSERT,
					'context'        => 'Test context',
					'payload'        => $this->sample_payload(),
					'hash_prefix'    => 'NP_',
					'result'         => true,
					'attempts'       => 1,
					'max_attempts'   => 6,
				],
				$overrides
			)
		);
	}

	/**
	 * Pin a row's last-update time, so ordering does not depend on the clock.
	 *
	 * @param int    $row_id   The row ID.
	 * @param string $datetime A GMT MySQL datetime.
	 */
	private function set_updated_at( int $row_id, string $datetime ) {
		global $wpdb;
		$wpdb->update( Push_Log::get_table_name(), [ 'updated_at' => $datetime ], [ 'id' => $row_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The IDs of a query's items, in the order returned.
	 *
	 * @param array $args Query arguments.
	 * @return int[]
	 */
	private function query_ids( array $args ): array {
		return wp_list_pluck( Push_Log::query( array_merge( [ 'integration_id' => 'sample' ], $args ) )['items'], 'id' );
	}

	/**
	 * A list is one integration's rows, newest activity first, light enough
	 * to page through: the payload stays out until a row is opened.
	 */
	public function test_query_lists_one_integration_newest_first_without_payloads() {
		$older = $this->record( [ 'email' => 'a@example.test' ] );
		$newer = $this->record( [ 'email' => 'b@example.test' ] );
		$this->record(
			[
				'integration_id' => 'other',
				'email'          => 'c@example.test',
			]
		);
		$this->set_updated_at( $older, '2026-09-10 10:00:00' );
		$this->set_updated_at( $newer, '2026-09-10 11:00:00' );

		$result = Push_Log::query( [ 'integration_id' => 'sample' ] );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ $newer, $older ], wp_list_pluck( $result['items'], 'id' ) );
		$this->assertArrayNotHasKey( 'payload', $result['items'][0] );
		$this->assertArrayNotHasKey( 'payload_hash', $result['items'][0] );
		$this->assertSame( 7, $result['items'][0]['user_id'] );
		$this->assertSame( 1, $result['items'][0]['attempts'] );
		$this->assertNull( $result['items'][0]['retry_action_id'] );

		$this->assertSame( [ $older, $newer ], $this->query_ids( [ 'order' => 'ASC' ] ) );
	}

	/**
	 * Status and operation narrow the list; a value the log does not know is
	 * ignored rather than returning nothing.
	 */
	public function test_query_filters_by_status_and_operation() {
		$this->record( [ 'email' => 'a@example.test' ] );
		$failed = $this->record(
			[
				'email'  => 'b@example.test',
				'result' => new \WP_Error( 'provider_down', 'ESP 503' ),
			]
		);
		$flag   = $this->record(
			[
				'email'     => 'c@example.test',
				'operation' => Push_Log::OPERATION_FLAG,
			]
		);

		$this->assertSame( [ $failed ], $this->query_ids( [ 'status' => Push_Log::STATUS_FAILED ] ) );
		$this->assertSame( [ $flag ], $this->query_ids( [ 'operation' => Push_Log::OPERATION_FLAG ] ) );

		$args = [
			'integration_id' => 'sample',
			'status'         => 'bogus',
		];
		$this->assertSame( 3, Push_Log::query( $args )['total'] );
	}

	/**
	 * Paging returns a slice and the total of the whole match. A page size
	 * outside 1 to 100 is pulled back in range.
	 */
	public function test_query_pages_and_counts() {
		foreach ( [ 'a', 'b', 'c' ] as $name ) {
			$this->record( [ 'email' => $name . '@example.test' ] );
		}

		$first = Push_Log::query(
			[
				'integration_id' => 'sample',
				'per_page'       => 2,
			]
		);
		$this->assertCount( 2, $first['items'] );
		$this->assertSame( 3, $first['total'] );

		$second = Push_Log::query(
			[
				'integration_id' => 'sample',
				'per_page'       => 2,
				'page'           => 2,
			]
		);
		$this->assertCount( 1, $second['items'] );

		$this->assertCount( 1, $this->query_ids( [ 'per_page' => 0 ] ) );
	}

	/**
	 * A reader who changed address keeps one history: a full email also
	 * matches rows written under the account it belongs to.
	 */
	public function test_search_by_full_email_follows_the_account() {
		$reader_id = self::factory()->user->create( [ 'user_email' => 'new@example.test' ] );
		$old_row   = $this->record(
			[
				'email'   => 'old@example.test',
				'user_id' => $reader_id,
			]
		);
		$new_row   = $this->record(
			[
				'email'   => 'new@example.test',
				'user_id' => $reader_id,
			]
		);
		$this->record(
			[
				'email'   => 'someone-else@example.test',
				'user_id' => $reader_id + 1,
			]
		);

		$found = $this->query_ids( [ 'search' => 'new@example.test' ] );
		sort( $found );
		$this->assertSame( [ $old_row, $new_row ], $found );

		// An address no account holds matches by address alone.
		$this->assertSame( [ $old_row ], $this->query_ids( [ 'search' => 'old@example.test' ] ) );
	}

	/**
	 * Anything short of a full email matches the start of the address. The
	 * search never matches the middle, and a wildcard typed in is a character.
	 */
	public function test_search_by_prefix_matches_the_start_of_the_address() {
		$reader = $this->record( [ 'email' => 'reader@example.test' ] );
		$this->record( [ 'email' => 'other@example.test' ] );

		$this->assertSame( [ $reader ], $this->query_ids( [ 'search' => 'read' ] ) );
		$this->assertSame( [], $this->query_ids( [ 'search' => 'example' ] ) );
		$this->assertSame( [], $this->query_ids( [ 'search' => '%' ] ) );
	}

	/**
	 * The table shortens an address longer than its column. Searching for the
	 * full address still finds the row.
	 */
	public function test_search_finds_an_address_stored_shortened() {
		$long_email = str_repeat( 'a', 200 ) . '@example.test';
		$row        = $this->record(
			[
				'email'   => $long_email,
				'user_id' => 0,
			]
		);

		$this->assertSame( [ $row ], $this->query_ids( [ 'search' => $long_email ] ) );
	}

	/**
	 * "Needs attention" is what still asks for someone to act: a push that is
	 * retrying, and a failure no later successful push has made up for. Every
	 * push sends the full contact, so a later success supersedes the failure.
	 */
	public function test_needs_attention_lists_open_failures_and_retries() {
		$error = new \WP_Error( 'provider_down', 'ESP 503' );

		// Failed, then succeeded later: made up for.
		$this->record(
			[
				'email'  => 'a@example.test',
				'result' => $error,
			]
		);
		$this->record( [ 'email' => 'a@example.test' ] );

		// Succeeded, then failed: still open.
		$this->record( [ 'email' => 'b@example.test' ] );
		$open_failure = $this->record(
			[
				'email'  => 'b@example.test',
				'result' => $error,
			]
		);

		// Waiting on a scheduled retry.
		$retrying = $this->record(
			[
				'email'  => 'c@example.test',
				'result' => $error,
			]
		);
		Push_Log::mark_retrying( $retrying, 9001 );

		$found = $this->query_ids( [ 'needs_attention' => true ] );
		sort( $found );
		$this->assertSame( [ $open_failure, $retrying ], $found );
		$this->assertSame(
			2,
			Push_Log::query(
				[
					'integration_id'  => 'sample',
					'needs_attention' => true,
				]
			)['total']
		);
	}

	/**
	 * A success under the reader's new address makes up for a failure under
	 * the old one. Guests share user ID 0, which must not link two of them.
	 */
	public function test_a_success_under_a_new_address_resolves_the_older_failure() {
		$error = new \WP_Error( 'provider_down', 'ESP 503' );

		$this->record(
			[
				'email'  => 'old@example.test',
				'result' => $error,
			]
		);
		$this->record( [ 'email' => 'new@example.test' ] );

		$guest_failure = $this->record(
			[
				'email'   => 'guest-one@example.test',
				'user_id' => 0,
				'result'  => $error,
			]
		);
		$this->record(
			[
				'email'   => 'guest-two@example.test',
				'user_id' => 0,
			]
		);

		$this->assertSame( [ $guest_failure ], $this->query_ids( [ 'needs_attention' => true ] ) );
	}

	/**
	 * A deletion sends no contact data, so a reader signing up again under the
	 * same address is not evidence the deletion reached the provider: only a
	 * flag or deletion that itself succeeded closes one out.
	 */
	public function test_a_later_upsert_does_not_resolve_a_failed_deletion() {
		$error = new \WP_Error( 'provider_down', 'ESP 503' );

		$failed_deletion = $this->record(
			[
				'email'     => 'gone@example.test',
				'user_id'   => 0,
				'operation' => Push_Log::OPERATION_DELETE,
				'payload'   => null,
				'result'    => $error,
			]
		);
		$this->record(
			[
				'email'   => 'gone@example.test',
				'user_id' => 0,
			]
		);

		$this->assertSame( [ $failed_deletion ], $this->query_ids( [ 'needs_attention' => true ] ) );

		$this->record(
			[
				'email'     => 'gone@example.test',
				'user_id'   => 0,
				'operation' => Push_Log::OPERATION_FLAG,
			]
		);

		$this->assertSame( [], $this->query_ids( [ 'needs_attention' => true ] ) );
	}

	/**
	 * Every optional filter appends its own placeholders to the query.
	 * Combining all of them catches a filter whose value landed out of order,
	 * which exercising one filter at a time would miss.
	 */
	public function test_query_combines_search_status_operation_and_needs_attention() {
		$error     = new \WP_Error( 'provider_down', 'ESP 503' );
		$reader_id = self::factory()->user->create( [ 'user_email' => 'match@example.test' ] );

		// Wrong address and account: excluded by search.
		$this->record(
			[
				'email'  => 'other@example.test',
				'result' => $error,
			]
		);

		// Succeeded: excluded by status.
		$this->record(
			[
				'email'   => 'match@example.test',
				'user_id' => $reader_id,
			]
		);

		// A flag, not an upsert: excluded by operation.
		$this->record(
			[
				'email'     => 'match@example.test',
				'user_id'   => $reader_id,
				'operation' => Push_Log::OPERATION_FLAG,
				'result'    => $error,
			]
		);

		// Failed, but a later success for the reader made up for it: excluded by needs_attention.
		$this->record(
			[
				'email'   => 'match@example.test',
				'user_id' => $reader_id,
				'result'  => $error,
			]
		);
		$this->record(
			[
				'email'   => 'match@example.test',
				'user_id' => $reader_id,
			]
		);

		// Matches every filter, and nothing later resolves it.
		$match = $this->record(
			[
				'email'   => 'match@example.test',
				'user_id' => $reader_id,
				'result'  => $error,
			]
		);

		$found = $this->query_ids(
			[
				'search'          => 'match@example.test',
				'status'          => Push_Log::STATUS_FAILED,
				'operation'       => Push_Log::OPERATION_UPSERT,
				'needs_attention' => true,
			]
		);

		$this->assertSame( [ $match ], $found );
	}

	/**
	 * A resolving success is later by clock time, not only by insertion order:
	 * a failure can be logged after the success that already made up for it,
	 * when a retry chain and a fresh push interleave.
	 */
	public function test_needs_attention_follows_clock_time_over_row_order() {
		$success = $this->record( [ 'email' => 'a@example.test' ] );
		$failure = $this->record(
			[
				'email'  => 'a@example.test',
				'result' => new \WP_Error( 'provider_down', 'ESP 503' ),
			]
		);
		$this->set_updated_at( $success, '2026-09-10 12:00:00' );
		$this->set_updated_at( $failure, '2026-09-10 11:00:00' );

		$this->assertSame( [], $this->query_ids( [ 'needs_attention' => true ] ) );
	}

	/**
	 * Opening a row returns what was sent. A row is only readable through the
	 * integration it belongs to, so one integration's screen cannot open
	 * another's rows.
	 */
	public function test_get_returns_the_row_with_its_payload_decoded() {
		$row_id = $this->record();

		$row = Push_Log::get( $row_id, 'sample' );

		$this->assertSame( $row_id, $row['id'] );
		$this->assertSame( $this->sample_payload(), $row['payload'] );
		$this->assertArrayNotHasKey( 'payload_hash', $row );
		$this->assertNull( Push_Log::get( $row_id, 'other' ) );
		$this->assertNull( Push_Log::get( $row_id + 1000, 'sample' ) );
	}

	/**
	 * A row is compared with the last push that reached the provider: a failed
	 * push never arrived, and another reader's push is not this reader's data.
	 */
	public function test_predecessor_is_the_previous_successful_push_for_the_reader() {
		$first = $this->record();
		$this->record(
			[
				'payload' => $this->sample_payload( [ 'NP_Total Paid' => '150' ] ),
				'result'  => new \WP_Error( 'provider_down', 'ESP 503' ),
			]
		);
		$this->record(
			[
				'email'   => 'other@example.test',
				'user_id' => 8,
			]
		);
		$latest = $this->record( [ 'payload' => $this->sample_payload( [ 'NP_Total Paid' => '180' ] ) ] );

		$predecessor = Push_Log::get_predecessor( Push_Log::get( $latest, 'sample' ) );

		$this->assertSame( $first, $predecessor['id'] );
		$this->assertSame( '120', $predecessor['payload']['metadata']['NP_Total Paid'] );
		$this->assertNull( Push_Log::get_predecessor( Push_Log::get( $first, 'sample' ) ) );
	}

	/**
	 * A change of address does not cut the history: the predecessor follows
	 * the account.
	 */
	public function test_predecessor_follows_the_account_across_an_email_change() {
		$before_change = $this->record( [ 'email' => 'old@example.test' ] );
		$after_change  = $this->record( [ 'email' => 'new@example.test' ] );

		$predecessor = Push_Log::get_predecessor( Push_Log::get( $after_change, 'sample' ) );

		$this->assertSame( $before_change, $predecessor['id'] );
	}

	/**
	 * The address and the account are asked separately, and the later of the
	 * two answers wins: a push logged under an address the reader has already
	 * left is still compared with the newest push that reached the provider.
	 */
	public function test_predecessor_takes_the_later_of_the_address_and_the_account() {
		$under_old_address = $this->record( [ 'email' => 'old@example.test' ] );
		$under_new_address = $this->record( [ 'email' => 'new@example.test' ] );
		$back_under_old    = $this->record(
			[
				'email'   => 'old@example.test',
				'payload' => $this->sample_payload( [ 'NP_Total Paid' => '180' ] ),
			]
		);

		$predecessor = Push_Log::get_predecessor( Push_Log::get( $back_under_old, 'sample' ) );

		$this->assertNotSame( $under_old_address, $predecessor['id'] );
		$this->assertSame( $under_new_address, $predecessor['id'] );
	}

	/**
	 * A table that cannot be read is not an empty log. The failure reaches the
	 * caller, without the statement, which carries the reader's address.
	 */
	public function test_query_reports_a_table_it_cannot_read() {
		$this->record();
		$break_reads = function ( $query ) {
			return str_replace( Push_Log::get_table_name(), 'table_that_does_not_exist', $query );
		};
		add_filter( 'query', $break_reads );

		$result = Push_Log::query( [ 'integration_id' => 'sample' ] );

		remove_filter( 'query', $break_reads );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_push_log_read_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'table_that_does_not_exist', $result->get_error_message() );
	}

	/**
	 * The comparison names what changed, what is new and what was dropped,
	 * with real changes first, fields that change on every visit after them,
	 * and everything else last.
	 */
	public function test_compare_orders_real_changes_before_volatile_ones() {
		$predecessor = [
			'payload' => [
				'email'    => 'reader@example.test',
				'name'     => 'Sample Reader',
				'metadata' => [
					'NP_Membership Status' => 'active',
					'NP_Total Paid'        => '120',
					'NP_Last Active'       => '2026-09-03',
					'NP_Dropped'           => 'gone',
				],
			],
		];
		$row         = [
			'payload' => [
				'email'    => 'reader@example.test',
				'name'     => 'Sample Reader',
				'metadata' => [
					'NP_Membership Status' => 'active',
					'NP_Total Paid'        => '180',
					'NP_Last Active'       => '2026-09-10',
					'NP_New Field'         => 'added',
				],
			],
		];

		$fields = Push_Log::compare_payloads( $row, $predecessor, 'NP_' );

		$this->assertSame(
			[ 'NP_Total Paid', 'NP_New Field', 'NP_Dropped', 'NP_Last Active', 'email', 'name', 'NP_Membership Status' ],
			wp_list_pluck( $fields, 'key' )
		);
		$this->assertSame(
			[
				'key'      => 'NP_Total Paid',
				'label'    => 'Total Paid',
				'before'   => '120',
				'after'    => '180',
				'changed'  => true,
				'volatile' => false,
			],
			$fields[0]
		);
		$this->assertNull( $fields[1]['before'] );
		$this->assertNull( $fields[2]['after'] );
		$this->assertTrue( $fields[3]['volatile'] );
		$this->assertTrue( $fields[3]['changed'] );
		$this->assertSame( 'Email', $fields[4]['label'] );
		$this->assertFalse( $fields[4]['changed'] );
	}

	/**
	 * With nothing to compare against, nothing is claimed to have changed.
	 */
	public function test_compare_without_a_predecessor_claims_no_changes() {
		$fields = Push_Log::compare_payloads( [ 'payload' => $this->sample_payload() ], null, 'NP_' );

		$this->assertCount( 4, $fields );
		foreach ( $fields as $field ) {
			$this->assertNull( $field['before'] );
			$this->assertFalse( $field['changed'] );
		}
	}

	/**
	 * A hard delete sends no data, so there is nothing to list.
	 */
	public function test_compare_of_a_hard_delete_is_empty() {
		$this->assertSame( [], Push_Log::compare_payloads( [ 'payload' => null ], [ 'payload' => $this->sample_payload() ], 'NP_' ) );
	}

	/**
	 * The previous address is log context, not data sent: it is listed, never
	 * reported as a change. A value that is not text is compared as text.
	 */
	public function test_compare_lists_previous_email_without_comparing_it() {
		$row = [
			'payload' => array_merge(
				$this->sample_payload( [ 'NP_Lists' => [ 'daily', 'weekly' ] ] ),
				[ 'previous_email' => 'old@example.test' ]
			),
		];

		$fields = array_column( Push_Log::compare_payloads( $row, [ 'payload' => $this->sample_payload() ], 'NP_' ), null, 'key' );

		$this->assertFalse( $fields['previous_email']['changed'] );
		$this->assertNull( $fields['previous_email']['before'] );
		$this->assertSame( 'old@example.test', $fields['previous_email']['after'] );
		$this->assertSame( '["daily","weekly"]', $fields['NP_Lists']['after'] );
		$this->assertTrue( $fields['NP_Lists']['changed'] );
	}

	/**
	 * A deletion flag carries the address and a couple of deletion fields by
	 * design. The rest of the contact was not cleared at the provider, so the
	 * comparison keeps to what the flag itself sent.
	 */
	public function test_compare_of_a_flag_keeps_to_the_fields_it_sent() {
		$row = [
			'operation' => Push_Log::OPERATION_FLAG,
			'payload'   => [
				'email'    => 'reader@example.test',
				'metadata' => [ 'NP_Deleted' => 'yes' ],
			],
		];

		$keys = wp_list_pluck( Push_Log::compare_payloads( $row, [ 'payload' => $this->sample_payload() ], 'NP_' ), 'key' );
		sort( $keys );

		$this->assertSame( [ 'NP_Deleted', 'email' ], $keys );
	}

	/**
	 * An upsert sends the whole contact, so a field it stopped sending is a
	 * field the provider no longer hears about: it stays on the list.
	 */
	public function test_compare_of_an_upsert_still_lists_a_dropped_field() {
		$row = [
			'operation' => Push_Log::OPERATION_UPSERT,
			'payload'   => [
				'email'    => 'reader@example.test',
				'metadata' => [ 'NP_Membership Status' => 'active' ],
			],
		];

		$fields = array_column( Push_Log::compare_payloads( $row, [ 'payload' => $this->sample_payload() ], 'NP_' ), null, 'key' );

		$this->assertArrayHasKey( 'NP_Total Paid', $fields );
		$this->assertNull( $fields['NP_Total Paid']['after'] );
		$this->assertTrue( $fields['NP_Total Paid']['changed'] );
	}

	/**
	 * The comparison mutes the same fields the collapse check ignores, so a
	 * site that extends the list sees it on the screen too.
	 */
	public function test_compare_honors_the_volatile_fields_filter() {
		$add_field = function ( $fields ) {
			$fields[] = 'Total Paid';
			return $fields;
		};
		add_filter( 'newspack_integrations_push_log_volatile_fields', $add_field );

		$fields = array_column(
			Push_Log::compare_payloads(
				[ 'payload' => $this->sample_payload( [ 'NP_Total Paid' => '180' ] ) ],
				[ 'payload' => $this->sample_payload() ],
				'NP_'
			),
			null,
			'key'
		);
		remove_filter( 'newspack_integrations_push_log_volatile_fields', $add_field );

		$this->assertTrue( $fields['NP_Total Paid']['volatile'] );
	}
}
