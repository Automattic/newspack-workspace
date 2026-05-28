<?php
/**
 * Class Newsletters Test Send_List
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Send_List;

/**
 * Tests the Send_List class
 */
class Send_List_Test extends WP_UnitTestCase {

	use Send_Lists_Setup;

	/**
	 * Test constructor.
	 */
	public function test_constructor() {
		$list    = new Send_List( self::$configs['valid_list'] );
		$sublist = new Send_List( self::$configs['valid_sublist'] );
		$this->assertInstanceOf( Send_List::class, $list );
		$this->assertInstanceOf( Send_List::class, $sublist );
	}

	/**
	 * Test constructor with invalid input.
	 */
	public function test_constructor_with_invalid() {
		$this->expectException( \InvalidArgumentException::class );
		$list = new Send_List( self::$configs['invalid'] );
	}

	/**
	 * Test config with unsupported properties.
	 */
	public function test_get_config_schema() {
		$config = self::$configs['valid_list'];
		$config['unsupported_prop'] = 'unsupported';

		$list        = new Send_List( $config );
		$list_config = $list->to_array();
		$schema      = $list->get_config_schema();

		// Unsupported props are ignored.
		$this->assertArrayNotHasKey( 'unsupported_prop', $list_config );
		foreach ( $list_config as $key => $value ) {
			$this->assertArrayHasKey( $key, $schema['properties'] );
		}
	}

	/**
	 * Test get methods.
	 */
	public function test_get() {
		$config   = self::$configs['valid_sublist'];
		$sublist = new Send_List( $config );
		$this->assertSame( $config['provider'], $sublist->get_provider() );
		$this->assertSame( $config['id'], $sublist->get_id() );
		$this->assertSame( $config['type'], $sublist->get_type() );
		$this->assertSame( $config['entity_type'], $sublist->get_entity_type() );
		$this->assertSame( $config['name'], $sublist->get_name() );
	}

	/**
	 * Test type casting.
	 */
	public function test_type() {
		$config          = self::$configs['valid_list'];
		$config['id']    = 123; // Integer.
		$config['count'] = '100'; // String.
		$list           = new Send_List( $config );
		$schema         = $list->get_config_schema();
		$list_config    = $list->to_array();
		foreach ( $list_config as $key => $value ) {
			$this->assertSame( gettype( $value ), $schema['properties'][ $key ]['type'] );
		}
	}

	/**
	 * Test dynamic and manually-set label + value properties.
	 */
	public function test_dynamic_properties() {
		$list        = new Send_List( self::$configs['valid_list'] );
		$list_config = $list->to_array();
		$this->assertSame( '[AUDIENCE] Valid List (100 contacts)', $list_config['label'] );
		$this->assertSame( $list_config['id'], $list_config['value'] );

		$config          = self::$configs['valid_sublist'];
		$config['label'] = 'Custom Label';
		$config['value'] = 'Custom Value';

		$sublist        = new Send_List( $config );
		$sublist_config = $sublist->to_array();
		$this->assertSame( 'Custom Label', $sublist_config['label'] );
		$this->assertSame( 'Custom Value', $sublist_config['value'] );
	}
}
