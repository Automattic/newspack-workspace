<?php
/**
 * REST terms field trait.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared `register_rest_field` scaffolding for the list pages' taxonomy
 * columns.
 *
 * The counterpart to `Rest_Author_Field`, and there for the same reason:
 * `_embed=wp:term` dispatches an internal REST request per term group
 * per row, and asking for it drags `_links` (and core's per-row target
 * hints) in with it.
 *
 * Terms ship as `{ id, name }` rather than names alone so Quick Edit can
 * seed its taxonomy pickers from the same payload the column renders.
 */
trait Rest_Terms_Field {
	/**
	 * Register a terms field on the given CPT.
	 *
	 * @param string        $cpt        CPT slug.
	 * @param string        $field_name REST field name.
	 * @param array<string> $taxonomies Taxonomies to expose, keyed into the payload by slug.
	 */
	protected static function register_terms_field( string $cpt, string $field_name, array $taxonomies ): void {
		$properties = [];
		foreach ( $taxonomies as $taxonomy ) {
			$properties[ $taxonomy ] = [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'   => [ 'type' => 'integer' ],
						'name' => [ 'type' => 'string' ],
					],
				],
			];
		}

		register_rest_field(
			$cpt,
			$field_name,
			[
				'get_callback' => static function ( $post_array ) use ( $taxonomies ) {
					return self::get_terms_payload( isset( $post_array['id'] ) ? (int) $post_array['id'] : 0, $taxonomies );
				},
				'schema'       => [
					'context'    => [ 'view', 'edit' ],
					'type'       => 'object',
					'readonly'   => true,
					'properties' => $properties,
				],
			]
		);
	}

	/**
	 * A post's terms in the given taxonomies.
	 *
	 * Every requested taxonomy is present in the result, so a renderer
	 * never has to tell "no terms" apart from "taxonomy missing".
	 *
	 * @param int           $post_id    Post ID.
	 * @param array<string> $taxonomies Taxonomies to read.
	 * @return array<string, array<array{id: int, name: string}>>
	 */
	public static function get_terms_payload( int $post_id, array $taxonomies ): array {
		$payload = array_fill_keys( $taxonomies, [] );
		if ( ! $post_id ) {
			return $payload;
		}

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			$payload[ $taxonomy ] = array_values(
				array_map(
					static function ( $term ) {
						return [
							'id'   => (int) $term->term_id,
							'name' => (string) $term->name,
						];
					},
					$terms
				)
			);
		}

		return $payload;
	}
}
