<?php
/**
 * Newspack Capabilities.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Capabilities Class.
 */
final class Capabilities {
	/**
	 * Initialize Hooks.
	 */
	public static function init() {
		add_filter( 'user_has_cap', [ __CLASS__, 'map_capabilities' ], 10, 2 );
	}

	/**
	 * Map legacy capabilities to granularly-controlled capabilities.
	 * This allows custom post type capabilities to be mapped from regular post
	 * capabilities. This way, when a custom post type becomes more granularily controlled
	 * with its own caps, users won't need to have their capabilities updated.
	 *
	 * @param bool[]   $allcaps All caps.
	 * @param string[] $caps    Required primitive capabilities for the requested capability.
	 */
	public static function map_capabilities( $allcaps, $caps ) {
		$capabilities_map = apply_filters( 'newspack_capabilities_map', [] );
		foreach ( $capabilities_map as $post_type => $base_post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object ) {
				continue;
			}
			$post_type_object_base = get_post_type_object( $base_post_type );
			if ( ! $post_type_object_base ) {
				continue;
			}
			foreach ( $caps as $requested_cap ) {
				if ( stripos( $requested_cap, $post_type ) !== false ) {
					$found_base_cap_name = array_search( $requested_cap, (array) $post_type_object->cap, true );
					$required_mapped_cap = $post_type_object_base->cap->$found_base_cap_name;
					if (
						$required_mapped_cap !== false
						&& isset( $allcaps[ $required_mapped_cap ] )
						&& $allcaps[ $required_mapped_cap ]
					) {
						$allcaps[ $requested_cap ] = true;
					}
				}
			}
		}
		return $allcaps;
	}

	/**
	 * Determines if the current user has the required capability.
	 *
	 * @param string $capability The capability to check.
	 * @param string $post_type  The post type to check the capability against.
	 * @return bool True if the user has the capability, false otherwise.
	 */
	public static function current_user_can( $capability, $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		if ( isset( $post_type_object->cap->$capability ) ) {
			return current_user_can( $post_type_object->cap->$capability );
		}
		return false;
	}
}
Capabilities::init();
