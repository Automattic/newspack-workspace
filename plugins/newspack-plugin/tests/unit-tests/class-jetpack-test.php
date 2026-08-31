<?php
/**
 * Tests for Jetpack integration tweaks.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use Newspack\Jetpack;

/**
 * Test class for the Jetpack share-link bot obfuscation tweaks.
 *
 * @group jetpack
 */
class Test_Jetpack extends \WP_UnitTestCase {

	/**
	 * Setup before class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once NEWSPACK_ABSPATH . 'includes/plugins/class-jetpack.php';
	}

	/**
	 * Representative args as Jetpack's Sharing_Source::get_link() collects them via
	 * func_get_args(): [ $url, $text, $accessible_name, $query, $id, $data_attributes ].
	 *
	 * @param string $query The share query string, e.g. 'share=twitter'.
	 * @return array
	 */
	private function get_link_args( $query = 'share=twitter' ) {
		return [
			'https://example.com/a-post/',
			'X',
			'Share on X',
			$query,
			'sharing-twitter-1',
			[],
		];
	}

	/**
	 * The display-query filter should blank the query for round-trip share services,
	 * so the rendered href is the bare (cacheable) permalink.
	 */
	public function test_obfuscate_share_query_blanks_share_service() {
		$this->assertSame(
			'',
			Jetpack::obfuscate_share_query( 'share=twitter', null, 'sharing-twitter-1', $this->get_link_args() )
		);
	}

	/**
	 * The display-query filter should leave non-round-trip queries untouched.
	 */
	public function test_obfuscate_share_query_ignores_non_share_query() {
		$this->assertSame(
			'foo=bar',
			Jetpack::obfuscate_share_query( 'foo=bar', null, 'id', $this->get_link_args( 'foo=bar' ) )
		);
		$this->assertSame(
			'',
			Jetpack::obfuscate_share_query( '', null, 'id', $this->get_link_args( '' ) )
		);
	}

	/**
	 * The data-attributes filter should stash the original share query so the client
	 * script can rebuild the real URL on genuine user interaction.
	 */
	public function test_data_attribute_added_for_share_service() {
		$result = Jetpack::add_obfuscation_data_attribute( [], null, 'sharing-twitter-1', $this->get_link_args() );
		$this->assertArrayHasKey( 'share-query', $result );
		$this->assertSame( 'share=twitter', $result['share-query'] );
	}

	/**
	 * The data-attributes filter should not touch non-round-trip services and should
	 * preserve any attributes already present.
	 */
	public function test_data_attribute_not_added_for_non_share_service() {
		$existing = [ 'foo' => 'bar' ];
		$result   = Jetpack::add_obfuscation_data_attribute( $existing, null, 'id', $this->get_link_args( '' ) );
		$this->assertArrayNotHasKey( 'share-query', $result );
		$this->assertSame( $existing, $result );
	}

	/**
	 * The opt-out filter should disable query blanking entirely.
	 */
	public function test_opt_out_filter_disables_query_blanking() {
		add_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		$this->assertSame(
			'share=twitter',
			Jetpack::obfuscate_share_query( 'share=twitter', null, 'sharing-twitter-1', $this->get_link_args() )
		);
		remove_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
	}

	/**
	 * The opt-out filter should disable the data attribute entirely.
	 */
	public function test_opt_out_filter_disables_data_attribute() {
		add_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		$result = Jetpack::add_obfuscation_data_attribute( [], null, 'sharing-twitter-1', $this->get_link_args() );
		$this->assertArrayNotHasKey( 'share-query', $result );
		remove_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
	}
}
