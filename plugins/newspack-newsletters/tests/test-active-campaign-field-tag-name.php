<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for resolving an ActiveCampaign custom field's perstag by field name.
 *
 * Newsletter links carry a merge tag for a synced field, and AC substitutes it
 * per recipient. The tag is the field's perstag, which AC generates from the
 * title and an AC admin may rename — so it has to be read back from the
 * account, never derived from the field name.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test Newspack_Newsletters_Active_Campaign::get_field_merge_tag_name().
 */
class ActiveCampaignFieldTagNameTest extends WP_UnitTestCase {

	/**
	 * Fields the mocked ActiveCampaign account reports via GET /api/3/fields.
	 *
	 * @var array
	 */
	private $remote_fields = [];

	/**
	 * Number of field-list requests the mocked HTTP layer served.
	 *
	 * @var int
	 */
	private $list_calls = 0;

	/**
	 * Set up: credentials, intercepted HTTP, and a clean tag cache.
	 */
	public function set_up() {
		parent::set_up();
		$this->remote_fields = [];
		$this->list_calls    = 0;
		delete_transient( 'np_nl_field_tag_' . md5( 'NP_Account' ) );
		delete_transient( 'np_nl_field_tag_' . md5( 'NP_Missing' ) );
		// The per-request memo is a static property (see
		// $field_merge_tag_name_memo's docblock), so it otherwise survives
		// across every test in this process, not just within one. Reset it
		// directly via reflection; there's no public API for it.
		$memo = new ReflectionProperty( 'Newspack_Newsletters_Active_Campaign', 'field_merge_tag_name_memo' );
		$memo->setAccessible( true );
		$memo->setValue( null, [] );
		Newspack_Newsletters_Active_Campaign::instance()->set_api_credentials(
			[
				'url' => 'https://example.api-us1.com',
				'key' => 'test-key',
			]
		);
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		parent::tear_down();
	}

	/**
	 * Play an ActiveCampaign account holding $remote_fields.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    HTTP request arguments.
	 * @param string $url     Request URL.
	 *
	 * @return array
	 */
	public function mock_http( $preempt, $args, $url ) {
		if ( false !== strpos( $url, '/api/3/fields' ) && 'GET' === $args['method'] ) {
			$this->list_calls++;
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode(
					[
						'fields' => $this->remote_fields,
						'meta'   => [ 'total' => count( $this->remote_fields ) ],
					]
				),
			];
		}
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode( [] ),
		];
	}

	/**
	 * The common case: the perstag AC generated from the field title.
	 */
	public function test_resolves_generated_perstag() {
		$this->remote_fields = [
			[
				'title'   => 'NP_Account',
				'perstag' => 'NP_ACCOUNT',
			],
		];
		$this->assertSame(
			'NP_ACCOUNT',
			Newspack_Newsletters_Active_Campaign::instance()->get_field_merge_tag_name( 'NP_Account' )
		);
	}

	/**
	 * An AC admin renamed the perstag. The title still matches, and the
	 * account's actual perstag is the only value AC will substitute.
	 */
	public function test_resolves_renamed_perstag_by_title() {
		$this->remote_fields = [
			[
				'title'   => 'NP_Account',
				'perstag' => 'READER_ID',
			],
		];
		$this->assertSame(
			'READER_ID',
			Newspack_Newsletters_Active_Campaign::instance()->get_field_merge_tag_name( 'NP_Account' )
		);
	}

	/**
	 * A field that isn't on the account yields no tag, so the caller emits no
	 * param rather than a tag AC would leave unsubstituted.
	 */
	public function test_returns_empty_for_unknown_field() {
		$this->remote_fields = [
			[
				'title'   => 'Something Else',
				'perstag' => 'SOMETHING_ELSE',
			],
		];
		$this->assertSame(
			'',
			Newspack_Newsletters_Active_Campaign::instance()->get_field_merge_tag_name( 'NP_Missing' )
		);
	}

	/**
	 * A row with an empty perstag can't supply a usable merge tag, so it must
	 * never match — mirroring the guard in add_contact().
	 */
	public function test_ignores_field_with_empty_perstag() {
		$this->remote_fields = [
			[
				'title'   => 'NP_Account',
				'perstag' => '',
			],
		];
		$this->assertSame(
			'',
			Newspack_Newsletters_Active_Campaign::instance()->get_field_merge_tag_name( 'NP_Account' )
		);
	}

	/**
	 * Link decoration runs once per link per newsletter render, and the AC
	 * field list is an uncached paginated fetch — so the result must be cached.
	 */
	public function test_caches_resolved_tag() {
		$this->remote_fields = [
			[
				'title'   => 'NP_Account',
				'perstag' => 'NP_ACCOUNT',
			],
		];
		$provider = Newspack_Newsletters_Active_Campaign::instance();
		$provider->get_field_merge_tag_name( 'NP_Account' );
		$provider->get_field_merge_tag_name( 'NP_Account' );
		$this->assertSame( 1, $this->list_calls );
	}

	/**
	 * An empty field name never reaches the API.
	 */
	public function test_empty_field_name_makes_no_request() {
		$this->assertSame(
			'',
			Newspack_Newsletters_Active_Campaign::instance()->get_field_merge_tag_name( '' )
		);
		$this->assertSame( 0, $this->list_calls );
	}

	/**
	 * A dead object-cache drop-in makes get_transient() silently miss every
	 * time, whether or not set_transient() actually persisted anything — a
	 * recurring failure mode on this platform. Without a per-request memo in
	 * front of the transient, link decoration (which resolves the same field
	 * once per link) would repeat the whole paginated fetch for every link in
	 * the newsletter.
	 *
	 * Forces that failure mode directly via the `transient_{$transient}`
	 * filter, which WordPress applies unconditionally to get_transient()'s
	 * return value — unlike `pre_transient_{$transient}`, whose own default
	 * is `false`, so a filter merely returning `false` there is a no-op and
	 * would not actually force a miss against a value that was successfully
	 * set.
	 *
	 * instance() hands back a brand-new object on every call in this test
	 * environment (see its IS_TEST_ENV escape hatch), so resolving twice via
	 * two separate instance() calls also proves the memo is static — an
	 * instance property would not have survived between them.
	 */
	public function test_memoizes_within_a_request_when_transient_cannot_return() {
		$this->remote_fields = [
			[
				'title'   => 'NP_Account',
				'perstag' => 'NP_ACCOUNT',
			],
		];
		$cache_key = 'np_nl_field_tag_' . md5( 'NP_Account' );
		add_filter( "transient_{$cache_key}", '__return_false' );

		try {
			$this->assertSame( 'NP_ACCOUNT', Newspack_Newsletters_Active_Campaign::instance()->get_field_merge_tag_name( 'NP_Account' ) );
			$this->assertSame( 'NP_ACCOUNT', Newspack_Newsletters_Active_Campaign::instance()->get_field_merge_tag_name( 'NP_Account' ) );
		} finally {
			remove_filter( "transient_{$cache_key}", '__return_false' );
		}

		$this->assertSame( 1, $this->list_calls, 'A per-request memo must prevent a second fetch when the transient layer cannot return a cached value.' );
	}
}
