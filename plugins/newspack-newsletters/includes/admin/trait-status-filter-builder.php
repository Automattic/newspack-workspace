<?php
/**
 * Status filter builder trait.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared status-filter scaffolding for list-page REST endpoints.
 */
trait Status_Filter_Builder {
	/**
	 * Parse a `?status=` param into a deduplicated list of kinds.
	 *
	 * @param mixed $value Raw param value (string or array).
	 * @return string[]
	 */
	protected static function parse_status_values( $value ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}
		$raw = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values(
			array_unique(
				array_filter(
					array_map( 'trim', array_map( 'strval', $raw ) ),
					static function ( $v ) {
						return '' !== $v;
					}
				)
			)
		);
	}

	/**
	 * Token-scoped, self-removing `posts_where` install. The token
	 * gate is what prevents nested WP_Query invocations from
	 * consuming the filter before the intended query runs.
	 *
	 * @param array    $args           Query args being assembled.
	 * @param string[] $bucket_clauses Already-prepared SQL clauses to OR.
	 * @param string   $token_key      Query-args key scoping the closure.
	 * @return array
	 */
	protected static function install_bucket_filter( array $args, array $bucket_clauses, string $token_key ): array {
		if ( empty( $bucket_clauses ) ) {
			return $args;
		}

		$token              = uniqid( $token_key . '_', true );
		$args[ $token_key ] = $token;

		$callback = static function ( $where, $wp_query ) use ( &$callback, $token, $token_key, $bucket_clauses ) {
			if ( ! is_object( $wp_query ) || $wp_query->get( $token_key ) !== $token ) {
				return $where;
			}
			$where .= ' AND ( ' . implode( ' OR ', $bucket_clauses ) . ' )';
			remove_filter( 'posts_where', $callback, 10 );
			$callback = null;
			return $where;
		};
		add_filter( 'posts_where', $callback, 10, 2 );

		// Belt-and-braces drain in case the owning query short-circuits before firing.
		add_action(
			'shutdown',
			static function () use ( &$callback ) {
				if ( $callback ) {
					remove_filter( 'posts_where', $callback, 10 );
					$callback = null;
				}
			},
			0
		);

		return $args;
	}
}
