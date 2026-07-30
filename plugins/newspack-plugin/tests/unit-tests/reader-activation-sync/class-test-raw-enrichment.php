<?php
/**
 * Tests for raw-key metadata enrichment helpers.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Reader_Activation_Sync;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Sync\Metadata;

/**
 * Raw enrichment tests.
 *
 * @group raw-enrichment
 */
class Test_Raw_Enrichment extends \WP_UnitTestCase {

	/**
	 * Registration data is looked up from user meta and added as raw keys,
	 * with connected_account derived for SSO registration methods.
	 */
	public function test_registration_data_added_from_user_meta_as_raw_keys() {
		$user_id = self::factory()->user->create();
		\update_user_meta( $user_id, Reader_Activation::REGISTRATION_METHOD, 'google' );
		\update_user_meta( $user_id, Reader_Activation::REGISTRATION_PAGE, 'https://example.com/join' );

		$metadata = Metadata::add_registration_data_raw( [ 'account' => $user_id ] );

		$this->assertSame( 'google', $metadata['registration_method'] );
		$this->assertSame( 'https://example.com/join', $metadata['registration_page'] );
		// 'google' is an SSO method, so connected_account is derived.
		$this->assertSame( 'google', $metadata['connected_account'] );
	}

	/**
	 * Without an 'account' key resolving to a user, metadata passes through unchanged.
	 */
	public function test_registration_data_noop_without_account() {
		$this->assertSame( [ 'foo' => 'bar' ], Metadata::add_registration_data_raw( [ 'foo' => 'bar' ] ) );
	}

	/**
	 * UTM params from the signup page URL are expanded into raw, suffixed
	 * `signup_page_utm_*` keys; non-UTM query params are ignored.
	 */
	public function test_utm_expansion_from_signup_url_uses_raw_suffixed_keys() {
		$metadata = Metadata::add_utm_data_raw(
			[ 'current_page_url' => 'https://example.com/signup?utm_source=fb&utm_medium=social&other=x' ]
		);

		$this->assertSame( 'fb', $metadata['signup_page_utm_source'] );
		$this->assertSame( 'social', $metadata['signup_page_utm_medium'] );
		$this->assertArrayNotHasKey( 'signup_page_utm_other', $metadata );
	}

	/**
	 * When a payment page URL is present, UTM expansion prefers it over the
	 * signup page and emits `payment_page_utm_*` keys instead.
	 */
	public function test_utm_expansion_prefers_payment_page() {
		$metadata = Metadata::add_utm_data_raw(
			[
				'payment_page'     => 'https://example.com/donate?utm_campaign=year-end',
				'current_page_url' => 'https://example.com/signup?utm_source=fb',
			]
		);

		$this->assertSame( 'year-end', $metadata['payment_page_utm_campaign'] );
		$this->assertArrayNotHasKey( 'signup_page_utm_source', $metadata );
	}

	/**
	 * UTM expansion never overwrites a value already present in the metadata.
	 */
	public function test_utm_expansion_does_not_overwrite_existing() {
		$metadata = Metadata::add_utm_data_raw(
			[
				'current_page_url'       => 'https://example.com/signup?utm_source=fb',
				'signup_page_utm_source' => 'original',
			]
		);

		$this->assertSame( 'original', $metadata['signup_page_utm_source'] );
	}
}
