<?php
/**
 * Collection Meta Fields handler.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Collection meta fields and related operations.
 */
class Collection_Meta {

	use Traits\Meta_Handler;

	/**
	 * Get meta definitions.
	 *
	 * @return array Array of meta definitions. See `Traits\Meta_Handler::get_meta_definitions()` for more details.
	 */
	public static function get_meta_definitions() {
		return [
			'volume'                     => [
				'type'              => 'string',
				'label'             => __( 'Volume', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			],
			'number'                     => [
				'type'              => 'string',
				'label'             => __( 'Number', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			],
			'period'                     => [
				'type'              => 'string',
				'label'             => __( 'Period', 'newspack-plugin' ),
				'description'       => __( 'Period as a string (e.g., "Spring 2025", "January 2025")', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			],
			'subscribe_link'             => [
				'type'              => 'string',
				'label'             => __( 'Subscription URL', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => [
					'schema' => [
						'format' => 'uri',
					],
				],
			],
			'order_link'                 => [
				'type'              => 'string',
				'label'             => __( 'Order URL', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => [
					'schema' => [
						'format' => 'uri',
					],
				],
			],
			'ctas'                       => [
				'type'              => 'array',
				'label'             => __( 'Call-to-Action', 'newspack-plugin' ),
				'description'       => __( 'Add multiple CTAs linking to attachments or external URLs.', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => [ __CLASS__, 'sanitize_ctas' ],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'type'  => [
									'type' => 'string',
									'enum' => [ 'attachment', 'link' ],
								],
								'label' => [
									'type' => 'string',
								],
								'id'    => [
									'type' => 'integer',
								],
								'url'   => [
									'type'   => 'string',
									'format' => 'uri',
								],
							],
						],
					],
				],
			],
			'cover_story_img_visibility' => [
				'type'              => 'string',
				'field_type'        => 'select',
				'label'             => __( 'Cover Story Image Visibility', 'newspack-plugin' ),
				'description'       => __( 'Choose whether to display featured images for cover stories in this collection.', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => function ( $value ) {
					return in_array( $value, [ 'inherit', 'show', 'hide' ], true ) ? $value : 'inherit';
				},
				'show_in_rest'      => [
					'schema' => [
						'type' => 'string',
						'enum' => [ 'inherit', 'show', 'hide' ],
					],
				],
				'default'           => 'inherit',
				'options'           => [
					[
						'label' => __( 'Use global setting', 'newspack-plugin' ),
						'value' => 'inherit',
					],
					[
						'label' => __( 'Show', 'newspack-plugin' ),
						'value' => 'show',
					],
					[
						'label' => __( 'Hide', 'newspack-plugin' ),
						'value' => 'hide',
					],
				],
			],
		];
	}


	/**
	 * Initialize the meta fields handler.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_fields' ] );
	}

	/**
	 * Register meta fields for the collection post type.
	 */
	public static function register_meta() {
		self::register_meta_for_object( 'post', Post_Type::get_post_type() );
	}

	/**
	 * Sanitize CTAs array.
	 *
	 * @param mixed $value Array of CTA objects.
	 * @return array Sanitized array of CTA objects.
	 */
	public static function sanitize_ctas( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_filter(
			array_map( [ self::class, 'sanitize_single_cta' ], $value )
		);
	}

	/**
	 * Sanitize a single CTA object.
	 *
	 * @param mixed $cta CTA object to sanitize.
	 * @return array|null Sanitized CTA or null if invalid.
	 */
	private static function sanitize_single_cta( $cta ) {
		// Validate basic structure.
		if ( ! is_array( $cta ) || ! isset( $cta['type'], $cta['label'] ) ) {
			return null;
		}

		$type  = sanitize_text_field( $cta['type'] );
		$label = sanitize_text_field( $cta['label'] );

		// Validate type and label.
		if ( ! in_array( $type, [ 'attachment', 'link' ], true ) || empty( $label ) ) {
			return null;
		}

		$sanitized_cta = [
			'type'  => $type,
			'label' => $label,
		];

		// Type-specific validation and sanitization.
		if ( 'attachment' === $type ) {
			// Check if the attachment ID is a number and if the post type is attachment.
			if ( ! isset( $cta['id'] ) || ! is_numeric( $cta['id'] ) || 'attachment' !== get_post_type( $cta['id'] ) ) {
				return null;
			}
			$sanitized_cta['id'] = absint( $cta['id'] );
		} else {
			if ( ! isset( $cta['url'] ) || empty( $cta['url'] ) ) {
				return null;
			}
			$url = esc_url_raw( $cta['url'] );
			if ( ! $url ) {
				return null;
			}
			$sanitized_cta['url'] = $url;
		}

		return $sanitized_cta;
	}

	/**
	 * Register custom REST API fields for collections.
	 */
	public static function register_rest_fields() {
		register_rest_field(
			Post_Type::get_post_type(),
			'ctas',
			[
				'get_callback' => [ __CLASS__, 'get_collection_ctas_for_rest' ],
				'schema'       => [
					'description' => __( 'Collection CTAs', 'newspack-plugin' ),
					'type'        => 'array',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'url'   => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
							'class' => [ 'type' => 'string' ],
						],
					],
					'context'     => [ 'view', 'edit' ],
				],
			]
		);
	}

	/**
	 * Get collection CTAs for REST API response.
	 *
	 * @param array $post Post data.
	 * @return array Array of processed CTAs.
	 *
	 * @see Query_Helper::get_ctas()
	 */
	public static function get_collection_ctas_for_rest( $post ) {
		return array_map(
			function ( $cta ) {
				return [
					'label' => esc_html( $cta['label'] ?? '' ),
					'url'   => esc_url( $cta['url'] ?? '' ),
					'type'  => esc_attr( $cta['type'] ?? '' ),
					'class' => esc_attr( $cta['class'] ?? '' ),
				];
			},
			Query_Helper::get_ctas( $post['id'] )
		);
	}
}
