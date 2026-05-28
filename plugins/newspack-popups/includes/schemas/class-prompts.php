<?php
/**
 * The Prompts Schema
 *
 * @package Newspack
 */

namespace Newspack\Campaigns\Schemas;

use Newspack\Campaigns\Schema;
use Newspack_Popups_Model;

/**
 * The Prompts Schema
 */
class Prompts extends Schema {

	/**
	 * Get the schema.
	 *
	 * @return array The schema.
	 */
	public static function get_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'slug'              => [
					'name'     => 'slug', // An optional unique slug to describe the purpose of the prompt.
					'type'     => 'string',
					'required' => false,
				],
				'title'             => [
					'name'     => 'title',
					'type'     => 'string',
					'required' => true,
				],
				'content'           => [
					'name'     => 'content',
					'type'     => 'string',
					'required' => true,
				],
				'featured_image_id' => [
					'name'     => 'featured_image_id',
					'type'     => 'integer', // Attachment ID to be used as the featured image.
					'required' => false,
				],
				'ready'             => [
					'name'     => 'ready',
					'type'     => 'boolean',
					'required' => false,
				],
				'duplicate_of'      => [
					'name'     => 'duplicate_of',
					'type'     => 'integer',
					'required' => false,
					'default'  => 0,
				],
				'campaign_groups'   => [
					'name'     => 'campaign_groups',
					'type'     => 'array',
					'required' => false,
					'default'  => [],
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'id'   => [
								'type' => 'integer',
							],
							'name' => [
								'type' => 'string',
							],
						],
					],
				],
				'segments'          => [
					'name'     => 'segments',
					'type'     => 'array',
					'required' => false,
					'default'  => [],
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'id'   => [
								'type' => 'integer',
							],
							'name' => [
								'type' => 'string',
							],
						],
					],
				],
				'status'            => [
					'name'     => 'status',
					'type'     => 'string',
					'required' => true,
					'enum'     => [
						'publish',
						'draft',
					],
				],
				'categories'        => [
					'name'     => 'categories',
					'type'     => 'array',
					'required' => false,
					'default'  => [],
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'id'   => [
								'type' => 'integer',
							],
							'name' => [
								'type' => 'string',
							],
						],
					],
				],
				'tags'              => [
					'name'     => 'tags',
					'type'     => 'array',
					'required' => false,
					'default'  => [],
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'id'   => [
								'type' => 'integer',
							],
							'name' => [
								'type' => 'string',
							],
						],
					],
				],
				'options'           => [
					'type'                 => 'object',
					'required'             => true,
					'additionalProperties' => false,
					'properties'           => [
						'background_color'               => [
							'name'     => 'background_color',
							'type'     => 'string',
							'format'   => 'hex-color',
							'required' => false,
							'default'  => '#FFFFFF',
						],
						'hide_border'                    => [
							'name'     => 'hide_border',
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						],
						'large_border'                   => [
							'name'     => 'large_border',
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						],
						'no_padding'                     => [
							'name'     => 'no_padding',
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						],
						'frequency'                      => [
							'name'     => 'frequency',
							'type'     => 'string',
							'required' => true,
							'enum'     => [
								'once',
								'weekly',
								'daily',
								'always',
								'custom',
							],

						],
						'frequency_max'                  => [
							'name'     => 'frequency_max',
							'type'     => 'integer',
							'required' => false,
							'default'  => 0,
						],
						'frequency_start'                => [
							'name'     => 'frequency_start',
							'type'     => 'integer',
							'required' => false,
							'default'  => 0,
						],
						'frequency_between'              => [
							'name'     => 'frequency_between',
							'type'     => 'integer',
							'required' => false,
							'default'  => 0,
						],
						'frequency_reset'                => [
							'name'     => 'frequency_reset',
							'type'     => 'string',
							'required' => false,
							'default'  => 'month',
							'enum'     => [
								'month',
								'week',
								'day',
							],
						],
						'close_button_background_color'  => [
							'name'     => 'close_button_background_color',
							'type'     => 'string',
							'required' => false,
							'default'  => '#00000000', // format: 'hex-color' removed to pass tests with an 8-character hex. Related: https://core.trac.wordpress.org/ticket/55962.
						],
						'enable_close_button_background' => [
							'name'     => 'enable_close_button_background',
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						],
						'overlay_color'                  => [
							'name'     => 'overlay_color',
							'type'     => 'string',
							'required' => false,
							'format'   => 'hex-color',
							'default'  => '#000000',
						],
						'overlay_opacity'                => [
							'name'     => 'overlay_opacity',
							'type'     => 'integer',
							'required' => false,
							'default'  => 30,
							'maximum'  => 100,
						],
						'overlay_size'                   => [
							'name'     => 'overlay_size',
							'type'     => 'string',
							'required' => false,
							'default'  => 'medium',
							'enum'     => array_map(
								function( $item ) {
									return $item['value'];
								},
								Newspack_Popups_Model::get_popup_size_options()
							),
						],
						'no_overlay_background'          => [
							'name'     => 'no_overlay_background',
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						],
						'placement'                      => [
							'name'     => 'placement',
							'type'     => 'string',
							'required' => true,
							'enum'     => [
								// overlay.
								'top_left',
								'top_right',
								'top',
								'bottom_left',
								'bottom_right',
								'bottom',
								'center',
								'center_left',
								'center_right',

								// inline.
								'inline',
								'archives',
								'above_header',
								'manual',
								'custom1',
								'custom2',
								'custom3',
							],
						],
						'trigger_type'                   => [
							'name'     => 'trigger_type',
							'type'     => 'string',
							'required' => false,
							'default'  => 'time',
							'enum'     => [
								'time',
								'scroll',
								'blocks_count',
							],
						],
						'trigger_delay'                  => [
							'name'     => 'trigger_delay',
							'type'     => 'integer',
							'required' => false,
							'default'  => 0,
						],
						'trigger_scroll_progress'        => [
							'name'     => 'trigger_scroll_progress',
							'type'     => 'integer',
							'required' => false,
							'default'  => 0,
						],
						'trigger_blocks_count'           => [
							'name'     => 'trigger_blocks_count',
							'type'     => 'integer',
							'required' => false,
							'default'  => 0,
						],
						'archive_insertion_posts_count'  => [
							'name'     => 'archive_insertion_posts_count',
							'type'     => 'integer',
							'required' => false,
							'default'  => 1,
						],
						'archive_insertion_is_repeating' => [
							'name'     => 'archive_insertion_is_repeating',
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						],
						'utm_suppression'                => [
							'name'     => 'utm_suppression',
							'type'     => 'string',
							'required' => false,
							'default'  => '',
						],
						'post_types'                     => [
							'name'     => 'post_types',
							'type'     => 'array',
							'required' => false,
							'default'  => Newspack_Popups_Model::get_default_popup_post_types(),
							'items'    => [
								'type' => 'string',
							],
						],
						'archive_page_types'             => [
							'name'     => 'archive_page_types',
							'type'     => 'array',
							'required' => false,
							'default'  => Newspack_Popups_Model::get_supported_archive_page_types(),
							'items'    => [
								'type' => 'string',
							],
						],
						'additional_classes'             => [
							'name'     => 'additional_classes',
							'type'     => 'string',
							'required' => false,
							'default'  => '',
						],
						'excluded_categories'            => [
							'name'     => 'excluded_categories',
							'type'     => 'array',
							'required' => false,
							'default'  => [],
							'items'    => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [
									'id'   => [
										'type' => 'integer',
									],
									'name' => [
										'type' => 'string',
									],
								],
							],
						],
						'excluded_tags'                  => [
							'name'     => 'excluded_tags',
							'type'     => 'array',
							'required' => false,
							'default'  => [],
							'items'    => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [
									'id'   => [
										'type' => 'integer',
									],
									'name' => [
										'type' => 'string',
									],
								],
							],
						],
						'expiration_date'                => [
							'name'     => 'expiration_date',
							'type'     => 'string',
							'required' => false,
							'default'  => false,
						],
						'newspack_popups_has_disabled_popups' => [
							'name'     => 'newspack_popups_has_disabled_popups',
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						],
					],
				],
				// Required user inputs when auto-generating a prompt. These will be shown as fields in the UI.
				'user_input_fields' => [
					'name'     => 'user_input_fields',
					'type'     => 'array',
					'required' => false,
					'default'  => [],
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							// Slug for the input. Must be unique per prompt.
							'name'        => [
								'type' => 'string',
							],
							// Data type for the input.
							'type'        => [
								'type' => 'string',
							],
							// Label for the input.
							'label'       => [
								'type' => 'string',
							],
							// Help text describing the input.
							'description' => [
								'type' => 'string',
							],
							// Whether the input is required to generate the prompt or if it can be generated with an empty value. If true, the default value will be used when the input is empty.
							'required'    => [
								'type' => 'boolean',
							],
							// Default value of the input. This will be populated by default in the UI.
							'default'     => [
								'type' => [ 'array', 'integer', 'string' ],
							],
							// User-inputted value of the input, if available.
							'value'       => [
								'type' => [ 'array', 'integer', 'string' ],
							],
							// If a string, maximum length for the input value. This will be used to validate the input in the UI.
							'max_length'  => [
								'type'     => 'integer',
								'required' => false,
							],
							// If an array, selectable options.
							'options'     => [
								'type'     => 'array',
								'required' => false,
							],
						],
					],
				],
				'help_info'         => [
					'type'                 => 'object',
					'required'             => false,
					'additionalProperties' => false,
					'properties'           => [
						'screenshot'      => [
							'name'     => 'screenshot',
							'type'     => 'string',
							'required' => false,
							'default'  => '', // Filename of a screenshot to display. Will look for it in the plugin's src/assets folder.
						],
						'description'     => [
							'name'     => 'description',
							'type'     => 'string',
							'required' => false,
							'default'  => '',
						],
						'recommendations' => [
							'name'     => 'recommendations',
							'type'     => 'array',
							'required' => false,
							'items'    => [
								'type' => 'string',
							],
							'default'  => [],
						],
						'url'             => [
							'name'     => 'url',
							'type'     => 'string',
							'required' => false,
							'default'  => '',
						],
					],
				],
			],
		];
	}
}
