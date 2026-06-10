<?php
/**
 * Block editor customizations for the Newspack Profiles plugin.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use NewspackProfiles\Traits\Singleton;

/**
 * Block_Editor class to handle block editor related functionalities, such as modifying block editor settings.
 */
class Block_Editor {

	use Singleton;

	/**
	 * Constructor for the Block_Editor class.
	 */
	protected function __construct() {
		add_filter( 'block_editor_settings_all', array( $this, 'modify_block_editor_settings' ) );
		add_filter( 'wp_kses_allowed_html', array( $this, 'allow_to_render_svg_in_wp_kses' ) );
		add_filter( 'block_core_social_link_get_services', array( $this, 'add_extra_social_services' ) );
	}

	/**
	 * Modify block editor settings to ensure a "Conditional Style Text" color option is available in color palettes.
	 *
	 * @param array $settings Current block editor settings.
	 *
	 * @return array Modified block editor settings.
	 */
	public function modify_block_editor_settings( array $settings ): array {
		if ( is_array( $settings['colors'] ?? null ) ) {
			$settings['colors'] = $this->add_conditional_style_text_color_if_not_exists( $settings['colors'] );
		}

		if ( is_array( $settings['__experimentalFeatures']['color']['palette']['default'] ?? null ) ) {
			$settings['__experimentalFeatures']['color']['palette']['default'] = $this->add_conditional_style_text_color_if_not_exists( $settings['__experimentalFeatures']['color']['palette']['default'] );
		}

		if ( is_array( $settings['__experimentalFeatures']['color']['palette']['theme'] ?? null ) ) {
			$settings['__experimentalFeatures']['color']['palette']['theme'] = $this->add_conditional_style_text_color_if_not_exists( $settings['__experimentalFeatures']['color']['palette']['theme'] );
		}

		return $settings;
	}

	/**
	 * Add a "Conditional Style Text" color to a given array of colors if it doesn't already exist.
	 *
	 * @param array $colors Array of color definitions.
	 *
	 * @return array Modified array of color definitions with "Conditional Style Text" added if it was not present.
	 */
	private function add_conditional_style_text_color_if_not_exists( array $colors ): array {
		$conditional_style_text_color = array(
			'slug'  => 'np-conditional-style-text',
			'name'  => __( 'Conditional Style Text', 'newspack-profiles' ),
			'color' => 'np-conditional-style-text',
		);

		$colors = is_array( $colors ) ? $colors : array();

		$has_color = ! empty(
			array_filter(
				$colors,
				function ( $color ) use ( $conditional_style_text_color ) {
					return ( $color['slug'] ?? '' ) === $conditional_style_text_color['slug'];
				}
			)
		);

		if ( ! $has_color ) {
			$colors = array_merge( array( $conditional_style_text_color ), $colors );
		}

		return $colors;
	}

	/**
	 * Allow rendering of SVG elements in wp_kses by adding 'svg' and 'path' tags with specific attributes to the allowed tags.
	 *
	 * @param array $allowed_tags Current array of allowed HTML tags and their attributes.
	 *
	 * @return array Modified array of allowed HTML tags including 'svg' and 'path'.
	 */
	public function allow_to_render_svg_in_wp_kses( array $allowed_tags ): array {
		$allowed_tags['svg'] = array(
			'width'       => true,
			'height'      => true,
			'viewBox'     => true,
			'aria-hidden' => true,
			'focusable'   => true,
			...( is_array( $allowed_tags['svg'] ?? '' ) ? $allowed_tags['svg'] : array() ),
		);

		$allowed_tags['path'] = array(
			'd' => true,
			...( is_array( $allowed_tags['path'] ?? '' ) ? $allowed_tags['path'] : array() ),
		);

		return $allowed_tags;
	}

	/**
	 * Add extra social services to the list of services available in the Social Link block.
	 *
	 * @param array $services_data Current array of social services data.
	 *
	 * @return array Modified array of social services data including additional services.
	 */
	public function add_extra_social_services( array $services_data ): array {
		if ( ! isset( $services_data['phone'] ) ) {
			$services_data['phone'] = array(
				'name' => _x( 'Phone', 'Social link block variation name', 'newspack-profiles' ),
				'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.1 5.0C8.9 4.5 8.2 4.2 7.7 4.4L7.5 4.4C5.6 5.0 3.9 6.8 4.4 9.1C5.5 14.3 9.7 18.5 14.9 19.6C17.2 20.1 19.0 18.4 19.6 16.5L19.6 16.3C19.8 15.8 19.5 15.1 19.0 14.9L16.0 13.7C15.5 13.4 14.9 13.6 14.6 14.0L13.4 15.4C11.4 14.4 9.6 12.6 8.6 10.5L10.0 9.4C10.4 9.0 10.6 8.5 10.3 8.0L9.1 5.0z"></path></svg>',
			);
		}

		return $services_data;
	}
}
