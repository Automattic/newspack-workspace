<?php
/**
 * Tests the Newspack Newsletters configuration manager.
 *
 * @package Newspack\Tests
 */

use Newspack\Configuration_Managers;

require_once dirname( __DIR__ ) . '/mocks/newsletters-mocks.php';

/**
 * Tests the Newspack Newsletters configuration manager.
 */
class Newspack_Test_Newsletters_Configuration_Manager extends WP_UnitTestCase {

	/**
	 * Per-test setup.
	 */
	public function set_up() {
		parent::set_up();
		Newspack_Newsletters_Contacts::reset_calls();
	}

	/**
	 * Per-test teardown.
	 */
	public function tear_down() {
		Newspack_Newsletters_Contacts::reset_calls();
		parent::tear_down();
	}

	/**
	 * The deprecated add_contact() raises a deprecation notice, then upserts the contact.
	 */
	public function test_add_contact_is_deprecated_and_upserts() {
		$this->setExpectedDeprecated( 'Newspack\Newspack_Newsletters_Configuration_Manager::add_contact' );

		$configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-newsletters' );
		$contact               = [ 'email' => 'test-reader' ];
		$result                = $configuration_manager->add_contact( $contact, 'list-1' );

		$this->assertTrue( $result );
		$this->assertCount( 1, Newspack_Newsletters_Contacts::$upsert_calls );
		$this->assertSame( $contact, Newspack_Newsletters_Contacts::$upsert_calls[0]['contact'] );
		$this->assertSame( 'list-1', Newspack_Newsletters_Contacts::$upsert_calls[0]['master_list_id'] );
	}
}
