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
}
