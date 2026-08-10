<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for ActiveCampaign custom-field matching during contact upsert.
 *
 * Background: `add_contact()` registers metadata fields on ActiveCampaign
 * before syncing the contact. A field is looked up by its generated perstag;
 * when an AC admin has renamed a field's perstag, the lookup misses, the
 * field-create call fails ("There is already a field with this title"), and
 * the whole signup aborts. Fields must also match by title, the payload must
 * carry the field's actual perstag, and a field-create failure must never
 * block the contact sync.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test ActiveCampaign field matching in add_contact().
 */
class ActiveCampaignFieldMatchingTest extends WP_UnitTestCase {

	/**
	 * Every ActiveCampaign action invoked through the mocked HTTP layer, in order.
	 *
	 * @var string[]
	 */
	private $called_actions = [];

	/**
	 * Body of the last contact_sync/contact_add request.
	 *
	 * @var array
	 */
	private $contact_payload = [];

	/**
	 * Fields the mocked AC account reports via GET /api/3/fields.
	 *
	 * @var array
	 */
	private $remote_fields = [];

	/**
	 * Set up: configure credentials and intercept all outbound HTTP.
	 */
	public function set_up() {
		parent::set_up();
		$this->called_actions  = [];
		$this->contact_payload = [];
		$this->remote_fields   = [];
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
	 * Intercept outbound requests and play an AC account with $remote_fields.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    HTTP request arguments.
	 * @param string $url     Request URL.
	 *
	 * @return array
	 */
	public function mock_http( $preempt, $args, $url ) {
		$respond = function ( $code, $body ) {
			return [
				'response' => [
					'code'    => $code,
					'message' => 200 === $code ? 'OK' : 'Unprocessable Entity',
				],
				'body'     => wp_json_encode( $body ),
			];
		};

		if ( false !== strpos( $url, '/api/3/fields' ) && 'GET' === $args['method'] ) {
			$this->called_actions[] = 'v3:fields:list';
			return $respond(
				200,
				[
					'fields' => $this->remote_fields,
					'meta'   => [ 'total' => count( $this->remote_fields ) ],
				]
			);
		}
		if ( false !== strpos( $url, '/api/3/fields' ) && 'POST' === $args['method'] ) {
			$this->called_actions[] = 'v3:fields:create';
			return $respond(
				422,
				[
					'errors' => [
						[
							'code'  => 'duplicate',
							'title' => 'There is already a field with this title',
						],
					],
				]
			);
		}
		if ( false !== strpos( $url, '/api/3/contacts' ) ) {
			$this->called_actions[] = 'v3:contacts';
			return $respond( 200, [ 'contacts' => [] ] );
		}
		if ( false !== strpos( $url, 'admin/api.php' ) ) {
			$action                 = isset( $args['body']['api_action'] ) ? $args['body']['api_action'] : 'v1:unknown';
			$this->called_actions[] = $action;
			if ( in_array( $action, [ 'contact_add', 'contact_sync', 'contact_edit' ], true ) ) {
				$this->contact_payload = $args['body'];
			}
			return $respond( 200, [ 'result_code' => 1, 'subscriber_id' => 42 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		$this->called_actions[] = 'unmatched:' . $url;
		return $respond( 200, [ 'result_code' => 1 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * A field whose perstag was renamed on the AC side must be matched by
	 * title, and the contact payload must carry the field's ACTUAL perstag.
	 */
	public function test_field_matched_by_title_when_perstag_renamed() {
		$this->remote_fields = [
			[
				'id'      => '7',
				'title'   => 'Newsletter Subscription Method',
				'perstag' => 'NEWSLETTERSSUBSCRIPTIONMETHOD',
				'type'    => 'text',
			],
		];

		// phpcs:ignore phpcsSniffs.Newsletters.ForbiddenMethods.PossibleForbiddenContactsMethods, phpcsSniffs.Newsletters.ForbiddenContactsMethods.ForbiddenContactsMethods -- this test exercises the provider method itself.
		$result = Newspack_Newsletters_Active_Campaign::instance()->add_contact(
			[
				'email'    => 'reader@example.net',
				'metadata' => [ 'Newsletter Subscription Method' => 'newsletter-block' ],
			],
			'1'
		);

		$this->assertFalse( is_wp_error( $result ), 'Signup must not fail when a field title exists with a renamed perstag. Got: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$this->assertNotContains( 'v3:fields:create', $this->called_actions, 'No field-create should be attempted when a field with the same title exists.' );
		$this->assertArrayHasKey( 'field[%NEWSLETTERSSUBSCRIPTIONMETHOD%,0]', $this->contact_payload, 'Contact payload must use the existing field\'s actual perstag.' );
	}

	/**
	 * A failed field-create must not block the contact sync.
	 */
	public function test_field_create_failure_does_not_block_signup() {
		$this->remote_fields = []; // Field genuinely missing; create will fail with 422.

		// phpcs:ignore phpcsSniffs.Newsletters.ForbiddenMethods.PossibleForbiddenContactsMethods, phpcsSniffs.Newsletters.ForbiddenContactsMethods.ForbiddenContactsMethods -- this test exercises the provider method itself.
		$result = Newspack_Newsletters_Active_Campaign::instance()->add_contact(
			[
				'email'    => 'reader2@example.net',
				'metadata' => [ 'Newsletter Subscription Method' => 'newsletter-block' ],
			],
			'1'
		);

		$this->assertFalse( is_wp_error( $result ), 'A field-create failure must not abort the signup.' );
		$this->assertContains( 'v3:fields:create', $this->called_actions, 'Field creation should have been attempted.' );
		$this->assertTrue( in_array( 'contact_add', $this->called_actions, true ) || in_array( 'contact_sync', $this->called_actions, true ), 'Contact sync must still run after a failed field-create.' );
	}

	/**
	 * The perstag-match fast path is unchanged: no field-create attempted,
	 * generated perstag used.
	 */
	public function test_field_matched_by_perstag_unchanged() {
		$this->remote_fields = [
			[
				'id'      => '7',
				'title'   => 'Newsletter Subscription Method',
				'perstag' => 'NEWSLETTER_SUBSCRIPTION_METHOD',
				'type'    => 'text',
			],
		];

		// phpcs:ignore phpcsSniffs.Newsletters.ForbiddenMethods.PossibleForbiddenContactsMethods, phpcsSniffs.Newsletters.ForbiddenContactsMethods.ForbiddenContactsMethods -- this test exercises the provider method itself.
		$result = Newspack_Newsletters_Active_Campaign::instance()->add_contact(
			[
				'email'    => 'reader3@example.net',
				'metadata' => [ 'Newsletter Subscription Method' => 'newsletter-block' ],
			],
			'1'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertNotContains( 'v3:fields:create', $this->called_actions );
		$this->assertArrayHasKey( 'field[%NEWSLETTER_SUBSCRIPTION_METHOD%,0]', $this->contact_payload );
	}

	/**
	 * Title matching must survive malformed field rows: array_column() skips
	 * rows missing the key and reindexes, which can map a match back to the
	 * wrong field (and thus the wrong perstag).
	 */
	public function test_title_match_survives_field_rows_without_title() {
		$this->remote_fields = [
			[
				'id'      => '3',
				'perstag' => 'UNRELATED_FIELD',
				'type'    => 'text',
				// No title key at all.
			],
			[
				'id'    => '5',
				'title' => 'Some Broken Field',
				'type'  => 'text',
				// Title but no perstag: unusable for payload, must not match.
			],
			[
				'id'      => '7',
				'title'   => 'newsletter subscription method ',
				'perstag' => 'NEWSLETTERSSUBSCRIPTIONMETHOD',
				'type'    => 'text',
				// Re-cased + padded title: AC treats titles as duplicates
				// case-insensitively, so this must still match.
			],
		];

		// phpcs:ignore phpcsSniffs.Newsletters.ForbiddenMethods.PossibleForbiddenContactsMethods, phpcsSniffs.Newsletters.ForbiddenContactsMethods.ForbiddenContactsMethods -- this test exercises the provider method itself.
		$result = Newspack_Newsletters_Active_Campaign::instance()->add_contact(
			[
				'email'    => 'reader4@example.net',
				'metadata' => [ 'Newsletter Subscription Method' => 'newsletter-block' ],
			],
			'1'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertArrayHasKey( 'field[%NEWSLETTERSSUBSCRIPTIONMETHOD%,0]', $this->contact_payload, 'Title match must resolve to the matched field\'s perstag, not a reindexed neighbor\'s.' );
		$this->assertArrayNotHasKey( 'field[%UNRELATED_FIELD%,0]', $this->contact_payload, 'Value must not be written to an unrelated field.' );
	}

	/**
	 * An empty-string perstag is as unusable as a missing one: it yields no
	 * valid payload key, and two such fields would collide on `field[%%,0]`.
	 * A row like that must never win a title match.
	 */
	public function test_title_match_skips_field_rows_with_empty_perstag() {
		$this->remote_fields = [
			[
				'id'      => '4',
				'title'   => 'Newsletter Subscription Method',
				'perstag' => '',
				'type'    => 'text',
				// Matching title, but an empty perstag: unusable, must not match.
			],
			[
				'id'      => '9',
				'title'   => 'Newsletter Subscription Method',
				'perstag' => 'NEWSLETTERSSUBSCRIPTIONMETHOD',
				'type'    => 'text',
			],
		];

		// phpcs:ignore phpcsSniffs.Newsletters.ForbiddenMethods.PossibleForbiddenContactsMethods, phpcsSniffs.Newsletters.ForbiddenContactsMethods.ForbiddenContactsMethods -- this test exercises the provider method itself.
		$result = Newspack_Newsletters_Active_Campaign::instance()->add_contact(
			[
				'email'    => 'reader5@example.net',
				'metadata' => [ 'Newsletter Subscription Method' => 'newsletter-block' ],
			],
			'1'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertArrayNotHasKey( 'field[%%,0]', $this->contact_payload, 'An empty perstag must never produce a payload key.' );
		$this->assertArrayHasKey( 'field[%NEWSLETTERSSUBSCRIPTIONMETHOD%,0]', $this->contact_payload, 'Title match must skip the empty-perstag row and resolve to the usable field.' );
		$this->assertNotContains( 'v3:fields:create', $this->called_actions, 'A usable field with the same title exists, so no create should be attempted.' );
	}

	/**
	 * A metadata key that sanitizes down to an empty perstag has nowhere valid
	 * to write, so it must be skipped outright rather than created — a create
	 * that succeeded would still leave the payload key malformed.
	 */
	public function test_metadata_key_with_empty_generated_perstag_is_skipped() {
		$this->remote_fields = [];

		// phpcs:ignore phpcsSniffs.Newsletters.ForbiddenMethods.PossibleForbiddenContactsMethods, phpcsSniffs.Newsletters.ForbiddenContactsMethods.ForbiddenContactsMethods -- this test exercises the provider method itself.
		$result = Newspack_Newsletters_Active_Campaign::instance()->add_contact(
			[
				'email'    => 'reader6@example.net',
				'metadata' => [ '!!!' => 'newsletter-block' ],
			],
			'1'
		);

		$this->assertFalse( is_wp_error( $result ), 'An unusable metadata key must not abort the signup.' );
		$this->assertNotContains( 'v3:fields:create', $this->called_actions, 'No field-create should be attempted for a key with no usable perstag.' );
		$this->assertArrayNotHasKey( 'field[%%,0]', $this->contact_payload, 'An empty perstag must never produce a payload key.' );
		$this->assertTrue( in_array( 'contact_add', $this->called_actions, true ) || in_array( 'contact_sync', $this->called_actions, true ), 'Contact sync must still run after skipping an unusable field.' );
	}
}
