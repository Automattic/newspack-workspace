<?php
/**
 * Tests automated Parse.ly setup features.
 *
 * @package Newspack\Tests
 */

use Newspack\Configuration_Managers;

/**
 * Tests Parse.ly config features.
 */
class Newspack_Test_Parsely_Configuration_Manager extends WP_UnitTestCase {

	/**
	 * Test auto-generation of Parse.ly API key.
	 */
	public function test_api_key_generation() {
		$configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'wp-parsely' );

		update_option( 'siteurl', 'https://example.com' );
		$this->assertEquals( 'example.com', $configuration_manager->get_parsely_api_key() );

		update_option( 'siteurl', 'https://www.example.com' );
		$this->assertEquals( 'example.com', $configuration_manager->get_parsely_api_key() );

		update_option( 'siteurl', 'https://news.example.com/' );
		$this->assertEquals( 'news.example.com', $configuration_manager->get_parsely_api_key() );
	}
}
