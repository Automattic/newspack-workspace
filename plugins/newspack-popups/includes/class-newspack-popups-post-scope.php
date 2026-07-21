<?php
/**
 * Newspack Popups Post Scope
 *
 * Supports prompts that are scoped to a single post (e.g. Contextual Prompts):
 * they display only on their parent post and are kept out of the general
 * eligible-prompts query so sites with many scoped prompts don't pay a query
 * cost on every page view.
 *
 * The scope link is the prompt's `post_parent` (set to the article ID). This is
 * deliberate: excluding scoped prompts from the eligible query is then a cheap
 * `post_parent = 0` filter on the posts table, rather than a meta anti-join that
 * gets more expensive as scoped prompts accumulate.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Popups Post Scope Class.
 */
final class Newspack_Popups_Post_Scope {
	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'newspack_popups_should_display_prompt', [ __CLASS__, 'filter_should_display' ], 10, 2 );
	}

	/**
	 * The post ID a prompt is scoped to, or 0 if it is site-wide.
	 *
	 * @param array $popup A popup object (must contain an 'id' key).
	 * @return int Scoped post ID, or 0.
	 */
	public static function get_scoped_post_id( $popup ) {
		if ( empty( $popup['id'] ) ) {
			return 0;
		}
		return (int) get_post_field( 'post_parent', $popup['id'] );
	}

	/**
	 * Gate a scoped prompt to its parent post.
	 *
	 * A scoped prompt overrides the usual taxonomy/post-type context match: it
	 * shows on its parent post regardless of those, and nowhere else. Non-scoped
	 * prompts are returned unchanged.
	 *
	 * @param bool  $should_display Result of the prior should_display checks.
	 * @param array $popup          The popup being assessed.
	 * @return bool
	 */
	public static function filter_should_display( $should_display, $popup ) {
		$scoped_post_id = self::get_scoped_post_id( $popup );
		if ( ! $scoped_post_id ) {
			return $should_display;
		}
		return is_singular() && (int) get_the_ID() === $scoped_post_id;
	}

	/**
	 * Exclude scoped prompts from a WP_Query args array.
	 *
	 * Restricts the query to top-level prompts (post_parent = 0), so post-scoped
	 * prompts never load through the general eligible-prompts query. They are
	 * injected explicitly for the current post via get_scoped_popups_for_current_post().
	 *
	 * @param array $args WP_Query args.
	 * @return array Modified args.
	 */
	public static function exclude_scoped_from_args( $args ) {
		$args['post_parent'] = 0;
		return $args;
	}

	/**
	 * Prompts scoped to the current singular post, ready to merge into the
	 * display candidates. Empty on non-singular views.
	 *
	 * @param bool $include_unpublished Whether to include unpublished prompts.
	 * @return array Popup objects.
	 */
	public static function get_scoped_popups_for_current_post( $include_unpublished = false ) {
		if ( ! is_singular() ) {
			return [];
		}
		return Newspack_Popups_Model::retrieve_scoped_popups( get_the_ID(), $include_unpublished );
	}
}
