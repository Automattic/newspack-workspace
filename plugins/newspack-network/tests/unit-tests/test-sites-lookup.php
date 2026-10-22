<?php
/**
 * Class Test_Sites_Lookup
 *
 * @package Newspack_Network
 */

use Newspack_Network\Hub\Node as Hub_Node;
use Newspack_Network\Site_Role;
use Newspack_Network\Utils\Sites;

/**
 * Test the site name and link helpers on Utils\Sites.
 */
class Test_Sites_Lookup extends WP_UnitTestCase {

	/**
	 * Set up a node that knows about a hub and one other node.
	 */
	public function set_up() {
		parent::set_up();

		update_option( Site_Role::OPTION_NAME, Site_Role::NODE_ROLE );
		update_option( 'newspack_node_hub_url', 'https://hub.example.test' );
		update_option( 'newspack_network_hub_name', 'Hub Gazette' );
		update_option(
			Hub_Node::HUB_NODES_SYNCED_OPTION,
			[
				[
					'id'    => 2,
					'title' => 'Node Daily',
					'url'   => 'http://node-b.example.test',
				],
			]
		);

		Sites::reset_cache();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( Site_Role::OPTION_NAME );
		delete_option( 'newspack_node_hub_url' );
		delete_option( 'newspack_network_hub_name' );
		delete_option( Hub_Node::HUB_NODES_SYNCED_OPTION );
		Sites::reset_cache();
		parent::tear_down();
	}

	/**
	 * Scheme, host case and a trailing slash don't stop a URL from matching.
	 */
	public function test_name_resolves_across_scheme_and_trailing_slash() {
		$this->assertSame( 'Node Daily', Sites::get_site_name( 'https://node-b.example.test/' ) );
		$this->assertSame( 'Hub Gazette', Sites::get_site_name( 'https://hub.example.test' ) );
	}

	/**
	 * A site that isn't in the network list shows as its bare domain.
	 */
	public function test_unknown_site_falls_back_to_bare_domain() {
		$this->assertSame( 'gone.example.test', Sites::get_site_name( 'https://gone.example.test/' ) );
	}

	/**
	 * URLs normalize to lowercase host plus path, without scheme or trailing slash.
	 */
	public function test_normalize_url() {
		$this->assertSame( 'node-b.example.test', Sites::normalize_url( 'HTTPS://Node-B.example.test/' ) );
		$this->assertSame( 'node-b.example.test/blog', Sites::normalize_url( 'http://node-b.example.test/blog/' ) );
	}

	/**
	 * The current site is recognized whatever its scheme or trailing slash.
	 */
	public function test_is_current_site() {
		$this->assertTrue( Sites::is_current_site( trailingslashit( get_bloginfo( 'url' ) ) ) );
		$this->assertFalse( Sites::is_current_site( 'https://node-b.example.test' ) );
	}

	/**
	 * The edit link points at the subscription's edit screen on the other site.
	 */
	public function test_subscription_edit_url() {
		$this->assertSame(
			'https://node-b.example.test/wp-admin/post.php?post=500&action=edit',
			Sites::get_subscription_edit_url( 'https://node-b.example.test/', 500 )
		);
	}

	/**
	 * Site names are remembered until the cache is reset.
	 */
	public function test_site_names_are_cached_until_reset() {
		$this->assertSame( 'Hub Gazette', Sites::get_site_name( 'https://hub.example.test' ) );

		update_option( 'newspack_network_hub_name', 'Renamed Hub' );
		$this->assertSame( 'Hub Gazette', Sites::get_site_name( 'https://hub.example.test' ) );

		Sites::reset_cache();
		$this->assertSame( 'Renamed Hub', Sites::get_site_name( 'https://hub.example.test' ) );
	}

	/**
	 * The public site list stays fresh: only the name lookup is cached.
	 */
	public function test_get_all_sites_is_not_cached() {
		Sites::get_site_name( 'https://hub.example.test' );

		update_option( 'newspack_network_hub_name', 'Renamed Hub' );

		$this->assertSame( 'Renamed Hub', Sites::get_all_sites()[0]['name'] );
	}
}
