<?php
/**
 * Pattern configuration for profile templates.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\Configs;

use const NewspackProfiles\PATTERNS_DIR;

const SAMPLE_FIELD_MAPPINGS = array(
	'Image'             => array(
		'type'    => 'image_url',
		'visible' => true,
	),
	'Name'              => array(
		'type'    => 'string',
		'visible' => true,
	),
	'Bio'               => array(
		'type'    => 'string',
		'visible' => true,
	),
	'Show more details' => array(
		'type'    => 'button_url',
		'visible' => true,
	),
	'Twitter Profile'   => array(
		'type'            => 'social_link',
		'social_platform' => 'twitter',
		'visible'         => true,
	),
	'Facebook Profile'  => array(
		'type'            => 'social_link',
		'social_platform' => 'facebook',
		'visible'         => true,
	),
	'YouTube Profile'   => array(
		'type'            => 'social_link',
		'social_platform' => 'youtube',
		'visible'         => true,
	),
	'Instagram Profile' => array(
		'type'            => 'social_link',
		'social_platform' => 'instagram',
		'visible'         => true,
	),
	'Website'           => array(
		'type'    => 'social_link',
		'visible' => true,
	),
);

const VALID_FIELD_TYPES = array( 'string', 'image_url', 'button_url', 'social_link' );

/**
 * Pattern_Config class to handle block patterns configuration.
 */
class Pattern_Config {

	/**
	 * Get pattern configurations.
	 *
	 * @return array The array of pattern configurations.
	 */
	private static function get_config(): array {
		$config = array();

		$single_pattern_positions = array(
			'image-top-details-bottom' => esc_html__( 'Image on Top and Details on Bottom', 'newspack-profiles' ),
			'image-left-details-right' => esc_html__( 'Image on Left and Details on Right', 'newspack-profiles' ),
		);

		foreach ( $single_pattern_positions as $position => $position_label ) {
			$config[] = array(
				'type'  => 'single',
				'name'  => "newspack-profiles/{$position}",
				'title' => $position_label,
				'file'  => PATTERNS_DIR . "{$position}.html",
			);
		}

		$list_pattern_positions = array(
			'image-top-details-bottom' => esc_html__( 'Image on Top and Details on Bottom', 'newspack-profiles' ),
			'image-left-details-right' => esc_html__( 'Image on Left and Details on Right', 'newspack-profiles' ),
			'details-only'             => esc_html__( 'Details Only', 'newspack-profiles' ),
		);

		foreach ( $list_pattern_positions as $position => $position_label ) {
			$config[] = array(
				'type'  => 'list',
				'name'  => "newspack-profiles/list-{$position}",
				'title' => $position_label,
				'file'  => PATTERNS_DIR . "list-{$position}.html",
			);
		}

		return $config;
	}

	/**
	 * Get all pattern configurations.
	 *
	 * @param array $field_mappings Optional field mappings for dynamic placeholder replacement.
	 *
	 * @return array The array of pattern configurations.
	 */
	public static function get_all( array $field_mappings = SAMPLE_FIELD_MAPPINGS ): array {
		$config = self::get_config();

		return array_map(
			function ( $pattern_config ) use ( $field_mappings ) {
				return self::prepare_pattern_config( $pattern_config, $field_mappings );
			},
			$config
		);
	}

	/**
	 * Get pattern configuration by slug.
	 *
	 * @param string $slug           The pattern slug.
	 * @param array  $field_mappings Optional field mappings for dynamic placeholder replacement.
	 *
	 * @return array The pattern configuration.
	 */
	public static function get( string $slug, array $field_mappings = SAMPLE_FIELD_MAPPINGS ): array {
		foreach ( self::get_config() as $config ) {
			if ( $config['name'] === $slug ) {
				return self::prepare_pattern_config( $config, $field_mappings );
			}
		}

		return array();
	}

	/**
	 * Prepare pattern configuration by loading content from file.
	 *
	 * @param array $config         The pattern configuration.
	 * @param array $field_mappings Optional field mappings for dynamic placeholder replacement.
	 *
	 * @return array The prepared pattern configuration.
	 */
	private static function prepare_pattern_config( array $config, array $field_mappings = array() ): array {
		$pattern_content = file_get_contents( $config['file'] );

		if ( ! $pattern_content ) {
			return array();
		}

		$pattern_content = self::prepare_pattern_content( $pattern_content, $field_mappings );

		return array(
			'type'    => $config['type'],
			'name'    => $config['name'],
			'title'   => $config['title'],
			'content' => $pattern_content,
		);
	}

	/**
	 * Prepare pattern content by replacing placeholders with mapped fields.
	 *
	 * @param string $content        Pattern content.
	 * @param array  $field_mappings Optional field mappings.
	 *
	 * @return string The prepared pattern content.
	 */
	private static function prepare_pattern_content( string $content, array $field_mappings ): string {
		$ordered_fields_by_type = self::get_ordered_fields_by_type( $field_mappings );

		$consumed_counts = array(
			'string'     => 0,
			'image_url'  => 0,
			'button_url' => 0,
		);

		$replace_inline_placeholder = function ( array $matches ) use ( &$consumed_counts, $ordered_fields_by_type ) {
			$type = $matches[1];

			$index = $consumed_counts[ $type ];

			if ( ! isset( $ordered_fields_by_type[ $type ][ $index ] ) ) {
				return '';
			}

			++$consumed_counts[ $type ];

			return $ordered_fields_by_type[ $type ][ $index ]['name'];
		};

		$content = preg_replace_callback( '/\{\{(string|image_url|button_url)\}\}/', $replace_inline_placeholder, $content );

		$remaining_text_fields = array_slice( $ordered_fields_by_type['string'], $consumed_counts['string'] );

		$content = str_replace(
			'<!-- placeholder-for-other-text-fields -->',
			self::build_other_text_fields_markup( $remaining_text_fields ),
			$content
		);

		$content = str_replace(
			'<!-- placeholder-for-social-media-fields -->',
			self::build_social_media_fields_markup( $ordered_fields_by_type['social_link'] ),
			$content
		);

		return $content;
	}

	/**
	 * Get ordered fields grouped by their types based on the provided field mappings.
	 *
	 * @param array $field_mappings Optional field mappings.
	 *
	 * @return array
	 */
	private static function get_ordered_fields_by_type( array $field_mappings ): array {
		$ordered_fields_by_type = array();

		foreach ( VALID_FIELD_TYPES as $type ) {
			$ordered_fields_by_type[ $type ] = array();
		}

		if ( empty( $field_mappings ) ) {
			return $ordered_fields_by_type;
		}

		foreach ( $field_mappings as $field_name => $mapping ) {
			if ( ! is_array( $mapping ) || ( isset( $mapping['visible'] ) && false === $mapping['visible'] ) ) {
				continue;
			}

			$type = ! empty( $mapping['type'] ) ? $mapping['type'] : 'string';

			if ( ! in_array( $type, VALID_FIELD_TYPES, true ) ) {
				continue;
			}

			$field_by_type = array(
				'name'  => $field_name,
				'order' => isset( $mapping['order'] ) && is_int( $mapping['order'] ) ? (int) $mapping['order'] : 0,
			);

			if ( 'social_link' === $type ) {
				$field_by_type['social_platform'] = isset( $mapping['social_platform'] ) ? (string) $mapping['social_platform'] : '';
			}

			$ordered_fields_by_type[ $type ][] = $field_by_type;
		}

		foreach ( VALID_FIELD_TYPES as $type ) {
			usort(
				$ordered_fields_by_type[ $type ],
				function ( $a, $b ) {
					return ( $a['order'] ?? 0 ) <=> ( $b['order'] ?? 0 );
				}
			);
		}

		return $ordered_fields_by_type;
	}

	/**
	 * Build markup for additional text fields.
	 *
	 * @param array $fields Text fields.
	 *
	 * @return string
	 */
	private static function build_other_text_fields_markup( array $fields ): string {
		if ( empty( $fields ) ) {
			return '';
		}

		return implode(
			'',
			array_map(
				function ( $field ) {
					return '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"remote-data/binding","args":{"field":"' . esc_attr( $field['name'] ) . '"}}}}} --><p></p><!-- /wp:paragraph -->';
				},
				$fields
			)
		);
	}

	/**
	 * Build markup for social media icon fields.
	 *
	 * @param array $fields Social fields.
	 *
	 * @return string
	 */
	private static function build_social_media_fields_markup( array $fields ): string {
		if ( empty( $fields ) ) {
			return '';
		}

		$social_links_markup = array_map(
			function ( $field ) {
				$attributes = array(
					'metadata' => array(
						'bindings' => array(
							'url' => array(
								'source' => 'remote-data/binding',
								'args'   => array(
									'field' => esc_attr( $field['name'] ),
								),
							),
						),
					),
				);

				$attributes['service'] = ! empty( $field['social_platform'] ) ? esc_attr( $field['social_platform'] ) : 'generic';

				return '<!-- wp:social-link ' . wp_json_encode( $attributes ) . ' /-->';
			},
			$fields
		);

		return '<!-- wp:social-links {"iconColor":"np-conditional-style-text","iconColorValue":"np-conditional-style-text","className":"is-style-logos-only"} --><ul class="wp-block-social-links has-icon-color is-style-logos-only">'
		. implode(
			'',
			$social_links_markup
		)
		. '</ul><!-- /wp:social-links -->';
	}
}
