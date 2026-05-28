<?php
/**
 * The Segments Schema
 *
 * @package Newspack
 */

namespace Newspack\Campaigns\Schemas;

use Newspack\Campaigns\Schema;
use Newspack_Segments_Model;

/**
 * The Segments Schema
 */
class Segments extends Schema {

	/**
	 * Get the schema.
	 *
	 * @return array The schema.
	 */
	public static function get_schema() {
		$schema               = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name' => [
					'name'     => 'name',
					'type'     => 'string',
					'required' => true,
				],
				'id'   => [
					'name'     => 'id',
					'type'     => 'integer',
					'required' => true,
				],
			],
		];
		$schema['properties'] = array_merge( $schema['properties'], Newspack_Segments_Model::get_meta_schema() );
		return $schema;
	}
}
