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

	/**
	 * Build the product "family" a plan row spans: the row product itself plus
	 * its variation children (variable) or bundled members (grouped).
	 *
	 * @param int $product_id Plan row product ID.
	 * @return array{parent:int,variations:int[],members:int[]}
	 */
	public static function get_product_family( $product_id ) {
		$family = [
			'parent'     => (int) $product_id,
			'variations' => [],
			'members'    => [],
		];
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $family;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $family;
		}
		$children = array_map( 'intval', $product->get_children() );
		if ( $product->is_type( 'grouped' ) ) {
			$family['members'] = $children;
		} else {
			$family['variations'] = $children;
		}
		return $family;
	}

	/**
	 * Derive the effective config of a Checkout Button block relative to a plan
	 * family. Returns the ids the block's data-checkout JSON will emit at render
	 * time — the JS URL trigger matches against those, not the raw attributes
	 * (variable-locked buttons collapse product onto the variation in view.php).
	 *
	 * @param array $attrs  Block attributes from parse_blocks().
	 * @param array $family See get_product_family().
	 * @return array|null Effective config, or null when the block doesn't match.
	 */
	public static function derive_checkout_button_config( $attrs, $family ) {
		$block_product   = isset( $attrs['product'] ) ? (int) $attrs['product'] : 0;
		$block_variation = isset( $attrs['variation'] ) ? (int) $attrs['variation'] : 0;
		if ( ! $block_product ) {
			return null;
		}
		$parent     = (int) $family['parent'];
		$variations = array_map( 'intval', $family['variations'] );
		$members    = array_map( 'intval', $family['members'] );

		$config = null;
		if ( $block_product === $parent ) {
			$locked = in_array( $block_variation, array_merge( $variations, $members ), true ) ? $block_variation : 0;
			$config = [
				'product_id'           => $parent,
				'variation_id'         => $locked ? $locked : null,
				'has_variation_picker' => ! $locked && ( ! empty( $attrs['is_variable'] ) || ! empty( $members ) ),
			];
		} elseif ( in_array( $block_product, $variations, true ) ) {
			$config = [
				'product_id'           => $parent,
				'variation_id'         => $block_product,
				'has_variation_picker' => false,
			];
		} elseif ( in_array( $block_product, $members, true ) ) {
			$config = [
				'product_id'           => $block_product,
				'variation_id'         => null,
				'has_variation_picker' => false,
			];
		}
		if ( ! $config ) {
			return null;
		}
		$behavior                = isset( $attrs['afterSuccessBehavior'] ) ? (string) $attrs['afterSuccessBehavior'] : '';
		$config['coupon']        = isset( $attrs['coupon'] ) && '' !== (string) $attrs['coupon'] ? (string) $attrs['coupon'] : null;
		$config['after_success'] = '' !== $behavior ? [
			'behavior'     => $behavior,
			'url'          => isset( $attrs['afterSuccessURL'] ) ? (string) $attrs['afterSuccessURL'] : '',
			'button_label' => isset( $attrs['afterSuccessButtonLabel'] ) ? (string) $attrs['afterSuccessButtonLabel'] : '',
		] : null;
		return $config;
	}
}
