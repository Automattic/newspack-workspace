<?php
/**
 * Tests the plugin management functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Plugin_Manager;

/**
 * Test plugin management functionality.
 */
class Newspack_Test_Plugin_Manager extends WP_UnitTestCase {
	/**
	 * Test Plugin_Manager::get_managed_plugins.
	 */
	public function test_get_managed_plugins() {
		$managed_plugins = Plugin_Manager::get_managed_plugins();

		$this->assertArrayHasKey( 'jetpack', $managed_plugins );

		$expected_jetpack_info = [
			'Name'        => __( 'Jetpack', 'newspack' ),
			'Description' => esc_html__( 'Bring the power of the WordPress.com cloud to your self-hosted WordPress. Jetpack enables you to connect your blog to a WordPress.com account to use the powerful features normally only available to WordPress.com users.', 'newspack' ),
			'Author'      => 'Automattic',
			'PluginURI'   => 'https://jetpack.com/',
			'AuthorURI'   => 'https://automattic.com/',
			'Download'    => 'wporg',
			'EditPath'    => 'admin.php?page=jetpack',
			'HandoffLink' => 'http://example.org/wp-admin/admin.php?page=jetpack',
			'Slug'        => 'jetpack',
			'TextDomain'  => '',
			'DomainPath'  => '',
			'Version'     => '',
			'Status'      => 'uninstalled',
		];
		$this->assertEquals( $expected_jetpack_info, $managed_plugins['jetpack'] );
	}

	/**
	 * Test the plugin-slug parser.
	 */
	public function test_get_plugin_slug() {
		$this->assertEquals( 'hello-dolly', Plugin_Manager::get_plugin_slug( 'https://downloads.wordpress.org/plugin/hello-dolly.1.6.zip' ) );
		$this->assertEquals( 'hello-dolly', Plugin_Manager::get_plugin_slug( 'https://downloads.wordpress.org/plugin/hello-dolly.zip/' ) );
		$this->assertEquals( 'hello-dolly', Plugin_Manager::get_plugin_slug( 'https://downloads.wordpress.org/plugin/hello-dolly.zip?foo=blah&1' ) );
		$this->assertEquals( false, Plugin_Manager::get_plugin_slug( 'https://downloads.wordpress.org/plugin/hello-dolly' ) );
		$this->assertEquals( false, Plugin_Manager::get_plugin_slug( 'https://downloads.wordpress.org/plugin/hello-dolly.tar' ) );
		$this->assertEquals( 'hello-dolly', Plugin_Manager::get_plugin_slug( 'hello-dolly' ) );
		$this->assertEquals( false, Plugin_Manager::get_plugin_slug( '' ) );
		$this->assertEquals( false, Plugin_Manager::get_plugin_slug( true ) );
		$this->assertEquals( false, Plugin_Manager::get_plugin_slug( new WP_Error() ) );
		$this->assertEquals( false, Plugin_Manager::get_plugin_slug( 20 ) );
	}
}
