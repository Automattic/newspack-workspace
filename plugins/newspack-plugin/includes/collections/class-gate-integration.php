<?php
/**
 * Connects Collections to content gates.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a content gate cover the Collections archive page.
 */
class Gate_Integration {

	/**
	 * Set up hooks.
	 */
	public static function init() {
		add_filter( 'newspack_content_gate_supported_archive_post_types', [ __CLASS__, 'add_archive_support' ] );
	}

	/**
	 * Offer the Collection post type to content gates as an archive.
	 *
	 * @param string[] $post_types Post types whose archive a gate can restrict.
	 *
	 * @return string[]
	 */
	public static function add_archive_support( $post_types ) {
		$post_types[] = Post_Type::get_post_type();
		return $post_types;
	}
}
