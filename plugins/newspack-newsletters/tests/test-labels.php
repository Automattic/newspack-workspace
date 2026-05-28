<?php
/**
 * Class Newsletters Test provider labels
 *
 * @package Newspack_Newsletters
 */

/**
 * Newsletters Labels Test.
 */
class Newsletters_Labels_Test extends WP_UnitTestCase {

	/**
	 * Test the labels inheritance
	 */
	public function test_labels_inheritance() {
		$ac_labels = Newspack_Newsletters_Active_Campaign::get_labels();
		$this->assertSame( 'Active Campaign', $ac_labels['name'] );
		$this->assertSame( 'list', $ac_labels['list'] );

		$ac_labels = Newspack_Newsletters_Constant_Contact::get_labels();
		$this->assertSame( 'Constant Contact', $ac_labels['name'] );
		$this->assertSame( 'list or segment', $ac_labels['list'] );
	}

	/**
	 * Data provider for test_get_label
	 *
	 * @return array
	 */
	public function get_label_provider() {
		return [
			'AC list'        => [
				'Newspack_Newsletters_Active_Campaign',
				'list',
				'list',
			],
			'AC invalid'     => [
				'Newspack_Newsletters_Active_Campaign',
				'invalid',
				'',
			],
			'mailchimp list' => [
				'Newspack_Newsletters_Mailchimp',
				'list',
				'audience',
			],
			'mailchimp name' => [
				'Newspack_Newsletters_Mailchimp',
				'name',
				'Mailchimp',
			],
		];
	}

	/**
	 * Test label method
	 *
	 * @param string $provider The provider class name.
	 * @param string $key The label key.
	 * @param string $expected The expected value.
	 * @return void
	 * @dataProvider get_label_provider
	 */
	public function test_get_label( $provider, $key, $expected ) {
		$this->assertSame( $expected, $provider::label( $key ) );
	}
}
