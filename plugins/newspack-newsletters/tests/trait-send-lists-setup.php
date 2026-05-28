<?php
/**
 * Class Newsletters Test Send_List
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Send_List;
use Newspack\Newsletters\Send_Lists;

/**
 * Traits for the Send_List and Send_Lists test classes
 */
trait Send_Lists_Setup {
	/**
	 * Array of send list config objects for testing.
	 *
	 * @var array
	 */
	public static $configs = [
		// Missing required properties, plus invalid values.
		'invalid'         => [
			'provider' => 'invalid_provider',
			'type'     => 'invalid_type',
		],
		'valid_list'      => [
			'provider'    => 'mailchimp',
			'type'        => 'list',
			'id'          => '123',
			'name'        => 'Valid List',
			'entity_type' => 'Audience',
			'count'       => 100,
		],
		// Missing parent ID.
		'invalid_sublist' => [
			'provider'    => 'mailchimp',
			'type'        => 'sublist',
			'id'          => '456',
			'name'        => 'Valid Sublist',
			'entity_type' => 'Group',
			'count'       => 50,
		],
		'valid_sublist'   => [
			'provider'    => 'mailchimp',
			'type'        => 'sublist',
			'id'          => '456',
			'parent'      => '123',
			'name'        => 'Valid Sublist',
			'entity_type' => 'Group',
			'count'       => 50,
		],
	];
}
