<?php
/**
 * Tests for raw-key metadata enrichment helpers.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Reader_Activation_Sync;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Sync\Field_Registry;
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

	/**
	 * Guards get_utm_key() against a get_raw_keys() match that get_key()
	 * cannot resolve. get_raw_keys() is id-space — every id an integration
	 * has enabled, available or not — while get_key() only knows currently
	 * available fields and returns false for the rest, as the legacy payment
	 * UTM field is while WooCommerce is inactive. On PHP 8, strpos( $key,
	 * false ) coerces the needle to '' and matches at position 0, so without
	 * an explicit guard every key looked like a match once get_key() missed,
	 * and get_utm_key() echoed the unrelated key straight back.
	 */
	public function test_get_utm_key_rejects_an_unresolvable_raw_key_match() {
		// Stands in for a field whose declaring class is unavailable — the
		// legacy payment fields on a site without WooCommerce: the registry
		// (and so id-space) still carries the raw key, the available-only keys
		// map behind get_key() does not.
		$hide_from_available_map = function ( $keys, $only_available ) {
			if ( $only_available ) {
				unset( $keys['payment_page_utm'] );
			}
			return $keys;
		};
		\add_filter( 'newspack_ras_metadata_keys', $hide_from_available_map, 10, 2 );
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', [ 'v1:payment_page_utm' ] );
		Field_Registry::reset();

		$this->assertContains(
			'payment_page_utm',
			Metadata::get_raw_keys(),
			'Precondition: the enabled id is still visible in id-space.'
		);
		$this->assertFalse(
			Metadata::get_key( 'payment_page_utm' ),
			'Precondition: the unavailable field has no prefixed key.'
		);
		$this->assertFalse( Metadata::get_utm_key( 'Some_Random_Key' ) );

		\remove_filter( 'newspack_ras_metadata_keys', $hide_from_available_map, 10 );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		Field_Registry::reset();
	}
}
