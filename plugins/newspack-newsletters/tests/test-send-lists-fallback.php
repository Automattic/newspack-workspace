<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for Newspack_Newsletters_Service_Provider::get_send_lists_with_fallback().
 *
 * The fallback exists so a stored send-list/sublist id that belongs to a
 * previously-connected ESP cannot make the editor's campaign retrieval fail
 * unrecoverably.
 *
 * Providers signal "this id did not resolve" in three different ways, so the
 * fallback has to recognise all of them: ActiveCampaign passes `ids` upstream
 * and returns a WP_Error, Mailchimp and Constant Contact fetch everything and
 * filter in PHP (yielding an empty array), and Mailchimp throws outright when
 * the API itself is unhappy. The first group of tests pins those shapes against
 * the base class; the last drives the real ActiveCampaign provider through a
 * mocked HTTP layer so the suite proves at least one real provider reaches the
 * fallback rather than only asserting against a stub.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test the send-list fallback on the service-provider base class.
 */
class Send_Lists_Fallback_Test extends WP_UnitTestCase {

	/**
	 * Every $args array passed to the stubbed get_send_lists(), in order.
	 *
	 * @var array[]
	 */
	private $calls = [];

	/**
	 * Build a concrete stand-in for the abstract base class whose
	 * get_send_lists() is driven by a caller-supplied callback, so we exercise
	 * only the real get_send_lists_with_fallback() logic.
	 *
	 * @param callable $get_send_lists Callback( array $args, bool $to_array ): array|WP_Error.
	 * @return Newspack_Newsletters_Service_Provider&PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_provider( callable $get_send_lists ) {
		$provider = $this->getMockBuilder( Newspack_Newsletters_Service_Provider::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_send_lists' ] )
			->getMockForAbstractClass();
		$provider->method( 'get_send_lists' )->willReturnCallback(
			function ( $args, $to_array = false ) use ( $get_send_lists ) {
				$this->calls[] = $args;
				return $get_send_lists( $args, $to_array );
			}
		);
		return $provider;
	}

	/**
	 * A valid targeted fetch is returned untouched, with no retry.
	 */
	public function test_returns_targeted_result_when_it_succeeds() {
		$provider = $this->make_provider(
			function () {
				return [
					[
						'id'   => '5',
						'name' => 'Main',
					],
				];
			}
		);
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'  => [ '5' ],
				'type' => 'list',
			],
			true
		);
		$this->assertSame(
			[
				[
					'id'   => '5',
					'name' => 'Main',
				],
			],
			$result
		);
		$this->assertCount( 1, $this->calls, 'A successful targeted fetch must not retry.' );
	}

	/**
	 * Mailchimp and Constant Contact filter ids in PHP after fetching
	 * everything, so an unknown id comes back as an empty array rather than an
	 * error. That shape must trigger the fallback too, otherwise the recovery
	 * only ever works for ActiveCampaign.
	 */
	public function test_empty_targeted_result_falls_back() {
		$provider = $this->make_provider(
			function ( $args ) {
				if ( ! empty( $args['ids'] ) ) {
					return [];
				}
				return [
					[
						'id'   => '9',
						'name' => 'Audience A',
					],
				];
			}
		);
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'  => [ 'mc_foreign' ],
				'type' => 'list',
			],
			true
		);
		$this->assertSame(
			[
				[
					'id'   => '9',
					'name' => 'Audience A',
				],
			],
			$result
		);
		$this->assertCount( 2, $this->calls, 'An empty targeted result must retry.' );
		$this->assertArrayNotHasKey( 'ids', $this->calls[1], 'Retry must drop ids.' );
	}

	/**
	 * Mailchimp signals API failure by throwing rather than returning a
	 * WP_Error. An uncaught throw would sail past the fallback entirely, so it
	 * is normalized and treated like any other failed lookup.
	 */
	public function test_thrown_provider_error_is_normalized_and_falls_back() {
		$provider = $this->make_provider(
			function ( $args ) {
				if ( ! empty( $args['ids'] ) ) {
					throw new Exception( 'Invalid MailChimp API key supplied.' );
				}
				return [
					[
						'id'   => '11',
						'name' => 'Audience B',
					],
				];
			}
		);
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'  => [ 'mc_foreign' ],
				'type' => 'list',
			],
			true
		);
		$this->assertSame(
			[
				[
					'id'   => '11',
					'name' => 'Audience B',
				],
			],
			$result
		);
		$this->assertCount( 2, $this->calls, 'A thrown provider error must retry.' );
	}

	/**
	 * When a provider throws on every attempt the ESP is genuinely unreachable.
	 * The throw must surface as a WP_Error rather than escaping to the caller,
	 * so the documented array|WP_Error return holds.
	 */
	public function test_thrown_provider_error_surfaces_as_wp_error() {
		$provider = $this->make_provider(
			function () {
				throw new Exception( 'Invalid MailChimp API key supplied.' );
			}
		);
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'  => [ 'mc_foreign' ],
				'type' => 'list',
			],
			true
		);
		$this->assertWPError( $result );
		$this->assertSame( 'newspack_newsletters_send_lists_fetch_failed', $result->get_error_code() );
		$this->assertSame( 'Invalid MailChimp API key supplied.', $result->get_error_message() );
	}

	/**
	 * The parent_id arg is a scope, not the suspect value: a stale sublist id
	 * must first be retried within its parent list, so the result stays tied to
	 * the list the user actually selected.
	 */
	public function test_retry_keeps_parent_id_before_widening() {
		$provider = $this->make_provider(
			function ( $args ) {
				if ( ! empty( $args['ids'] ) ) {
					return new WP_Error( 'bad_id', 'foreign id' );
				}
				return [
					[
						'id'     => '9',
						'name'   => 'Segment A',
						'parent' => 'ac_list',
					],
				];
			}
		);
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'       => [ 'ac_stale' ],
				'parent_id' => 'ac_list',
				'type'      => 'sublist',
			],
			true
		);
		$this->assertSame(
			[
				[
					'id'     => '9',
					'name'   => 'Segment A',
					'parent' => 'ac_list',
				],
			],
			$result
		);
		$this->assertCount( 2, $this->calls, 'The scoped retry must resolve without widening further.' );
		$this->assertArrayNotHasKey( 'ids', $this->calls[1], 'Retry must drop ids.' );
		$this->assertSame( 'ac_list', $this->calls[1]['parent_id'], 'Retry must keep parent_id as the scope.' );
		$this->assertSame( 'sublist', $this->calls[1]['type'], 'Retry must keep type.' );
	}

	/**
	 * If the parent list is stale too, the scoped retry resolves nothing and the
	 * fallback widens all the way.
	 */
	public function test_widens_fully_when_parent_is_also_stale() {
		$provider = $this->make_provider(
			function ( $args ) {
				if ( ! empty( $args['ids'] ) || ! empty( $args['parent_id'] ) ) {
					return new WP_Error( 'bad_id', 'foreign id' );
				}
				return [
					[
						'id'   => '9',
						'name' => 'Segment A',
					],
				];
			}
		);
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'       => [ 'mc_dead' ],
				'parent_id' => 'mc_list',
				'type'      => 'sublist',
			],
			true
		);
		$this->assertSame(
			[
				[
					'id'   => '9',
					'name' => 'Segment A',
				],
			],
			$result
		);
		$this->assertCount( 3, $this->calls, 'A stale parent must widen in two stages.' );
		$this->assertSame( 'mc_list', $this->calls[1]['parent_id'], 'The first retry keeps the parent scope.' );
		$this->assertArrayNotHasKey( 'ids', $this->calls[2], 'The full retry drops ids.' );
		$this->assertArrayNotHasKey( 'parent_id', $this->calls[2], 'The full retry drops parent_id.' );
		$this->assertSame( 'sublist', $this->calls[2]['type'], 'The full retry keeps type.' );
	}

	/**
	 * When every fetch errors the ESP is genuinely unreachable, so the error is
	 * returned for the caller to surface. The error reported is the one from the
	 * lookup the caller actually asked for.
	 */
	public function test_returns_error_when_untargeted_fetch_also_fails() {
		$provider = $this->make_provider(
			function ( $args ) {
				return new WP_Error(
					empty( $args['ids'] ) ? 'esp_down_untargeted' : 'esp_down',
					'unreachable'
				);
			}
		);
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'  => [ '5' ],
				'type' => 'list',
			],
			true
		);
		$this->assertWPError( $result );
		$this->assertSame( 'esp_down', $result->get_error_code(), 'The original failure is the one worth reporting.' );
		$this->assertCount( 2, $this->calls );
	}

	/**
	 * An outage that returns an empty array rather than an error must still
	 * surface as an empty result, not as a silent success carrying stale data.
	 */
	public function test_returns_empty_when_nothing_resolves() {
		$provider = $this->make_provider(
			function () {
				return [];
			}
		);
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'  => [ '5' ],
				'type' => 'list',
			],
			true
		);
		$this->assertSame( [], $result );
		$this->assertCount( 2, $this->calls );
	}

	/**
	 * With no targeting keys there is nothing to fall back from: an error is
	 * returned unchanged and no retry happens (e.g. a plain search request).
	 */
	public function test_no_targeting_keys_returns_error_without_retry() {
		$provider = $this->make_provider(
			function () {
				return new WP_Error( 'search_failed', 'nope' );
			}
		);
		$result = $provider->get_send_lists_with_fallback( [ 'type' => 'list' ], true );
		$this->assertWPError( $result );
		$this->assertCount( 1, $this->calls, 'Without targeting keys there is no retry.' );
	}

	/**
	 * A legitimately empty untargeted fetch is not a fallback case and must not
	 * be retried into oblivion.
	 */
	public function test_no_targeting_keys_returns_empty_without_retry() {
		$provider = $this->make_provider(
			function () {
				return [];
			}
		);
		$result = $provider->get_send_lists_with_fallback( [ 'type' => 'list' ], true );
		$this->assertSame( [], $result );
		$this->assertCount( 1, $this->calls, 'Without targeting keys there is no retry.' );
	}

	/**
	 * End-to-end against the real ActiveCampaign provider, with only the HTTP
	 * layer mocked.
	 *
	 * This is the test the stubs above cannot stand in for: it proves a real
	 * provider actually produces the failure shape the fallback keys on, and
	 * that a stale id therefore recovers instead of erroring the editor out.
	 */
	public function test_active_campaign_stale_id_recovers_through_fallback() {
		$provider = Newspack_Newsletters_Active_Campaign::instance();
		$provider->set_api_credentials(
			[
				'url' => 'https://example.api-us1.com',
				'key' => 'test-key',
			]
		);

		// get_lists() memoizes the full set; clear it so this test starts cold.
		$memo = new ReflectionProperty( Newspack_Newsletters_Active_Campaign::class, 'lists' );
		$memo->setAccessible( true );
		$memo->setValue( $provider, null );

		$requested_ids = [];
		$mock_http     = function ( $preempt, $args, $url ) use ( &$requested_ids ) {
			$query = [];
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$requested_ids[] = $query['ids'] ?? null;

			// The stored id belongs to a previously-connected ESP: AC reports it
			// as a failed lookup rather than an empty result.
			if ( isset( $query['ids'] ) && 'all' !== $query['ids'] ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'result_code'    => 0,
							'result_message' => 'Nothing is returned',
						]
					),
				];
			}

			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'result_code' => 1,
						'0'           => [
							'id'   => '42',
							'name' => 'Newsroom Main',
						],
					]
				),
			];
		};

		add_filter( 'pre_http_request', $mock_http, 10, 3 );
		$result = $provider->get_send_lists_with_fallback(
			[
				'ids'  => [ 'mc_audience_from_old_esp' ],
				'type' => 'list',
			],
			true
		);
		remove_filter( 'pre_http_request', $mock_http, 10 );
		$memo->setValue( $provider, null );

		$this->assertNotWPError( $result, 'A stale id must not error the editor out.' );
		$this->assertCount( 1, $result, 'The untargeted retry supplies the selectable lists.' );
		$this->assertSame( '42', $result[0]['id'] );
		$this->assertSame(
			[ 'mc_audience_from_old_esp', 'all' ],
			$requested_ids,
			'The targeted lookup is tried first, then widened.'
		);
	}
}
