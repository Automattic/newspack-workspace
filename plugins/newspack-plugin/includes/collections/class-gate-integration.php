<?php
/**
 * Connects Collections to content gates.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;

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

	/**
	 * Print the gate in place of the archive's list, if a gate covers it.
	 *
	 * Called by the archive template, which skips the list when this returns true.
	 *
	 * @return bool Whether the gate was printed.
	 */
	public static function maybe_render_archive_gate(): bool {
		$archive_gate = Content_Restriction_Control::get_archive_gate();
		if ( ! $archive_gate ) {
			return false;
		}
		// Claim the once-per-request render lock before rendering, as every gate
		// render path does, so nothing else renders a second gate on this page.
		Content_Gate::mark_gate_as_rendered();
		echo Content_Gate::get_archive_gate_html( $archive_gate['gate_layout_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered gate layout, as for posts.
		return true;
	}
}
