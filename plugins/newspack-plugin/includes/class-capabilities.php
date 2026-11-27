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
	 * Capability required to use the Access Control admin screens: Readers, Access
	 * Control, premium newsletter restrictions and a user's gate access panel.
	 *
	 * Held by anyone with `manage_options` or `manage_woocommerce`, unless a role
	 * or user stores it explicitly. Store staff manage members and plans, as they
	 * could under WooCommerce Memberships, and administrators keep access on a site
	 * without WooCommerce, where nobody holds `manage_woocommerce`.
	 *
	 * Granted through `user_has_cap` rather than `map_meta_cap`, so it also holds
	 * where core checks it without mapping it, as when a post type's capability
	 * map resolves `edit_post` to it.
	 */
	const MANAGE_ACCESS_CONTROL = 'newspack_manage_access_control';

	/**
	 * Initialize Hooks.
	 */
	public static function init() {
		add_filter( 'user_has_cap', [ __CLASS__, 'map_capabilities' ], 10, 2 );
		add_filter( 'user_has_cap', [ __CLASS__, 'grant_manage_access_control' ], 10, 2 );
	}

	/**
	 * Grant MANAGE_ACCESS_CONTROL to a user holding either capability it stands for.
	 *
	 * A value stored on the role or user wins, so a role editor can withhold
	 * Access Control from store staff.
	 *
	 * @param bool[]   $allcaps All caps.
	 * @param string[] $caps    Required primitive capabilities for the requested capability.
	 *
	 * @return bool[]
	 */
	public static function grant_manage_access_control( array $allcaps, array $caps ): array {
		if ( isset( $allcaps[ self::MANAGE_ACCESS_CONTROL ] ) ) {
			return $allcaps;
		}
		if ( in_array( self::MANAGE_ACCESS_CONTROL, $caps, true ) && ( ! empty( $allcaps['manage_options'] ) || ! empty( $allcaps['manage_woocommerce'] ) ) ) {
			$allcaps[ self::MANAGE_ACCESS_CONTROL ] = true;
		}
		return $allcaps;
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
