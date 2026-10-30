<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for ActiveCampaign contact read methods.
 *
 * Background: `get_contact_data( $email, true )` exposes the contact's field
 * values under the provider-neutral `metadata` key that callers such as the
 * ESP reader-data pull consume. The ActiveCampaign API mostly omits fields
 * the contact has no value for, but can report an empty value — those must
 * be filtered out in code, so `metadata` carries the same meaning on every
 * provider instead of leaning on API behavior.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test ActiveCampaign contact read methods.
 */
class ActiveCampaignContactMethodsTest extends WP_UnitTestCase {

	/**
	 * Email of the mocked contact.
	 *
	 * @var string
	 */
	const CONTACT_EMAIL = 'with-field-values@example.com';

	/**
	 * Set up: configure credentials and intercept all outbound HTTP.
	 */
	public function set_up() {
		parent::set_up();
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
		// The provider caches contact lookups per email on the (singleton)
		// instance, so drop the entry rather than leak it across tests.
		Newspack_Newsletters_Active_Campaign::instance()->clear_contact_data( self::CONTACT_EMAIL );
		parent::tear_down();
	}

	/**
	 * Intercept outbound requests and play an AC account with one contact.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    HTTP request arguments.
	 * @param string $url     Request URL.
	 *
	 * @return array
	 */
	public function mock_http( $preempt, $args, $url ) {
		$respond = function ( $body ) {
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( $body ),
			];
		};

		// The detail fetch for the found contact, carrying its field values.
		if ( false !== strpos( $url, '/api/3/contacts/101' ) ) {
			return $respond(
				[
					'fieldValues' => [
						[
							'field' => '1',
							'value' => '0',
						],
						[
							'field' => '2',
							'value' => '',
						],
						[
							'field' => '3',
							'value' => 'reader',
						],
					],
				]
			);
		}
		// The field definitions mapping field ids to perstags.
		if ( false !== strpos( $url, '/api/3/fields' ) ) {
			return $respond(
				[
					'fields' => [
						[
							'id'      => '1',
							'perstag' => 'DONATIONS',
						],
						[
							'id'      => '2',
							'perstag' => 'UNSET',
						],
						[
							'id'      => '3',
							'perstag' => 'MEMBERSHIP',
						],
					],
					'meta'   => [ 'total' => 3 ],
				]
			);
		}
		// The search by email resolving the contact id.
		if ( false !== strpos( $url, '/api/3/contacts' ) ) {
			return $respond(
				[
					'contacts' => [
						[
							'id'    => '101',
							'email' => self::CONTACT_EMAIL,
						],
					],
				]
			);
		}
		return $respond( [] );
	}

	/**
	 * An empty value reported by the API is a field the contact hasn't filled
	 * in, not a value — it must not reach `metadata`. A falsy-but-real value
	 * (`'0'` from a number field) is a value the contact has, and stays.
	 *
	 * @return void
	 */
	public function test_metadata_drops_unset_fields_but_keeps_zero() {
		$contact_data = Newspack_Newsletters_Active_Campaign::instance()->get_contact_data( self::CONTACT_EMAIL, true );

		$this->assertArrayNotHasKey( 'UNSET', $contact_data['metadata'], 'A field the contact has no value for is not metadata.' );
		$this->assertArrayHasKey( 'DONATIONS', $contact_data['metadata'], 'Zero is a value the contact has.' );
		$this->assertSame( '0', $contact_data['metadata']['DONATIONS'] );
		$this->assertSame( 'reader', $contact_data['metadata']['MEMBERSHIP'] );
	}
}
