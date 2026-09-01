<?php
/**
 * REST author field trait.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Shared `register_rest_field` scaffolding for the list pages' Author
 * column.
 *
 * It exists so a list never has to ask for `_embed=author`. Embedding
 * forces `_links` into the response, and core answers that by building
 * every row's link set and computing target hints for its `self` link,
 * which re-resolves the whole REST route map per row. Reading the two
 * values the column renders costs a primed cache lookup instead.
 */
trait Rest_Author_Field {
	/**
	 * Register an author field on the given CPT.
	 *
	 * @param string $cpt        CPT slug.
	 * @param string $field_name REST field name.
	 */
	protected static function register_author_field( string $cpt, string $field_name ): void {
		register_rest_field(
			$cpt,
			$field_name,
			[
				'get_callback' => [ static::class, 'rest_get_author' ],
				'schema'       => [
					// Only the list screens read this, and they ask for `edit`.
					// `view` would ship it to anonymous reads of the public
					// newsletters collection.
					'context'    => [ 'edit' ],
					'type'       => [ 'object', 'null' ],
					'readonly'   => true,
					'properties' => [
						'id'          => [ 'type' => 'integer' ],
						'name'        => [ 'type' => 'string' ],
						'avatar_urls' => [ 'type' => 'object' ],
					],
				],
			]
		);
	}

	/**
	 * `get_callback` adapter, matching `Rest_Status_Field`.
	 *
	 * @param array $post_array Prepared post, as passed by the controller.
	 * @return array|null
	 */
	public static function rest_get_author( $post_array ): ?array {
		return self::get_author_payload( isset( $post_array['id'] ) ? (int) $post_array['id'] : 0 );
	}

	/**
	 * A post author reduced to what the Author column renders.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null { id, name, avatar_urls }, or null when the author is gone.
	 */
	public static function get_author_payload( int $post_id ): ?array {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$user = get_userdata( (int) $post->post_author );
		if ( ! $user ) {
			return null;
		}

		// Keyed like core's `avatar_urls` so the shared user-row component
		// reads this field unchanged: the row renders at 16px, so 24 is the
		// 1x source and 48 the retina one.
		$avatar_urls = [];
		foreach ( [ 24, 48 ] as $size ) {
			$url = get_avatar_url( $user->ID, [ 'size' => $size ] );
			if ( is_string( $url ) && '' !== $url ) {
				$avatar_urls[ $size ] = $url;
			}
		}

		return [
			'id'          => (int) $user->ID,
			'name'        => (string) $user->display_name,
			'avatar_urls' => $avatar_urls,
		];
	}
}
