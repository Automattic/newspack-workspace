<?php
/**
 * Promotional URL targets: scan content for modal-checkout-compatible blocks
 * and derive the effective config a promotional URL must match (NPPD-1707).
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Finds pages/posts containing Checkout Button or Donate blocks compatible
 * with a given plan, and derives per-block effective config so the generator
 * UI can only emit URLs that will actually trigger the modal checkout.
 */
final class Promo_Url_Targets {

	const CACHE_TTL            = 15 * MINUTE_IN_SECONDS;
	const CACHE_VERSION_OPTION = 'newspack_promo_targets_cache_version';
	const SCAN_LIMIT           = 100;
	const PATTERN_SCAN_LIMIT   = 20;
	const MAX_BLOCK_DEPTH      = 3;

	/**
	 * Register hooks. Idempotent (guarded by has_action).
	 */
	public static function init() {
		if ( ! has_action( 'save_post', [ __CLASS__, 'bump_cache_version' ] ) ) {
			add_action( 'save_post', [ __CLASS__, 'bump_cache_version' ] );
		}
	}

	/**
	 * Invalidate cached scans when content that can carry the blocks changes.
	 * Note: save_post also fires on trash, which is desirable here.
	 *
	 * @param int $post_id Saved post ID.
	 */
	public static function bump_cache_version( $post_id ) {
		if ( ! in_array( get_post_type( $post_id ), [ 'page', 'post', 'wp_block' ], true ) ) {
			return;
		}
		update_option( self::CACHE_VERSION_OPTION, (string) time(), false );
	}

	/**
	 * Find published pages/posts whose content contains the given block —
	 * directly, or via a published synced pattern (wp_block ref).
	 *
	 * @param string $block_name Block name, e.g. `newspack-blocks/checkout-button`.
	 * @return array [ int[] $ids, bool $truncated ]
	 */
	public static function find_candidate_post_ids( $block_name ) {
		global $wpdb;
		$like  = '%' . $wpdb->esc_like( '<!-- wp:' . $block_name ) . '%';
		$limit = self::SCAN_LIMIT + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE %s ORDER BY post_modified DESC LIMIT %d",
				$like,
				$limit
			)
		);
		// Synced patterns containing the block, newest-modified first and
		// capped; if the cap is hit there may be more patterns (and more
		// referencing pages) we never see, so that must be signaled too.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pattern_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'wp_block' AND post_content LIKE %s ORDER BY post_modified DESC LIMIT %d",
				$like,
				self::PATTERN_SCAN_LIMIT
			)
		);
		$truncated = count( $pattern_ids ) === self::PATTERN_SCAN_LIMIT;
		foreach ( $pattern_ids as $pattern_id ) {
			// Anchor the ref match to the full id: block serialization is
			// `{"ref":123}` or `{"ref":123,...}`, so an unanchored match on
			// `"ref":5` would also false-positive on `"ref":52`.
			$ref_like_comma = '%' . $wpdb->esc_like( '"ref":' . (int) $pattern_id . ',' ) . '%';
			$ref_like_brace = '%' . $wpdb->esc_like( '"ref":' . (int) $pattern_id . '}' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$referencing = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT %d",
					$ref_like_comma,
					$ref_like_brace,
					$limit
				)
			);
			$ids = array_merge( $ids, $referencing );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		// Direct-content and ref-based ids are each newest-first within their
		// own query, but the merged union isn't; re-sort it in one query.
		if ( count( $ids ) > 1 ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$placeholders}) ORDER BY post_modified DESC";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $ids ) ) );
		}

		$truncated = $truncated || count( $ids ) > self::SCAN_LIMIT;
		return [ array_slice( $ids, 0, self::SCAN_LIMIT ), $truncated ];
	}

	/**
	 * Extract attribute arrays for every occurrence of a block in serialized
	 * content, recursing into inner blocks and published synced-pattern refs.
	 *
	 * @param string $content    Serialized post content.
	 * @param string $block_name Block name to match.
	 * @param int    $depth      Recursion depth (pattern refs), capped.
	 * @return array List of attribute arrays.
	 */
	public static function extract_blocks( $content, $block_name, $depth = 0 ) {
		if ( $depth > self::MAX_BLOCK_DEPTH ) {
			return [];
		}
		$found  = [];
		$walker = function ( $blocks ) use ( &$walker, &$found, $block_name, $depth ) {
			foreach ( $blocks as $block ) {
				if ( $block_name === $block['blockName'] ) {
					$found[] = is_array( $block['attrs'] ) ? $block['attrs'] : [];
				}
				if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
					$ref = get_post( (int) $block['attrs']['ref'] );
					if ( $ref && 'wp_block' === $ref->post_type && 'publish' === $ref->post_status ) {
						$found = array_merge( $found, self::extract_blocks( $ref->post_content, $block_name, $depth + 1 ) );
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walker( $block['innerBlocks'] );
				}
			}
		};
		$walker( parse_blocks( $content ) );
		return $found;
	}
}
