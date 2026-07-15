<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for Newspack_Newsletters_Service_Provider::get_send_lists_with_fallback().
 *
 * The fallback exists so a stored send-list/sublist id that belongs to a
 * previously-connected ESP cannot make the editor's campaign retrieval fail
 * unrecoverably. A targeted lookup that errors is retried without its
 * targeting keys; only a genuinely unreachable ESP (the retry also errors)
 * surfaces an error.
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
	 * A targeted fetch that errors falls back to an untargeted fetch, and the
	 * retry strips BOTH ids and parent_id while keeping type.
	 */
	public function test_falls_back_and_strips_ids_and_parent_id() {
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
		$this->assertCount( 2, $this->calls, 'A failed targeted fetch must retry exactly once.' );
		$this->assertArrayNotHasKey( 'ids', $this->calls[1], 'Retry must drop ids.' );
		$this->assertArrayNotHasKey( 'parent_id', $this->calls[1], 'Retry must drop parent_id.' );
		$this->assertSame( 'sublist', $this->calls[1]['type'], 'Retry must keep type.' );
	}

	/**
	 * When both the targeted and the untargeted fetch error, the ESP is
	 * genuinely unreachable, so the error is returned for the caller to surface.
	 */
	public function test_returns_error_when_untargeted_fetch_also_fails() {
		$provider = $this->make_provider(
			function () {
				return new WP_Error( 'esp_down', 'unreachable' );
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
		$this->assertSame( 'esp_down', $result->get_error_code() );
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
}
