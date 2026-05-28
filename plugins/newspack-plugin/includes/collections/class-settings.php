<?php
/**
 * Collections Settings handler.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Collections settings management.
 */
class Settings {

	/**
	 * Option name for all collection settings.
	 */
	public const OPTION_NAME = 'newspack_collections_settings';

	/**
	 * Posts per page options.
	 */
	public const POSTS_PER_PAGE_OPTIONS = [ 12, 18, 24 ];

	/**
	 * Post indicator style options.
	 */
	public const POST_INDICATOR_STYLE_OPTIONS = [ 'default', 'card' ];

	/**
	 * Query parameter name for filtering by collection category.
	 */
	public const CATEGORY_QUERY_PARAM = 'np_collections_category';

	/**
	 * Query parameter name for filtering by year.
	 */
	public const YEAR_QUERY_PARAM = 'np_collections_year';

	/**
	 * Get fields definitions to be used in the REST API.
	 *
	 * @param string $return_type Whether to return only the default values, keys, or all. Returns all the configuration by default.
	 * @return array Fields definitions.
	 */
	public static function get_rest_args( $return_type = 'all' ) {
		$fields = [
			// Custom Naming section.
			'custom_naming_enabled' => [
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
			'custom_name'           => [
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'custom_singular_name'  => [
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'custom_slug'           => [
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => function ( $value ) {
					return sanitize_title( is_string( $value ) ? $value : '' );
				},
			],
			// Global CTAs section.
			'subscribe_link'        => [
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			],
			'order_link'            => [
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			],
			// Collections Archive section.
			'posts_per_page'        => [
				'required'          => false,
				'default'           => 12,
				'sanitize_callback' => function ( $value ) {
					$value = intval( $value );
					return in_array( $value, self::POSTS_PER_PAGE_OPTIONS, true ) ? $value : 12;
				},
			],
			'category_filter_label' => [
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'highlight_latest'      => [
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
			// Collection Single section.
			'articles_block_attrs'  => [
				'required'          => false,
				'default'           => [],
				'sanitize_callback' => [ self::class, 'sanitize_articles_block_attrs' ],
			],
			'show_cover_story_img'  => [
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
			// Collection Posts section.
			'post_indicator_style'  => [
				'required'          => false,
				'default'           => 'default',
				'sanitize_callback' => function ( $value ) {
					return in_array( $value, self::POST_INDICATOR_STYLE_OPTIONS, true ) ? $value : 'default';
				},
			],
			'card_message'          => [
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];

		switch ( $return_type ) {
			case 'defaults':
				$fields = array_map(
					function ( $field ) {
						return $field['default'];
					},
					$fields
				);
				break;
			case 'keys':
				$fields = array_keys( $fields );
				break;
		}

		return $fields;
	}

	/**
	 * Get all collection settings with defaults applied.
	 *
	 * @return array Collection settings.
	 */
	public static function get_settings() {
		$collection_settings = get_option( self::OPTION_NAME, [] );
		$defaults            = self::get_rest_args( 'defaults' );

		return wp_parse_args( $collection_settings, $defaults );
	}

	/**
	 * Update collection settings.
	 *
	 * @param array $settings Settings to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update_settings( $settings ) {
		$current_settings = get_option( self::OPTION_NAME, [] );
		$updated_settings = array_merge( $current_settings, $settings );

		return update_option( self::OPTION_NAME, $updated_settings );
	}

	/**
	 * Update a specific setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool True on success, false on failure.
	 */
	public static function update_setting( $key, $value ) {
		$current_settings         = get_option( self::OPTION_NAME, [] );
		$current_settings[ $key ] = $value;

		return update_option( self::OPTION_NAME, $current_settings );
	}

	/**
	 * Get a specific setting.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Optional default value if setting is not set.
	 * @return mixed Setting value or null if not set.
	 */
	public static function get_setting( $key, $default_value = null ) {
		$settings = self::get_settings();

		return ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) ? $settings[ $key ] : $default_value;
	}

	/**
	 * Get the custom name setting.
	 *
	 * @param string $key           Setting key. Default is 'custom_name'.
	 * @param mixed  $default_value Optional default value if setting is not set.
	 * @return mixed Setting value or null if not set.
	 */
	public static function get_custom_name( $key = 'custom_name', $default_value = null ) {
		$settings = self::get_settings();

		if ( ! empty( $settings['custom_naming_enabled'] ) && ! empty( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		return $default_value;
	}

	/**
	 * Get the plural collection label.
	 *
	 * @return string The plural collection label.
	 */
	public static function get_collection_label() {
		return self::get_custom_name( 'custom_name', _x( 'Collections', 'collections general label', 'newspack' ) );
	}

	/**
	 * Get the singular collection label.
	 *
	 * @return string The singular collection label.
	 */
	public static function get_collection_singular_label() {
		return self::get_custom_name( 'custom_singular_name', _x( 'Collection', 'collections singular label', 'newspack' ) );
	}

	/**
	 * Get the collection slug.
	 *
	 * @return string
	 */
	public static function get_collection_slug() {
		return self::get_custom_name( 'custom_slug', 'collections' );
	}

	/**
	 * Update collection settings from REST request.
	 * Conditionally flushes rewrite rules when there are changes that will affect the post type or taxonomy slugs.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return array Updated collection settings.
	 */
	public static function update_from_request( $request ) {
		$settings         = self::get_settings();
		$updated_settings = [];

		foreach ( self::get_rest_args( 'keys' ) as $key ) {
			if ( ! $request->has_param( $key ) ) {
				continue;
			}

			$new_value = $request->get_param( $key );

			if ( $new_value !== $settings[ $key ] ) {
				$updated_settings[ $key ] = $new_value;
			}
		}

		if ( empty( $updated_settings ) ) {
			return $settings;
		}

		/**
		 * Fires before updating collection settings.
		 *
		 * @param array $settings         Current collection settings.
		 * @param array $updated_settings Updated collection settings.
		 */
		do_action( 'newspack_collections_before_update_settings', $settings, $updated_settings );

		self::update_settings( $updated_settings );

		// Flush rewrite rules only if slug-related settings changed.
		if ( ! empty( array_intersect( array_keys( $updated_settings ), [ 'custom_naming_enabled', 'custom_slug' ] ) ) ) {
			/**
			 * Fires before flushing rewrite rules after collection settings are updated.
			 *
			 * @param array $updated_settings Updated collection settings.
			 */
			do_action( 'newspack_collections_before_flush_rewrites', $updated_settings );

			flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
		}

		return self::get_settings();
	}

	/**
	 * Sanitize articles block attributes, preserving unmanaged keys.
	 *
	 * @param mixed $value The input value to sanitize.
	 * @return array Sanitized articles block attributes.
	 */
	public static function sanitize_articles_block_attrs( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}

		// Get current value to preserve unmanaged keys.
		$attrs = self::get_settings()['articles_block_attrs'] ?? null;
		if ( ! is_array( $attrs ) ) {
			return [];
		}

		// UI-managed keys with their sanitization.
		$ui_managed = [
			'showCategory' => 'rest_sanitize_boolean',
		];

		// Update only UI-managed keys.
		foreach ( $ui_managed as $key => $sanitizer ) {
			if ( isset( $value[ $key ] ) ) {
				$attrs[ $key ] = $sanitizer( $value[ $key ] );
			} elseif ( array_key_exists( $key, $value ) ) {
				unset( $attrs[ $key ] );
			}
		}

		return $attrs;
	}
}
