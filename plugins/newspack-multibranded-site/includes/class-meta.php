<?php
/**
 * Newspack Multibranded site term meta parent class.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site;

/**
 * Newspack Multibranded site meta parent class
 */
abstract class Meta {

	/**
	 * The meta type
	 *
	 * @var string
	 */
	public static $type = 'term';

	/**
	 * Initializes the Meta
	 */
	public static function init() {
		self::register_option();
	}

	/**
	 * Register the tem meta
	 *
	 * @return void
	 */
	public static function register_option() {
		$type   = static::get_schema()['type'] ?? 'string';
		$params = [
			'description'   => static::get_description(),
			'single'        => true,
			'show_in_rest'  => [
				'schema' => static::get_schema(),
			],
			'type'          => $type,
			'auth_callback' => [ get_called_class(), 'auth_callback' ],
		];

		if ( 'post' === static::$type ) {
			$post_types = static::get_post_types();
			foreach ( $post_types as $post_type ) {
				$params['object_subtype'] = $post_type;
				register_meta(
					'post',
					static::get_key(),
					$params
				);
			}
			return;
		}

		if ( 'term' === static::$type ) {
			$params['object_subtype'] = static::get_taxonomy();
		}
		register_meta(
			static::$type,
			static::get_key(),
			$params
		);
	}

	/**
	 * Get the taxonomy to register the meta to, if meta type is term
	 *
	 * @return string
	 */
	public static function get_taxonomy() {
		return Taxonomy::SLUG;
	}

	/**
	 * Get the post types to register the meta to, if meta type is post
	 *
	 * @return array
	 */
	public static function get_post_types() {
		return [];
	}

	/**
	 * Returns whether the current user can edit the meta
	 *
	 * @param bool   $allowed   Whether the user can add the object meta. Default false.
	 * @param string $meta_key  The meta key.
	 * @param int    $object_id Object ID.
	 * @return bool
	 */
	public static function auth_callback( $allowed, $meta_key, $object_id ) {
		return current_user_can( static::get_capability(), $object_id );
	}

	/**
	 * Returns the capability needed to edit the meta
	 *
	 * @return string
	 */
	public static function get_capability() {
		if ( 'post' === static::$type ) {
			return 'edit_post'; // singular, to check meta cap against the post id.
		}
		if ( 'term' === static::$type ) {
			$tax_object = get_taxonomy( static::get_taxonomy() );
			return $tax_object->cap->manage_terms;
		}
		if ( 'user' === static::$type ) {
			return 'edit_users';
		}

		// default to manage_options, but this should never happen.
		return 'manage_options';
	}

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	abstract public static function get_key();

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	abstract public static function get_description();

	/**
	 * Gets the meta schema
	 *
	 * @return array
	 */
	abstract public static function get_schema();
}
