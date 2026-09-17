<?php
/**
 * WooCommerce content detector.
 *
 * Detects whether the current front-end request renders WooCommerce content
 * (blocks or classic shortcodes) so the Perfmatters integration can veto the
 * "Disable WooCommerce Scripts" strip on those requests only. See NPPM-193.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Detects WooCommerce content on the current request.
 */
class WooCommerce_Content_Detector {

	/**
	 * Memoized per-request result. Null = not yet computed.
	 *
	 * @var bool|null
	 */
	private static $memo = null;

	/**
	 * WooCommerce shortcode tags that render storefront content depending on
	 * WooCommerce's frontend stylesheets.
	 *
	 * Maintenance obligation: review on WooCommerce major upgrades (the list can
	 * drift from WooCommerce's actual registrations). See design doc
	 * "Dependencies & fragility".
	 *
	 * @var string[]
	 */
	private static $wc_shortcode_tags = [
		'products',
		'product',
		'product_page',
		'product_category',
		'product_categories',
		'recent_products',
		'featured_products',
		'sale_products',
		'best_selling_products',
		'top_rated_products',
		'related_products',
		'add_to_cart',
		'add_to_cart_url',
		'woocommerce_cart',
		'woocommerce_checkout',
		'woocommerce_my_account',
		'woocommerce_order_tracking',
		'shop_messages',
	];

	/**
	 * Whether the current request renders WooCommerce content.
	 *
	 * Fail-open: on any error, returns true (assume WooCommerce content present)
	 * so the Perfmatters strip is vetoed and assets are kept — never strip on
	 * doubt.
	 *
	 * Scope: this detects WooCommerce content embedded in otherwise-non-WooCommerce
	 * requests (a block/shortcode on a page, CPT, widget, or FSE template). It does
	 * NOT try to recognize native WooCommerce routes (shop, cart, checkout, account,
	 * single product, product taxonomies) — Perfmatters keeps WooCommerce assets on
	 * those itself, gating its strip on `is_woocommerce()/is_cart()/is_checkout()/
	 * is_account_page()/is_product()/is_product_category()/is_shop()`. So returning
	 * false on those routes is correct; the strip never runs there.
	 *
	 * Must be called once the main query and the FSE template are resolved (it reads
	 * `get_queried_object()` and `$_wp_current_template_content`) and memoizes for
	 * the request. The sole caller runs on `perfmatters_disable_woocommerce_scripts`
	 * (wp_enqueue_scripts, priority 99), which satisfies that ordering.
	 *
	 * @return bool
	 */
	public static function current_request_has_woocommerce_content() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		try {
			$visited    = [];
			self::$memo = self::scan_queried_post( $visited )
				|| self::scan_active_block_widgets( $visited )
				|| self::scan_fse_template( $visited );
		} catch ( \Throwable $e ) {
			// Fail open: keep WooCommerce assets. Set the memo BEFORE the (fallible)
			// log call and guard the log, so a misbehaving `newspack_log` listener
			// can't escape this catch and re-introduce the hard failure during
			// wp_enqueue_scripts that fail-open exists to prevent.
			self::$memo = true;
			try {
				// newspack_log surfaces a *persistent* failure (perf win silently
				// off site-wide) in Newspack Manager, not just local logs.
				Logger::newspack_log(
					'newspack_perfmatters_wc_detection_error',
					'WooCommerce content detection failed; keeping WooCommerce assets (fail-open).',
					[ 'error' => $e->getMessage() ],
					'error'
				);
			} catch ( \Throwable $log_error ) {
				// Last resort if a newspack_log listener throws: the local logger
				// writes without dispatching an action, so fail-open still holds.
				Logger::log( 'WooCommerce content detection fail-open log failed: ' . $log_error->getMessage(), 'NEWSPACK-PERFMATTERS', 'error' );
			}
		}

		return self::$memo;
	}

	/**
	 * Reset the per-request memo. For tests only.
	 */
	public static function reset_memo() {
		self::$memo = null;
	}

	/**
	 * Recursive markup scanner: matchers first, then indirection expansion.
	 *
	 * @param string $markup  Block markup to scan.
	 * @param array  $visited Reference set of already-resolved refs ("type:id").
	 * @return bool
	 */
	private static function markup_has_woocommerce( $markup, &$visited ) {
		if ( ! is_string( $markup ) || '' === $markup ) {
			return false;
		}
		if ( self::markup_has_wc_block( $markup ) || self::markup_has_wc_shortcode( $markup ) ) {
			return true;
		}
		return self::expand_references( $markup, $visited );
	}

	/**
	 * Whether markup contains any woocommerce/* block (catches any nesting depth
	 * because serialized block markup is inline).
	 *
	 * @param string $markup Block markup.
	 * @return bool
	 */
	private static function markup_has_wc_block( $markup ) {
		return str_contains( $markup, '<!-- wp:woocommerce/' );
	}

	/**
	 * Whether markup contains any known WooCommerce shortcode. Relies on the
	 * shortcode being registered (WooCommerce registers its shortcodes on `init`,
	 * before wp_enqueue_scripts priority 99).
	 *
	 * @param string $markup Markup/content.
	 * @return bool
	 */
	private static function markup_has_wc_shortcode( $markup ) {
		foreach ( self::$wc_shortcode_tags as $tag ) {
			if ( has_shortcode( $markup, $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Follow core/template-part and core/block references found in markup.
	 *
	 * @param string $markup  Block markup.
	 * @param array  $visited Reference set.
	 * @return bool
	 */
	private static function expand_references( $markup, &$visited ) {
		$has_part = str_contains( $markup, '<!-- wp:template-part' );
		// 'wp:block ' (with the trailing space) matches only core/block: block names
		// are slash-separated namespace/name, so a space after 'wp:block' appears
		// only when the block name is exactly 'block' (core/block, always serialized
		// with a ref attr). Avoids parsing markup that has no references.
		$has_pattern = str_contains( $markup, '<!-- wp:block ' );
		if ( ( ! $has_part && ! $has_pattern ) || ! function_exists( 'parse_blocks' ) ) {
			return false;
		}
		return self::scan_blocks( parse_blocks( $markup ), $visited );
	}

	/**
	 * Recurse a parsed block tree, resolving template-part and synced-pattern
	 * references. The visited set guards reference cycles; $depth bounds runaway
	 * innerBlocks nesting (which carries no reference identity to track).
	 *
	 * @param array $blocks  Parsed blocks.
	 * @param array $visited Reference set ("type:id").
	 * @param int   $depth   Current innerBlocks recursion depth.
	 * @return bool
	 * @throws \RuntimeException If the block nesting depth limit is exceeded (caught
	 *                           by the entry point's fail-open handler).
	 */
	private static function scan_blocks( $blocks, &$visited, $depth = 0 ) {
		if ( $depth > 100 ) {
			// Runaway nesting is unexpected; fail open via the caller's catch
			// (keep assets + log) rather than silently under-detecting and
			// letting Perfmatters strip the assets.
			throw new \RuntimeException( 'WooCommerce content detection exceeded the maximum block nesting depth.' );
		}
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : '';

			if ( 'core/block' === $name ) {
				$content = self::resolve_synced_pattern( $block, $visited );
				if ( null !== $content && self::markup_has_woocommerce( $content, $visited ) ) {
					return true;
				}
			} elseif ( 'core/template-part' === $name ) {
				$content = self::resolve_template_part( $block, $visited );
				if ( null !== $content && self::markup_has_woocommerce( $content, $visited ) ) {
					return true;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && self::scan_blocks( $block['innerBlocks'], $visited, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a core/block (synced pattern / reusable block) to its content.
	 *
	 * @param array $block   The core/block block.
	 * @param array $visited Reference set.
	 * @return string|null Content, or null if unresolvable or already visited.
	 */
	private static function resolve_synced_pattern( $block, &$visited ) {
		$ref = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;
		if ( ! $ref ) {
			return null;
		}
		$key = 'block:' . $ref;
		if ( isset( $visited[ $key ] ) ) {
			return null;
		}
		$visited[ $key ] = true;
		$post            = get_post( $ref );
		return ( $post instanceof \WP_Post && 'wp_block' === $post->post_type ) ? $post->post_content : null;
	}

	/**
	 * Resolve a core/template-part block to its content. Resolution is recursive
	 * via markup_has_woocommerce (a part may include another part).
	 *
	 * @param array $block   The template-part block.
	 * @param array $visited Reference set.
	 * @return string|null Content, or null if unresolvable or already visited.
	 */
	private static function resolve_template_part( $block, &$visited ) {
		if ( ! function_exists( 'get_block_template' ) ) {
			return null;
		}
		$slug = isset( $block['attrs']['slug'] ) ? $block['attrs']['slug'] : '';
		if ( '' === $slug ) {
			return null;
		}
		$theme = isset( $block['attrs']['theme'] ) ? $block['attrs']['theme'] : get_stylesheet();
		$id    = $theme . '//' . $slug;
		$key   = 'part:' . $id;
		if ( isset( $visited[ $key ] ) ) {
			return null;
		}
		$visited[ $key ] = true;
		$template        = get_block_template( $id, 'wp_template_part' );
		return ( $template && ! empty( $template->content ) ) ? $template->content : null;
	}

	/**
	 * Source: the queried post's content (post-type-agnostic).
	 *
	 * @param array $visited Reference set.
	 * @return bool
	 */
	private static function scan_queried_post( &$visited ) {
		$queried = get_queried_object();
		if ( ! $queried instanceof \WP_Post ) {
			return false;
		}
		return self::markup_has_woocommerce( $queried->post_content, $visited );
	}

	/**
	 * Source: active block widgets. Scans only widgets assigned to active
	 * sidebars; wp_inactive_widgets are deliberately skipped so orphaned widgets
	 * cannot veto the Perfmatters strip site-wide.
	 *
	 * @param array $visited Reference set.
	 * @return bool
	 */
	private static function scan_active_block_widgets( &$visited ) {
		$sidebars = wp_get_sidebars_widgets();
		if ( empty( $sidebars ) || ! is_array( $sidebars ) ) {
			return false;
		}
		$instances = get_option( 'widget_block', [] );
		if ( empty( $instances ) || ! is_array( $instances ) ) {
			return false;
		}
		foreach ( $sidebars as $sidebar_id => $widget_ids ) {
			// Skip the inactive store: orphaned widgets must not veto the strip.
			if ( 'wp_inactive_widgets' === $sidebar_id || empty( $widget_ids ) || ! is_array( $widget_ids ) ) {
				continue;
			}
			foreach ( $widget_ids as $widget_id ) {
				if ( ! preg_match( '/^block-(\d+)$/', (string) $widget_id, $matches ) ) {
					continue;
				}
				$index = (int) $matches[1];
				if ( empty( $instances[ $index ]['content'] ) ) {
					continue;
				}
				if ( self::markup_has_woocommerce( $instances[ $index ]['content'], $visited ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Source: the resolved FSE template.
	 *
	 * @param array $visited Reference set.
	 * @return bool
	 */
	private static function scan_fse_template( &$visited ) {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return false;
		}
		// WordPress populates this global in locate_block_template() on the
		// template_include filter — before wp_enqueue_scripts (priority 99) runs.
		// Guard the empty/unset case (a classic/hybrid route on a block theme may
		// leave it empty): treat as a clean miss, not an error.
		// NOTE: underscore-prefixed core internal; re-verify on WP upgrades.
		if ( ! isset( $GLOBALS['_wp_current_template_content'] ) || '' === $GLOBALS['_wp_current_template_content'] ) {
			return false;
		}
		return self::markup_has_woocommerce( $GLOBALS['_wp_current_template_content'], $visited );
	}
}
