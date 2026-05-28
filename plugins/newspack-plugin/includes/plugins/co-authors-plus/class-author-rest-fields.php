<?php
/**
 * Author REST Fields class.
 *
 * Registers the newspack_author_info REST field on posts, providing
 * enriched author data (avatar URLs, guest status) to the block editor.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the newspack_author_info REST field on posts.
 */
class Author_Rest_Fields {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Priority 11 overrides the simpler registration of the same field
		// by newspack-newsletters (priority 10), adding enriched avatar and guest data.
		add_action( 'rest_api_init', [ __CLASS__, 'register_fields' ], 11 );
	}

	/**
	 * Register the newspack_author_info REST field on posts.
	 */
	public static function register_fields() {
		register_rest_field(
			'post',
			'newspack_author_info',
			[
				'get_callback' => [ __CLASS__, 'get_author_info' ],
				'schema'       => [
					'description' => __( 'Enriched author information for byline and avatar blocks.', 'newspack-plugin' ),
					'type'        => 'array',
					'context'     => [ 'edit' ],
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'            => [ 'type' => 'integer' ],
							'display_name'  => [ 'type' => 'string' ],
							'author_link'   => [ 'type' => 'string' ],
							'user_nicename' => [ 'type' => 'string' ],
							'is_guest'      => [ 'type' => 'boolean' ],
							'avatar_urls'   => [
								'type'                 => 'object',
								'additionalProperties' => [ 'type' => 'string' ],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Get enriched author info for a post.
	 *
	 * @param array $post REST API post array.
	 * @return array Array of author data objects.
	 */
	public static function get_author_info( $post ) {
		$post_id = $post['id'];

		// Use CoAuthors Plus when available.
		if ( function_exists( 'get_coauthors' ) ) {
			$coauthors = get_coauthors( $post_id );
			if ( ! empty( $coauthors ) ) {
				return array_map( [ __CLASS__, 'format_coauthor' ], $coauthors );
			}
		}

		// Fallback to single WP author.
		$author_id = get_post_field( 'post_author', $post_id );
		$user      = get_user_by( 'id', $author_id );
		if ( ! $user ) {
			return [];
		}

		return [
			[
				'id'            => (int) $user->ID,
				'display_name'  => $user->display_name,
				'author_link'   => get_author_posts_url( $user->ID ),
				'user_nicename' => $user->user_nicename,
				'is_guest'      => false,
				'avatar_urls'   => rest_get_avatar_urls( $user->user_email ),
			],
		];
	}

	/**
	 * Format a CoAuthors Plus coauthor object for the REST response.
	 *
	 * @param object $coauthor CoAuthors Plus coauthor object (WP_User or guest author).
	 * @return array Formatted author data.
	 */
	private static function format_coauthor( $coauthor ) {
		$is_guest = isset( $coauthor->type ) && 'guest-author' === $coauthor->type;

		// Pass the numeric ID so CAP's pre_get_avatar_data filter fires,
		// resolving guest author avatars from their featured image.
		$avatar_urls = rest_get_avatar_urls( $coauthor->ID );

		return [
			'id'            => (int) $coauthor->ID,
			'display_name'  => $coauthor->display_name,
			'author_link'   => get_author_posts_url( $coauthor->ID, $coauthor->user_nicename ),
			'user_nicename' => $coauthor->user_nicename,
			'is_guest'      => $is_guest,
			'avatar_urls'   => $avatar_urls,
		];
	}
}

Author_Rest_Fields::init();
